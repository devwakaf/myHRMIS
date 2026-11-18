<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tetapan Cuti (spec-02 §2.14).
 * Authorisation is done by the controller (policies / permissions).
 */
final class LeaveSettingsRequest extends FormRequest
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
            'default_leave_policy_id' => ['nullable', 'integer'],
            'default_max_backdate_days' => ['required', 'integer', 'min:0', 'max:365'],
            'calendar_colleague_visibility' => ['required', Rule::in(['off', 'department_names_only'])],
            'expiry_reminder_days' => ['required', 'integer', 'min:1', 'max:365'],
            'encashment_rate_basis' => ['required', Rule::in(['org_daily_rate', 'orp_26'])],
            'applicant_may_cancel_approved' => ['required', Rule::in(['before_start', 'never'])],
        ];
    }
}
