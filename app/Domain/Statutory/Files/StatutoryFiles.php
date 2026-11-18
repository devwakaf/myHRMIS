<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Files;

use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Enums\PayrollFileKind;
use App\Domain\Payroll\Exports\PayrollFileStore;
use App\Domain\Payroll\Models\PayrollFile;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Statutory\Enums\FileScheme;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Models\StatutoryFileFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Government upload files (spec-04 §6.1–§6.3): PERKESO combined 278-char file
 * (ASSIST 2.0), CP39 (LHDN) and the KWSP contribution file (layout unverified).
 * They read only submitted slips of the period (plus withheld per setting) and
 * validate every record before anything is written.
 */
final class StatutoryFiles
{
    public function __construct(private readonly PayrollFileStore $store) {}

    /**
     * @return Collection<int, SalarySlip>
     */
    public function slips(CarbonImmutable $period): Collection
    {
        $settings = PayrollSettings::current();

        return SalarySlip::query()->where('status', DocumentStatus::Submitted->value)
            ->whereDate('period', $period->startOfMonth()->toDateString())
            ->when(! $settings->include_withheld_in_statutory, fn ($q) => $q->where('payment_status', '!=', PaymentStatus::Withheld->value))
            ->with('statutory')->orderBy('employee_no')->get();
    }

    public function perkeso(CarbonImmutable $period): PayrollFile
    {
        $format = $this->format(FileScheme::Perkeso, $period);
        $org = Organisation::query()->findOrFail(OrganisationContext::id());
        $layout = $format->layout;
        $length = (int) ($layout['record_length'] ?? 278);
        $lines = [];
        $errors = [];
        $total = '0';

        foreach ($this->slips($period) as $slip) {
            $st = $slip->statutory;
            $ctx = ['org' => $org, 'slip' => $slip, 'statutory' => $st, 'period' => $period];
            $id = FileFieldResolver::value('employee.perkeso_id', $ctx);
            if ($id === '') {
                $errors["slip.{$slip->id}"] = (string) __('statutory.errors.missing_id', ['employee' => $slip->employee_no, 'field' => 'IC / SSFW']);

                continue;
            }
            $lines[] = $this->fixedRecord($layout, $length, $ctx);
            $total = Num::add($total, (string) $st?->socso_employer, (string) $st?->socso_employee, (string) $st?->eis_employer, (string) $st?->eis_employee, (string) $st?->skbbk_employee);
        }
        $this->throwIf($errors);

        $name = $this->fileName($format, $org, $period, 'PERKESO_'.$period->format('mY').'.txt');

        return $this->store->store(PayrollFileKind::Perkeso, $period, $name, $this->join($lines, $format), [
            'format_code' => $format->code, 'format_version' => $format->version, 'unverified' => ! $format->isVerified(),
            'records' => count($lines), 'total' => Num::money($total),
        ]);
    }

