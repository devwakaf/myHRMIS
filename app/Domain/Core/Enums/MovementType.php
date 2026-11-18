<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum MovementType: string
{
    use TranslatableEnum;

    case Promotion = 'promotion';
    case Transfer = 'transfer';
    case Redesignation = 'redesignation';
    case Regrade = 'regrade';
    case Confirmation = 'confirmation';
    case EmploymentTypeChange = 'employment_type_change';
    case InterOrgTransfer = 'inter_org_transfer';

    public static function translationKey(): string
    {
        return 'core.enums.movement_type';
    }
}
