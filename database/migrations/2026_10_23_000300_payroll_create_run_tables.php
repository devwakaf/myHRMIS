<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll runs, slips, adjustments, tax years, files and EA (spec-04 §2.5–§2.10).
 */
return new class extends Migration
{
    /** Standard A5.3 lifecycle columns. */
    private function lifecycle(Blueprint $table): void
    {
        $table->string('status', 20)->default('draft');
        $table->timestamp('submitted_at')->nullable();
        $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('cancelled_at')->nullable();
        $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        $table->text('cancel_reason')->nullable();
        $table->unsignedBigInteger('amended_from_id')->nullable();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    }

    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->date('period');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('payment_date');
            $table->string('processing_state', 16)->default('idle');
            $table->string('batch_id', 36)->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_finished_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->json('filters')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference', 100)->nullable();
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('gross_total', 15, 2)->default(0);
            $table->decimal('deduction_total', 15, 2)->default(0);
            $table->decimal('net_total', 15, 2)->default(0);
            $table->decimal('employer_total', 15, 2)->default(0);
            $table->unsignedInteger('withheld_count')->default(0);
            $table->json('rate_set_ids')->nullable();
            $table->boolean('unverified_acknowledged')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('ytd_refreshed_at')->nullable();
            $this->lifecycle($table);
            $table->unique(['organisation_id', 'number']);
            $table->index(['organisation_id', 'period', 'status']);
        });

        Schema::create('payroll_run_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->boolean('included')->default(true);
            $table->string('exclusion_reason')->nullable();
            $table->string('generation_status', 16)->default('pending');
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            // Draft-only overrides kept across "Jana Semula" (copied onto the slip).
            $table->decimal('lwp_override_days', 8, 3)->nullable();
            $table->string('lwp_override_reason')->nullable();
            $table->decimal('pcb_override_amount', 15, 2)->nullable();
            $table->string('pcb_override_reason')->nullable();
            $table->unsignedBigInteger('salary_slip_id')->nullable();
            $table->string('active_period_key', 40)->nullable()->unique();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('salary_withholdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('from_period');
            $table->unsignedTinyInteger('cycles')->nullable(); // null = until released / cancelled
            $table->text('reason');
            $this->lifecycle($table);
            $table->unique(['organisation_id', 'number']);
        });

        Schema::create('salary_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('period');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('actual_start');
            $table->date('actual_end');
            $table->date('payment_date');
            $table->string('payment_status', 16)->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('salary_withholding_cycle_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('active_period_key', 40)->nullable()->unique();
            // Frozen configuration
            $table->foreignId('salary_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_structure_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('structure_revision')->default(1);
            $table->string('calc_version', 20);
            // Employee snapshot
            $table->string('employee_no', 30);
            $table->string('full_name', 150);
            $table->string('identity_type', 32)->nullable();
            $table->string('ic_no', 12)->nullable();
            $table->string('old_ic_no', 12)->nullable();
            $table->string('passport_no', 20)->nullable();
            $table->string('passport_country', 2)->nullable();
            $table->string('citizenship_status', 32);
            $table->date('date_of_birth');
            $table->unsignedSmallInteger('age_years');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->string('cost_centre_code', 30)->nullable();
            $table->string('payment_mode', 20);
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('bank_account_no', 20)->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->string('kwsp_no', 20)->nullable();
            $table->string('perkeso_no', 20)->nullable();
            $table->string('tin', 14)->nullable();
            $table->string('tax_residency', 16)->nullable();
            $table->unsignedTinyInteger('pcb_category')->default(1);
            $table->string('mtd_scheme', 32)->nullable();
            $table->json('children')->nullable();
            // Days
            $table->decimal('calendar_days', 8, 3)->default(0);
            $table->decimal('working_days', 8, 3)->nullable();
            $table->string('rate_basis', 30);
            $table->decimal('divisor', 6, 2);
            $table->decimal('outside_employment_days', 8, 3)->default(0);
            $table->decimal('lwp_days', 8, 3)->default(0);
            $table->decimal('absent_days', 8, 3)->default(0);
            $table->decimal('unmarked_days', 8, 3)->default(0);
            $table->decimal('unpaid_days_total', 8, 3)->default(0);
            $table->decimal('payment_fraction', 12, 10)->default(1);
            $table->decimal('payment_days', 8, 3)->default(0);
            $table->decimal('lwp_override_days', 8, 3)->nullable();
            $table->string('lwp_override_reason')->nullable();
            // Totals
            $table->decimal('gross_pay', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2)->default(0);
            $table->decimal('net_rounding_adjustment', 15, 2)->default(0);
            $table->decimal('employer_total', 15, 2)->default(0);
            // Overrides
            $table->decimal('pcb_override_amount', 15, 2)->nullable();
            $table->string('pcb_override_reason')->nullable();
            // Audit
            $table->json('warnings')->nullable();
            $table->boolean('has_errors')->default(false);
            $table->json('calc_trace')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_template_version', 20)->nullable();
            $table->timestamp('emailed_at')->nullable();
            $this->lifecycle($table);
            $table->unique(['organisation_id', 'number']);
            $table->index(['employee_id', 'period', 'status']);
            $table->index(['payroll_run_id', 'status']);
        });

        Schema::create('salary_withholding_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_withholding_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->foreignId('salary_slip_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_reason')->nullable();
            $table->unsignedBigInteger('payroll_file_id')->nullable();
            $table->timestamps();
            $table->unique(['salary_withholding_id', 'period']);
        });

        Schema::create('salary_slip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_slip_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name_ms', 80);
            $table->string('name_en', 80);
            $table->smallInteger('sort_order')->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('full_cycle_amount', 15, 2)->default(0);
            $table->decimal('structure_amount', 15, 2)->default(0);
            $table->decimal('additional_amount', 15, 2)->default(0);
            $table->string('source', 30);
            $table->unsignedBigInteger('additional_salary_id')->nullable();
            $table->decimal('quantity', 10, 3)->nullable();
            $table->decimal('rate', 15, 6)->nullable();
            $table->decimal('taxable_exempt_amount', 15, 2)->default(0);
            $table->boolean('include_in_gross')->default(true);
            $table->boolean('epf_wage')->default(false);
            $table->boolean('socso_wage')->default(false);
            $table->boolean('eis_wage')->default(false);
            $table->boolean('hrdc_wage')->default(false);
            $table->boolean('taxable')->default(false);
            $table->string('pcb_type', 16)->default('normal');
            $table->string('ea_box', 40)->default('none');
            $table->boolean('is_epf_bonus')->default(false);
            $table->boolean('ot_wage_base')->default(false);
            $table->boolean('is_wage_reduction')->default(false);
            $table->boolean('show_on_slip')->default(true);
            $table->string('note')->nullable();
            $table->index(['salary_slip_id', 'kind']);
        });

        Schema::create('salary_slip_statutory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_slip_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('epf_part', 4)->default('none');
            $table->decimal('epf_wage', 15, 2)->default(0);
            $table->decimal('epf_wage_excl_bonus', 15, 2)->default(0);
            $table->decimal('epf_employee', 15, 2)->default(0);
            $table->decimal('epf_employer', 15, 2)->default(0);
            $table->decimal('epf_employee_normal', 15, 2)->default(0);
            $table->string('epf_method', 10)->default('none');
            $table->unsignedBigInteger('epf_rate_set_id')->nullable();
            $table->string('socso_category', 4)->default('none');
            $table->decimal('socso_wage', 15, 2)->default(0);
            $table->decimal('socso_employee', 15, 2)->default(0);
            $table->decimal('socso_employer', 15, 2)->default(0);
            $table->boolean('skbbk_applies')->default(false);
            $table->decimal('skbbk_employee', 15, 2)->default(0);
            $table->unsignedBigInteger('socso_rate_set_id')->nullable();
            $table->boolean('eis_eligible')->default(false);
            $table->decimal('eis_wage', 15, 2)->default(0);
            $table->decimal('eis_employee', 15, 2)->default(0);
            $table->decimal('eis_employer', 15, 2)->default(0);
            $table->unsignedBigInteger('eis_rate_set_id')->nullable();
            $table->boolean('hrdc_applies')->default(false);
            $table->decimal('hrdc_wage', 15, 2)->default(0);
            $table->decimal('hrdc_levy', 15, 2)->default(0);
            $table->decimal('hrdc_rate', 9, 4)->default(0);
            $table->unsignedBigInteger('hrdc_rate_set_id')->nullable();
            $table->string('pcb_method', 24)->default('normal');
            $table->decimal('pcb_normal', 15, 2)->default(0);
            $table->decimal('pcb_additional', 15, 2)->default(0);
            $table->decimal('pcb_before_zakat', 15, 2)->default(0);
            $table->decimal('zakat_payroll', 15, 2)->default(0);
            $table->decimal('zakat_external', 15, 2)->default(0);
            $table->decimal('pcb_net', 15, 2)->default(0);
            $table->decimal('cp38', 15, 2)->default(0);
            $table->unsignedBigInteger('mtd_rate_set_id')->nullable();
            $table->json('pcb_inputs')->nullable();
            $table->json('ytd_snapshot')->nullable();
        });

        Schema::create('additional_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16)->default('one_off');
            $table->date('period')->nullable();
            $table->date('from_period')->nullable();
            $table->date('to_period')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('mode', 10)->default('add');
            $table->boolean('prorate')->default(false);
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('source_type', 30)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_ref', 60)->nullable();
            // unique among non-cancelled rows (idempotent postings)
            $table->string('active_source_ref', 60)->nullable()->storedAs("IF(`status` <> 'cancelled', `source_ref`, NULL)");
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete(); // run that posted it (OT)
            $table->string('note')->nullable();
            $table->text('reason');
            $this->lifecycle($table);
            $table->unique(['organisation_id', 'number']);
            $table->unique('active_source_ref');
            $table->index(['employee_id', 'status']);
        });

        Schema::create('additional_salary_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('additional_salary_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_slip_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->decimal('amount', 15, 2);
            $table->unique(['additional_salary_id', 'period'], 'additional_salary_applications_unique');
        });

        Schema::create('arrears', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_assignment_id')->constrained()->restrictOnDelete();
            $table->date('from_period');
            $table->date('to_period');
            $table->date('payout_period');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->text('notes')->nullable();
            $this->lifecycle($table);
            $table->unique(['organisation_id', 'number']);
        });

        Schema::create('arrear_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arrear_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->decimal('entitled_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->foreignId('old_salary_slip_id')->nullable()->constrained('salary_slips')->nullOnDelete();
            $table->boolean('included')->default(true);
            $table->text('basis')->nullable();
        });

        Schema::create('payroll_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_slip_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('lwp_days_reversed', 8, 3)->nullable();
            $table->date('payout_period');
            $table->text('reason');
            $this->lifecycle($table);
            $table->unique(['organisation_id', 'number']);
        });

        Schema::create('payroll_correction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_correction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('basis')->nullable();
        });

        Schema::create('tax_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('tax_year');
            $table->date('period');
            $table->boolean('is_correction')->default(false);
            $table->unsignedBigInteger('consumed_by_slip_id')->nullable();
            $table->string('prev_employer_name')->nullable();
            $table->string('prev_employer_e_no', 20)->nullable();
            $table->date('prev_from')->nullable();
            $table->date('prev_to')->nullable();
            foreach (['prev_gross_normal', 'prev_gross_additional', 'prev_epf', 'prev_pcb', 'prev_zakat', 'prev_cp38', 'prev_lp', 'zakat_amount'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
            $table->string('zakat_authority')->nullable();
            $table->string('receipt_no', 60)->nullable();
            $table->date('receipt_date')->nullable();
            $table->text('remarks')->nullable();
            $this->lifecycle($table);
            $table->unique(['organisation_id', 'number']);
            $table->index(['employee_id', 'tax_year', 'status']);
        });

        Schema::create('tax_declaration_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_declaration_id')->constrained()->cascadeOnDelete();
            $table->string('relief_code', 40);
            $table->decimal('amount', 15, 2);
            $table->string('reason')->nullable();
        });

        Schema::create('employee_tax_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->date('through_period')->nullable();
            foreach (['y_normal', 'y_additional', 'k', 'x', 'z', 'lp', 'cp38_total', 'socso_employee', 'eis_employee',
                'skbbk_employee', 'epf_employer'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
            $table->json('ea_boxes')->nullable();
            $table->json('tax_exempt_used')->nullable();
            $table->timestamp('rebuilt_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'tax_year']);
        });

        Schema::create('payroll_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period');
            $table->string('kind', 30);
            $table->unsignedBigInteger('organisation_bank_account_id')->nullable();
            $table->string('format_code', 40)->nullable();
            $table->string('format_version', 20)->nullable();
            $table->string('file_name');
            $table->string('file_path');
            $table->char('sha256', 64);
            $table->unsignedInteger('record_count')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('status', 16)->default('generated');
            $table->boolean('format_unverified')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_ref', 100)->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'period', 'kind']);
        });

        Schema::create('statutory_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('scheme', 16);
            $table->date('period');
            $table->decimal('amount', 15, 2);
            $table->date('paid_on');
            $table->string('receipt_no', 60)->nullable();
            $table->foreignId('payroll_file_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organisation_id', 'scheme', 'period']);
        });

        Schema::create('ea_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->string('serial_no', 40);
            $table->string('status', 16)->default('draft');
            $table->json('data');
            $table->string('pdf_path')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'tax_year', 'status']);
        });
    }

    public function down(): void
    {
        foreach ([
            'ea_forms', 'statutory_remittances', 'payroll_files', 'employee_tax_years', 'tax_declaration_lines', 'tax_declarations',
            'payroll_correction_lines', 'payroll_corrections', 'arrear_lines', 'arrears', 'additional_salary_applications',
            'additional_salaries', 'salary_slip_statutory', 'salary_slip_lines', 'salary_withholding_cycles', 'salary_slips',
            'salary_withholdings', 'payroll_run_employees', 'payroll_runs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
