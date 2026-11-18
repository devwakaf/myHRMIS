<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EmergencyRelationship: string
{
    use TranslatableEnum;

    case Spouse = 'spouse';
    case Parent = 'parent';
    case Sibling = 'sibling';
    case Child = 'child';
    case Relative = 'relative';
    case Friend = 'friend';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'core.enums.emergency_relationship';
    }
}
