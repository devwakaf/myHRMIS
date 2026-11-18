<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mohon Cuti form (spec-02 §2.8, §3.9).
 * Authorisation is done by the controller (policies / permissions).
 */
final class LeaveApplicationRequest extends FormRequest
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
            'employee_id' => ['nullable', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            'half_day_date' => ['nullable', 'date'],
            'half_day_session' => ['nullable', Rule::in(['am', 'pm'])],
            'event_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'contact_during_leave' => ['nullable', 'string', 'max:30'],
            'handover_note' => ['nullable', 'string', 'max:2000'],
            'block_list_acknowledged' => ['nullable', 'boolean'],
            'submit' => ['nullable', 'boolean'],
            'choices' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }
}
