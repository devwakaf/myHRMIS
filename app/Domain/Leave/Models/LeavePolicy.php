<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leave Policy (spec-02 §2.3).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property-read Collection<int, LeavePolicyRow> $rows
 */
class LeavePolicy extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function activityLogModule(): string
    {
        return 'leave';
    }

    /**
     * @return HasMany<LeavePolicyRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(LeavePolicyRow::class);
    }
}
