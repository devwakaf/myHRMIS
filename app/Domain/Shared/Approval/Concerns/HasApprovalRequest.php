<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Concerns;

use App\Domain\Shared\Approval\Enums\RequestStatus;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * For Approvable documents: the request relation and status mirroring (A6.7).
 * The document's `status` column (ApprovalStatus) mirrors the request outcome.
 *
 * @mixin Model
 */
trait HasApprovalRequest
{
    /**
     * @return MorphOne<ApprovalRequest, $this>
     */
    public function approvalRequest(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable')->withoutGlobalScopes();
    }

    public function isPendingApproval(): bool
    {
        return $this->getAttribute('status') === ApprovalStatus::Pending;
    }

    /**
     * Mirror the request status onto the document (lifecycle columns only, so the
     * A5.4 lock allows it after submit).
     */
    public function mirrorApprovalStatus(RequestStatus $status, ?int $actorId = null, ?string $reason = null): void
    {
        $this->setAttribute('status', ApprovalStatus::from($status->value));

        if ($status === RequestStatus::Pending) {
            $this->setAttribute('submitted_at', now());
            $this->setAttribute('submitted_by', $actorId);
        }

        if ($status === RequestStatus::Cancelled) {
            $this->setAttribute('cancelled_at', now());
            $this->setAttribute('cancelled_by', $actorId);
            $this->setAttribute('cancel_reason', $reason);
        }

        $this->save();
    }
}
