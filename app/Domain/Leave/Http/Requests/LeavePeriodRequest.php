<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tempoh Cuti (spec-02 §2.2).
 * Authorisation is done by the controller (policies / permissions).
 */
final class LeavePeriodRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after:from_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
