<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic save for simple masters (Cawangan, Jawatan, Gred, Jenis Perkhidmatan,
 * Jenis Dokumen). Deactivation is blocked while active employees reference the row (B2).
 */
final class SaveMaster
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  TModel|null  $model
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    public function handle(string $class, ?Model $model, array $data, ?string $employeeForeignKey = null): Model
    {
        if ($model !== null && $employeeForeignKey !== null
            && array_key_exists('is_active', $data) && ! $data['is_active'] && $model->getAttribute('is_active')) {
            $referenced = Employee::query()
                ->where($employeeForeignKey, $model->getKey())
                ->where('status', '!=', EmployeeStatus::Left->value)
                ->exists();
            if ($referenced) {
                throw DomainValidation::withMessages(['is_active' => __('core.masters.cannot_deactivate')]);
            }
        }

        if ($model === null) {
            return $class::query()->create($data);
        }

        $model->update($data);

        return $model;
    }
}
