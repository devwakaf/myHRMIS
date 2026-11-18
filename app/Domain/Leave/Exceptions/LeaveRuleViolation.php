<?php

declare(strict_types=1);

namespace App\Domain\Leave\Exceptions;

use App\Domain\Shared\Support\DomainError;

/** A Cuti business rule refused the operation; the message is a leave.* translation key. */
final class LeaveRuleViolation extends DomainError {}
