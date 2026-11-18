<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Contracts;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Approval\Enums\RequestStatus;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Models\User;

/**
 * Module-facing contract of the approval engine (A6.7).
 *
 * The `check*` and `on*` hooks run synchronously inside the engine's
 * transaction; mandatory side effects belong there, never in event listeners.
 */
interface Approvable
{
    /** Registry key, e.g. 'leave'. */
    public function approvalType(): string;

    public function approvalApplicant(): Employee;

    /**
     * Condition context: flat scalars keyed by catalogue attribute names.
     *
     * @return array<string, scalar|null>
     */
    public function approvalAttributes(): array;

    /**
     * Inbox / mail line: ['key' => translation key, 'params' => [...]].
     *
     * @return array{key: string, params?: array<string, scalar|null>}
     */
    public function approvalSummary(): array;

    /**
     * Facts an approver needs to decide (e.g. leave balance). Never the full profile.
     *
     * @return array<string, mixed>
     */
    public function decisionContext(User $viewer): array;

    /** Veto before the final approval (in transaction). */
    public function checkApprovalCompletion(ApprovalRequest $request): ApprovalCheck;

    public function onApprovalCompleted(ApprovalRequest $request): void;

    public function onApprovalRejected(ApprovalRequest $request): void;

    public function onApprovalCancelled(ApprovalRequest $request, bool $wasApproved): void;

    /** Mirror the request status onto the document; provided by HasApprovalRequest. */
    public function mirrorApprovalStatus(RequestStatus $status, ?int $actorId = null, ?string $reason = null): void;
}
