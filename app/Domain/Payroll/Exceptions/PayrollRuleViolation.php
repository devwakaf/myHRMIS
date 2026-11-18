<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Exceptions;

use App\Domain\Shared\Support\DomainError;

/** A Payroll business rule refused an action; the message is a payroll.* translation key. */
final class PayrollRuleViolation extends DomainError {}
