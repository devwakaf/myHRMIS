<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pelarasan Cuti (spec-02 §2.9).
 * Authorisation is done by the controller (policies / permissions).
 */
final class AdjustmentRequest extends FormRequest
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
            'leave_allocation_id' => ['required', 'integer'],
            'direction' => ['required', Rule::in(['add', 'reduce'])],
            'days' => ['required', 'numeric', 'gt:0', 'max:366'],
            'effective_date' => ['required', 'date'],
            'expires_on' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
        ];
    }
}
