<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Writes rows to a temporary XLSX or CSV file (spatie/simple-excel) and returns
 * it as a download that is deleted after sending.
 */
final class SpreadsheetExport
{
    /**
     * @param  list<string>  $headers  translated column titles, same order as row values
     * @param  iterable<array<int|string, mixed>>  $rows
     */
    public static function download(string $baseName, array $headers, iterable $rows, string $format = 'xlsx'): BinaryFileResponse
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';
        $directory = storage_path('app/private/exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.Str::uuid()->toString().'.'.$format;
        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        $writer->addRow($headers);
        foreach ($rows as $row) {
            $writer->addRow(array_values(array_map(fn ($v) => is_bool($v) ? ($v ? 1 : 0) : $v, $row)));
        }
        $writer->close();

        return response()
            ->download($path, $baseName.'-'.now()->format('Ymd-His').'.'.$format)
            ->deleteFileAfterSend();
    }
}
