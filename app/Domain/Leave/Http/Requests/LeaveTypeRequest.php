<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Jenis Cuti (spec-02 §2.1); cross-field rules live in LeaveConfigActions::saveType.
 * Authorisation is done by the controller (policies / permissions).
 */
final class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'entitlement_basis' => ['required', Rule::in(['per_period', 'earned', 'per_event', 'compensatory', 'unlimited'])],
            'pay_mode' => ['required', Rule::in(['paid', 'unpaid', 'partially_paid'])],
            'paid_fraction' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'counts_holidays' => ['boolean'],
            'allow_half_day' => ['boolean'],
            'requires_reason' => ['boolean'],
            'requires_attachment' => ['nullable', Rule::in(['never', 'always', 'over_days'])],
            'attachment_over_days' => ['nullable', 'numeric', 'min:0'],
            'min_service_days_to_apply' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'min_notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notice_enforcement' => ['nullable', Rule::in(['warn', 'block'])],
            'max_backdate_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'max_days_per_application' => ['nullable', 'numeric', 'min:0'],
            'max_consecutive_days' => ['nullable', 'numeric', 'min:0'],
            'max_days_per_period' => ['nullable', 'numeric', 'min:0'],
            'allow_negative' => ['boolean'],
            'max_negative_days' => ['nullable', 'numeric', 'min:0'],
            'allow_carry_forward' => ['boolean'],
            'max_carry_forward_days' => ['nullable', 'numeric', 'min:0'],
            'carry_forward_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'carry_includes_carried' => ['boolean'],
            'allow_encashment' => ['boolean'],
            'max_encashable_days' => ['nullable', 'numeric', 'min:0'],
            'encashment_reserve_days' => ['nullable', 'numeric', 'min:0'],
            'accrual_frequency' => ['nullable', Rule::in(['monthly', 'quarterly', 'half_yearly', 'yearly'])],
            'accrual_timing' => ['nullable', Rule::in(['sub_period_start', 'sub_period_end', 'joining_day'])],
            'rounding_step' => ['nullable', Rule::in(['0.25', '0.5', '1', '0.50', '1.00'])],
            'proration_method' => ['nullable', Rule::in(['none', 'days', 'completed_months'])],
            'prorate_on_separation' => ['boolean'],
            'per_event_max_days' => ['nullable', 'numeric', 'min:0'],
            'max_events_per_service' => ['nullable', 'integer', 'min:1'],
            'max_events_per_period' => ['nullable', 'integer', 'min:1'],
            'requires_event_date' => ['boolean'],
            'eligible_gender' => ['nullable', Rule::in(['male', 'female'])],
            'eligible_marital_statuses' => ['nullable', 'array'],
            'eligible_marital_statuses.*' => [Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'compensatory_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
