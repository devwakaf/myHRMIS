<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MC / supporting file upload (pdf, jpg, png; 10 MB).
 * Authorisation is done by the controller (policies / permissions).
 */
final class LeaveAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'kind' => ['nullable', Rule::in(['mc', 'hospital_letter', 'other'])],
            'mc_serial_no' => ['nullable', 'string', 'max:40'],
            'issuer_name' => ['nullable', 'string', 'max:150'],
        ];
    }
}
