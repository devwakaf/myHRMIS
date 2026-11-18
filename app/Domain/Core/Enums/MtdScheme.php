<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum MtdScheme: string
{
    use TranslatableEnum;

    case Normal = 'normal';
    case ReturningExpert15 = 'returning_expert_15';
    case KnowledgeWorker15 = 'knowledge_worker_15';
    case CSuiteNonCitizen15 = 'c_suite_non_citizen_15';

    public static function translationKey(): string
    {
        return 'core.enums.mtd_scheme';
    }
}
