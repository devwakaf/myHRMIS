<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penugasan Shift — single or bulk wizard (spec-03 §2.6).
 * Authorisation is done by the controller (policies / permissions).
 */
final class ShiftAssignmentRequest extends FormRequest
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
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer'],
            'shift_type_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'mode' => ['nullable', 'in:add,replace'],
            'overtime_type_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
            'preview' => ['nullable', 'boolean'],
        ];
    }
}
