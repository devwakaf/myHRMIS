<?php

// spec-02 §7 cases 34–35 (per-event types and eligibility, §3.10).

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->w = leaveWorld('2026-06-01');
});

function leaveErrorsOf(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// #34
it('applies the Paterniti eligibility, service, event-count and notice rules', function () {
    $ptr = seededType('PTR');

    $female = staff(['gender' => 'female']);
    expect(leaveErrorsOf(fn () => applyLeave($female, $ptr, '2026-08-03', '2026-08-09'))['leave_type_id'][0])->toBe(__('leave.errors.not_eligible_gender'));

    $newcomer = staff(['date_of_joining' => '2025-11-13']); // 200 days of service on 01/06/2026
    expect(leaveErrorsOf(fn () => applyLeave($newcomer, $ptr, '2026-06-01', '2026-06-07'))['leave_type_id'][0])->toBe(__('leave.errors.min_service', ['days' => 365]));

    $father = staff();
    $first = applyLeave($father, $ptr, '2026-06-15', '2026-06-21'); // notice 14 days < 30
    expect($this->leaveWarnings)->toBe([__('leave.errors.min_notice', ['days' => 30])])
        ->and((string) $first->total_days)->toBe('7.000');

    foreach (['2026-08-03', '2026-09-07', '2026-10-05', '2026-11-02'] as $from) {
        applyLeave($father, $ptr, $from, CarbonImmutable::parse($from)->addDays(6)->toDateString());
    }
    expect(leaveErrorsOf(fn () => applyLeave($father, $ptr, '2026-12-07', '2026-12-13'))['leave_type_id'][0])->toBe(__('leave.errors.max_events_service', ['count' => 5]));
});

// #35
it('counts a Bersalin application with a different event date as a new event', function () {
    $bsl = seededType('BSL');
    $bsl->update(['max_events_per_service' => 1]);
    $mother = staff(['gender' => 'female']);

    applyLeave($mother, $bsl, '2026-06-01', '2026-07-31', ['event_date' => '2026-06-01']);

    expect(leaveErrorsOf(fn () => applyLeave($mother, $bsl, '2026-08-03', '2026-08-31', ['event_date' => '2026-08-03'])))->toHaveKey('leave_type_id')
        ->and(leaveErrorsOf(fn () => applyLeave($mother, $bsl, '2026-08-01', '2026-09-06')))->toHaveKey('event_date');

    // HR splitting the same confinement (same event date) is one event
    $split = applyLeave($mother, $bsl, '2026-08-01', '2026-09-06', ['event_date' => '2026-06-01'], $this->w->hr);
    expect($split->is_on_behalf)->toBeTrue()->and($split->status->value)->toBe('pending');
});
