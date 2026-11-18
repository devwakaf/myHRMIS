<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Models\ShiftScheduleAssignment;
use App\Domain\Attendance\Services\RosterGenerator;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Daily 00:15 (spec-03 §4.9, §6): generate every enabled roster up to the horizon.
 * One failing roster (e.g. a locked date) does not stop the others.
 */
final class ExtendRosters implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(RosterGenerator $generator): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($generator): void {
                foreach (ShiftScheduleAssignment::query()->where('is_enabled', true)->get() as $roster) {
                    try {
                        $generator->extend($roster);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }
    }
}
