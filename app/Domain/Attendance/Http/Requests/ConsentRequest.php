<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Notis Privasi Lokasi decision.
 * Authorisation is done by the controller (policies / permissions).
 */
final class ConsentRequest extends FormRequest
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
            'accepted' => ['required', 'boolean'],
        ];
    }
}
