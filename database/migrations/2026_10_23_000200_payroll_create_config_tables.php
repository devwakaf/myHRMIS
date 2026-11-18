<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll configuration (spec-04 §2.2–§2.4, §2.10): settings, components,
 * structures, assignments, paying accounts and bank file formats. Also adds the
 * M4 foreign keys that M2/M3 left as plain columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_file_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('version', 20)->default('1');
            $table->string('file_type', 10)->default('csv'); // csv | fixed
            $table->json('layout');
            $table->string('status', 16)->default('unverified');
            $table->string('source_ref')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('organisation_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->string('account_no', 30);
            $table->string('account_name', 150);
            $table->string('corporate_id', 40)->nullable();
            $table->foreignId('bank_file_format_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('days_source', 30)->default('leave_only');
            $table->string('unmarked_day_treatment', 16)->default('present');
            $table->string('partial_month_basis', 30)->default('follow_daily_rate');
            $table->string('lwp_presentation', 30)->default('reduce_earning');
            $table->string('y2_basis', 20)->default('current_month');
            $table->string('publish_slips_on', 16)->default('submit');
            $table->boolean('email_slips')->default(true);
            $table->string('pdf_password_mode', 16)->default('none');
            $table->boolean('show_employer_contributions')->default(true);
            $table->boolean('show_ytd')->default(true);
            $table->boolean('show_leave_balance')->default(false);
            $table->string('ic_display', 16)->default('masked');
            $table->decimal('variance_threshold_percent', 5, 2)->default(10);
            $table->boolean('block_unverified_rates')->default(true);
            $table->boolean('include_withheld_in_statutory')->default(true);
            $table->boolean('allow_submitter_mark_paid')->default(false);
            $table->boolean('min_wage_check')->default(true);
            $table->foreignId('default_bank_account_id')->nullable()->constrained('organisation_bank_accounts')->nullOnDelete();
            $table->foreignId('kwsp_file_format_id')->nullable()->constrained('statutory_file_formats')->nullOnDelete();
            $table->string('net_pay_rounding', 20)->default('none');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name_ms', 80);
            $table->string('name_en', 80);
            $table->string('kind', 30);
            $table->string('calculation', 16);
            $table->string('statutory_key', 30)->nullable();
            $table->decimal('default_amount', 15, 2)->nullable();
            $table->text('default_formula')->nullable();
            $table->text('default_condition')->nullable();
            $table->json('default_formula_ast')->nullable();
            $table->json('default_condition_ast')->nullable();
            $table->boolean('prorate')->default(false);
            $table->boolean('include_in_gross')->default(true);
            $table->boolean('show_on_slip')->default(true);
            $table->boolean('show_if_zero')->default(false);
            $table->boolean('epf_wage')->default(false);
            $table->boolean('socso_wage')->default(false);
            $table->boolean('eis_wage')->default(false);
            $table->boolean('hrdc_wage')->default(false);
            $table->boolean('taxable')->default(false);
            $table->string('pcb_type', 16)->default('normal');
            $table->string('tax_exemption_code', 40)->nullable();
            $table->string('ea_box', 40)->default('none');
            $table->boolean('is_epf_bonus')->default(false);
            $table->boolean('ot_wage_base')->default(false);
            $table->boolean('arrear_eligible')->default(false);
            $table->boolean('is_wage_reduction')->default(false);
            $table->foreignId('arrear_component_id')->nullable()->constrained('salary_components')->nullOnDelete();
            $table->string('rounding_mode', 20)->default('half_up_2dp');
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->text('description_ms')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('first_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
            $table->unique(['organisation_id', 'statutory_key']);
        });

        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name_ms');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_validated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });

        Schema::create('salary_structure_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->smallInteger('sort_order')->default(0);
            $table->string('calculation_override', 16)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->text('formula')->nullable();
            $table->text('condition')->nullable();
            $table->boolean('prorate_override')->nullable();
            $table->json('formula_ast')->nullable();
            $table->json('condition_ast')->nullable();
            $table->json('referenced_codes')->nullable();
            $table->timestamps();
            $table->unique(['salary_structure_id', 'salary_component_id'], 'salary_structure_rows_unique');
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->foreignId('default_salary_structure_id')->nullable()->after('salary_max')
                ->constrained('salary_structures')->nullOnDelete();
        });

        Schema::create('salary_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_structure_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('base_salary', 15, 2);
            $table->string('reason', 20)->default('new_hire');
            $table->decimal('epf_employee_rate_override', 9, 4)->nullable();
            $table->decimal('epf_employer_rate_override', 9, 4)->nullable();
            $table->unsignedBigInteger('employee_movement_id')->nullable();
            $table->unsignedSmallInteger('opening_year')->nullable();
            foreach (['opening_gross_normal', 'opening_gross_additional', 'opening_epf_employee', 'opening_pcb', 'opening_zakat',
                'opening_cp38', 'opening_socso_employee', 'opening_eis_employee', 'opening_skbbk_employee', 'opening_lp'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
            $table->json('opening_ea_breakdown')->nullable();
            $table->unsignedBigInteger('tp3_declaration_id')->nullable();
            $table->string('opening_source', 20)->default('manual');
            $table->text('remarks')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('amended_from_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['employee_id', 'status', 'effective_from']);
        });

        Schema::create('salary_assignment_amounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->unique(['salary_assignment_id', 'salary_component_id'], 'salary_assignment_amounts_unique');
        });

        // M4 foreign keys on columns created by M2/M3.
        Schema::table('leave_types', function (Blueprint $table) {
            $table->foreign('encashment_salary_component_id')->references('id')->on('salary_components')->restrictOnDelete();
        });
        Schema::table('overtime_types', function (Blueprint $table) {
            $table->foreign('salary_component_id')->references('id')->on('salary_components')->restrictOnDelete();
        });
        Schema::table('overtime_rules', function (Blueprint $table) {
            $table->foreign('salary_component_id')->references('id')->on('salary_components')->restrictOnDelete();
        });
        Schema::table('overtime_claim_lines', function (Blueprint $table) {
            $table->foreign('salary_component_id')->references('id')->on('salary_components')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('overtime_claim_lines', fn (Blueprint $t) => $t->dropForeign(['salary_component_id']));
        Schema::table('overtime_rules', fn (Blueprint $t) => $t->dropForeign(['salary_component_id']));
        Schema::table('overtime_types', fn (Blueprint $t) => $t->dropForeign(['salary_component_id']));
        Schema::table('leave_types', fn (Blueprint $t) => $t->dropForeign(['encashment_salary_component_id']));
        Schema::dropIfExists('salary_assignment_amounts');
        Schema::dropIfExists('salary_assignments');
        Schema::table('grades', fn (Blueprint $t) => $t->dropConstrainedForeignId('default_salary_structure_id'));
        Schema::dropIfExists('salary_structure_rows');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('payroll_settings');
        Schema::dropIfExists('organisation_bank_accounts');
        Schema::dropIfExists('bank_file_formats');
    }
};
