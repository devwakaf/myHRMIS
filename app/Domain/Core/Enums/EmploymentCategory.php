<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EmploymentCategory: string
{
    use TranslatableEnum;

    case Permanent = 'permanent';
    case Contract = 'contract';
    case PartTime = 'part_time';
    case Intern = 'intern';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'core.enums.employment_category';
    }
}
