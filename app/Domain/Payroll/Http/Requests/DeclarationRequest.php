<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Deklarasi Cukai (spec-04 §2.8). Authorisation is done by the controller. */
final class DeclarationRequest extends FormRequest
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
            'type' => ['required', 'in:tp1,tp3,zakat_external'],
            'period' => ['required', 'date'],
            'is_correction' => ['boolean'],
            'prev_employer_name' => ['nullable', 'string'],
            'prev_employer_e_no' => ['nullable', 'string', 'max:20'],
            'prev_from' => ['nullable', 'date'],
            'prev_to' => ['nullable', 'date'],
            'prev_gross_normal' => ['nullable', 'numeric'],
            'prev_gross_additional' => ['nullable', 'numeric'],
            'prev_epf' => ['nullable', 'numeric'],
            'prev_pcb' => ['nullable', 'numeric'],
            'prev_zakat' => ['nullable', 'numeric'],
            'prev_cp38' => ['nullable', 'numeric'],
            'prev_lp' => ['nullable', 'numeric'],
            'zakat_amount' => ['nullable', 'numeric'],
            'zakat_authority' => ['nullable', 'string'],
            'receipt_no' => ['nullable', 'string', 'max:60'],
            'receipt_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
            'lines' => ['nullable', 'array'],
            'lines.*.relief_code' => ['required', 'string'],
            'lines.*.amount' => ['required', 'numeric'],
            'lines.*.reason' => ['nullable', 'string'],
            'submit' => ['boolean'],
        ];
    }
}
