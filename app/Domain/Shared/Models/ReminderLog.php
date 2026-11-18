<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency ledger for reminder jobs: one row per (org, employee, kind, reference, date).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property string $kind
 * @property string $reference
 */
class ReminderLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organisation_id', 'employee_id', 'kind', 'reference', 'reference_date'];

    protected function casts(): array
    {
        return ['reference_date' => 'immutable_date'];
    }

    /**
     * Record a reminder; returns false if it was already sent.
     */
    public static function claim(int $organisationId, ?int $employeeId, string $kind, CarbonInterface $referenceDate, string $reference = ''): bool
    {
        $inserted = static::query()->insertOrIgnore([
            'organisation_id' => $organisationId,
            'employee_id' => $employeeId ?? 0,
            'kind' => $kind,
            'reference' => $reference,
            'reference_date' => $referenceDate->format('Y-m-d'),
            'created_at' => now(),
        ]);

        return $inserted > 0;
    }
}
