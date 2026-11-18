<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dokumen Kakitangan (B1.10). Files live on the private disk; download only via
 * the authorised controller.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property int $document_type_id
 * @property string $title
 * @property string $file_path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $expires_on
 * @property int|null $remind_before_days
 * @property int|null $uploaded_by
 * @property-read DocumentType $documentType
 * @property-read Employee $employee
 */
class EmployeeDocument extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity;

    protected $fillable = [
        'organisation_id', 'employee_id', 'document_type_id', 'title', 'file_path', 'original_name', 'mime',
        'size_bytes', 'issued_on', 'expires_on', 'remind_before_days', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'size_bytes' => 'integer',
            'remind_before_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
