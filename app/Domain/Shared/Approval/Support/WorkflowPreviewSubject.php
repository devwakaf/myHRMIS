<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Support;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Enums\RequestStatus;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Models\User;
use LogicException;

/**
 * In-memory stand-in used by "Pratonton" on the Aliran Kelulusan screen: lets HR
 * dry-run the active workflow for an employee and sample attribute values.
 * It is never submitted.
 */
final class WorkflowPreviewSubject implements Approvable
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function __construct(
        private readonly string $type,
        private readonly Employee $applicant,
        private readonly array $attributes,
    ) {}

    public function approvalType(): string
    {
        return $this->type;
    }

    public function approvalApplicant(): Employee
    {
        return $this->applicant;
    }

    public function approvalAttributes(): array
    {
        return $this->attributes;
    }

    public function approvalSummary(): array
    {
        return ['key' => 'approval.preview.summary'];
    }

    public function decisionContext(User $viewer): array
    {
        return [];
    }

    public function checkApprovalCompletion(ApprovalRequest $request): ApprovalCheck
    {
        throw new LogicException('Preview subjects are never submitted.');
    }

    public function onApprovalCompleted(ApprovalRequest $request): void
    {
        throw new LogicException('Preview subjects are never submitted.');
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        throw new LogicException('Preview subjects are never submitted.');
    }

    public function onApprovalCancelled(ApprovalRequest $request, bool $wasApproved): void
    {
        throw new LogicException('Preview subjects are never submitted.');
    }

    public function mirrorApprovalStatus(RequestStatus $status, ?int $actorId = null, ?string $reason = null): void
    {
        throw new LogicException('Preview subjects are never submitted.');
    }
}
