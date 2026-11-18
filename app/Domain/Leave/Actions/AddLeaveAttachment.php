<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Leave\Enums\AttachmentKind;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveApplicationAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * MC / hospital letter on a Permohonan Cuti (spec-02 §2.8): private disk under
 * organisations/{org}/leave/{application}/. Mime/size are enforced by the FormRequest
 * (pdf, jpg, png; 10 MB). Never exported.
 */
final class AddLeaveAttachment
{
    /**
     * @param  array{kind?: string|null, mc_serial_no?: string|null, issuer_name?: string|null}  $data
     */
    public function handle(LeaveApplication $application, UploadedFile $file, array $data, User $actor): LeaveApplicationAttachment
    {
        $disk = (string) config('myhrmis.documents.disk', 'local');
        $directory = sprintf('organisations/%d/leave/%d', $application->organisation_id, $application->id);
        $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $path = (string) $file->storeAs($directory, $name, ['disk' => $disk]);

        return LeaveApplicationAttachment::query()->create([
            'leave_application_id' => $application->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'kind' => AttachmentKind::tryFrom((string) ($data['kind'] ?? '')) ?? AttachmentKind::Mc,
            'mc_serial_no' => $data['mc_serial_no'] ?? null,
            'issuer_name' => $data['issuer_name'] ?? null,
            'uploaded_by' => $actor->id,
        ]);
    }
}
