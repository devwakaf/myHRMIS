<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corak Roster form (spec-03 §2.8, §3.7).
 * Authorisation is done by the controller (policies / permissions).
 */
final class ShiftScheduleRequest extends FormRequest
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
            'cycle_length_days' => ['required', 'integer', 'min:1', 'max:28'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'days' => ['required', 'array'],
            'days.*.day_index' => ['required', 'integer', 'min:0'],
            'days.*.kind' => ['required', 'in:shift,rest,off'],
            'days.*.shift_type_id' => ['nullable', 'integer'],
        ];
    }
}
