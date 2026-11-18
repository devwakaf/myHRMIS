<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\Lindung24Status;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Dating\EffectiveDated;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LINDUNG 24 Jam election (B1.9) — effective-dated series per employee.
 * Owned by Statutory (M4), captured in Teras. No row = opted in.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property Lindung24Status $status
 * @property CarbonImmutable $effective_month
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property int|null $recorded_by
 * @property string|null $note
 */
class Lindung24Election extends Model
{
    use BelongsToOrganisation, EffectiveDated, LogsBusinessActivity;

    protected $fillable = ['organisation_id', 'employee_id', 'status', 'effective_month', 'effective_from', 'effective_to', 'recorded_by', 'note'];

    protected function casts(): array
    {
        return [
            'status' => Lindung24Status::class,
            'effective_month' => 'immutable_date',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    public function activityLogModule(): string
    {
        return 'statutory';
    }

    public function seriesKey(): array
    {
        return ['employee_id' => $this->employee_id];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
