<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Jenis Shift form (spec-03 §2.5, §3.3).
 * Authorisation is done by the controller (policies / permissions).
 */
final class ShiftTypeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'start_time' => ['required', 'date_format:H:i:s,H:i'],
            'end_time' => ['required', 'date_format:H:i:s,H:i'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'break_start_time' => ['nullable', 'date_format:H:i:s,H:i'],
            'open_before_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'close_after_minutes' => ['required', 'integer', 'min:0', 'max:720'],
            'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'early_grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'track_late' => ['boolean'],
            'track_early' => ['boolean'],
            'half_day_threshold_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'absent_threshold_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'hours_method' => ['nullable', 'in:first_last,paired'],
            'single_punch_policy' => ['required', 'in:review,absent,half_day,present'],
            'auto_attendance' => ['boolean'],
            'process_from' => ['nullable', 'date'],
            'absent_buffer_days' => ['required', 'integer', 'min:0', 'max:7'],
            'holiday_list_id' => ['nullable', 'integer'],
            'overtime_enabled' => ['boolean'],
            'overtime_type_id' => ['nullable', 'integer'],
            'count_pre_shift_minutes' => ['boolean'],
            'colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['boolean'],
        ];
    }
}
