<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Payroll Run wizard step 1 (spec-04 §1.2). Authorisation is done by the controller (permissions / policies). */
final class PayrollRunRequest extends FormRequest
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
            'period' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date'],
            'payment_date' => ['nullable', 'date'],
            'filters' => ['nullable', 'array'],
            'filters.*' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
