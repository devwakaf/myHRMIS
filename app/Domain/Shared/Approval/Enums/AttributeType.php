<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AttributeType: string
{
    use TranslatableEnum;

    case Number = 'number';
    case Text = 'string';
    case Enum = 'enum';
    case Boolean = 'bool';

    public static function translationKey(): string
    {
        return 'approval.attribute_types';
    }
}
