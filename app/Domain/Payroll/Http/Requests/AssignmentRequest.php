<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Penugasan Struktur Gaji (spec-04 §2.4). Authorisation is done by the controller (permissions / policies). */
final class AssignmentRequest extends FormRequest
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
            'salary_structure_id' => ['required', 'integer'],
            'effective_from' => ['required', 'date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'in:new_hire,increment,promotion,correction,other'],
            'epf_employee_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'epf_employer_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amounts' => ['nullable', 'array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0'],
            'opening_year' => ['nullable', 'integer'],
            'opening_gross_normal' => ['nullable', 'numeric'], 'opening_gross_additional' => ['nullable', 'numeric'],
            'opening_epf_employee' => ['nullable', 'numeric'], 'opening_pcb' => ['nullable', 'numeric'], 'opening_zakat' => ['nullable', 'numeric'],
            'opening_cp38' => ['nullable', 'numeric'], 'opening_socso_employee' => ['nullable', 'numeric'], 'opening_eis_employee' => ['nullable', 'numeric'],
            'opening_skbbk_employee' => ['nullable', 'numeric'], 'opening_lp' => ['nullable', 'numeric'],
            'opening_ea_breakdown' => ['nullable', 'array'],
            'remarks' => ['nullable', 'string'],
            'submit' => ['boolean'],
        ];
    }
}
