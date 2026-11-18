<?php

declare(strict_types=1);

namespace App\Domain\Shared\Numbering;

use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Per-organisation, per-year running numbers: {PREFIX}-{YYYY}-{00000} (A5.5).
 */
final class DocumentNumberGenerator
{
    public function next(string $prefix, ?CarbonInterface $date = null, ?int $organisationId = null, int $padding = 5): string
    {
        $year = (int) ($date ?? now())->format('Y');
        $value = $this->nextValue($prefix, $year, $organisationId ?? OrganisationContext::id());

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $value, $padding, '0', STR_PAD_LEFT));
    }

    /**
     * Reserve the next integer for (organisation, key, year).
     */
    public function nextValue(string $key, int $year, int $organisationId): int
    {
        return DB::transaction(function () use ($key, $year, $organisationId): int {
            DB::table('document_sequences')->insertOrIgnore([
                'organisation_id' => $organisationId,
                'sequence_key' => $key,
                'year' => $year,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('document_sequences')
                ->where('organisation_id', $organisationId)
                ->where('sequence_key', $key)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $value = (int) $row->next_value;

            DB::table('document_sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return $value;
        });
    }
}
