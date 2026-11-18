<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Permohonan Perlu Semakan" HR work queue (spec-02 §3.14, §6.2.7).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $leave_application_id
 * @property string $reason
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 * @property-read LeaveApplication $application
 */
class LeaveReviewTask extends Model
{
    use BelongsToOrganisation;

    protected $fillable = ['organisation_id', 'leave_application_id', 'reason', 'details', 'resolved_at', 'resolved_by', 'resolution_note'];

    protected function casts(): array
    {
        return ['details' => 'array', 'resolved_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<LeaveApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }
}
