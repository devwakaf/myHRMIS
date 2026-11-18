<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Jenis OT form (spec-03 §3.12).
 * Authorisation is done by the controller (policies / permissions).
 */
final class OvertimeTypeRequest extends FormRequest
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
            'basis' => ['required', 'in:statutory,contractual'],
            'rounding_unit_minutes' => ['required', 'integer', 'min:1', 'max:240'],
            'min_minutes_per_day' => ['required', 'integer', 'min:0', 'max:1440'],
            'daily_max_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'salary_component_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
            'rules' => ['required', 'array'],
            'rules.*.day_type' => ['required', 'in:normal,rest_day,public_holiday'],
            'rules.*.beyond_normal_multiplier' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'rules.*.within_normal_mode' => ['nullable', 'in:none,orp_half_or_full,orp_days'],
            'rules.*.within_normal_orp_days' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'rules.*.salary_component_id' => ['nullable', 'integer'],
        ];
    }
}
