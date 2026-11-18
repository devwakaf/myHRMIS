<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Shared\Money\Num;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Support\Band;
use App\Domain\Statutory\Support\EisResult;
use App\Domain\Statutory\Support\EisTable;
use App\Domain\Statutory\Support\StatutoryAge;
use App\Domain\Statutory\Support\StatutoryPerson;

/**
 * SIP / EIS (Akta 800) (spec-04 §4.6 5c; stat §3). Eligibility by citizenship list,
 * age window and first-insured age; amounts from the band of min(wage, ceiling).
 */
final class EisCalculator
{
    public function calculate(EisTable $table, StatutoryPerson $person, string $wage): EisResult
    {
        $wage = Num::max($wage, '0');
        $age = StatutoryAge::forPeriod($person->dateOfBirth, $person->periodStart, $person->periodEnd, $table->ageSwitchRule);

        $eligible = in_array($person->citizenship->value, $table->eligibleCitizenships, true)
            && $age >= $table->minAge && $age < $table->maxAge
            && ($person->firstInsuredAge === null || $person->firstInsuredAge < $table->firstInsuredExclusionAge)
            && ! $person->eisExempt;

        if (! $eligible || ! Num::isPositive($wage)) {
            return new EisResult($eligible, Num::money($eligible ? $wage : '0'), '0.00', '0.00', $table->rateSetId, null);
        }

        $lookup = Num::min($wage, $table->wageCeiling);
        $band = Band::find($table->bands, $lookup) ?? throw new StatutoryRateException('statutory.errors.eis_band_missing', [
            'wage' => Num::money($lookup), 'code' => $table->code,
        ]);

        return new EisResult(true, Num::money($wage), Num::money($band->amount('employer', 'SIP')), Num::money($band->amount('employee', 'SIP')), $table->rateSetId, $band->toArray());
    }
}
