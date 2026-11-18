<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Exceptions;

use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Support\DomainError;

final class ApprovalCompletionBlocked extends DomainError
{
    public function __construct(public readonly ApprovalCheck $check)
    {
        parent::__construct((string) $check->translationKey, $check->params);
    }
}
