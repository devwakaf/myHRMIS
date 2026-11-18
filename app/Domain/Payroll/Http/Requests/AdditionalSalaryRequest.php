<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Elaun/Potongan Tambahan (spec-04 §2.6). Authorisation is done by the controller. */
final class AdditionalSalaryRequest extends FormRequest
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
            'salary_component_id' => ['required', 'integer'],
            'kind' => ['required', 'in:one_off,recurring'],
            'period' => ['nullable', 'date'],
            'from_period' => ['nullable', 'date'],
            'to_period' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', 'in:add,replace'],
            'prorate' => ['boolean'],
            'reason' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:255'],
            'submit' => ['boolean'],
        ];
    }
}
