<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\ApplicantCancelRule;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Services\LeaveApplicationApproval;
use App\Domain\Leave\Services\LeaveApplicationValidator;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Tarik Balik (pending), Batal (approved) and Batal & Salin (spec-02 §4.1).
 * Leave decides whether an approved request may be cancelled; the reversal
 * itself runs in LeaveApplication::onApprovalCancelled().
 */
final class CancelLeaveApplication
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly LeaveApplicationValidator $validator,
        private readonly LeaveApplicationApproval $approval,
        private readonly RoleService $roles,
    ) {}

    /** Withdraw a pending application (applicant, HR with create-for-others, or system when $actor is null). */
    public function withdraw(LeaveApplication $application, ?User $actor, string $reason): LeaveApplication
    {
        if ($application->status !== ApprovalStatus::Pending) {
            throw new LeaveRuleViolation('leave.errors.not_pending');
        }
        $request = $application->approvalRequest()->firstOrFail();
        $this->approvals->cancel($request, $actor, $reason);

        return $application->refresh();
    }

    /** Cancel an approved application; null actor = system (e.g. separation). */
    public function cancelApproved(LeaveApplication $application, ?User $actor, string $reason): LeaveApplication
    {
        if ($application->status !== ApprovalStatus::Approved) {
            throw new LeaveRuleViolation('leave.errors.not_approved');
        }
        if ($actor !== null && ! $this->mayCancelApproved($application, $actor)) {
            throw new AuthorizationException((string) __('leave.errors.cancel_not_allowed'));
        }

        return DB::transaction(function () use ($application, $actor, $reason): LeaveApplication {
            $application->loadMissing('days');
            $employee = Employee::query()->findOrFail($application->employee_id);
            [$lockedUnpaid] = $this->validator->lockedDates($employee, $this->approval->breakdownOf($application));
            if ($lockedUnpaid !== []) {
                throw new LeaveRuleViolation('leave.errors.payroll_locked', ['dates' => implode(', ', $lockedUnpaid)]);
            }

            $request = $application->approvalRequest()->firstOrFail();
            $this->approvals->cancel($request, $actor, $reason);

            return $application->refresh();
        });
    }

    /** Pending → withdraw; approved → cancel. */
    public function cancel(LeaveApplication $application, ?User $actor, string $reason): LeaveApplication
    {
        return $application->status === ApprovalStatus::Pending
            ? $this->withdraw($application, $actor, $reason)
            : $this->cancelApproved($application, $actor, $reason);
    }

    /** "Batal & Salin": cancel with reason, then open a prefilled draft pointing back (A5.6). */
    public function cancelAndCopy(LeaveApplication $application, User $actor, string $reason): LeaveApplication
    {
        return DB::transaction(function () use ($application, $actor, $reason): LeaveApplication {
            $this->cancel($application, $actor, $reason);
            $copy = $application->refresh()->duplicateAsDraft();
            $copy->forceFill(['balance_at_submit' => null, 'is_backdated' => false])->save();

            $days = $application->days()->get()->map(fn ($d) => [
                'leave_application_id' => $copy->id,
                'date' => $d->date->toDateString(),
                'day_kind' => $d->day_kind->value,
                'session' => $d->session->value,
                'portion' => (string) $d->portion,
                'unpaid_portion' => (string) $d->unpaid_portion,
                'holiday_list_id' => $d->holiday_list_id,
            ])->all();
            if ($days !== []) {
                $copy->days()->getModel()->newQuery()->insert($days);
            }

            return $copy;
        });
    }

    /**
     * Applicant: only when the setting allows it and the leave has not started.
     * Anyone else: needs leave.application.cancel-approved (HR).
     */
    public function mayCancelApproved(LeaveApplication $application, User $actor): bool
    {
        if ($actor->is_super_admin) {
            return true;
        }

        $applicantUserId = Employee::query()->whereKey($application->employee_id)->value('user_id');
        if ($applicantUserId !== null && (int) $applicantUserId === $actor->id) {
            return LeaveSettings::current($application->organisation_id)->applicant_may_cancel_approved === ApplicantCancelRule::BeforeStart
                && $application->from_date->greaterThan(CarbonImmutable::today());
        }

        return $this->roles->can($actor, $application->organisation_id, 'leave.application.cancel-approved')
            && $this->roles->can($actor, $application->organisation_id, 'leave.application.create-for-others');
    }
}
