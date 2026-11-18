<?php

// spec-02 §7 cases 21–25 (carry-forward and expiry, §3.11–§3.12).

use App\Domain\Leave\Actions\EncashmentActions;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Jobs\ExpireLeaveLots;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Reports\LeaveReports;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Services\LotExpirer;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->w = leaveWorld('2025-12-01');
    leaveHolidayList($this->w->hr, [], [], 2025);
    $this->p2025 = LeavePeriod::query()->whereDate('from_date', '2025-01-01')->firstOrFail();
    $this->p2026 = $this->w->period;
    $this->employee = staff(['date_of_joining' => '2020-01-01']);
    $this->type = leaveTypeMake(['code' => 'TAHX', 'allow_carry_forward' => true, 'max_carry_forward_days' => '5', 'carry_forward_expiry_days' => 90, 'allow_encashment' => true]);
    $this->expire = fn () => (new ExpireLeaveLots)->handle(app(LotExpirer::class));
    $this->kinds = fn (LeaveAllocation $a, MovementKind $k) => LeaveMovement::query()->where('leave_allocation_id', $a->id)->where('kind', $k->value)->get();
});

/** 2025: 12 credited, 4 taken, 2 encashed → 6 unused. */
function leaveUse2025(object $t): LeaveAllocation
{
    $old = allocate($t->employee, $t->type, '12', $t->p2025);
    approveLeave(applyLeave($t->employee, $t->type, '2025-12-01', '2025-12-04'), $t->w->approver);
    $enc = app(EncashmentActions::class);
    $draft = $enc->saveDraft(null, ['leave_allocation_id' => $old->id, 'encashment_date' => '2025-12-15', 'days' => '2', 'monthly_basic' => '3000.00']);
    $enc->submit($draft['encashment'], $t->w->hr);

    return $old;
}

// #21
it('carries up to the maximum, expires the leftover and adds it to the new period', function () {
    $old = leaveUse2025($this);
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:10'));
    $new = allocate($this->employee, $this->type, '12', $this->p2026);

    $out = ($this->kinds)($old, MovementKind::CarryOut);
    $in = ($this->kinds)($new, MovementKind::CarryIn);
    expect($out)->toHaveCount(1)->and((string) $out[0]->days)->toBe('-5.000')
        ->and($in)->toHaveCount(1)->and((string) $in[0]->days)->toBe('5.000')
        ->and($in[0]->date_from->toDateString())->toBe('2026-01-01')
        ->and($in[0]->date_to->toDateString())->toBe('2026-03-31');

    ($this->expire)();
    $expiry = ($this->kinds)($old, MovementKind::Expiry);
    expect($expiry)->toHaveCount(1)->and((string) $expiry[0]->days)->toBe('-1.000')
        ->and(app(LeaveBalance::class)->on($this->employee, $this->type, '2026-01-01')['available'])->toBe('17.000');
});

// #22
it('trims the carry to the per-period ceiling', function () {
    $this->type->update(['max_days_per_period' => '16']);
    leaveUse2025($this);
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:10'));
    $new = allocate($this->employee, $this->type, '12', $this->p2026);

    expect((string) ($this->kinds)($new, MovementKind::CarryIn)[0]->days)->toBe('4.000');
});

// #23
it('uses carried days first and expires what is left when the carried lot ends', function () {
    leaveUse2025($this);
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:10'));
    $new = allocate($this->employee, $this->type, '12', $this->p2026);
    ($this->expire)();
    $carried = ($this->kinds)($new, MovementKind::CarryIn)[0];
    $main = app(LeaveLedger::class)->mainLot($new);

    $leave = approveLeave(applyLeave($this->employee, $this->type, '2026-03-30', '2026-04-03'), $this->w->approver);
    $debits = LeaveMovement::query()->fromSource($leave)->where('kind', MovementKind::Application->value)->get()->keyBy('lot_id');
    expect((string) $debits[$carried->id]->days)->toBe('-2.000')
        ->and((string) $debits[$main->id]->days)->toBe('-3.000');

    $this->travelTo(CarbonImmutable::parse('2026-04-01 00:20'));
    ($this->expire)();
    $expiry = LeaveMovement::query()->where('lot_id', $carried->id)->where('kind', MovementKind::Expiry->value)->get();
    expect($expiry)->toHaveCount(1)->and((string) $expiry[0]->days)->toBe('-3.000');
});

// #24
it('reclaims an expired carried lot for a retro approval and re-expires the rest', function () {
    leaveUse2025($this);
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:10'));
    $new = allocate($this->employee, $this->type, '12', $this->p2026);
    ($this->expire)();
    $carried = ($this->kinds)($new, MovementKind::CarryIn)[0];

    $this->travelTo(CarbonImmutable::parse('2026-04-01 00:20'));
    ($this->expire)();
    $firstExpiry = LeaveMovement::query()->where('lot_id', $carried->id)->where('kind', MovementKind::Expiry->value)->firstOrFail();
    expect((string) $firstExpiry->days)->toBe('-5.000');

    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00'));
    $retro = applyLeave($this->employee, $this->type, '2026-03-16', null, [], $this->w->hr); // HR on behalf, back-dated
    approveLeave($retro, $this->w->approver);

    $rows = LeaveMovement::query()->where('lot_id', $carried->id)->orderBy('id')->get();
    $reversal = $rows->firstWhere('reverses_movement_id', $firstExpiry->id);
    expect($reversal)->not->toBeNull()
        ->and((string) $reversal->days)->toBe('5.000')
        ->and((string) $rows->firstWhere('kind', MovementKind::Application)->days)->toBe('-1.000')
        ->and((string) $rows->where('kind', MovementKind::Expiry)->last()->days)->toBe('-4.000')
        ->and(app(LeaveLedger::class)->remaining($carried))->toBe('0.000')
        ->and(LeaveMovement::query()->whereKey($firstExpiry->id)->exists())->toBeTrue();
});

// #25
it('does not carry already-carried days again when carry_includes_carried is off', function () {
    $this->travelTo(CarbonImmutable::parse('2025-12-01'));
    allocate($this->employee, $this->type, '5', $this->p2025);
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:10'));
    $mid = allocate($this->employee, $this->type, '0', $this->p2026);
    expect((string) ($this->kinds)($mid, MovementKind::CarryIn)[0]->days)->toBe('5.000');

    $p2027 = LeavePeriod::factory()->year(2027)->create();
    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:10'));
    $next = allocate($this->employee, $this->type, '8', $p2027);

    expect(($this->kinds)($next, MovementKind::CarryIn))->toHaveCount(0)
        ->and($next->carry_processed_at)->not->toBeNull();
});

// §6.2 report 1: closing = opening + credited − taken − encashed − reduced − carried out − expired
it('derives the Baki Cuti Kakitangan report from the ledger', function () {
    leaveUse2025($this);
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:10'));
    allocate($this->employee, $this->type, '12', $this->p2026);
    ($this->expire)();

    $reports = app(LeaveReports::class);
    $y2025 = $reports->balanceSummary(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-12-31'), [$this->employee->id], [$this->type->id])[0];
    expect($y2025)->toMatchArray(['opening' => '0', 'credited' => '12.000', 'taken' => '4.000', 'encashed' => '2.000', 'carried_out' => '0', 'expired' => '0', 'closing' => '6.000']);

    $jan = $reports->balanceSummary(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'), [$this->employee->id], [$this->type->id])[0];
    expect($jan)->toMatchArray(['opening' => '6.000', 'credited' => '17.000', 'carried_out' => '5.000', 'expired' => '1.000', 'closing' => '17.000', 'available' => '17.000']);
});
