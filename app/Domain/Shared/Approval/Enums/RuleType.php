<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum RuleType: string
{
    use TranslatableEnum;

    case NamedApprover = 'named_approver';
    case DepartmentApprovers = 'department_approvers';
    case NamedOrDepartment = 'named_or_department';
    case DepartmentHead = 'department_head';
    case ReportsTo = 'reports_to';
    case Role = 'role';
    case SpecificUser = 'specific_user';

    public static function translationKey(): string
    {
        return 'approval.rule_types';
    }
}
