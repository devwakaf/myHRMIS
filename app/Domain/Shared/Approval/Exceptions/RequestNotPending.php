<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Exceptions;

use App\Domain\Shared\Support\DomainError;

final class RequestNotPending extends DomainError {}
