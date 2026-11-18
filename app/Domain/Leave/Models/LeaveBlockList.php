<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Core\Models\Department;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Senarai Sekatan Cuti (spec-02 §2.13).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property bool $applies_to_all_departments
 * @property int|null $leave_type_id
 * @property bool $is_active
 * @property-read Collection<int, LeaveBlockListDate> $dates
 * @property-read Collection<int, Department> $departments
 * @property-read Collection<int, User> $exemptUsers
 */
class LeaveBlockList extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'name', 'applies_to_all_departments', 'leave_type_id', 'is_active'];

    protected function casts(): array
    {
        return ['applies_to_all_departments' => 'boolean', 'is_active' => 'boolean'];
    }

    public function activityLogModule(): string
    {
        return 'leave';
    }

    /**
     * @return HasMany<LeaveBlockListDate, $this>
     */
    public function dates(): HasMany
    {
        return $this->hasMany(LeaveBlockListDate::class)->orderBy('date');
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'leave_block_list_departments');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function exemptUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'leave_block_list_exempt_users');
    }
}
