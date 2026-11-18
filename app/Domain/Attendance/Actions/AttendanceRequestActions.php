<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\AttendanceRequestKind;
use App\Domain\Attendance\Enums\RequestDayOutcome;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Services\AttendanceRequestPlanner;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Permohonan Kehadiran around the approval engine (spec-03 §4.8): draft, submit
 * (validation + "nothing to apply" refusal), withdraw while pending, cancel after
 * approval (HR outside locked periods; applicant only while all dates are future).
 */
final class AttendanceRequestActions
{
    public const MAX_SPAN_DAYS = 31;

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly AttendanceRequestPlanner $planner,
        private readonly PayrollLockQuery $locks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(?AttendanceRequest $request, Employee $employee, array $data): AttendanceRequest
    {
        $request ??= new AttendanceRequest(['employee_id' => $employee->id, 'organisation_id' => $employee->organisation_id]);
        if (! $request->isDraft()) {
            throw new AttendanceRuleViolation('attendance.errors.not_draft');
        }

        $isHalf = (bool) ($data['is_half_day'] ?? false);
        $request->fill([
            'kind' => $data['kind'],
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'] ?? $data['from_date'],
            'is_half_day' => $isHalf,
            'half_day_date' => $isHalf ? ($data['half_day_date'] ?? $data['from_date']) : null,
            'half_day_session' => $isHalf ? ($data['half_day_session'] ?? 'am') : null,
            'include_rest_days' => (bool) ($data['include_rest_days'] ?? false),
            'shift_type_id' => $data['shift_type_id'] ?? null,
            'duty_location' => $data['duty_location'] ?? null,
            'explanation' => (string) ($data['explanation'] ?? ''),
        ]);
        $this->validate($request, $employee);
        $request->save();

        return $request;
    }

    /**
     * @param  array<string, mixed>  $choices
     */
    public function submit(AttendanceRequest $request, User $actor, array $choices = []): AttendanceRequest
    {
        return DB::transaction(function () use ($request, $actor, $choices): AttendanceRequest {
            $employee = Employee::query()->lockForUpdate()->findOrFail($request->employee_id);
            $this->validate($request, $employee);

            $plan = $this->planner->plan($request);
            if (AttendanceRequestPlanner::nothingToApply($plan)) {
                $reasons = array_map(fn (array $d) => $d['date']->format('d/m/Y').': '.$d['outcome']->label(), $plan);
                throw DomainValidation::withMessages(['from_date' => __('attendance.errors.nothing_to_apply', ['days' => implode('; ', $reasons)])]);
            }

            $this->approvals->submit($request, $actor, $choices);

            return $request->refresh();
        });
    }

    public function withdraw(AttendanceRequest $request, User $actor, string $reason): void
    {
        if ($request->status !== ApprovalStatus::Pending) {
            throw new AttendanceRuleViolation('attendance.errors.not_pending');
        }
        $this->approvals->cancel($request->approvalRequest()->firstOrFail(), $actor, $reason);
    }

    public function cancelApproved(AttendanceRequest $request, User $actor, string $reason, bool $asHr): void
    {
        if ($request->status !== ApprovalStatus::Approved) {
            throw new AttendanceRuleViolation('attendance.errors.not_approved');
        }
        $employee = $request->approvalApplicant();
        if ($asHr) {
            if ($this->locks->lockedDatesIn($employee, $request->range()) !== []) {
                throw new AttendanceRuleViolation('attendance.errors.period_locked', ['dates' => $request->from_date->format('d/m/Y')]);
            }
        } elseif (! $request->from_date->greaterThan(CarbonImmutable::today())) {
            throw new AttendanceRuleViolation('attendance.errors.cancel_started');
        }

        $this->approvals->cancel($request->approvalRequest()->firstOrFail(), $actor, $reason);
    }

    private function validate(AttendanceRequest $r, Employee $employee): void
    {
        $errors = [];
        $from = $r->from_date;
        $to = $r->to_date;

        if ($to->lessThan($from)) {
            $errors['to_date'] = __('attendance.errors.range_invalid');
        } elseif ((int) $from->diffInDays($to) + 1 > self::MAX_SPAN_DAYS) {
            $errors['to_date'] = __('attendance.errors.range_too_long', ['days' => self::MAX_SPAN_DAYS]);
        }
        if ($r->is_half_day && ($r->half_day_date === null || $r->half_day_date->lessThan($from) || $r->half_day_date->greaterThan($to))) {
            $errors['half_day_date'] = __('attendance.errors.half_day_outside');
        }
        if ($r->kind === AttendanceRequestKind::OnDuty && trim((string) $r->duty_location) === '') {
            $errors['duty_location'] = __('attendance.errors.duty_location_required');
        }
        if (trim((string) $r->explanation) === '') {
            $errors['explanation'] = __('attendance.errors.explanation_required');
        }
        if ($from->lessThan($employee->date_of_joining) || ($employee->relieving_date !== null && $to->greaterThan($employee->relieving_date))) {
            $errors['from_date'] = __('attendance.errors.outside_employment');
        }

        if ($errors === []) {
            $overlap = AttendanceRequest::query()->where('employee_id', $employee->id)
                ->when($r->exists, fn ($q) => $q->whereKeyNot($r->id))
                ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
                ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())
                ->value('number');
            if ($overlap !== null) {
                $errors['from_date'] = __('attendance.errors.request_overlap', ['number' => $overlap]);
            }

            $locked = $this->locks->lockedDatesIn($employee, new DateRange($from, $to));
            if ($locked !== []) {
                $errors['from_date'] = __('attendance.errors.period_locked', ['dates' => implode(', ', array_map(fn ($d) => $d->format('d/m/Y'), $locked))]);
            }
        }

        DomainValidation::throwIf($errors);
    }

    /**
     * Live per-day preview for the form (not stored).
     *
     * @return list<array{date: string, outcome: string}>
     */
    public function preview(AttendanceRequest $request): array
    {
        return array_map(fn (array $d) => ['date' => $d['date']->toDateString(), 'outcome' => $d['outcome']->value, 'label' => $d['outcome']->label()], $this->planner->plan($request));
    }

    public static function skipped(RequestDayOutcome $outcome): bool
    {
        return str_starts_with($outcome->value, 'skipped_');
    }
}
