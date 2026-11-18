<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Statutory\Enums\AgeSwitchRule;
use App\Domain\Statutory\Enums\CitizenshipGroup;
use App\Domain\Statutory\Enums\EpfBonusBase;
use App\Domain\Statutory\Enums\EpfPart;
use App\Domain\Statutory\Enums\EpfPercentRounding;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Enums\RateSetStatus;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Support\Band;
use App\Domain\Statutory\Support\EisTable;
use App\Domain\Statutory\Support\EpfRule;
use App\Domain\Statutory\Support\EpfTable;
use App\Domain\Statutory\Support\HrdcTable;
use App\Domain\Statutory\Support\MinimumWage;
use App\Domain\Statutory\Support\MtdBand;
use App\Domain\Statutory\Support\MtdTable;
use App\Domain\Statutory\Support\ReliefTable;
use App\Domain\Statutory\Support\SocsoTable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the rate set of a scheme effective on a date (A9; only unverified or
 * verified sets resolve) and loads its tables into memory. Memoised per instance
 * (bound as scoped), so one payroll job reads each table once.
 */
final class StatutoryRateRepository
{
    /** @var array<string, StatutoryRateSet|null> */
    private array $sets = [];

    /** @var array<int, object> */
    private array $tables = [];

    public function setFor(RateScheme $scheme, CarbonInterface|string $date): ?StatutoryRateSet
    {
        $day = CarbonImmutable::parse($date instanceof CarbonInterface ? $date->format('Y-m-d') : $date)->toDateString();
        $key = $scheme->value.'|'.$day;

        if (! array_key_exists($key, $this->sets)) {
            $this->sets[$key] = StatutoryRateSet::query()
                ->where('scheme', $scheme->value)
                ->whereIn('status', [RateSetStatus::Unverified->value, RateSetStatus::Verified->value])
                ->effectiveOn($day)
                ->orderByDesc('effective_from')
                ->first();
        }

        return $this->sets[$key];
    }

    public function requireSet(RateScheme $scheme, CarbonInterface $date): StatutoryRateSet
    {
        return $this->setFor($scheme, $date) ?? throw new StatutoryRateException('statutory.errors.no_rate_set', [
            'scheme' => $scheme->label(), 'date' => $date->format('d/m/Y'),
        ]);
    }

    public function epf(StatutoryRateSet $set): EpfTable
    {
        /** @var EpfTable */
        return $this->tables[$set->id] ??= $this->loadEpf($set);
    }

    public function socso(StatutoryRateSet $set): SocsoTable
    {
        /** @var SocsoTable */
        return $this->tables[$set->id] ??= $this->loadSocso($set);
    }

    public function eis(StatutoryRateSet $set): EisTable
    {
        /** @var EisTable */
        return $this->tables[$set->id] ??= $this->loadEis($set);
    }

    public function mtd(StatutoryRateSet $set): MtdTable
    {
        /** @var MtdTable */
        return $this->tables[$set->id] ??= $this->loadMtd($set);
    }

    public function reliefs(StatutoryRateSet $set): ReliefTable
    {
        /** @var ReliefTable */
        return $this->tables[$set->id] ??= new ReliefTable(
            $set->id,
            DB::table('tp1_relief_codes')->where('rate_set_id', $set->id)->pluck('annual_cap', 'code')->map(fn ($v) => $v === null ? null : (string) $v)->all(),
            DB::table('tax_exemption_caps')->where('rate_set_id', $set->id)->pluck('annual_cap', 'code')->map(fn ($v) => $v === null ? null : (string) $v)->all(),
        );
    }

    public function hrdc(StatutoryRateSet $set): HrdcTable
    {
        /** @var HrdcTable */
        return $this->tables[$set->id] ??= new HrdcTable($set->id, DB::table('hrdc_levy_rules')->where('rate_set_id', $set->id)
            ->orderBy('min_headcount')->get()->map(fn (object $r) => [
                'min_headcount' => (int) $r->min_headcount,
                'max_headcount' => $r->max_headcount === null ? null : (int) $r->max_headcount,
                'rate' => (string) $r->rate,
                'mandatory' => (bool) $r->mandatory,
                'base_citizenships' => array_values((array) json_decode((string) $r->base_citizenships, true)),
                'rounding_mode' => (string) $r->rounding_mode,
            ])->values()->all());
    }

    public function minimumWage(StatutoryRateSet $set): ?MinimumWage
    {
        $row = DB::table('minimum_wages')->where('rate_set_id', $set->id)->first();

        return $row === null ? null : new MinimumWage($set->id, (string) $row->monthly, (string) $row->hourly);
    }

    public function flush(): void
    {
        $this->sets = [];
        $this->tables = [];
    }

