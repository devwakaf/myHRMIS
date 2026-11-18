<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Permohonan Kehadiran form (spec-03 §2.3).
 * Authorisation is done by the controller (policies / permissions).
 */
final class AttendanceRequestRequest extends FormRequest
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
            'employee_id' => ['nullable', 'integer'],
            'kind' => ['required', 'in:wfh,on_duty'],
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
            'is_half_day' => ['nullable', 'boolean'],
            'half_day_date' => ['nullable', 'date'],
            'half_day_session' => ['nullable', 'in:am,pm'],
            'include_rest_days' => ['nullable', 'boolean'],
            'shift_type_id' => ['nullable', 'integer'],
            'duty_location' => ['nullable', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
            'choices' => ['nullable', 'array'],
        ];
    }
}
