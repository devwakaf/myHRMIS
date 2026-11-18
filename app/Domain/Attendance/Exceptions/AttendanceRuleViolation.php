<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Exceptions;

use App\Domain\Shared\Support\DomainError;

/** A Kehadiran business rule refused the action (translated message, flash error / 422). */
class AttendanceRuleViolation extends DomainError {}
