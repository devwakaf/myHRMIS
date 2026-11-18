<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Files;

use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Shared\Money\Num;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statutory listings (spec-04 §6.6): Senarai Caruman KWSP, PERKESO (with SKBBK), SIP,
 * PCB &amp; Zakat and Levi HRD Corp for one month. They read the same slips as the
 * government files (submitted, withheld per setting). Rows carry decimal strings; money
 * columns are flagged so the screen, the PDF and the spreadsheet format them alike.
 */
final class StatutoryListings
{
    public const KINDS = ['kwsp', 'perkeso', 'sip', 'pcb', 'hrdc'];

    public function __construct(private readonly StatutoryFiles $files) {}

    /**
     * @return array{columns: list<array{key: string, label: string, money: bool}>, rows: list<array<string, mixed>>, totals: array<string, string>, summary: array<string, string>}
     */
    public function build(string $kind, CarbonImmutable $period): array
    {
        $slips = $this->files->slips($period);

        return match ($kind) {
            'kwsp' => $this->table($kind, $slips->map(function (SalarySlip $s): array {
                $st = $s->statutory;

                return [
                    'employee_no' => $s->employee_no, 'full_name' => $s->full_name, 'member_id' => $s->kwsp_no ?: ($s->ic_no ?: (string) $s->passport_no),
                    'part' => $st === null ? '' : (string) __('statutory.enums.epf_part.'.$st->epf_part), 'wage' => (string) $st?->epf_wage,
                    'employer' => (string) $st?->epf_employer, 'employee' => (string) $st?->epf_employee,
                    'total' => Num::money(Num::add((string) $st?->epf_employer, (string) $st?->epf_employee)),
                ];
            })->filter(fn (array $r) => Num::isPositive($r['total']))->values()->all(), ['wage', 'employer', 'employee', 'total'], ['wage', 'employer', 'employee', 'total']),
            'perkeso' => $this->table($kind, $slips->map(function (SalarySlip $s): array {
                $st = $s->statutory;

                return [
                    'employee_no' => $s->employee_no, 'full_name' => $s->full_name, 'member_id' => $s->perkeso_no ?: ($s->ic_no ?: (string) $s->passport_no),
                    'category' => $st === null ? '' : (string) __('statutory.enums.socso_category.'.$st->socso_category), 'wage' => (string) $st?->socso_wage,
                    'employer' => (string) $st?->socso_employer, 'employee' => (string) $st?->socso_employee, 'skbbk' => (string) $st?->skbbk_employee,
                    'total' => Num::money(Num::add((string) $st?->socso_employer, (string) $st?->socso_employee, (string) $st?->skbbk_employee)),
                ];
            })->filter(fn (array $r) => Num::isPositive($r['total']))->values()->all(), ['wage', 'employer', 'employee', 'skbbk', 'total'], ['wage', 'employer', 'employee', 'skbbk', 'total']),
            'sip' => $this->table($kind, $slips->filter(fn (SalarySlip $s) => $s->statutory?->eis_eligible === true)->map(function (SalarySlip $s): array {
                $st = $s->statutory;

                return [
                    'employee_no' => $s->employee_no, 'full_name' => $s->full_name, 'member_id' => $s->ic_no ?: (string) $s->passport_no,
                    'wage' => (string) $st?->eis_wage, 'employer' => (string) $st?->eis_employer, 'employee' => (string) $st?->eis_employee,
                    'total' => Num::money(Num::add((string) $st?->eis_employer, (string) $st?->eis_employee)),
                ];
            })->values()->all(), ['wage', 'employer', 'employee', 'total'], ['wage', 'employer', 'employee', 'total']),
            'pcb' => $this->table($kind, $slips->map(function (SalarySlip $s): array {
                $st = $s->statutory;

                return [
                    'employee_no' => $s->employee_no, 'full_name' => $s->full_name, 'tin' => (string) $s->tin, 'pcb' => (string) $st?->pcb_net,
                    'cp38' => (string) $st?->cp38, 'zakat' => (string) $st?->zakat_payroll,
                    'total' => Num::money(Num::add((string) $st?->pcb_net, (string) $st?->cp38)),
                ];
            })->filter(fn (array $r) => Num::isPositive($r['total']) || Num::isPositive($r['zakat']))->values()->all(), ['pcb', 'cp38', 'zakat', 'total'], ['pcb', 'cp38', 'zakat', 'total']),
            default => $this->hrdc($slips),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $money  money column keys
     * @param  list<string>  $sum  keys summed into the totals row
     * @return array{columns: list<array{key: string, label: string, money: bool}>, rows: list<array<string, mixed>>, totals: array<string, string>, summary: array<string, string>}
     */
    private function table(string $kind, array $rows, array $money, array $sum): array
    {
        $keys = $rows === [] ? $this->defaultKeys($kind) : array_keys($rows[0]);
        $columns = array_map(fn (string $k) => ['key' => $k, 'label' => (string) __('statutory.listings.columns.'.$k), 'money' => in_array($k, $money, true)], $keys);
        $totals = [];
        foreach ($sum as $key) {
            $totals[$key] = Num::money(Num::add(...array_map(fn (array $r) => (string) $r[$key], $rows) ?: ['0']));
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'summary' => ['count' => (string) count($rows)]];
    }

    /**
     * @return list<string>
     */
    private function defaultKeys(string $kind): array
    {
        return match ($kind) {
            'kwsp' => ['employee_no', 'full_name', 'member_id', 'part', 'wage', 'employer', 'employee', 'total'],
            'perkeso' => ['employee_no', 'full_name', 'member_id', 'category', 'wage', 'employer', 'employee', 'skbbk', 'total'],
            'sip' => ['employee_no', 'full_name', 'member_id', 'wage', 'employer', 'employee', 'total'],
            'pcb' => ['employee_no', 'full_name', 'tin', 'pcb', 'cp38', 'zakat', 'total'],
            default => ['employee_no', 'full_name', 'citizenship', 'wage', 'rate', 'levy'],
        };
    }

    /**
     * Levi HRD Corp: employees the levy applies to, with the Malaysian headcount.
     *
     * @param  Collection<int, SalarySlip>  $slips
     * @return array{columns: list<array{key: string, label: string, money: bool}>, rows: list<array<string, mixed>>, totals: array<string, string>, summary: array<string, string>}
     */
    private function hrdc($slips): array
    {
        $rows = [];
        $malaysian = 0;
        foreach ($slips as $s) {
            $st = $s->statutory;
            if ($st === null || ! $st->hrdc_applies) {
                continue;
            }
            $isMalaysian = in_array($s->citizenship_status, ['citizen', 'permanent_resident'], true);
            $malaysian += $isMalaysian ? 1 : 0;
            $rows[] = [
                'employee_no' => $s->employee_no, 'full_name' => $s->full_name, 'citizenship' => (string) __('core.enums.citizenship_status.'.$s->citizenship_status),
                'wage' => (string) $st->hrdc_wage, 'rate' => rtrim(rtrim((string) $st->hrdc_rate, '0'), '.').'%', 'levy' => (string) $st->hrdc_levy,
            ];
        }
        $result = $this->table('hrdc', $rows, ['wage', 'levy'], ['wage', 'levy']);
        $result['summary'] = ['count' => (string) count($rows), 'malaysian' => (string) $malaysian];

        return $result;
    }
}
