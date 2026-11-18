<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual Peruntukan Cuti (spec-02 §2.5).
 * Authorisation is done by the controller (policies / permissions).
 */
final class AllocationRequest extends FormRequest
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
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'leave_period_id' => ['required', 'integer'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'initial_days' => ['required', 'numeric', 'min:0', 'max:366'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'submit' => ['nullable', 'boolean'],
        ];
    }
}
