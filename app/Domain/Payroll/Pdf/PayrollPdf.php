<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Pdf;

use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Models\EaForm;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Services\SlipPublisher;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Organisation\OrganisationContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;

/**
 * PDF documents of the Payroll module (spec-04 §1.4, §6.4–§6.6): Slip Gaji, Borang EA,
 * PCB 2(II), the cash / cheque payment list and the statutory listings. Layouts are our
 * own (resources/views/pdf), always rendered in Bahasa Melayu with the "RM 1,234.56"
 * money format; no remote resources are touched (config/dompdf.php).
 */
final class PayrollPdf
{
    /** Bump when a template changes: cached slip PDFs with an older version are re-rendered. */
    public const TEMPLATE_VERSION = 'slip-1';

    /** "1234.5" -> "RM 1,234.50"; negatives "-RM 12.00". */
    public static function money(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $text = trim((string) $value);
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '00');

        return ($negative ? '-' : '').'RM '.number_format((int) $whole, 0, '.', ',').'.'.substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    /** "2026-01-15" -> "15/01/2026". */
    public static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        [$y, $m, $d] = array_pad(explode('-', substr($value, 0, 10)), 3, '');

        return $d !== '' ? "$d/$m/$y" : $value;
    }

    /** "2026-01" or a date -> "Januari 2026". */
    public static function monthName(string $period): string
    {
        [$y, $m] = array_pad(explode('-', $period), 2, '1');

        return (string) __('common.months.'.(int) $m).' '.$y;
    }

    // ------------------------------------------------------------------ Slip Gaji

    /** Raw PDF bytes of a slip (password per setting). */
    public function slip(SalarySlip $slip): string
    {
        return $this->inMalay(function () use ($slip): string {
            $slip->loadMissing(['lines', 'statutory']);
            $settings = PayrollSettings::current($slip->organisation_id);
            $org = Organisation::query()->find($slip->organisation_id);
            $ic = $slip->ic_no;
            if ($settings->ic_display === 'masked' && $ic !== null && strlen($ic) >= 4) {
                $ic = str_repeat('*', strlen($ic) - 4).substr($ic, -4);
            }
            $lines = $slip->lines->filter(fn ($l) => $l->show_on_slip);

            $pdf = $this->render('pdf.slip', [
                'slip' => $slip, 'org' => $org, 'settings' => $settings, 'ic' => $ic,
                'designation' => $slip->designation_id === null ? null : Designation::query()->whereKey($slip->designation_id)->value('name'),
                'department' => $slip->department_id === null ? null : Department::query()->whereKey($slip->department_id)->value('name'),
                'earnings' => $lines->where('kind', ComponentKind::Earning)->values(),
                'deductions' => $lines->where('kind', ComponentKind::Deduction)->values(),
                'employer' => $lines->where('kind', ComponentKind::EmployerContribution)->values(),
                'ytd' => $slip->statutory?->ytd_snapshot,
                'bankLast4' => $slip->bank_account_no === null ? null : substr($slip->bank_account_no, -4),
            ]);
            $password = app(SlipPublisher::class)->pdfPassword($slip);
            if ($password !== null && $password !== '') {
                $pdf->setEncryption($password, '', ['print']);
            }

            return $pdf->output();
        });
    }

    /**
     * Path (local disk) of the cached slip PDF; rendered on first request after submit and
     * again when the template version changed. Drafts are rendered fresh and never cached.
     */
    public function ensureSlip(SalarySlip $slip): string
    {
        $disk = Storage::disk('local');
        $cacheable = $slip->status->value === 'submitted';
        if ($cacheable && $slip->pdf_path !== null && $slip->getAttribute('pdf_template_version') === self::TEMPLATE_VERSION && $disk->exists($slip->pdf_path)) {
            return $slip->pdf_path;
        }

        $path = 'payroll/slips/'.$slip->organisation_id.'/'.$slip->period->format('Y-m').'/'.$slip->number.($cacheable ? '' : '-draf').'.pdf';
        $disk->put($path, $this->slip($slip));
        if ($cacheable) {
            $slip->forceFill(['pdf_path' => $path, 'pdf_template_version' => self::TEMPLATE_VERSION])->save();
        }

        return $path;
    }

    // ------------------------------------------------------------------ EA, PCB 2(II)

    public function ea(EaForm $form): string
    {
        return $this->inMalay(fn (): string => $this->render('pdf.ea', ['form' => $form, 'data' => $form->data])->output());
    }

    /**
     * @param  array{employee_id: int, year: int, months: list<array<string, mixed>>, total_pcb: string, total_cp38: string}  $data
     * @param  array{employee_no: string, full_name: string, ic_no: string|null, tin: string|null}  $employee
     */
    public function pcb2ii(array $data, array $employee): string
    {
        return $this->inMalay(function () use ($data, $employee): string {
            $org = Organisation::query()->find(OrganisationContext::id());

            return $this->render('pdf.pcb2ii', ['data' => $data, 'employee' => $employee, 'org' => $org])->output();
        });
    }

    // ------------------------------------------------------------------ lists

    /**
     * @param  list<array{employee_no: string, full_name: string, payment_mode: string, net_pay: string}>  $rows
     */
    public function cashList(PayrollRun $run, array $rows): string
    {
        return $this->inMalay(function () use ($run, $rows): string {
            $org = Organisation::query()->find($run->organisation_id);
            $total = '0';
            foreach ($rows as $r) {
                $total = Num::add($total, $r['net_pay']);
            }

            return $this->render('pdf.cash-list', ['run' => $run, 'org' => $org, 'rows' => $rows, 'total' => Num::money($total)])->output();
        });
    }

    /**
     * @param  array{columns: list<array{key: string, label: string, money?: bool}>, rows: list<array<string, mixed>>, totals: array<string, string>}  $data
     */
    public function listing(string $title, string $period, array $data, bool $unverifiedNotice = false): string
    {
        return $this->inMalay(function () use ($title, $period, $data, $unverifiedNotice): string {
            $org = Organisation::query()->find(OrganisationContext::id());

            return $this->render('pdf.listing', ['title' => $title, 'period' => $period, 'data' => $data, 'org' => $org, 'unverified' => $unverifiedNotice])
                ->setPaper('a4', 'landscape')->output();
        });
    }

    /**
     * Run a renderer with the application locale switched to Bahasa Melayu (documents are always BM).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inMalay(callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale('ms');
        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data): DomPdf
    {
        if (! is_dir(storage_path('fonts'))) {
            mkdir(storage_path('fonts'), 0775, true);
        }

        return Pdf::loadView($view, $data)->setPaper('a4');
    }
}
