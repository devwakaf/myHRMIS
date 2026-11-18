<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Database\Factories\Shared\ApprovalWorkflowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Aliran Kelulusan (A6.4): one active version per (organisation, approval_type).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $approval_type
 * @property string $name
 * @property int $version
 * @property bool $is_active
 * @property bool $prevent_self_approval
 * @property bool $allow_override
 * @property bool $notify_applicant_each_step
 * @property string|null $description
 * @property Carbon|null $published_at
 * @property-read Collection<int, ApprovalWorkflowStep> $steps
 */
class ApprovalWorkflow extends Model
{
    /** @use HasFactory<ApprovalWorkflowFactory> */
    use BelongsToOrganisation, HasFactory, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'organisation_id', 'approval_type', 'name', 'version', 'is_active', 'prevent_self_approval',
        'allow_override', 'notify_applicant_each_step', 'description', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'prevent_self_approval' => 'boolean',
            'allow_override' => 'boolean',
            'notify_applicant_each_step' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function activityLogModule(): string
    {
        return 'approval';
    }

    /**
     * @return HasMany<ApprovalWorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowStep::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<ApprovalRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function isUsed(): bool
    {
        return $this->requests()->withoutGlobalScopes()->exists();
    }

    /**
     * @param  Builder<ApprovalWorkflow>  $query
     * @return Builder<ApprovalWorkflow>
     */
    public function scopeActiveFor(Builder $query, string $type): Builder
    {
        return $query->where('approval_type', $type)->where('is_active', true);
    }
}
