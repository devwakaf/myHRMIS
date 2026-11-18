<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Cancel / exclude / release: a reason is required. Authorisation is done by the controller (permissions / policies). */
final class ReasonRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
