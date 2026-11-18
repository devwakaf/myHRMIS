<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\DocumentType;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeDocument;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Store a document on the private disk under organisations/{org}/employees/{id}/ (B1.10).
 * Type and size limits are enforced by the FormRequest (pdf, jpg, png, docx; 10 MB).
 */
final class UploadEmployeeDocument
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Employee $employee, UploadedFile $file, array $data, User $actor): EmployeeDocument
    {
        $type = DocumentType::query()->where('is_active', true)->find($data['document_type_id'] ?? null);
        if ($type === null) {
            throw DomainValidation::withMessages(['document_type_id' => __('validation.exists', ['attribute' => __('validation.attributes.document_type_id')])]);
        }

        $disk = (string) config('myhrmis.documents.disk', 'local');
        $directory = sprintf('organisations/%d/employees/%d', $employee->organisation_id, $employee->id);
        $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs($directory, $name, ['disk' => $disk]);

        return EmployeeDocument::query()->create([
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'document_type_id' => $type->id,
            'title' => $data['title'] ?? $file->getClientOriginalName(),
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'remind_before_days' => $data['remind_before_days'] ?? null,
            'uploaded_by' => $actor->id,
        ]);
    }
}
