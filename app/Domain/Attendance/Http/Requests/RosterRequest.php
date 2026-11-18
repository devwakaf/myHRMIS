<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Roster board actions (spec-03 §2.9).
 * Authorisation is done by the controller (policies / permissions).
 */
final class RosterRequest extends FormRequest
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
            'action' => ['required', 'in:assign_pattern,set_shift,set_rest,clear,swap,stop'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer'],
            'shift_schedule_id' => ['nullable', 'integer'],
            'shift_schedule_assignment_id' => ['nullable', 'integer'],
            'anchor_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'shift_type_id' => ['nullable', 'integer'],
            'other_employee_id' => ['nullable', 'integer'],
            'overtime_type_id' => ['nullable', 'integer'],
        ];
    }
}
