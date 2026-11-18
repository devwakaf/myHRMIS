<?php

declare(strict_types=1);

namespace App\Domain\Leave\Exceptions;

use App\Domain\Shared\Support\DomainError;

/** Ledger rows are never updated or deleted (spec-02 §2.6); write a reversal instead. */
final class LedgerIsImmutable extends DomainError {}
