<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Exceptions;

use App\Domain\Shared\Support\DomainError;

/**
 * A per-employee calculation failure with a BM message (spec-04 §4.1, §5.2):
 * recorded on the run employee as `failed`, never fatal to the run.
 * Message key: payroll.errors.{code}.
 */
final class PayrollCalculationException extends DomainError
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function of(string $code, array $params = []): self
    {
        return new self('payroll.errors.'.$code, $params);
    }

    public function errorCode(): string
    {
        return substr($this->translationKey, strlen('payroll.errors.'));
    }
}
