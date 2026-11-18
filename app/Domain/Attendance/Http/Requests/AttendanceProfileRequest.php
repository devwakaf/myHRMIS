<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tab Kehadiran on the employee page (spec-03 §3.2).
 * Authorisation is done by the controller (policies / permissions).
 */
final class AttendanceProfileRequest extends FormRequest
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
            'checkin_required' => ['boolean'],
            'geofence_exempt' => ['boolean'],
            'is_manual_labour' => ['boolean'],
            'ot_eligibility' => ['required', 'in:auto,always,never'],
            'ot_eligibility_note' => ['nullable', 'string', 'max:1000'],
            'device_user_code' => ['nullable', 'string', 'max:30'],
            'default_shift_type_id' => ['nullable', 'integer'],
        ];
    }
}
