<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Senarai Sekatan Cuti (spec-02 §2.13).
 * Authorisation is done by the controller (policies / permissions).
 */
final class BlockListRequest extends FormRequest
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
            'applies_to_all_departments' => ['boolean'],
            'leave_type_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
            'dates' => ['present', 'array'],
            'dates.*.date' => ['required', 'date'],
            'dates.*.reason' => ['nullable', 'string', 'max:150'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer'],
            'exempt_user_ids' => ['nullable', 'array'],
            'exempt_user_ids.*' => ['integer'],
        ];
    }
}
