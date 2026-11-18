<?php

// spec-02 §7 cases 16–20 (balance, reservation, caps, negative balance).

use App\Domain\Leave\Actions\CancelLeaveApplication;
use App\Domain\Leave\Actions\SubmitLeaveApplication;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->w = leaveWorld('2026-06-01');
    $this->employee = staff();
    $this->type = leaveTypeMake(['code' => 'TST']);
    $this->balance = fn () => app(LeaveBalance::class)->on($this->employee, $this->type, '2026-06-01');
});

function leaveValidationMessage(Closure $fn, string $field): string
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors()[$field][0] ?? implode(' ', array_keys($e->errors()));
    }

    return '(no error)';
}

// #16
it('reserves pending days and refuses a request that would exceed the balance', function () {
    allocate($this->employee, $this->type, '10');
    applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-15'); // 6 working days, pending

    expect(($this->balance)()['available'])->toBe('4.000')
        ->and(($this->balance)()['reserved'])->toBe('6.000');

    $message = leaveValidationMessage(fn () => applyLeave($this->employee, $this->type, '2026-06-22', '2026-06-26'), 'leave_type_id');
    expect($message)->toBe(__('leave.errors.insufficient_balance', ['short' => '1', 'reserved' => '6']));
});

// #17
it('releases the reservation on withdraw and on rejection without writing ledger rows', function () {
    $allocation = allocate($this->employee, $this->type, '10');
    $a = applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-15');
    app(CancelLeaveApplication::class)->withdraw($a, $this->employee->user, 'Tukar fikiran');
    expect(($this->balance)()['available'])->toBe('10.000');

    $b = applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-15');
    app(ApprovalService::class)->reject($b->approvalRequest()->firstOrFail(), $this->w->approver, 'Tidak sesuai');

    expect($b->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and(($this->balance)()['available'])->toBe('10.000')
        ->and(LeaveMovement::query()->where('leave_allocation_id', $allocation->id)->where('kind', '!=', MovementKind::Allocation->value)->count())->toBe(0);
});

// #18
it('locks the allocation rows so that only one of two competing submissions passes', function () {
    allocate($this->employee, $this->type, '10');
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (str_contains($query->sql, 'leave_allocations') && str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = $query->sql;
        }
    });

    $first = draftLeave($this->employee, $this->type, '2026-06-08', '2026-06-15');
    $second = draftLeave($this->employee, $this->type, '2026-06-22', '2026-06-29');
    $results = [];
    foreach ([$first, $second] as $draft) {
        try {
            app(SubmitLeaveApplication::class)->submit($draft, $this->employee->user);
            $results[] = 'ok';
        } catch (ValidationException) {
            $results[] = 'refused';
        }
    }

    expect($results)->toBe(['ok', 'refused'])
        ->and($locks)->not->toBeEmpty()
        ->and($second->fresh()->status)->toBe(ApprovalStatus::Draft);
});

// #19
it('limits Hospitalisasi by the combined sick + hospitalisation cap', function () {
    $skt = seededType('SKT');
    $hos = seededType('HOS');
    $skt->update(['requires_attachment' => 'never']);
    $hos->update(['requires_attachment' => 'never']);
    allocate($this->employee, $skt, '22');
    allocate($this->employee, $hos, '60');

    $sick = applyLeave($this->employee, $skt, '2026-06-01', '2026-06-18'); // 14 working days
    approveLeave($sick, $this->w->approver);

    expect(app(LeaveBalance::class)->on($this->employee, $hos, '2026-06-19')['available'])->toBe('46.000');

    $message = leaveValidationMessage(fn () => applyLeave($this->employee, $hos, '2026-06-22', '2026-08-28'), 'leave_type_id');
    expect($message)->toContain('Had Sakit & Hospitalisasi');
});

// #20
it('allows a negative balance down to max_negative_days with a warning', function () {
    $type = leaveTypeMake(['code' => 'NEG', 'allow_negative' => true, 'max_negative_days' => '2']);
    allocate($this->employee, $type, '1');

    $first = applyLeave($this->employee, $type, '2026-06-08', '2026-06-10');
    expect($this->leaveWarnings)->toBe([__('leave.warnings.negative_balance', ['days' => '2'])]);
    approveLeave($first, $this->w->approver);

    expect(app(LeaveBalance::class)->on($this->employee, $type, '2026-06-01')['available'])->toBe('-2.000')
        ->and(fn () => applyLeave($this->employee, $type, '2026-06-15'))->toThrow(ValidationException::class);
});
