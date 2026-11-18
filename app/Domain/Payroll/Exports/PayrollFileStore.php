<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Exports;

use App\Domain\Payroll\Enums\PayrollFileKind;
use App\Domain\Payroll\Models\PayrollFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Writes an export to the private disk and records it in payroll_files
 * (spec-04 §2.10). A regenerated file supersedes the previous one of the same
 * kind, period, run and paying account.
 */
final class PayrollFileStore
{
    /**
     * @param  array{run_id?: int|null, account_id?: int|null, format_code?: string|null, format_version?: string|null, unverified?: bool, records?: int, total?: string}  $meta
     */
    public function store(PayrollFileKind $kind, CarbonImmutable $period, string $fileName, string $content, array $meta = []): PayrollFile
    {
        $previous = PayrollFile::query()->where('kind', $kind->value)->whereDate('period', $period->toDateString())
            ->where('payroll_run_id', $meta['run_id'] ?? null)
            ->where('organisation_bank_account_id', $meta['account_id'] ?? null)
            ->where('status', 'generated')->get();

        $path = 'payroll/'.$period->format('Y-m').'/'.$kind->value.'/'.now()->format('YmdHis').'-'.$fileName;
        Storage::disk('local')->put($path, $content);

        foreach ($previous as $file) {
            $file->forceFill(['status' => 'superseded'])->save();
        }

        $file = new PayrollFile;
        $file->forceFill([
            'payroll_run_id' => $meta['run_id'] ?? null,
            'period' => $period->toDateString(),
            'kind' => $kind,
            'organisation_bank_account_id' => $meta['account_id'] ?? null,
            'format_code' => $meta['format_code'] ?? null,
            'format_version' => $meta['format_version'] ?? null,
            'file_name' => $fileName,
            'file_path' => $path,
            'sha256' => hash('sha256', $content),
            'record_count' => $meta['records'] ?? 0,
            'total_amount' => $meta['total'] ?? '0.00',
            'status' => 'generated',
            'format_unverified' => $meta['unverified'] ?? false,
            'generated_by' => Auth::id(),
            'generated_at' => now(),
        ])->save();

        return $file;
    }

    public function contents(PayrollFile $file): string
    {
        return (string) Storage::disk('local')->get($file->file_path);
    }
}
