<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Semakan Kehadiran actions (spec-03 §4.4).
 * Authorisation is done by the controller (policies / permissions).
 */
final class ReviewActionRequest extends FormRequest
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
            'action' => ['required', 'in:ignore,reprocess,replace_existing'],
            'checkin_ids' => ['required', 'array', 'min:1'],
            'checkin_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