    public function cp39(CarbonImmutable $period): PayrollFile
    {
        $format = $this->format(FileScheme::Cp39, $period);
        $org = Organisation::query()->findOrFail(OrganisationContext::id());
        $layout = $format->layout;
        $literal = ($layout['options']['amount_style'] ?? 'literal') === 'literal';
        $details = [];
        $errors = [];
        $pcbTotal = '0';
        $cp38Total = '0';
        $pcbCount = 0;
        $cp38Count = 0;

        foreach ($this->slips($period) as $slip) {
            $st = $slip->statutory;
            $pcb = (string) ($st->pcb_net ?? '0');
            $cp38 = (string) ($st->cp38 ?? '0');
            if (! Num::isPositive($pcb) && ! Num::isPositive($cp38)) {
                continue;
            }
            if (strlen($slip->employee_no) > 10) {
                $errors["slip.{$slip->id}"] = (string) __('statutory.errors.employee_no_too_long', ['employee' => $slip->employee_no]);

                continue;
            }
            $ctx = ['org' => $org, 'slip' => $slip, 'statutory' => $st, 'period' => $period];
            $details[] = FixedWidthWriter::record(136, [
                ['start' => 1, 'length' => 1, 'value' => 'D'],
                ['start' => 2, 'length' => 11, 'value' => $this->tin((string) $slip->tin, $layout), 'align' => 'right', 'pad' => '0'],
                ['start' => 13, 'length' => 60, 'value' => FileFieldResolver::value('employee.full_name', $ctx)],
                ['start' => 73, 'length' => 12, 'value' => (string) $slip->old_ic_no],
                ['start' => 85, 'length' => 12, 'value' => FixedWidthWriter::digits($slip->ic_no)],
                ['start' => 97, 'length' => 12, 'value' => (string) $slip->passport_no],
                ['start' => 109, 'length' => 2, 'value' => (string) $slip->passport_country],
                ['start' => 111, 'length' => 8, 'value' => $this->amount($pcb, $literal), 'align' => 'right', 'pad' => '0'],
                ['start' => 119, 'length' => 8, 'value' => $this->amount($cp38, $literal), 'align' => 'right', 'pad' => '0'],
                ['start' => 127, 'length' => 10, 'value' => $slip->employee_no],
            ]);
            if (Num::isPositive($pcb)) {
                $pcbTotal = Num::add($pcbTotal, $pcb);
                $pcbCount++;
            }
            if (Num::isPositive($cp38)) {
                $cp38Total = Num::add($cp38Total, $cp38);
                $cp38Count++;
            }
        }
        $this->throwIf($errors);

        $employerNo = FixedWidthWriter::digits($org->getAttribute('lhdn_employer_no'));
        $header = FixedWidthWriter::record(57, [
            ['start' => 1, 'length' => 1, 'value' => 'H'],
            ['start' => 2, 'length' => 10, 'value' => FileFieldResolver::value('org.lhdn_hq_employer_no', ['org' => $org]), 'align' => 'right', 'pad' => '0'],
            ['start' => 12, 'length' => 10, 'value' => $employerNo, 'align' => 'right', 'pad' => '0'],
            ['start' => 22, 'length' => 4, 'value' => $period->format('Y')],
            ['start' => 26, 'length' => 2, 'value' => $period->format('m')],
            ['start' => 28, 'length' => 10, 'value' => $this->amount($pcbTotal, $literal), 'align' => 'right', 'pad' => '0'],
            ['start' => 38, 'length' => 5, 'value' => (string) $pcbCount, 'align' => 'right', 'pad' => '0'],
            ['start' => 43, 'length' => 10, 'value' => $this->amount($cp38Total, $literal), 'align' => 'right', 'pad' => '0'],
            ['start' => 53, 'length' => 5, 'value' => (string) $cp38Count, 'align' => 'right', 'pad' => '0'],
        ]);

        $name = str_pad(substr($employerNo, 0, 10), 10, '0', STR_PAD_LEFT).$period->format('m').'_'.$period->format('Y').'.txt';

        return $this->store->store(PayrollFileKind::Cp39, $period, $name, $this->join([$header, ...$details], $format), [
            'format_code' => $format->code, 'format_version' => $format->version, 'unverified' => ! $format->isVerified(),
            'records' => count($details), 'total' => Num::money(Num::add($pcbTotal, $cp38Total)),
        ]);
    }

