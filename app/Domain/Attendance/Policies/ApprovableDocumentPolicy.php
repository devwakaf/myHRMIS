<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Policies;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared O / A rules for Attendance's Approvable documents (spec-03 §8): the
 * applicant ("O"), holders of the view-all ability, and step assignees ("A",
 * decided by ApprovalService::canView — decision context only).
 */
abstract class ApprovableDocumentPolicy
{
    abstract protected function viewAllAbility(): string;

    abstract protected function createAbility(): string;

    public function viewAny(User $user): bool
    {
        return $user->can($this->viewAllAbility());
    }

    public function view(User $user, Model $doc): bool
    {
        if ((int) $doc->getAttribute('organisation_id') !== OrganisationContext::idOrNull()) {
            return false;
        }

        if ($this->isApplicant($user, $doc) || $user->can($this->viewAllAbility())) {
            return true;
        }

        $request = $doc instanceof Approvable ? ApprovalRequest::query()->withoutGlobalScopes()
            ->where('approvable_type', $doc->getMorphClass())->where('approvable_id', $doc->getKey())->first() : null;

        return $request !== null && app(ApprovalService::class)->canView($user, $request);
    }

    /** Create for oneself, or for others with attendance.request.create-for-others. */
    public function createFor(User $user, Employee $employee): bool
    {
        if ($employee->user_id !== null && (int) $employee->user_id === $user->id) {
            return $user->can($this->createAbility()) || $user->can('attendance.request.create-for-others');
        }

        return $user->can('attendance.request.create-for-others');
    }

    public function update(User $user, Model $doc): bool
    {
        return $doc->getAttribute('status') === ApprovalStatus::Draft
            && ($this->isApplicant($user, $doc) || $user->can('attendance.request.create-for-others'));
    }

    public function withdraw(User $user, Model $doc): bool
    {
        return $doc->getAttribute('status') === ApprovalStatus::Pending
            && ($this->isApplicant($user, $doc) || $user->can('attendance.request.create-for-others'));
    }

    public function isApplicant(User $user, Model $doc): bool
    {
        $userId = Employee::acrossOrganisations()->whereKey($doc->getAttribute('employee_id'))->value('user_id');

        return $userId !== null && (int) $userId === $user->id;
    }
}
