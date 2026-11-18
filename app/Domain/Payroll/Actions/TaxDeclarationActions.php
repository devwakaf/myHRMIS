<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\DeclarationType;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Models\TaxDeclarationLine;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Support\DomainValidation;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Services\StatutoryRateRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deklarasi Cukai (spec-04 §2.8): TP1 lines with annual cap check, TP3 previous
 * employer YTD, external zakat. Submit/cancel rebuild the tax year. Declarations
 * already consumed by a submitted slip are locked (corrections are new rows).
 */
final class TaxDeclarationActions
{
    public function __construct(
        private readonly StatutoryRateRepository $rates,
        private readonly RefreshTaxYear $refresh,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?TaxDeclaration $row, array $data): TaxDeclaration
    {
        if ($row !== null && ! $row->isDraft()) {
            throw new PayrollRuleViolation('payroll.errors.not_draft');
        }
        $employee = Employee::query()->find($data['employee_id'] ?? 0);
        $type = DeclarationType::tryFrom((string) ($data['type'] ?? ''));
        if ($employee === null || $type === null || empty($data['period'])) {
            throw DomainValidation::withMessages(['type' => __('payroll.errors.declaration_invalid')]);
        }
        $period = CarbonImmutable::parse((string) $data['period'])->startOfMonth();
        $isCorrection = (bool) ($data['is_correction'] ?? false);

        $values = ['employee_id' => $employee->id, 'type' => $type, 'tax_year' => $period->year, 'period' => $period->toDateString(), 'is_correction' => $isCorrection];
        foreach (TaxDeclaration::AMOUNT_COLUMNS as $column) {
            $values[$column] = Num::money((string) ($data[$column] ?? '0'));
        }
        foreach (['prev_employer_name', 'prev_employer_e_no', 'zakat_authority', 'receipt_no', 'remarks'] as $column) {
            $values[$column] = $data[$column] ?? null;
        }
        foreach (['prev_from', 'prev_to', 'receipt_date'] as $column) {
            $values[$column] = empty($data[$column]) ? null : CarbonImmutable::parse((string) $data[$column])->toDateString();
        }

        /** @var list<array{relief_code: string, amount: string|int, reason?: string|null}> $lines */
        $lines = $type === DeclarationType::Tp1 ? array_values((array) ($data['lines'] ?? [])) : [];
        $this->validateLines($employee, $period, $lines, $isCorrection, $row?->id);
        if ($type === DeclarationType::ZakatExternal && ! Num::isPositive($values['zakat_amount'])) {
            throw DomainValidation::withMessages(['zakat_amount' => __('payroll.errors.amount_positive')]);
        }

        return DB::transaction(function () use ($row, $values, $lines): TaxDeclaration {
            $row ??= new TaxDeclaration;
            $row->fill($values)->save();
            $row->lines()->delete();
            foreach ($lines as $line) {
                TaxDeclarationLine::query()->create([
                    'tax_declaration_id' => $row->id, 'relief_code' => $line['relief_code'],
                    'amount' => Num::money((string) $line['amount']), 'reason' => $line['reason'] ?? null,
                ]);
            }

            return $row;
        });
    }

    public function submit(TaxDeclaration $row, User $actor): TaxDeclaration
    {
        DB::transaction(fn () => $row->transitionTo(DocumentStatus::Submitted, $actor));
        $this->refresh->handle($row->employee_id, $row->tax_year);

        return $row;
    }

    public function cancel(TaxDeclaration $row, User $actor, string $reason): TaxDeclaration
    {
        if ($row->consumed_by_slip_id !== null) {
            throw new PayrollRuleViolation('payroll.errors.declaration_consumed');
        }
        if ($row->isDraft()) {
            $row->delete();

            return $row;
        }
        DB::transaction(fn () => $row->transitionTo(DocumentStatus::Cancelled, $actor, $reason));
        $this->refresh->handle($row->employee_id, $row->tax_year);

        return $row;
    }

    /**
     * @param  list<array{relief_code: string, amount: string|int, reason?: string|null}>  $lines
     */
    private function validateLines(Employee $employee, CarbonImmutable $period, array $lines, bool $isCorrection, ?int $exceptId): void
    {
        if ($lines === []) {
            return;
        }
        $set = $this->rates->setFor(RateScheme::TaxReliefs, $period);
        $caps = $set === null ? [] : $this->rates->reliefs($set)->tp1Caps;
        $errors = [];

        foreach ($lines as $i => $line) {
            $code = (string) ($line['relief_code'] ?? '');
            $amount = (string) ($line['amount'] ?? '0');
            if (! array_key_exists($code, $caps)) {
                $errors["lines.$i.relief_code"] = __('payroll.errors.relief_code_unknown', ['code' => $code]);

                continue;
            }
            if (! is_numeric($amount) || (Num::isNegative($amount) && ! $isCorrection) || Num::isZero($amount)) {
                $errors["lines.$i.amount"] = __('payroll.errors.amount_positive');

                continue;
            }
            if (Num::isNegative($amount) && trim((string) ($line['reason'] ?? '')) === '') {
                $errors["lines.$i.reason"] = __('payroll.errors.reason_required');
            }
            $cap = $caps[$code];
            if ($cap === null) {
                continue;
            }
            $used = (string) DB::table('tax_declaration_lines as l')->join('tax_declarations as d', 'd.id', '=', 'l.tax_declaration_id')
                ->where('d.employee_id', $employee->id)->where('d.tax_year', $period->year)->where('d.status', DocumentStatus::Submitted->value)
                ->where('l.relief_code', $code)->when($exceptId !== null, fn ($q) => $q->where('d.id', '!=', $exceptId))->sum('l.amount');
            if (Num::cmp(Num::add($used, $amount), $cap) > 0) {
                $errors["lines.$i.amount"] = __('payroll.errors.relief_cap_exceeded', ['code' => $code, 'cap' => $cap]);
            }
        }
        DomainValidation::throwIf($errors);
    }
}