    private function loadEpf(StatutoryRateSet $set): EpfTable
    {
        $params = DB::table('epf_params')->where('rate_set_id', $set->id)->first();
        $rules = DB::table('epf_rate_rules')->where('rate_set_id', $set->id)->orderBy('id')->get()->map(fn (object $r) => new EpfRule(
            EpfPart::from((string) $r->part),
            CitizenshipGroup::from((string) $r->citizenship_group),
            (int) $r->age_min,
            $r->age_max === null ? null : (int) $r->age_max,
            $r->wage_threshold === null ? null : (string) $r->wage_threshold,
            (string) $r->employer_rate_low,
            (string) $r->employer_rate_high,
            (string) $r->employee_rate,
            $r->table_limit === null ? null : (string) $r->table_limit,
            EpfPercentRounding::from((string) $r->percent_rounding),
            (bool) $r->bonus_rule,
            EpfBonusBase::from((string) $r->bonus_rule_base),
            (bool) $r->uses_table,
        ))->values()->all();

        $bands = [];
        foreach (DB::table('epf_wage_bands')->where('rate_set_id', $set->id)->orderBy('part')->orderBy('wage_from')->get() as $b) {
            $bands[(string) $b->part][] = new Band((string) $b->wage_from, (string) $b->wage_to, [
                'employer' => (string) $b->employer_amount, 'employee' => (string) $b->employee_amount,
            ]);
        }

        return new EpfTable(
            $set->id, $set->code,
            (int) ($params->min_age ?? 14), (int) ($params->max_age ?? 75),
            AgeSwitchRule::tryFrom((string) ($params->age_switch_rule ?? '')) ?? AgeSwitchRule::MonthAfterBirthday,
            (int) ($params->work_pass_stop_months ?? 2),
            $rules, $bands,
        );
    }

    private function loadSocso(StatutoryRateSet $set): SocsoTable
    {
        $p = DB::table('socso_params')->where('rate_set_id', $set->id)->first()
            ?? throw new StatutoryRateException('statutory.errors.params_missing', ['code' => $set->code]);
        $bands = DB::table('socso_bands')->where('rate_set_id', $set->id)->orderBy('wage_from')->get()->map(fn (object $b) => new Band(
            (string) $b->wage_from, (string) $b->wage_to, [
                'cat1_employer' => self::str($b->cat1_employer),
                'cat1_employee' => self::str($b->cat1_employee),
                'cat1_skbbk_employee' => self::str($b->cat1_skbbk_employee),
                'cat2_employer' => self::str($b->cat2_employer),
                'cat2_skbbk_employee' => self::str($b->cat2_skbbk_employee),
            ],
        ))->values()->all();

        return new SocsoTable(
            $set->id, $set->code, (string) $p->wage_ceiling, (int) $p->cat1_max_age, (int) $p->first_insured_cat2_age,
            AgeSwitchRule::tryFrom((string) $p->age_switch_rule) ?? AgeSwitchRule::MonthAfterBirthday,
            $p->skbbk_start === null ? null : CarbonImmutable::parse((string) $p->skbbk_start),
            $bands,
        );
    }

    private function loadEis(StatutoryRateSet $set): EisTable
    {
        $p = DB::table('eis_params')->where('rate_set_id', $set->id)->first()
            ?? throw new StatutoryRateException('statutory.errors.params_missing', ['code' => $set->code]);
        $bands = DB::table('eis_bands')->where('rate_set_id', $set->id)->orderBy('wage_from')->get()->map(fn (object $b) => new Band(
            (string) $b->wage_from, (string) $b->wage_to, ['employer' => (string) $b->employer_amount, 'employee' => (string) $b->employee_amount],
        ))->values()->all();

        return new EisTable(
            $set->id, $set->code, (string) $p->wage_ceiling, (int) $p->min_age, (int) $p->max_age, (int) $p->first_insured_exclusion_age,
            array_values(array_map('strval', (array) json_decode((string) $p->eligible_citizenships, true))),
            AgeSwitchRule::tryFrom((string) $p->age_switch_rule) ?? AgeSwitchRule::MonthAfterBirthday,
            $bands,
        );
    }

    private function loadMtd(StatutoryRateSet $set): MtdTable
    {
        $p = DB::table('mtd_parameters')->where('rate_set_id', $set->id)->first()
            ?? throw new StatutoryRateException('statutory.errors.params_missing', ['code' => $set->code]);
        $bands = DB::table('mtd_tax_bands')->where('rate_set_id', $set->id)->orderBy('p_above')->get()->map(fn (object $b) => new MtdBand(
            (string) $b->p_above, self::str($b->p_upto), (string) $b->m, (string) $b->r, (string) $b->b_cat1_3, (string) $b->b_cat2, (bool) $b->no_tax,
        ))->values()->all();

        return new MtdTable(
            $set->id, $set->code, (int) $p->tax_year, $bands,
            (string) $p->relief_individual, (string) $p->relief_spouse, (string) $p->relief_disabled_self, (string) $p->relief_disabled_spouse,
            (string) $p->child_basic, (string) $p->child_higher_ed, (string) $p->child_disabled, (string) $p->child_disabled_higher_ed,
            (string) $p->epf_relief_cap, (string) $p->pcb_minimum, (string) $p->additional_pcb_minimum, (string) $p->non_resident_rate,
            (bool) $p->auto_socso_eis_relief, (string) $p->socso_eis_relief_cap,
        );
    }

    private static function str(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
