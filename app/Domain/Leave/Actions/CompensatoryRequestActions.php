<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\CompensatoryLeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\CompensatoryLeaveApproval;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Lifecycle\DocumentLocked;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permohonan Cuti Gantian lifecycle (spec-02 §2.11, §3.16): draft → submit to the
 * `compensatory_leave` workflow; withdraw or cancel through the engine.
 */
final class CompensatoryRequestActions
{
    public function __construct(
        private readonly CompensatoryLeaveApproval $compensatory,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data  leave_type_id, work_from, work_to, half_day_date?, reason
     */
    public function saveDraft(?CompensatoryLeaveRequest $request, Employee $employee, array $data): CompensatoryLeaveRequest
    {
        if ($request !== null && ! $request->isDraft()) {
            throw DocumentLocked::forAttributes(array_keys($data));
        }
        $type = LeaveType::query()->find($data['leave_type_id'] ?? null);
        if ($type === null) {
            throw DomainValidation::withMessages(['leave_type_id' => __('leave.errors.invalid_type')]);
        }

        $request ??= new CompensatoryLeaveRequest;
        $request->fill([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'work_from' => $data['work_from'],
            'work_to' => $data['work_to'] ?? $data['work_from'],
            'half_day_date' => ! empty($data['half_day_date']) ? $data['half_day_date'] : null,
            'reason' => (string) ($data['reason'] ?? ''),
        ]);
        $request->setRelation('leaveType', $type);

        $outcome = $this->compensatory->validate($request);
        $request->fill(['days_claimed' => $outcome['days'], 'attendance_unverified' => $outcome['unverified']]);
        $request->save();

        return $request;
    }

    /**
     * @param  array<string, mixed>  $choices
     */
    public function submit(CompensatoryLeaveRequest $request, User $actor, array $choices = []): CompensatoryLeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $choices): CompensatoryLeaveRequest {
            /** @var CompensatoryLeaveRequest $request */
            $request = CompensatoryLeaveRequest::query()->lockForUpdate()->with('leaveType')->findOrFail($request->id);
            if (! $request->isDraft()) {
                throw DocumentLocked::forAttributes(['status']);
            }

            $outcome = $this->compensatory->validate($request);
            DomainValidation::throwIf($outcome['errors']);
            $request->forceFill(['days_claimed' => $outcome['days'], 'attendance_unverified' => $outcome['unverified']])->save();

            $this->approvals->submit($request, $actor, $choices);

            return $request->refresh();
        });
    }

    public function cancel(CompensatoryLeaveRequest $request, ?User $actor, string $reason): CompensatoryLeaveRequest
    {
        if (! in_array($request->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true)) {
            throw new LeaveRuleViolation('leave.errors.not_pending');
        }
        $this->approvals->cancel($request->approvalRequest()->firstOrFail(), $actor, $reason);

        return $request->refresh();
    }
}
