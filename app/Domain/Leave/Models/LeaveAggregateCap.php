<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Had Gabungan Cuti (spec-02 §2.12): one ceiling shared by several types per leave period.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property string $cap_days
 * @property bool $is_active
 * @property-read Collection<int, LeaveType> $types
 */
class LeaveAggregateCap extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'name', 'cap_days', 'is_active'];

    protected function casts(): array
    {
        return ['cap_days' => 'decimal:3', 'is_active' => 'boolean'];
    }

    public function activityLogModule(): string
    {
        return 'leave';
    }

    /**
     * @return BelongsToMany<LeaveType, $this>
     */
    public function types(): BelongsToMany
    {
        return $this->belongsToMany(LeaveType::class, 'leave_aggregate_cap_types');
    }
}
