<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Listeners;

use App\Domain\Core\Events\OrganisationCreated;
use App\Domain\Payroll\Actions\SeedPayrollDefaults;
use Illuminate\Events\Dispatcher;

/**
 * Payroll's reactions to Core events: per-Organisasi payroll defaults
 * (spec-04 §7.3) seeded synchronously inside CreateOrganisation.
 */
final class PayrollEventSubscriber
{
    public function onOrganisationCreated(OrganisationCreated $event): void
    {
        app(SeedPayrollDefaults::class)->handle($event->organisation->id);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrganisationCreated::class => 'onOrganisationCreated',
        ];
    }
}
