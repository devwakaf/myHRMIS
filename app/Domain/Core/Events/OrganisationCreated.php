<?php

declare(strict_types=1);

namespace App\Domain\Core\Events;

use App\Domain\Core\Models\Organisation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new Organisasi was created (B1.1 "On create"). Dispatched synchronously inside
 * CreateOrganisation's transaction and organisation context, so module listeners
 * (e.g. Leave defaults, spec-02 §6.4) seed atomically with the organisation.
 */
final class OrganisationCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Organisation $organisation,
    ) {}
}
