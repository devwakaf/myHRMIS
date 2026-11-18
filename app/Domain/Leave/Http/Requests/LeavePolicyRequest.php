<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Leave Policy with rows and service tiers (spec-02 §2.3).
 * Authorisation is done by the controller (policies / permissions).
 */
final class LeavePolicyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'rows' => ['present', 'array'],
            'rows.*.leave_type_id' => ['required', 'integer'],
            'rows.*.annual_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'rows.*.uses_service_tiers' => ['boolean'],
            'rows.*.tier_basis' => ['nullable', Rule::in(['prorata_by_anniversary', 'service_at_period_start', 'service_at_period_end'])],
            'rows.*.override_max_carry_forward_days' => ['nullable', 'numeric', 'min:0'],
            'rows.*.tiers' => ['nullable', 'array'],
            'rows.*.tiers.*.min_service_months' => ['required', 'integer', 'min:0', 'max:600'],
            'rows.*.tiers.*.days' => ['required', 'numeric', 'min:0', 'max:366'],
        ];
    }
}
