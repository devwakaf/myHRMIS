<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Struktur Gaji with its rows (spec-04 §2.4); formulas are checked by StructureActions. Authorisation is done by the controller (permissions / policies). */
final class StructureRequest extends FormRequest
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
            'name_ms' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'test_employee_id' => ['nullable', 'integer'],
            'rows' => ['array'],
            'rows.*.salary_component_id' => ['required', 'integer'],
            'rows.*.sort_order' => ['nullable', 'integer'],
            'rows.*.calculation_override' => ['nullable', 'in:fixed,formula'],
            'rows.*.amount' => ['nullable', 'numeric', 'min:0'],
            'rows.*.formula' => ['nullable', 'string', 'max:500'],
            'rows.*.condition' => ['nullable', 'string', 'max:500'],
            'rows.*.prorate_override' => ['nullable', 'boolean'],
        ];
    }
}
