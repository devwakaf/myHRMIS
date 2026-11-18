<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Support\Facades\DB;

/**
 * Create/update a Jabatan, including re-parenting (B1.3): same-org active parent,
 * no cycles, descendants' paths rewritten in one transaction; deactivation blocked
 * while active employees or active children exist.
 */
final class SaveDepartment
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data): Department {
            $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null;
            $parent = $parentId === null ? null : Department::query()->find($parentId);

            if ($parentId !== null && ($parent === null || ! $parent->is_active)) {
                throw DomainValidation::withMessages(['parent_id' => __('core.department.invalid_parent')]);
            }

            if ($department !== null && $parent !== null
                && ($parent->id === $department->id || str_contains($parent->path, '/'.$department->id.'/'))) {
                throw DomainValidation::withMessages(['parent_id' => __('core.department.cycle')]);
            }

            if ($department !== null && array_key_exists('is_active', $data) && ! $data['is_active'] && $department->is_active) {
                $this->guardDeactivation($department);
            }

            if ($department !== null && $department->parent_id === null && $parentId !== null) {
                throw DomainValidation::withMessages(['parent_id' => __('core.department.root_cannot_move')]);
            }

            if ($department === null) {
                if ($parentId === null) {
                    throw DomainValidation::withMessages(['parent_id' => __('validation.required', ['attribute' => __('core.department.fields.parent_id')])]);
                }

                return Department::query()->create($data);
            }

            $parentChanged = $department->parent_id !== $parentId;
            $department->update($data);

            if ($parentChanged) {
                $this->rewritePaths($department);
            }

            return $department;
        });
    }

    public function guardDeactivation(Department $department): void
    {
        $hasEmployees = Employee::query()->where('department_id', $department->id)
            ->where('status', '!=', EmployeeStatus::Left->value)->exists();
        $hasChildren = Department::query()->where('parent_id', $department->id)->where('is_active', true)->exists();

        if ($hasEmployees || $hasChildren) {
            throw DomainValidation::withMessages(['is_active' => __('core.department.cannot_deactivate')]);
        }
    }

    private function rewritePaths(Department $department): void
    {
        $oldPath = $department->path;
        $department->refreshPath();
        $department->refresh();
        $newPath = $department->path;
        $depthDelta = $department->depth - (count(array_filter(explode('/', $oldPath))) - 1);

        $descendants = Department::query()
            ->where('path', 'like', $oldPath.'%')
            ->whereKeyNot($department->id)
            ->lockForUpdate()
            ->get();

        foreach ($descendants as $child) {
            $child->path = $newPath.substr($child->path, strlen($oldPath));
            $child->depth = $child->depth + $depthDelta;
            $child->saveQuietly();
        }
    }
}
