<?php

declare(strict_types=1);

namespace App\Domain\Shared\Organisation;

use RuntimeException;

final class MissingOrganisationContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No active organisation context is set; org-scoped data cannot be queried.');
    }
}