    /** KWSP contribution file — layout UNVERIFIED (stat §7); exported with a warning banner. */
    public function kwsp(CarbonImmutable $period): PayrollFile
    {
        $settings = PayrollSettings::current();
        $format = $settings->kwsp_file_format_id !== null
            ? StatutoryFileFormat::query()->findOrFail($settings->kwsp_file_format_id)
            : $this->format(FileScheme::Kwsp, $period);
        $org = Organisation::query()->findOrFail(OrganisationContext::id());
        $layout = $format->layout;
        $delimiter = (string) ($layout['delimiter'] ?? ',');
        $lines = [];
        foreach ((array) ($layout['header_rows'] ?? []) as $row) {
            $lines[] = implode($delimiter, (array) $row);
        }
        $errors = [];
        $total = '0';
        foreach ($this->slips($period) as $slip) {
            $st = $slip->statutory;
            if ($st === null || (! Num::isPositive((string) $st->epf_employee) && ! Num::isPositive((string) $st->epf_employer))) {
                continue;
            }
            $ctx = ['org' => $org, 'slip' => $slip, 'statutory' => $st, 'period' => $period];
            if (FileFieldResolver::value('employee.kwsp_id', $ctx) === '') {
                $errors["slip.{$slip->id}"] = (string) __('statutory.errors.missing_id', ['employee' => $slip->employee_no, 'field' => 'KWSP / IC / pasport']);

                continue;
            }
            $cells = [];
            foreach ((array) ($layout['fields'] ?? []) as $field) {
                $cells[] = FileFieldResolver::format(FileFieldResolver::value((string) $field['source'], $ctx), $field['format'] ?? null);
            }
            $lines[] = implode($delimiter, $cells);
            $total = Num::add($total, (string) $st->epf_employee, (string) $st->epf_employer);
        }
        $this->throwIf($errors);

        $name = $this->fileName($format, $org, $period, 'KWSP_'.$period->addMonth()->format('mY').'.csv');

        return $this->store->store(PayrollFileKind::Kwsp, $period, $name, $this->join($lines, $format), [
            'format_code' => $format->code, 'format_version' => $format->version, 'unverified' => ! $format->isVerified(),
            'records' => count($lines) - count((array) ($layout['header_rows'] ?? [])), 'total' => Num::money($total),
        ]);
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $ctx
     */
    private function fixedRecord(array $layout, int $length, array $ctx): string
    {
        $fields = [];
        $numericPad = (string) ($layout['options']['numeric_pad'] ?? '0');
        foreach ((array) ($layout['fields'] ?? []) as $field) {
            $value = FileFieldResolver::format(FileFieldResolver::value((string) $field['source'], $ctx), $field['format'] ?? null);
            $isNumeric = ($field['format'] ?? null) === 'sen';
            $fields[] = [
                'start' => (int) $field['start'], 'length' => (int) $field['length'], 'value' => $value,
                'align' => (string) ($field['align'] ?? ($isNumeric ? 'right' : 'left')),
                'pad' => (string) ($field['pad'] ?? ($isNumeric ? $numericPad : ' ')),
            ];
        }

        return FixedWidthWriter::record($length, $fields);
    }

    private function format(FileScheme $scheme, CarbonImmutable $period): StatutoryFileFormat
    {
        return StatutoryFileFormat::effectiveFor($scheme, $period)
            ?? throw new StatutoryRateException('statutory.errors.no_file_format', ['scheme' => $scheme->label()]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function join(array $lines, StatutoryFileFormat $format): string
    {
        $eol = $format->line_ending === '' ? "\r\n" : stripcslashes($format->line_ending);

        return $lines === [] ? '' : implode($eol, $lines).$eol;
    }

    private function fileName(StatutoryFileFormat $format, Organisation $org, CarbonImmutable $period, string $fallback): string
    {
        $pattern = $format->filename_pattern;
        if ($pattern === null || $pattern === '') {
            return $fallback;
        }

        return strtr($pattern, [
            '{employer}' => (string) ($org->getAttribute('perkeso_employer_code') ?? $org->short_code),
            '{kwsp_employer}' => (string) $org->getAttribute('kwsp_employer_no'),
            '{MM}' => $period->format('m'), '{YYYY}' => $period->format('Y'), '{MMYYYY}' => $period->format('mY'),
            '{CMM}' => $period->addMonth()->format('m'), '{CYYYY}' => $period->addMonth()->format('Y'),
        ]);
    }

    /** CP39 amounts: literal "285.70" (default) or implied decimals "28570" (UNVERIFIED-STAT layout flag). */
    private function amount(string $value, bool $literal): string
    {
        return $literal ? Num::money($value) : Num::sen($value);
    }

    /**
     * @param  array<string, mixed>  $layout
     */
    private function tin(string $tin, array $layout): string
    {
        return ($layout['options']['tin_mode'] ?? 'digits_only') === 'as_is' ? strtoupper($tin) : FixedWidthWriter::digits($tin);
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function throwIf(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
