<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Komponen Gaji (spec-04 §2.3); business rules live in ComponentActions. Authorisation is done by the controller (permissions / policies). */
final class ComponentRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:30'],
            'name_ms' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'string'],
            'calculation' => ['required', 'string'],
            'statutory_key' => ['nullable', 'string'],
            'default_amount' => ['nullable', 'numeric', 'min:0'],
            'default_formula' => ['nullable', 'string', 'max:500'],
            'default_condition' => ['nullable', 'string', 'max:500'],
            'prorate' => ['boolean'], 'include_in_gross' => ['boolean'], 'show_on_slip' => ['boolean'], 'show_if_zero' => ['boolean'],
            'epf_wage' => ['boolean'], 'socso_wage' => ['boolean'], 'eis_wage' => ['boolean'], 'hrdc_wage' => ['boolean'], 'taxable' => ['boolean'],
            'pcb_type' => ['nullable', 'in:normal,additional'],
            'tax_exemption_code' => ['nullable', 'string', 'max:40'],
            'ea_box' => ['nullable', 'string'],
            'is_epf_bonus' => ['boolean'], 'ot_wage_base' => ['boolean'], 'arrear_eligible' => ['boolean'], 'is_wage_reduction' => ['boolean'],
            'arrear_component_id' => ['nullable', 'integer'],
            'rounding_mode' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'description_ms' => ['nullable', 'string'], 'description_en' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
