<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Enums\GenerationStatus;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Services\EligibleEmployees;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Payroll Run wizard steps 1, 2 and 4 (spec-04 §1.2): create/update the draft,
 * review employees (Kecualikan / Sertakan) and draft-only overrides (hari CTG,
 * PCB) that survive "Jana Semula".
 */
final class PayrollRunActions
{
    public function __construct(
        private readonly EligibleEmployees $eligible,
        private readonly GenerateSalarySlip $generate,
    ) {}

    /**
     * @param  array{period: string, payment_date?: string|null, filters?: array<string, mixed>|null, notes?: string|null}  $data
     */
    public function create(array $data, User $actor): PayrollRun
    {
        $period = CarbonImmutable::parse($data['period'])->startOfMonth();
        $run = new PayrollRun([
            'period' => $period->toDateString(),
            'period_start' => $period->toDateString(),
            'period_end' => $period->endOfMonth()->toDateString(),
            'payment_date' => isset($data['payment_date'])
                ? CarbonImmutable::parse($data['payment_date'])->toDateString() : $period->endOfMonth()->toDateString(),
            'filters' => $data['filters'] ?? [],
            'notes' => $data['notes'] ?? null,
            'prepared_by' => $actor->id,
        ]);
        $run->save();

        return $run;
    }

    /**
     * @param  array{payment_date?: string|null, filters?: array<string, mixed>|null, notes?: string|null}  $data
     */
    public function update(PayrollRun $run, array $data): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Draft) {
            $data = array_intersect_key($data, ['notes' => true, 'payment_date' => true]);
        }
        if ($run->status->isLocked() && isset($data['payment_date'])) {
            unset($data['payment_date']);
        }
        $run->fill(array_filter($data, fn ($v) => $v !== null))->save();

        return $run;
    }

    /**
     * Step 2 preview: eligible, without assignment, filtered out, with warnings.
     *
     * @return array{eligible: list<int>, no_assignment: list<int>, filtered_out: int}
     */
    public function preview(PayrollRun $run): array
    {
        $result = $this->eligible->for($run);

        return [
            'eligible' => $result['eligible']->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'no_assignment' => $result['no_assignment']->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'filtered_out' => $result['filtered_out'],
        ];
    }

    public function exclude(PayrollRun $run, int $employeeId, string $reason): PayrollRunEmployee
    {
        $this->assertEditable($run);

        return DB::transaction(function () use ($run, $employeeId, $reason): PayrollRunEmployee {
            $row = PayrollRunEmployee::query()->firstOrCreate(['payroll_run_id' => $run->id, 'employee_id' => $employeeId]);
            $this->generate->discardDraft($row);
            $row->forceFill([
                'included' => false, 'exclusion_reason' => $reason, 'active_period_key' => null,
                'generation_status' => GenerationStatus::Skipped, 'error_code' => 'excluded', 'error_message' => $reason,
            ])->save();
            app(FinalisePayrollGeneration::class)->recomputeTotals($run);

            return $row;
        });
    }

    public function include(PayrollRun $run, int $employeeId): PayrollRunEmployee
    {
        $this->assertEditable($run);
        $row = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->firstOrFail();
        $row->forceFill(['included' => true, 'exclusion_reason' => null, 'generation_status' => GenerationStatus::Pending, 'error_code' => null, 'error_message' => null])->save();

        return $row;
    }

    public function overrideLwp(PayrollRun $run, int $employeeId, ?string $days, string $reason): PayrollRunEmployee
    {
        $this->assertEditable($run);
        if ($days !== null && (! is_numeric($days) || Num::isNegative($days))) {
            throw DomainValidation::withMessages(['days' => __('payroll.errors.invalid_days')]);
        }
        $row = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->firstOrFail();
        $row->forceFill(['lwp_override_days' => $days === null ? null : Num::fixed($days, 3), 'lwp_override_reason' => $days === null ? null : $reason])->save();

        return $row;
    }

    public function overridePcb(PayrollRun $run, int $employeeId, ?string $amount, string $reason): PayrollRunEmployee
    {
        $this->assertEditable($run);
        if ($amount !== null && (! is_numeric($amount) || Num::isNegative($amount))) {
            throw DomainValidation::withMessages(['amount' => __('payroll.errors.invalid_amount')]);
        }
        $row = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->firstOrFail();
        $row->forceFill(['pcb_override_amount' => $amount === null ? null : Num::money($amount), 'pcb_override_reason' => $amount === null ? null : $reason])->save();

        return $row;
    }

    private function assertEditable(PayrollRun $run): void
    {
        if (! in_array($run->status, [PayrollRunStatus::Draft, PayrollRunStatus::SlipsGenerated], true)) {
            throw new PayrollRuleViolation('payroll.errors.run_locked_edit');
        }
    }
}
