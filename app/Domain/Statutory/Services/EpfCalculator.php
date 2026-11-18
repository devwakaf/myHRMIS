<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Money\Rounding;
use App\Domain\Statutory\Enums\CitizenshipGroup;
use App\Domain\Statutory\Enums\EpfBonusBase;
use App\Domain\Statutory\Enums\EpfPercentRounding;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Support\EpfResult;
use App\Domain\Statutory\Support\EpfRule;
use App\Domain\Statutory\Support\EpfTable;
use App\Domain\Statutory\Support\StatutoryAge;
use App\Domain\Statutory\Support\StatutoryPerson;

/**
 * KWSP contribution (spec-04 §4.6 5a; stat §1). Table method for wages up to the
 * table limit (bands verbatim), percentage method above it and for Part F, the
 * bonus-over-threshold rule, voluntary higher rates and K1 (EPF on normal pay).
 * Rounding variants come from the rule row (UNVERIFIED-STAT), never constants.
 */
final class EpfCalculator
{
    /**
     * @param  string  $wage  W: Σ epf_wage lines net of wage reductions
     * @param  string  $wageExclBonus  W_nb: W − Σ is_epf_bonus lines
     * @param  string  $wageNormal  EPF wage of normal remuneration only (for PCB K1)
     */
    public function calculate(
        EpfTable $table,
        StatutoryPerson $person,
        string $wage,
        string $wageExclBonus,
        string $wageNormal,
        ?string $employeeRateOverride = null,
        ?string $employerRateOverride = null,
    ): EpfResult {
        $wage = Num::max($wage, '0');
        $wageExclBonus = Num::max($wageExclBonus, '0');
        $wageNormal = Num::max($wageNormal, '0');

        if ($person->kwspExempt) {
            return EpfResult::none($wage, $wageExclBonus, $table->rateSetId);
        }

        $age = StatutoryAge::forPeriod($person->dateOfBirth, $person->periodStart, $person->periodEnd, $table->ageSwitchRule);
        if ($age < $table->minAge || $age >= $table->maxAge) {
            return EpfResult::none($wage, $wageExclBonus, $table->rateSetId);
        }

        $group = self::group($person->citizenship);
        $warnings = [];
        if ($group === CitizenshipGroup::Foreign && $person->workPassExpiry !== null
            && $person->periodStart->greaterThanOrEqualTo($person->workPassExpiry->subMonths($table->workPassStopMonths))) {
            $warnings[] = (string) __('payroll.warnings.epf_work_pass_stop', ['date' => $person->workPassExpiry->format('d/m/Y')]);

            return EpfResult::none($wage, $wageExclBonus, $table->rateSetId, $warnings);
        }

        $rule = $table->rule($group, $age);
        if ($rule === null) {
            return EpfResult::none($wage, $wageExclBonus, $table->rateSetId);
        }

        $shares = $this->shares($table, $rule, $wage, $wageExclBonus, true);
        $normal = $this->shares($table, $rule, $wageNormal, $wageNormal, false);

        $employee = $this->withOverride($shares['employee'], $wage, $employeeRateOverride);
        $employer = $this->withOverride($shares['employer'], $wage, $employerRateOverride);
        $employeeNormal = Num::min($this->withOverride($normal['employee'], $wageNormal, $employeeRateOverride), $employee);

        return new EpfResult(
            $rule->part, $shares['method'], Num::money($wage), Num::money($wageExclBonus),
            Num::money($employee), Num::money($employer), Num::money($employeeNormal), $table->rateSetId, $warnings,
            ['part' => $rule->part->value, 'age' => $age, 'band' => $shares['band'], 'bonus_rule' => $shares['bonus_rule'], 'rate_set' => $table->code],
        );
    }

    public static function group(CitizenshipStatus $citizenship): CitizenshipGroup
    {
        return match ($citizenship) {
            CitizenshipStatus::Citizen => CitizenshipGroup::Citizen,
            CitizenshipStatus::PermanentResident, CitizenshipStatus::ForeignPre1998 => CitizenshipGroup::PrOrPre1998,
            CitizenshipStatus::Foreign => CitizenshipGroup::Foreign,
        };
    }

    /**
     * @return array{employee: string, employer: string, method: string, band: array{from: string, to: string}|null, bonus_rule: bool}
     */
    private function shares(EpfTable $table, EpfRule $rule, string $wage, string $wageExclBonus, bool $applyBonusRule): array
    {
        if (! Num::isPositive($wage)) {
            return ['employee' => '0', 'employer' => '0', 'method' => 'none', 'band' => null, 'bonus_rule' => false];
        }

        $bonusApplies = $applyBonusRule && $rule->bonusRule && $rule->wageThreshold !== null
            && Num::cmp($wageExclBonus, $rule->wageThreshold) <= 0 && Num::cmp($wage, $rule->wageThreshold) > 0;

        $withinTable = $rule->usesTable && ($rule->tableLimit === null || Num::cmp($wage, $rule->tableLimit) <= 0);
        if ($withinTable) {
            $band = $table->band($rule->part, $wage) ?? throw new StatutoryRateException('statutory.errors.epf_band_missing', [
                'part' => $rule->part->value, 'wage' => Num::money($wage), 'code' => $table->code,
            ]);
            $employer = $band->amount('employer', 'KWSP');
            if ($bonusApplies) {
                $base = $rule->bonusBase === EpfBonusBase::BandUpper ? $band->to : $wage;
                $employer = (string) Rounding::ceilRinggit(Num::percent($base, $rule->employerRateLow));
            }

            return ['employee' => $band->amount('employee', 'KWSP'), 'employer' => $employer, 'method' => 'table', 'band' => $band->toArray(), 'bonus_rule' => $bonusApplies];
        }

        $low = $rule->wageThreshold === null || Num::cmp($wage, $rule->wageThreshold) <= 0 || $bonusApplies;
        $employerRate = $low ? $rule->employerRateLow : $rule->employerRateHigh;
        $employee = (string) Rounding::ceilRinggit(Num::percent($wage, $rule->employeeRate));

        if ($rule->percentRounding === EpfPercentRounding::CeilTotalThenSplit) {
            $total = (string) Rounding::ceilRinggit(Num::percent($wage, Num::add($employerRate, $rule->employeeRate)));
            $employer = Num::max(Num::sub($total, $employee), '0');
        } else {
            $employer = (string) Rounding::ceilRinggit(Num::percent($wage, $employerRate));
        }

        return ['employee' => $employee, 'employer' => $employer, 'method' => 'percent', 'band' => null, 'bonus_rule' => $bonusApplies];
    }

    /** Voluntary higher rate (stat §1.3): max(statutory, ceilRinggit(W × rate)). */
    private function withOverride(string $statutory, string $wage, ?string $rate): string
    {
        if ($rate === null || ! Num::isPositive($wage)) {
            return $statutory;
        }

        return Num::max($statutory, (string) Rounding::ceilRinggit(Num::percent($wage, $rate)));
    }
}
