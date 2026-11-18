<?php

declare(strict_types=1);

namespace App\Domain\Core\Exceptions;

use App\Domain\Shared\Support\DomainError;

final class NoHolidayListAssigned extends DomainError {}
