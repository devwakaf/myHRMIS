<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Leave\Enums\AttachmentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MC / hospital letter / other file on a Permohonan Cuti (private disk; health data).
 *
 * @property int $id
 * @property int $leave_application_id
 * @property string $file_path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property AttachmentKind $kind
 * @property string|null $mc_serial_no
 * @property string|null $issuer_name
 * @property int|null $uploaded_by
 * @property-read LeaveApplication $application
 */
class LeaveApplicationAttachment extends Model
{
    protected $fillable = ['leave_application_id', 'file_path', 'original_name', 'mime', 'size_bytes', 'kind', 'mc_serial_no', 'issuer_name', 'uploaded_by'];

    protected function casts(): array
    {
        return ['kind' => AttachmentKind::class, 'size_bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<LeaveApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }
}
