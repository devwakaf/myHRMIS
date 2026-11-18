<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tandakan Manual / Tanda Kehadiran Pukal (spec-03 §2.10, §4.10).
 * Authorisation is done by the controller (policies / permissions).
 */
final class MarkAttendanceRequest extends FormRequest
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
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'attendance_status' => ['required', 'in:present,absent,half_day,wfh,on_duty'],
            'other_half_status' => ['nullable', 'in:present,absent'],
            'half_day_session' => ['nullable', 'in:am,pm'],
            'shift_type_id' => ['nullable', 'integer'],
            'is_late' => ['nullable', 'boolean'],
            'late_minutes' => ['nullable', 'integer', 'min:0'],
            'is_early_exit' => ['nullable', 'boolean'],
            'early_exit_minutes' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['required', 'string', 'max:1000'],
        ];
    }
}
