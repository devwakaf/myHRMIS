<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\UpdateHrSettings;
use App\Domain\Core\Enums\EmployeeNoMode;
use App\Domain\Core\Enums\HolidayNoticeFrequency;
use App\Domain\Core\Models\HrSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tetapan HR (B1.15): Penomboran, Tempoh Percubaan & Persaraan, Waktu Kerja,
 * Peringatan, Layan Diri.
 */
final class HrSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->requirePermission('core.settings.manage', 'core.settings.view');
        $settings = HrSettings::current();

        return Inertia::render('Core/Settings/Hr', [
            'settings' => array_merge($settings->only($settings->getFillable()), [
                'employee_no_mode' => $settings->employee_no_mode->value,
                'holiday_notice' => $settings->holiday_notice->value,
                'self_service_editable' => $settings->selfServiceFields(),
            ]),
            'options' => [
                'number_modes' => EmployeeNoMode::options(),
                'holiday_notices' => HolidayNoticeFrequency::options(),
                'self_service_fields' => array_map(fn ($f) => ['value' => $f, 'label' => __('validation.attributes.'.$f)], HrSettings::SELF_SERVICE_CANDIDATES),
            ],
            'canManage' => $request->user()->can('core.settings.manage'),
        ]);
    }

    public function update(Request $request, UpdateHrSettings $update): RedirectResponse
    {
        $this->requirePermission('core.settings.manage');

        $data = $request->validate([
            'employee_no_format' => ['required', 'string', 'max:40'],
            'employee_no_mode' => ['required', Rule::enum(EmployeeNoMode::class)],
            'retirement_age' => ['required', 'integer', 'min:40', 'max:80'],
            'default_probation_months' => ['required', 'integer', 'min:0', 'max:24'],
            'standard_daily_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'standard_weekly_hours' => ['required', 'numeric', 'min:1', 'max:168'],
            'min_employment_age' => ['required', 'integer', 'min:13', 'max:21'],
            'birthday_digest_to_hr' => ['boolean'],
            'birthday_digest_to_colleagues' => ['boolean'],
            'anniversary_digest' => ['boolean'],
            'remind_probation_days' => ['required', 'integer', 'min:0', 'max:365'],
            'remind_contract_days' => ['required', 'integer', 'min:0', 'max:365'],
            'remind_work_pass_days' => ['required', 'integer', 'min:0', 'max:365'],
            'remind_document_days' => ['required', 'integer', 'min:0', 'max:365'],
            'remind_retirement_days' => ['required', 'integer', 'min:0', 'max:730'],
            'holiday_notice' => ['required', Rule::enum(HolidayNoticeFrequency::class)],
            'self_service_editable' => ['array'],
            'self_service_editable.*' => ['string'],
        ]);

        $update->handle(HrSettings::current(), $data);

        return $this->done('common.saved');
    }
}
