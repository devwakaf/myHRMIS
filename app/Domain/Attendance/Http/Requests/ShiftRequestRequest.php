<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Permohonan Shift form (spec-03 §2.4).
 * Authorisation is done by the controller (policies / permissions).
 */
final class ShiftRequestRequest extends FormRequest
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
            'shift_type_id' => ['required', 'integer'],
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
            'choices' => ['nullable', 'array'],
        ];
    }
}
