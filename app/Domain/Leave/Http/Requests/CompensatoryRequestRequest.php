<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Permohonan Cuti Gantian form (spec-02 §2.11).
 * Authorisation is done by the controller (policies / permissions).
 */
final class CompensatoryRequestRequest extends FormRequest
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
            'leave_type_id' => ['required', 'integer'],
            'work_from' => ['required', 'date'],
            'work_to' => ['required', 'date'],
            'half_day_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
            'choices' => ['nullable', 'array'],
        ];
    }
}
