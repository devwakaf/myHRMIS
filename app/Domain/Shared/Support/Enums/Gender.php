<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support\Enums;

enum Gender: string
{
    use TranslatableEnum;

    case Male = 'male';
    case Female = 'female';

    public static function translationKey(): string
    {
        return 'common.gender';
    }
}
