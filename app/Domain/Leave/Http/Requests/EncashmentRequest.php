<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gantian Tunai Cuti (spec-02 §2.10, §3.15).
 * Authorisation is done by the controller (policies / permissions).
 */
final class EncashmentRequest extends FormRequest
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
            'encashment_date' => ['required', 'date'],
            'days' => ['required', 'numeric', 'gt:0', 'max:366'],
            'rate_basis' => ['nullable', Rule::in(['org_daily_rate', 'orp_26'])],
            'monthly_basic' => ['nullable', 'numeric', 'gt:0'],
            'purpose' => ['nullable', Rule::in(['period_end', 'separation', 'other'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'submit' => ['nullable', 'boolean'],
        ];
    }
}
