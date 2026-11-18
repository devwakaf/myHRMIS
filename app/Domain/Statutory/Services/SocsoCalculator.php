<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Core\Enums\Lindung24Status;
use App\Domain\Shared\Money\Num;
use App\Domain\Statutory\Enums\SocsoCategory;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Support\Band;
use App\Domain\Statutory\Support\SocsoResult;
use App\Domain\Statutory\Support\SocsoTable;
use App\Domain\Statutory\Support\StatutoryAge;
use App\Domain\Statutory\Support\StatutoryPerson;

/**
 * PERKESO Akta 4 + LINDUNG 24 Jam (SKBBK) (spec-04 §4.6 5b; stat §2). Amounts are
 * read verbatim from the band of min(wage, ceiling); nothing is recomputed.
 */
final class SocsoCalculator
{
    public function calculate(SocsoTable $table, StatutoryPerson $person, string $wage): SocsoResult
    {
        $wage = Num::max($wage, '0');
        $age = StatutoryAge::forPeriod($person->dateOfBirth, $person->periodStart, $person->periodEnd, $table->ageSwitchRule);
        $category = $age >= $table->cat1MaxAge
            || ($person->firstInsuredAge !== null && $person->firstInsuredAge >= $table->firstInsuredCat2Age)
            ? SocsoCategory::Two : SocsoCategory::One;

        $skbbkApplies = $table->skbbkStart !== null
            && $person->periodStart->greaterThanOrEqualTo($table->skbbkStart)
            && ($person->isForeign() || $person->lindung24 !== Lindung24Status::OptedOut);

        if (! Num::isPositive($wage)) {
            return new SocsoResult($category, '0.00', '0.00', '0.00', '0.00', $skbbkApplies, $table->rateSetId, null);
        }

        $lookup = Num::min($wage, $table->wageCeiling);
        $band = Band::find($table->bands, $lookup) ?? throw new StatutoryRateException('statutory.errors.socso_band_missing', [
            'wage' => Num::money($lookup), 'code' => $table->code,
        ]);

        if ($category === SocsoCategory::One) {
            $employer = $band->amount('cat1_employer', 'PERKESO');
            $employee = $band->amount('cat1_employee', 'PERKESO');
            $skbbk = $skbbkApplies ? $band->amount('cat1_skbbk_employee', 'SKBBK') : '0';
        } else {
            $employer = $band->amount('cat2_employer', 'PERKESO');
            $employee = '0';
            $skbbk = $skbbkApplies ? $band->amount('cat2_skbbk_employee', 'SKBBK') : '0';
        }

        return new SocsoResult(
            $category, Num::money($wage), Num::money($employer), Num::money($employee), Num::money($skbbk),
            $skbbkApplies, $table->rateSetId, $band->toArray(),
        );
    }
}
