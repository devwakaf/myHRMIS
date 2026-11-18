<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Check-in post (spec-03 §4.7): coordinates are optional (geofence off / declined).
 * Authorisation is done by the controller (policies / permissions).
 */
final class CheckinRequest extends FormRequest
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
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'client_time' => ['nullable', 'date'],
            'client_request_id' => ['nullable', 'uuid'],
            'standalone' => ['nullable', 'boolean'],
        ];
    }
}
