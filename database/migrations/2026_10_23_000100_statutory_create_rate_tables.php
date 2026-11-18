<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statutory reference tables (spec-04 §2.11). Global (no organisation_id), edited by
 * Super Admin. Every rate table hangs off a statutory_rate_sets header so each slip
 * can stamp the set it used (A9). Band amounts are stored verbatim (never recomputed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_rate_sets', function (Blueprint $table) {
            $table->id();
            $table->string('scheme', 20);
            $table->string('code', 40)->unique();
            $table->string('name')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('draft');
            $table->boolean('is_placeholder')->default(false); // dev-only generated bands (never production)
            $table->string('source_url', 500)->nullable();
            $table->string('source_ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('fixture_passed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scheme', 'effective_from']);
        });

        Schema::create('epf_rate_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->char('part', 1);
            $table->string('citizenship_group', 20);
            $table->unsignedSmallInteger('age_min')->default(0);
            $table->unsignedSmallInteger('age_max')->nullable(); // exclusive
            $table->decimal('wage_threshold', 15, 2)->nullable();
            $table->decimal('employer_rate_low', 9, 4);
            $table->decimal('employer_rate_high', 9, 4);
            $table->decimal('employee_rate', 9, 4);
            $table->decimal('table_limit', 15, 2)->nullable();
            $table->string('percent_rounding', 30)->default('ceil_each_share');   // UNVERIFIED-STAT
            $table->boolean('bonus_rule')->default(false);
            $table->string('bonus_rule_base', 20)->default('band_upper');         // UNVERIFIED-STAT
            $table->boolean('uses_table')->default(true);
            $table->timestamps();
        });

        Schema::create('epf_wage_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->char('part', 1);
            $table->decimal('wage_from', 15, 2);
            $table->decimal('wage_to', 15, 2);
            $table->decimal('employer_amount', 15, 2);
            $table->decimal('employee_amount', 15, 2);
            $table->unique(['rate_set_id', 'part', 'wage_from']);
        });

        Schema::create('epf_params', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->unique()->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->unsignedSmallInteger('min_age')->default(14);
            $table->unsignedSmallInteger('max_age')->default(75);
            $table->string('age_switch_rule', 30)->default('month_after_birthday'); // UNVERIFIED-STAT
            $table->unsignedTinyInteger('work_pass_stop_months')->default(2);
            $table->unsignedTinyInteger('due_day')->default(15);
            $table->timestamps();
        });

        Schema::create('socso_params', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->unique()->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->decimal('wage_ceiling', 15, 2)->default(6000);
            $table->unsignedSmallInteger('cat1_max_age')->default(60);
            $table->unsignedSmallInteger('first_insured_cat2_age')->default(55);
            $table->string('age_switch_rule', 30)->default('month_after_birthday'); // UNVERIFIED-STAT
            $table->date('skbbk_start')->nullable();
            $table->date('skbbk_local_voluntary_from')->nullable();
            $table->date('skbbk_optout_window_from')->nullable();
            $table->date('skbbk_optout_window_to')->nullable();
            $table->unsignedTinyInteger('due_day')->default(15);
            $table->decimal('interest_rate_pa', 9, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('socso_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->decimal('wage_from', 15, 2);
            $table->decimal('wage_to', 15, 2);
            // Nullable so a partially keyed table can be imported; a missing amount fails the lookup loudly.
            $table->decimal('cat1_employer', 15, 2)->nullable();
            $table->decimal('cat1_employee', 15, 2)->nullable();
            $table->decimal('cat1_skbbk_employee', 15, 2)->nullable();
            $table->decimal('cat2_employer', 15, 2)->nullable();
            $table->decimal('cat2_skbbk_employee', 15, 2)->nullable();
            $table->unique(['rate_set_id', 'wage_from']);
        });

        Schema::create('eis_params', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->unique()->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->decimal('wage_ceiling', 15, 2)->default(6000);
            $table->unsignedSmallInteger('min_age')->default(18);
            $table->unsignedSmallInteger('max_age')->default(60);
            $table->unsignedSmallInteger('first_insured_exclusion_age')->default(57);
            $table->json('eligible_citizenships');
            $table->string('age_switch_rule', 30)->default('month_after_birthday'); // UNVERIFIED-STAT
            $table->timestamps();
        });

        Schema::create('eis_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->decimal('wage_from', 15, 2);
            $table->decimal('wage_to', 15, 2);
            $table->decimal('employer_amount', 15, 2);
            $table->decimal('employee_amount', 15, 2);
            $table->unique(['rate_set_id', 'wage_from']);
        });

        Schema::create('mtd_tax_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->decimal('p_above', 15, 2);           // exclusive
            $table->decimal('p_upto', 15, 2)->nullable(); // inclusive; null = open
            $table->decimal('m', 15, 2)->default(0);
            $table->decimal('r', 9, 4)->default(0);
            $table->decimal('b_cat1_3', 15, 2)->default(0);
            $table->decimal('b_cat2', 15, 2)->default(0);
            $table->boolean('no_tax')->default(false);
            $table->unique(['rate_set_id', 'p_above']);
        });

        Schema::create('mtd_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->unique()->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->decimal('relief_individual', 15, 2);
            $table->decimal('relief_spouse', 15, 2);
            $table->decimal('relief_disabled_self', 15, 2);
            $table->decimal('relief_disabled_spouse', 15, 2);
            $table->decimal('child_basic', 15, 2);
            $table->decimal('child_higher_ed', 15, 2);
            $table->decimal('child_disabled', 15, 2);
            $table->decimal('child_disabled_higher_ed', 15, 2);
            $table->decimal('epf_relief_cap', 15, 2)->default(4000);
            $table->decimal('voluntary_epf_cap_no_mandatory', 15, 2)->default(7000);
            $table->decimal('pcb_minimum', 15, 2)->default(10);
            $table->decimal('additional_pcb_minimum', 15, 2)->default(10);
            $table->decimal('non_resident_rate', 9, 4)->default(30);
            $table->unsignedSmallInteger('resident_min_days')->default(182);
            $table->boolean('auto_socso_eis_relief')->default(false); // UNVERIFIED-STAT (open Q5)
            $table->decimal('socso_eis_relief_cap', 15, 2)->default(350);
            $table->timestamps();
        });

        Schema::create('tp1_relief_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name_ms');
            $table->string('name_en')->nullable();
            $table->decimal('annual_cap', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unique(['rate_set_id', 'code']);
        });

        Schema::create('tax_exemption_caps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name_ms');
            $table->string('name_en')->nullable();
            $table->decimal('annual_cap', 15, 2)->nullable(); // null = fully exempt
            $table->unique(['rate_set_id', 'code']);
        });

        Schema::create('hrdc_levy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->unsignedInteger('min_headcount');
            $table->unsignedInteger('max_headcount')->nullable();
            $table->decimal('rate', 9, 4);
            $table->boolean('mandatory')->default(true);
            $table->json('base_citizenships');
            $table->string('rounding_mode', 20)->default('half_up_2dp');
        });

        Schema::create('minimum_wages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_set_id')->constrained('statutory_rate_sets')->cascadeOnDelete();
            $table->decimal('monthly', 15, 2);
            $table->decimal('hourly', 15, 2);
            $table->decimal('daily_6day', 15, 2);
            $table->decimal('daily_5day', 15, 2);
            $table->decimal('daily_4day', 15, 2);
            $table->string('scope', 20)->default('all');
        });

        Schema::create('statutory_file_formats', function (Blueprint $table) {
            $table->id();
            $table->string('scheme', 20);
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('version', 20);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('record_type', 10); // fixed | csv
            $table->json('layout');
            $table->string('filename_pattern')->nullable();
            $table->string('line_ending', 8)->default("\r\n");
            $table->string('encoding', 20)->default('ASCII');
            $table->string('status', 16)->default('unverified');
            $table->string('source_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scheme', 'effective_from']);
        });
    }

    public function down(): void
    {
        foreach ([
            'statutory_file_formats', 'minimum_wages', 'hrdc_levy_rules', 'tax_exemption_caps', 'tp1_relief_codes',
            'mtd_parameters', 'mtd_tax_bands', 'eis_bands', 'eis_params', 'socso_bands', 'socso_params', 'epf_params',
            'epf_wage_bands', 'epf_rate_rules', 'statutory_rate_sets',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
