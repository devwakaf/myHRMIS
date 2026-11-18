<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\HasLocalisedName;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Database\Factories\Core\DepartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jabatan (B1.3) — tree with a materialised path "/1/5/9/" (includes self).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property string|null $name_en
 * @property string $path
 * @property int $depth
 * @property string|null $cost_centre_code
 * @property int|null $head_employee_id
 * @property bool $is_active
 * @property-read Department|null $parent
 * @property-read Employee|null $head
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use BelongsToOrganisation, HasFactory, HasLocalisedName, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'organisation_id', 'parent_id', 'code', 'name', 'name_en', 'cost_centre_code', 'head_employee_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'depth' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(function (self $department): void {
            $department->refreshPath();
        });
    }

    /**
     * Recompute this row's path/depth from its parent (descendants handled by MoveDepartment).
     */
    public function refreshPath(): void
    {
        $parent = $this->parent_id === null ? null : static::query()->find($this->parent_id);
        $this->path = ($parent->path ?? '/').$this->id.'/';
        $this->depth = $parent === null ? 0 : $parent->depth + 1;
        $this->saveQuietly();
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * @return HasMany<DepartmentApprover, $this>
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(DepartmentApprover::class)->orderBy('sort_order');
    }

    /**
     * Ancestor ids, nearest first (excluding self).
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        $ids = array_map('intval', array_values(array_filter(explode('/', $this->path), fn ($p) => $p !== '')));
        array_pop($ids);

        return array_reverse($ids);
    }

    /**
     * @return Collection<int, Department>
     */
    public function ancestorsNearestFirst(): Collection
    {
        $ids = $this->ancestorIds();
        if ($ids === []) {
            return new Collection;
        }

        $byId = static::query()->whereIn('id', $ids)->get()->keyBy('id');
        /** @var Collection<int, Department> $ordered */
        $ordered = new Collection;
        foreach ($ids as $id) {
            $department = $byId->get($id);
            if ($department instanceof self) {
                $ordered->push($department);
            }
        }

        return $ordered;
    }

    /**
     * @param  Builder<Department>  $query
     * @return Builder<Department>
     */
    public function scopeDescendantsOf(Builder $query, self $department): Builder
    {
        return $query->where('path', 'like', $department->path.'%')->whereKeyNot($department->id);
    }
}
