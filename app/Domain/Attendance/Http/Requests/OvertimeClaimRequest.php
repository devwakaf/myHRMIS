<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tuntutan OT draft edits (spec-03 §2.14).
 * Authorisation is done by the controller (policies / permissions).
 */
final class OvertimeClaimRequest extends FormRequest
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
            'monthly_wage_snapshot' => ['nullable', 'numeric', 'min:0'],
            'cap_override_reason' => ['nullable', 'string', 'max:2000'],
            'line' => ['nullable', 'array'],
            'line.work_date' => ['required_with:line', 'date'],
            'line.day_type' => ['required_with:line', 'in:normal,rest_day,public_holiday'],
            'line.worked_minutes' => ['required_with:line', 'integer', 'min:0'],
            'line.beyond_normal_minutes' => ['nullable', 'integer', 'min:0'],
            'line.amount' => ['required_with:line', 'numeric', 'min:0'],
            'line.note' => ['required_with:line', 'string', 'max:1000'],
            'line.overtime_type_id' => ['nullable', 'integer'],
        ];
    }
}
