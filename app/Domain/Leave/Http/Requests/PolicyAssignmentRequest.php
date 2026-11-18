<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penetapan Leave Policy (spec-02 §2.4); bulk mode uses the filters.
 * Authorisation is done by the controller (policies / permissions).
 */
final class PolicyAssignmentRequest extends FormRequest
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
            'employee_id' => ['required_without:bulk', 'nullable', 'integer'],
            'leave_policy_id' => ['required', 'integer'],
            'effective_from' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'submit' => ['nullable', 'boolean'],
            'bulk' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employment_type_id' => ['nullable', 'integer'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer'],
        ];
    }
}
