<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\Lindung24Status;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Lindung24Election;
use App\Domain\Shared\Dating\EffectiveDatedSeries;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * LINDUNG 24 Jam election (B1.9). Foreign workers cannot opt out (mandatory).
 * Opt-out windows for locals are a Statutory setting (M4, UNVERIFIED-STAT); until
 * then any month is accepted for locals.
 */
final class RecordLindung24Election
{
    public function __construct(private readonly EffectiveDatedSeries $series) {}

    public function handle(Employee $employee, Lindung24Status $status, string $month, User $actor, ?string $note = null): Lindung24Election
    {
        if ($status === Lindung24Status::OptedOut && $employee->isForeign()) {
            throw DomainValidation::withMessages(['status' => __('core.lindung24.foreign_cannot_opt_out')]);
        }

        $first = CarbonImmutable::parse($month)->startOfMonth();

        $election = new Lindung24Election([
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'status' => $status,
            'effective_month' => $first->toDateString(),
            'effective_from' => $first->toDateString(),
            'recorded_by' => $actor->id,
            'note' => $note,
        ]);

        $this->series->insert($election);

        return $election;
    }
}
