<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Had Gabungan Cuti (spec-02 §2.12).
 * Authorisation is done by the controller (policies / permissions).
 */
final class AggregateCapRequest extends FormRequest
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
            'cap_days' => ['required', 'numeric', 'gt:0', 'max:366'],
            'is_active' => ['boolean'],
            'leave_type_ids' => ['required', 'array', 'min:2'],
            'leave_type_ids.*' => ['integer'],
        ];
    }
}
