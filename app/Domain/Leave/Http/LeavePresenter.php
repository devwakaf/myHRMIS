<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http;

use App\Domain\Leave\Models\CompensatoryLeaveRequest;
use App\Domain\Leave\Models\LeaveAdjustment;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveApplicationAttachment;
use App\Domain\Leave\Models\LeaveApplicationDay;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Support\Dec;

/**
 * Arrays for Inertia props / JSON (days as decimal strings, dates ISO Y-m-d).
 * The UI agent's TypeScript interfaces should mirror these shapes.
 */
final class LeavePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function type(LeaveType $type): array
    {
        return array_merge($type->attributesToArray(), [
            'display_name' => $type->displayName(),
            'has_balance' => $type->hasBalance(),
            'entitlement_basis_label' => $type->entitlement_basis->label(),
            'pay_mode_label' => $type->pay_mode->label(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function application(LeaveApplication $a): array
    {
        return [
            'id' => $a->id,
            'number' => $a->number,
            'employee_id' => $a->employee_id,
            'employee' => $a->relationLoaded('employee') ? $a->employee->full_name : null,
            'employee_no' => $a->relationLoaded('employee') ? $a->employee->employee_no : null,
            'leave_type_id' => $a->leave_type_id,
            'leave_type' => $a->relationLoaded('leaveType') ? $a->leaveType->displayName() : null,
            'leave_type_code' => $a->relationLoaded('leaveType') ? $a->leaveType->code : null,
            'colour' => $a->relationLoaded('leaveType') ? $a->leaveType->colour : null,
            'from_date' => $a->from_date->toDateString(),
            'to_date' => $a->to_date->toDateString(),
            'half_day_date' => $a->half_day_date?->toDateString(),
            'half_day_session' => $a->half_day_session?->value,
            'event_date' => $a->event_date?->toDateString(),
            'total_days' => Dec::norm((string) $a->total_days),
            'unpaid_days' => Dec::norm((string) $a->unpaid_days),
            'calendar_days' => $a->calendar_days,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'is_on_behalf' => $a->is_on_behalf,
            'is_backdated' => $a->is_backdated,
            'submitted_at' => $a->submitted_at?->format('Y-m-d H:i'),
            'cancel_reason' => $a->getAttribute('cancel_reason'),
            'amended_from_id' => $a->amended_from_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function applicationDetail(LeaveApplication $a, bool $showAttachments): array
    {
        $a->loadMissing(['days', 'attachments', 'leaveType', 'employee']);

        return array_merge(self::application($a), [
            'reason' => $a->reason,
            'contact_during_leave' => $a->contact_during_leave,
            'handover_note' => $a->handover_note,
            'balance_at_submit' => $a->balance_at_submit === null ? null : Dec::norm((string) $a->balance_at_submit),
            'block_list_acknowledged' => $a->block_list_acknowledged,
            'days' => $a->days->map(fn (LeaveApplicationDay $d) => [
                'date' => $d->date->toDateString(),
                'day_kind' => $d->day_kind->value,
                'day_kind_label' => $d->day_kind->label(),
                'session' => $d->session->value,
                'portion' => (string) $d->portion,
                'unpaid_portion' => (string) $d->unpaid_portion,
                'leave_allocation_id' => $d->leave_allocation_id,
            ])->values()->all(),
            'attachments' => $a->attachments->map(fn (LeaveApplicationAttachment $f) => [
                'id' => $f->id,
                'kind' => $f->kind->value,
                'kind_label' => $f->kind->label(),
                'original_name' => $f->original_name,
                'size_bytes' => $f->size_bytes,
                'download_url' => $showAttachments ? '/leave/attachments/'.$f->id.'/download' : null,
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function allocation(LeaveAllocation $a, bool $withLots = false): array
    {
        $out = [
            'id' => $a->id,
            'number' => $a->number,
            'employee_id' => $a->employee_id,
            'employee' => $a->relationLoaded('employee') ? $a->employee->full_name : null,
            'leave_type_id' => $a->leave_type_id,
            'leave_type' => $a->relationLoaded('leaveType') ? $a->leaveType->displayName() : null,
            'leave_period_id' => $a->leave_period_id,
            'period' => $a->relationLoaded('period') ? $a->period->name : null,
            'from_date' => $a->from_date->toDateString(),
            'to_date' => $a->to_date->toDateString(),
            'source' => $a->source->value,
            'source_label' => $a->source->label(),
            'entitlement_days' => Dec::norm((string) $a->entitlement_days),
            'initial_days' => Dec::norm((string) $a->initial_days),
            'carry_processed_at' => $a->carry_processed_at?->format('Y-m-d H:i'),
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'remarks' => $a->remarks,
        ];

        if ($withLots) {
            $ledger = app(LeaveLedger::class);
            $movements = $a->movements()->get();
            $lots = $movements->filter(fn (LeaveMovement $m) => $m->isLot());
            $remaining = $ledger->remainingMany($lots->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
            $out['lots'] = $lots->map(fn (LeaveMovement $m) => [
                'id' => $m->id,
                'kind' => $m->kind->value,
                'kind_label' => $m->kind->label(),
                'days' => (string) $m->days,
                'remaining' => $remaining[$m->id] ?? '0',
                'date_from' => $m->date_from->toDateString(),
                'date_to' => $m->date_to->toDateString(),
            ])->values()->all();
            $out['ledger'] = $movements->map(fn (LeaveMovement $m) => self::movement($m))->values()->all();
            $out['credit_schedules'] = $a->creditSchedules()->get()->map(fn ($s) => [
                'id' => $s->id,
                'due_date' => $s->due_date->toDateString(),
                'kind' => $s->kind->value,
                'days' => (string) $s->days,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'failure_reason' => $s->failure_reason,
            ])->values()->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function movement(LeaveMovement $m): array
    {
        return [
            'id' => $m->id,
            'kind' => $m->kind->value,
            'kind_label' => $m->kind->label(),
            'days' => (string) $m->days,
            'unpaid_days' => (string) $m->unpaid_days,
            'lot_id' => $m->lot_id,
            'leave_allocation_id' => $m->leave_allocation_id,
            'date_from' => $m->date_from->toDateString(),
            'date_to' => $m->date_to->toDateString(),
            'posted_on' => $m->posted_on->toDateString(),
            'source_type' => $m->source_type,
            'source_id' => $m->source_id,
            'reverses_movement_id' => $m->reverses_movement_id,
            'note' => $m->note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adjustment(LeaveAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'number' => $a->number,
            'leave_allocation_id' => $a->leave_allocation_id,
            'direction' => $a->direction->value,
            'direction_label' => $a->direction->label(),
            'days' => (string) $a->days,
            'effective_date' => $a->effective_date->toDateString(),
            'expires_on' => $a->expires_on?->toDateString(),
            'reason' => $a->reason,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function encashment(LeaveEncashment $e): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'employee_id' => $e->employee_id,
            'employee' => $e->relationLoaded('employee') ? $e->employee->full_name : null,
            'leave_type_id' => $e->leave_type_id,
            'leave_allocation_id' => $e->leave_allocation_id,
            'encashment_date' => $e->encashment_date->toDateString(),
            'eligible_days' => (string) $e->eligible_days,
            'days' => (string) $e->days,
            'rate_basis' => $e->rate_basis->value,
            'rate_basis_label' => $e->rate_basis->label(),
            'monthly_basic' => (string) $e->monthly_basic,
            'basic_entered_manually' => $e->basic_entered_manually,
            'daily_rate' => (string) $e->daily_rate,
            'amount' => (string) $e->amount,
            'payout_status' => $e->payout_status->value,
            'payout_status_label' => $e->payout_status->label(),
            'purpose' => $e->purpose->value,
            'status' => $e->status->value,
            'status_label' => $e->status->label(),
            'remarks' => $e->remarks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function compensatory(CompensatoryLeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'employee_id' => $r->employee_id,
            'employee' => $r->relationLoaded('employee') ? $r->employee->full_name : null,
            'leave_type_id' => $r->leave_type_id,
            'work_from' => $r->work_from->toDateString(),
            'work_to' => $r->work_to->toDateString(),
            'half_day_date' => $r->half_day_date?->toDateString(),
            'reason' => $r->reason,
            'days_claimed' => (string) $r->days_claimed,
            'attendance_unverified' => $r->attendance_unverified,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
        ];
    }
}
