<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lokasi Shift form (spec-03 §2.7, §3.8).
 * Authorisation is done by the controller (policies / permissions).
 */
final class ShiftLocationRequest extends FormRequest
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
            'branch_id' => ['nullable', 'integer'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'radius_m' => ['required', 'integer'],
            'accuracy_threshold_m' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'address_note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'links' => ['nullable', 'array'],
            'links.*.type' => ['required', 'in:branch,employee,shift_assignment,roster_assignment'],
            'links.*.id' => ['required', 'integer'],
        ];
    }
}
