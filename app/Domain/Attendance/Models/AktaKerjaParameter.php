<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Global, effective-dated Akta Kerja 1955 OT parameters (spec-03 §3.12, A9).
 * `verified_at` null → the UI shows "belum disahkan".
 *
 * @property int $id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $ot_wage_threshold
 * @property string $orp_divisor
 * @property string $normal_day_multiplier
 * @property string $rest_day_beyond_multiplier
 * @property string $ph_beyond_multiplier
 * @property string $ph_worked_extra_orp_days
 * @property string $rest_day_half_ratio
 * @property string $rest_day_half_orp_days
 * @property string $rest_day_full_orp_days
 * @property string $monthly_ot_cap_hours
 * @property string $max_daily_hours
 * @property string $normal_daily_hours
 * @property string $normal_weekly_hours
 * @property CarbonImmutable|null $verified_at
 */
class AktaKerjaParameter extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'ot_wage_threshold' => 'decimal:2',
            'orp_divisor' => 'decimal:2',
            'normal_day_multiplier' => 'decimal:4',
            'rest_day_beyond_multiplier' => 'decimal:4',
            'ph_beyond_multiplier' => 'decimal:4',
            'ph_worked_extra_orp_days' => 'decimal:2',
            'rest_day_half_ratio' => 'decimal:4',
            'rest_day_half_orp_days' => 'decimal:2',
            'rest_day_full_orp_days' => 'decimal:2',
            'monthly_ot_cap_hours' => 'decimal:2',
            'max_daily_hours' => 'decimal:2',
            'normal_daily_hours' => 'decimal:2',
            'normal_weekly_hours' => 'decimal:2',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public static function effectiveOn(CarbonInterface $date): self
    {
        $row = static::query()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('effective_from')
            ->first();

        return $row ?? throw new RuntimeException('No Akta Kerja parameters effective on '.$date->toDateString());
    }
}
