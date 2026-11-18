<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel / withdraw / Batal & Salin: a reason is required.
 * Authorisation is done by the controller (policies / permissions).
 */
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
