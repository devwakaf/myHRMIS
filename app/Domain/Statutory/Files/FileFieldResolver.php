<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Files;

use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Models\OrganisationBankAccount;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalarySlipStatutory;
use App\Domain\Shared\Money\Num;
use Carbon\CarbonImmutable;

/**
 * The closed catalogue of field sources used by file layouts (spec-04 §5.6, §6):
 * nothing outside this list can be read from a layout JSON.
 */
final class FileFieldResolver
{
    public const SOURCES = [
        'org.name', 'org.perkeso_employer_code', 'org.ssm_no', 'org.kwsp_employer_no', 'org.lhdn_employer_no', 'org.lhdn_hq_employer_no',
        'employee.employee_no', 'employee.full_name', 'employee.ic_no', 'employee.old_ic_no', 'employee.passport_no', 'employee.passport_country',
        'employee.perkeso_id', 'employee.kwsp_id', 'employee.kwsp_no', 'employee.tin', 'employee.bank_account_no', 'employee.bank_account_name',
        'employee.bank_code', 'slip.period_mmyyyy', 'slip.contribution_mmyyyy', 'slip.net_pay', 'slip.gross_pay', 'slip.payment_date',
        'statutory.socso_wage', 'statutory.socso_employer', 'statutory.socso_employee', 'statutory.eis_wage', 'statutory.eis_employer',
        'statutory.eis_employee', 'statutory.skbbk_employee', 'statutory.epf_wage', 'statutory.epf_employer', 'statutory.epf_employee',
        'statutory.pcb', 'statutory.cp38', 'run.payment_date', 'org_account.account_no', 'org_account.corporate_id', 'org_account.account_name',
        'totals.count', 'totals.amount', 'period.mm', 'period.yyyy', 'filler',
    ];

    /**
     * @param  array{org?: Organisation|null, slip?: SalarySlip|null, statutory?: SalarySlipStatutory|null, account?: OrganisationBankAccount|null, period?: CarbonImmutable|null, payment_date?: CarbonImmutable|null, totals?: array{count: int, amount: string}, bank_code?: string|null}  $ctx
     */
    public static function value(string $source, array $ctx): string
    {
        if (str_starts_with($source, 'literal:')) {
            return substr($source, 8);
        }

        $org = $ctx['org'] ?? null;
        $slip = $ctx['slip'] ?? null;
        $st = $ctx['statutory'] ?? null;
        $foreign = $slip !== null && in_array($slip->citizenship_status, ['foreign', 'foreign_pre_1998'], true);

        return match ($source) {
            'org.name' => (string) $org?->name,
            'org.perkeso_employer_code' => (string) $org?->getAttribute('perkeso_employer_code'),
            'org.ssm_no' => (string) $org?->getAttribute('ssm_no'),
            'org.kwsp_employer_no' => (string) $org?->getAttribute('kwsp_employer_no'),
            'org.lhdn_employer_no' => FixedWidthWriter::digits($org?->getAttribute('lhdn_employer_no')),
            'org.lhdn_hq_employer_no' => FixedWidthWriter::digits($org?->getAttribute('lhdn_hq_employer_no') ?? $org?->getAttribute('lhdn_employer_no')),
            'employee.employee_no' => (string) $slip?->employee_no,
            'employee.full_name' => FixedWidthWriter::ascii((string) $slip?->full_name),
            'employee.ic_no' => FixedWidthWriter::digits($slip?->ic_no),
            'employee.old_ic_no' => (string) $slip?->old_ic_no,
            'employee.passport_no' => (string) $slip?->passport_no,
            'employee.passport_country' => (string) $slip?->passport_country,
            'employee.perkeso_id' => $foreign ? (string) $slip->perkeso_no : FixedWidthWriter::digits($slip?->ic_no),
            'employee.kwsp_id' => (string) ($slip?->kwsp_no ?: ($foreign ? $slip->passport_no : FixedWidthWriter::digits($slip?->ic_no))),
            'employee.kwsp_no' => (string) $slip?->kwsp_no,
            'employee.tin' => (string) $slip?->tin,
            'employee.bank_account_no' => FixedWidthWriter::digits($slip?->bank_account_no),
            'employee.bank_account_name' => FixedWidthWriter::ascii((string) ($slip?->bank_account_name ?: $slip?->full_name)),
            'employee.bank_code' => (string) ($ctx['bank_code'] ?? ''),
            'slip.period_mmyyyy' => (string) $slip?->period->format('mY'),
            'slip.contribution_mmyyyy' => (string) $slip?->period->addMonth()->format('mY'),
            'slip.net_pay' => (string) $slip?->net_pay,
            'slip.gross_pay' => (string) $slip?->gross_pay,
            'slip.payment_date', 'run.payment_date' => (string) ($ctx['payment_date'] ?? $slip?->payment_date)?->format('Y-m-d'),
            'statutory.socso_wage' => (string) $st?->socso_wage,
            'statutory.socso_employer' => (string) $st?->socso_employer,
            'statutory.socso_employee' => (string) $st?->socso_employee,
            'statutory.eis_wage' => (string) $st?->eis_wage,
            'statutory.eis_employer' => (string) $st?->eis_employer,
            'statutory.eis_employee' => (string) $st?->eis_employee,
            'statutory.skbbk_employee' => (string) $st?->skbbk_employee,
            'statutory.epf_wage' => (string) $st?->epf_wage,
            'statutory.epf_employer' => (string) $st?->epf_employer,
            'statutory.epf_employee' => (string) $st?->epf_employee,
            'statutory.pcb' => (string) $st?->pcb_net,
            'statutory.cp38' => (string) $st?->cp38,
            'org_account.account_no' => FixedWidthWriter::digits($ctx['account']->account_no ?? ''),
            'org_account.corporate_id' => (string) ($ctx['account']->corporate_id ?? ''),
            'org_account.account_name' => FixedWidthWriter::ascii((string) ($ctx['account']->account_name ?? '')),
            'totals.count' => (string) ($ctx['totals']['count'] ?? 0),
            'totals.amount' => (string) ($ctx['totals']['amount'] ?? '0.00'),
            'period.mm' => (string) ($ctx['period'] ?? $slip?->period)?->format('m'),
            'period.yyyy' => (string) ($ctx['period'] ?? $slip?->period)?->format('Y'),
            'filler' => '',
            default => throw new \InvalidArgumentException("Unknown file field source [{$source}]."),
        };
    }

    /** Apply a field format: sen (integer cents), amount (2 dp), date_dmy, upper, digits. */
    public static function format(string $value, ?string $format): string
    {
        return match ($format) {
            'sen' => Num::sen($value === '' ? '0' : $value),
            'amount' => Num::money($value === '' ? '0' : $value),
            'date_dmy' => $value === '' ? '' : CarbonImmutable::parse($value)->format('dmY'),
            'upper' => FixedWidthWriter::ascii($value),
            'digits' => FixedWidthWriter::digits($value),
            default => $value,
        };
    }
}
