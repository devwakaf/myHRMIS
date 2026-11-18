<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Exceptions;

use App\Domain\Shared\Support\DomainError;

/** A statutory table is missing, incomplete or not effective (message key: statutory.errors.*). */
final class StatutoryRateException extends DomainError {}
