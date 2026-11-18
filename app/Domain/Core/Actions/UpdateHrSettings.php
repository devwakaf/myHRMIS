<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Support\Facades\DB;

/**
 * Tetapan HR (B1.15). Changing the retirement age recomputes every stored
 * retirement_date (B3.3).
 */
final class UpdateHrSettings
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(HrSettings $settings, array $data): HrSettings
    {
        if (isset($data['self_service_editable'])) {
            $invalid = array_diff((array) $data['self_service_editable'], HrSettings::SELF_SERVICE_CANDIDATES);
            if ($invalid !== []) {
                throw DomainValidation::withMessages(['self_service_editable' => __('core.settings.invalid_self_service')]);
            }
            $data['self_service_editable'] = array_values((array) $data['self_service_editable']);
        }

        if (isset($data['employee_no_format']) && preg_match('/\{#+\}/', (string) $data['employee_no_format']) !== 1) {
            throw DomainValidation::withMessages(['employee_no_format' => __('core.settings.invalid_number_format')]);
        }

        return DB::transaction(function () use ($settings, $data): HrSettings {
            $oldAge = $settings->retirement_age;
            $settings->update($data);

            if ($settings->retirement_age !== $oldAge) {
                $this->recomputeRetirementDates($settings->retirement_age);
            }

            return $settings;
        });
    }

    public function recomputeRetirementDates(int $age): void
    {
        Employee::query()->select(['id', 'date_of_birth', 'retirement_date', 'organisation_id'])
            ->chunkById(200, function ($employees) use ($age): void {
                foreach ($employees as $employee) {
                    $employee->retirement_date = $employee->date_of_birth->addYears($age);
                    $employee->save();
                }
            });
    }
}
