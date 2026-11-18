<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum WorkPassType: string
{
    use TranslatableEnum;

    case EmploymentPass = 'employment_pass';
    case TemporaryEmploymentPass = 'temporary_employment_pass';
    case ProfessionalVisitPass = 'professional_visit_pass';
    case DependantPass = 'dependant_pass';
    case ResidencePassTalent = 'residence_pass_talent';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'core.enums.work_pass_type';
    }
}
