<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Services\OvertimeClaimGenerator;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Monthly on the 1st at 04:00 (spec-03 §6): draft Tuntutan OT for the previous
 * month where ot_generate_drafts_monthly is on. Also used by "Jana Draf OT".
 */
final class GenerateOvertimeClaims implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly ?string $month = null,
        public readonly ?int $organisationId = null,
    ) {}

    public function handle(OvertimeClaimGenerator $generator): void
    {
        $month = $this->month !== null ? CarbonImmutable::parse($this->month) : CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $ids = $this->organisationId !== null ? [$this->organisationId]
            : Organisation::query()->where('is_active', true)->pluck('id')->all();

        foreach ($ids as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($generator, $month, $orgId): void {
                if ($this->organisationId === null && ! AttendanceSettings::current((int) $orgId)->ot_generate_drafts_monthly) {
                    return;
                }
                $generator->generateForMonth($month);
            });
        }
    }
}
