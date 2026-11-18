<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

use App\Domain\Shared\Support\DomainError;

/**
 * Formula engine error (spec-04 §3.7). `code` is the catalogue key; the message
 * is lang/{ms,en}/payroll.php `formula.{code}`.
 */
final class FormulaError extends DomainError
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public readonly string $errorCode,
        array $params = [],
    ) {
        parent::__construct('payroll.formula.'.$errorCode, $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function of(string $code, array $params = []): self
    {
        return new self($code, $params);
    }
}
