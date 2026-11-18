<?php

use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Core\Models\Lindung24Election;
use App\Domain\Shared\Audit\Activity;
use App\Domain\Shared\Dating\EffectiveDatedSeries;
use App\Domain\Shared\Lifecycle\DocumentLocked;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\InvalidTransition;
use App\Domain\Shared\Numbering\DocumentNumberGenerator;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->org = inOrg(makeOrganisation());
    $this->actor = userIn($this->org, 'pengurus_hr');
});

it('numbers documents per organisation and year', function () {
    $gen = app(DocumentNumberGenerator::class);

    expect($gen->next('TST', now()->setDate(2026, 3, 1)))->toBe('TST-2026-00001')
        ->and($gen->next('TST', now()->setDate(2026, 5, 1)))->toBe('TST-2026-00002')
        ->and($gen->next('TST', now()->setDate(2027, 1, 1)))->toBe('TST-2027-00001');

    $other = makeOrganisation();
    expect($gen->next('TST', now()->setDate(2026, 3, 1), $other->id))->toBe('TST-2026-00001');

    $hla = HolidayListAssignment::factory()->create();
    expect($hla->number)->toStartWith('HLA-');
});

it('enforces lifecycle transitions, stamps actors and requires a cancel reason', function () {
    $hla = HolidayListAssignment::factory()->create();

    $hla->transitionTo(DocumentStatus::Submitted, $this->actor);
    expect($hla->status)->toBe(DocumentStatus::Submitted)
        ->and($hla->submitted_by)->toBe($this->actor->id)
        ->and(fn () => $hla->transitionTo(DocumentStatus::Draft, $this->actor))->toThrow(InvalidTransition::class)
        ->and(fn () => $hla->transitionTo(DocumentStatus::Cancelled, $this->actor, ''))->toThrow(ValidationException::class);

    $hla->transitionTo(DocumentStatus::Cancelled, $this->actor, 'Salah senarai');
    expect($hla->fresh()->cancel_reason)->toBe('Salah senarai')
        ->and(fn () => $hla->transitionTo(DocumentStatus::Submitted, $this->actor))->toThrow(InvalidTransition::class);
});

// B8 #26
it('allows deleting drafts only and locks submitted documents', function () {
    $draft = HolidayListAssignment::factory()->create();
    $draft->delete();
    expect(HolidayListAssignment::query()->find($draft->id))->toBeNull();

    $hla = HolidayListAssignment::factory()->create();
    $hla->transitionTo(DocumentStatus::Submitted, $this->actor);

    expect(fn () => $hla->delete())->toThrow(DocumentLocked::class);
    $hla->remarks = 'ubah';
    expect(fn () => $hla->save())->toThrow(DocumentLocked::class);

    $this->actingAs($this->actor)->delete("/holiday-assignments/{$hla->id}")->assertForbidden();
});

it('keeps an effective-dated series gap-free and re-opens the predecessor on removal', function () {
    $employee = employee();
    $series = app(EffectiveDatedSeries::class);
    $make = fn (string $from) => new Lindung24Election([
        'organisation_id' => $this->org->id, 'employee_id' => $employee->id, 'status' => 'opted_in',
        'effective_month' => $from, 'effective_from' => $from,
    ]);

    $series->insert($jan = $make('2026-01-01'));
    $series->insert($jun = $make('2026-06-01'));
    $series->insert($mar = $make('2026-03-01'));

    expect($jan->fresh()->effective_to->toDateString())->toBe('2026-02-28')
        ->and($mar->fresh()->effective_to->toDateString())->toBe('2026-05-31')
        ->and($jun->fresh()->effective_to)->toBeNull()
        ->and(Lindung24Election::query()->effectiveOn('2026-04-15')->first()->id)->toBe($mar->id)
        ->and(fn () => $series->insert($make('2026-03-01')))->toThrow(ValidationException::class);

    $series->remove($mar->fresh());
    $mar->delete();
    expect($jan->fresh()->effective_to->toDateString())->toBe('2026-05-31');
});

it('writes activity log entries with the organisation', function () {
    $employee = employee();
    $employee->update(['mobile_phone' => '019-1111111']);

    $log = Activity::query()->where('subject_id', $employee->id)
        ->where('subject_type', $employee->getMorphClass())->latest('id')->first();

    expect($log->organisation_id)->toBe($this->org->id)
        ->and($log->log_name)->toBe('core');
});

it('gives Super Admin every ability through Gate::before', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    expect($admin->can('payroll.run.submit'))->toBeTrue();
});
