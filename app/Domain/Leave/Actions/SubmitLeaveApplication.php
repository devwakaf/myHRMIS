<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\DaySession;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveApplicationValidator;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Leave\Services\LeaveDayCalculator;
use App\Domain\Leave\Support\DayBreakdown;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Exceptions\NoActiveWorkflow;
use App\Domain\Shared\Lifecycle\DocumentLocked;
use App\Domain\Shared\Support\DomainError;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mohon Cuti (spec-02 §3.9, §4.1): save a draft with its day rows, preview the
 * outcome live, and submit — validation, balance snapshot, then
 * ApprovalService::submit inside one transaction.
 */
final class SubmitLeaveApplication
{
    public function __construct(
        private readonly LeaveDayCalculator $calculator,
        private readonly LeaveApplicationValidator $validator,
        private readonly LeaveBalance $balance,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data  leave_type_id, from_date, to_date, half_day_date?, half_day_session?, event_date?, reason?, contact_during_leave?, handover_note?, block_list_acknowledged?
     */
    public function saveDraft(?LeaveApplication $application, Employee $employee, array $data, User $actor): LeaveApplication
    {
        if ($application !== null && ! $application->isDraft()) {
            throw DocumentLocked::forAttributes(array_keys($data));
        }

        return DB::transaction(function () use ($application, $employee, $data, $actor): LeaveApplication {
            $type = $this->type($data);
            $application ??= new LeaveApplication;
            $this->fill($application, $employee, $type, $data, $actor);
            $breakdown = $this->breakdown($application, $employee, $type);
            $this->applyBreakdown($application, $breakdown);
            $application->save();
            $this->storeDays($application, $breakdown);

            return $application->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $choices  approval engine choices (applicant_chooses)
     * @return list<string> warnings
     */
    public function submit(LeaveApplication $application, User $actor, array $choices = []): array
    {
        return DB::transaction(function () use ($application, $actor, $choices): array {
            /** @var LeaveApplication $application */
            $application = LeaveApplication::query()->lockForUpdate()->findOrFail($application->id);
            if (! $application->isDraft()) {
                throw DocumentLocked::forAttributes(['status']);
            }

            $employee = Employee::query()->findOrFail($application->employee_id);
            $type = LeaveType::query()->findOrFail($application->leave_type_id);

            // Recompute with today's holiday data before validating.
            $breakdown = $this->breakdown($application, $employee, $type);
            $this->applyBreakdown($application, $breakdown);
            $this->storeDays($application, $breakdown);

            $outcome = $this->validator->validate($application, $employee, $type, $breakdown, $application->attachments()->count());
            DomainValidation::throwIf($outcome['errors']);

            $balance = $this->balance->on($employee, $type, $application->from_date, $application->id);
            $application->forceFill([
                'balance_at_submit' => $type->hasBalance() ? $balance['available'] : null,
                'is_backdated' => $application->from_date->lessThan(CarbonImmutable::today()),
            ])->save();

            $this->approvals->submit($application, $actor, $choices);

            return $outcome['warnings'];
        });
    }

    /**
     * Save and submit in one go (form "Hantar").
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $choices
     * @return array{application: LeaveApplication, warnings: list<string>}
     */
    public function apply(Employee $employee, array $data, User $actor, array $choices = [], ?LeaveApplication $draft = null): array
    {
        return DB::transaction(function () use ($employee, $data, $actor, $choices, $draft): array {
            $application = $this->saveDraft($draft, $employee, $data, $actor);
            $warnings = $this->submit($application, $actor, $choices);

            return ['application' => $application->refresh(), 'warnings' => $warnings];
        });
    }

    /**
     * Live preview for the form: day breakdown, balance before/after, warnings,
     * errors and the approval route. Nothing is persisted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(Employee $employee, array $data, User $actor, ?LeaveApplication $draft = null, int $attachmentCount = 0): array
    {
        $type = $this->type($data);
        $application = $draft !== null ? clone $draft : new LeaveApplication;
        $this->fill($application, $employee, $type, $data, $actor);

        try {
            $breakdown = $this->breakdown($application, $employee, $type);
        } catch (ValidationException $e) {
            return ['breakdown' => null, 'errors' => array_map(fn ($m) => $m[0], $e->errors()), 'warnings' => [], 'balance' => null, 'route' => null];
        }
        $this->applyBreakdown($application, $breakdown);

        $outcome = DB::transaction(function () use ($application, $employee, $type, $breakdown, $attachmentCount, $draft): array {
            $count = $attachmentCount + ($draft?->attachments()->count() ?? 0);

            return $this->validator->validate($application, $employee, $type, $breakdown, $count);
        });
        $balance = $this->balance->on($employee, $type, $application->from_date, $draft?->id);

        $route = null;
        try {
            $route = $this->approvals->preview($application, [], $actor);
        } catch (NoActiveWorkflow|DomainError $e) {
            $outcome['errors']['approval'] = $e->userMessage();
        }

        return [
            'breakdown' => $breakdown->toArray(),
            'errors' => $outcome['errors'],
            'warnings' => $outcome['warnings'],
            'block_hits' => $outcome['block_hits'],
            'balance' => [
                'before' => $balance['available'],
                'after' => $type->hasBalance() ? Dec::sub($balance['available'], $breakdown->totalDays) : null,
                'reserved' => $balance['reserved'],
                'expiring' => $balance['expiring'],
            ],
            'suggested_to_date' => $this->calculator->suggestedToDate($type, $application->from_date)?->toDateString(),
            'route' => $route,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function type(array $data): LeaveType
    {
        $type = LeaveType::query()->find($data['leave_type_id'] ?? null);
        if ($type === null) {
            throw DomainValidation::withMessages(['leave_type_id' => __('leave.errors.invalid_type')]);
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(LeaveApplication $application, Employee $employee, LeaveType $type, array $data, User $actor): void
    {
        $session = isset($data['half_day_session']) && $data['half_day_session'] !== '' ? DaySession::tryFrom((string) $data['half_day_session']) : null;
        $halfDate = ! empty($data['half_day_date']) ? $data['half_day_date'] : null;

        $application->fill([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => $data['from_date'] ?? null,
            'to_date' => $data['to_date'] ?? ($data['from_date'] ?? null),
            'half_day_date' => $halfDate,
            'half_day_session' => $halfDate === null ? null : ($session ?? DaySession::Am),
            'event_date' => ! empty($data['event_date']) ? $data['event_date'] : null,
            'reason' => $data['reason'] ?? null,
            'contact_during_leave' => $data['contact_during_leave'] ?? null,
            'handover_note' => $data['handover_note'] ?? null,
            'block_list_acknowledged' => (bool) ($data['block_list_acknowledged'] ?? false),
            'is_on_behalf' => $employee->user_id === null || (int) $employee->user_id !== $actor->id,
            'applied_by_user_id' => $actor->id,
        ]);
        $application->setRelation('leaveType', $type);
    }

    private function breakdown(LeaveApplication $application, Employee $employee, LeaveType $type): DayBreakdown
    {
        if ($application->from_date === null || $application->to_date === null) {
            throw DomainValidation::withMessages(['from_date' => __('validation.required', ['attribute' => __('leave.fields.from_date')])]);
        }

        return $this->calculator->build($employee, $type, $application->from_date, $application->to_date, $application->half_day_date, $application->half_day_session);
    }

    private function applyBreakdown(LeaveApplication $application, DayBreakdown $breakdown): void
    {
        $application->setAttribute('total_days', $breakdown->totalDays);
        $application->setAttribute('unpaid_days', $breakdown->unpaidDays);
        $application->setAttribute('calendar_days', $breakdown->calendarDays);
    }

    public function storeDays(LeaveApplication $application, DayBreakdown $breakdown): void
    {
        $application->days()->delete();
        $rows = [];
        foreach ($breakdown->days as $day) {
            $rows[] = [
                'leave_application_id' => $application->id,
                'date' => $day['date']->toDateString(),
                'day_kind' => $day['day_kind']->value,
                'session' => $day['session']->value,
                'portion' => $day['portion'],
                'unpaid_portion' => $day['unpaid_portion'],
                'holiday_list_id' => $day['holiday_list_id'],
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $application->days()->getModel()->newQuery()->insert($chunk);
        }
        $application->unsetRelation('days');
    }
}
