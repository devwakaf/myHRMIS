<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Statutory\Enums\RateScheme;

/**
 * Which child tables belong to each scheme and their CSV columns (spec-04 §7.1).
 * CSV files: UTF-8, header row, dot decimals, no thousands separators; an empty
 * cell means "not keyed yet" (nullable columns only).
 */
final class RateTableCatalogue
{
    /** table => columns imported from CSV (rate_set_id is added). */
    public const COLUMNS = [
        'epf_wage_bands' => ['part', 'wage_from', 'wage_to', 'employer_amount', 'employee_amount'],
        'epf_rate_rules' => ['part', 'citizenship_group', 'age_min', 'age_max', 'wage_threshold', 'employer_rate_low', 'employer_rate_high',
            'employee_rate', 'table_limit', 'percent_rounding', 'bonus_rule', 'bonus_rule_base', 'uses_table'],
        'socso_bands' => ['wage_from', 'wage_to', 'cat1_employer', 'cat1_employee', 'cat1_skbbk_employee', 'cat2_employer', 'cat2_skbbk_employee'],
        'eis_bands' => ['wage_from', 'wage_to', 'employer_amount', 'employee_amount'],
        'mtd_tax_bands' => ['p_above', 'p_upto', 'm', 'r', 'b_cat1_3', 'b_cat2', 'no_tax'],
        'tp1_relief_codes' => ['code', 'name_ms', 'name_en', 'annual_cap', 'notes'],
        'tax_exemption_caps' => ['code', 'name_ms', 'name_en', 'annual_cap'],
        'hrdc_levy_rules' => ['min_headcount', 'max_headcount', 'rate', 'mandatory', 'base_citizenships', 'rounding_mode'],
        'minimum_wages' => ['monthly', 'hourly', 'daily_6day', 'daily_5day', 'daily_4day', 'scope'],
    ];

    /** Columns that may be blank in a CSV (stored as NULL). */
    public const NULLABLE = [
        'age_max', 'wage_threshold', 'table_limit', 'cat1_employer', 'cat1_employee', 'cat1_skbbk_employee', 'cat2_employer',
        'cat2_skbbk_employee', 'p_upto', 'annual_cap', 'notes', 'name_en', 'max_headcount',
    ];

    /** One-row parameter tables per scheme. */
    public const PARAMS = [
        'epf' => 'epf_params', 'socso' => 'socso_params', 'eis' => 'eis_params', 'mtd' => 'mtd_parameters',
    ];

    /**
     * @return list<string>
     */
    public static function tablesFor(RateScheme $scheme): array
    {
        return match ($scheme) {
            RateScheme::Epf => ['epf_rate_rules', 'epf_wage_bands', 'epf_params'],
            RateScheme::Socso => ['socso_bands', 'socso_params'],
            RateScheme::Eis => ['eis_bands', 'eis_params'],
            RateScheme::Mtd => ['mtd_tax_bands', 'mtd_parameters'],
            RateScheme::TaxReliefs => ['tp1_relief_codes', 'tax_exemption_caps'],
            RateScheme::Hrdc => ['hrdc_levy_rules'],
            RateScheme::MinimumWage => ['minimum_wages'],
        };
    }

    /** Default CSV target table of a scheme. */
    public static function bandTable(RateScheme $scheme): string
    {
        return match ($scheme) {
            RateScheme::Epf => 'epf_wage_bands',
            RateScheme::Socso => 'socso_bands',
            RateScheme::Eis => 'eis_bands',
            RateScheme::Mtd => 'mtd_tax_bands',
            RateScheme::TaxReliefs => 'tp1_relief_codes',
            RateScheme::Hrdc => 'hrdc_levy_rules',
            RateScheme::MinimumWage => 'minimum_wages',
        };
    }
}
