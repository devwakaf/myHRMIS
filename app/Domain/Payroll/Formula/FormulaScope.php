<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

use BcMath\Number;

/**
 * Variable bag a formula is evaluated against (spec-04 §3.4: the full-cycle bag
 * or the actual bag). Unknown names raise unknown_identifier.
 */
interface FormulaScope
{
    public function value(string $name): Number|bool|string;

    /** Full-cycle (un-prorated) value of a component: FULL(CODE). */
    public function full(string $code): Number;
}
