<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdraw / cancel / void: a reason is required.
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
