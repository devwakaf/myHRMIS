<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuti configuration (spec-02 §2.1–§2.4, §2.12–§2.14): Jenis Cuti, Tempoh Cuti,
 * Leave Policy (+ rows, tiers), Penetapan Leave Policy, Had Gabungan Cuti,
 * Senarai Sekatan Cuti and Tetapan Cuti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('name_en', 100)->nullable();
            $table->char('colour', 7)->default('#2563eb');
            $table->string('entitlement_basis', 32);
            $table->string('pay_mode', 32);
            $table->decimal('paid_fraction', 5, 4)->default(1);
            $table->boolean('counts_holidays')->default(false);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('requires_reason')->default(false);
            $table->string('requires_attachment', 32)->default('never');
            $table->decimal('attachment_over_days', 8, 3)->nullable();
            $table->unsignedSmallInteger('min_service_days_to_apply')->default(0);
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->string('notice_enforcement', 32)->default('warn');
            $table->unsignedSmallInteger('max_backdate_days')->nullable();
            $table->decimal('max_days_per_application', 8, 3)->nullable();
            $table->decimal('max_consecutive_days', 8, 3)->nullable();
            $table->decimal('max_days_per_period', 8, 3)->nullable();
            $table->boolean('allow_negative')->default(false);
            $table->decimal('max_negative_days', 8, 3)->default(0);
            $table->boolean('allow_carry_forward')->default(false);
            $table->decimal('max_carry_forward_days', 8, 3)->nullable();
            $table->unsignedSmallInteger('carry_forward_expiry_days')->nullable();
            $table->boolean('carry_includes_carried')->default(false);
            $table->boolean('allow_encashment')->default(false);
            $table->decimal('max_encashable_days', 8, 3)->nullable();
            $table->decimal('encashment_reserve_days', 8, 3)->default(0);
            $table->unsignedBigInteger('encashment_salary_component_id')->nullable(); // FK added in M4
            $table->string('accrual_frequency', 32)->nullable();
            $table->string('accrual_timing', 32)->nullable();
            $table->decimal('rounding_step', 4, 2)->nullable();
            $table->string('proration_method', 32)->default('none');
            $table->boolean('prorate_on_separation')->default(false);
            $table->decimal('per_event_max_days', 8, 3)->nullable();
            $table->unsignedSmallInteger('max_events_per_service')->nullable();
            $table->unsignedSmallInteger('max_events_per_period')->nullable();
            $table->boolean('requires_event_date')->default(false);
            $table->string('eligible_gender', 16)->nullable();
            $table->json('eligible_marital_statuses')->nullable();
            $table->unsignedSmallInteger('compensatory_expiry_days')->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });

        Schema::create('leave_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->date('from_date');
            $table->date('to_date');
            $table->text('notes')->nullable();
            $table->timestamp('allocations_generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organisation_id', 'from_date']);
        });

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('leave_policy_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->decimal('annual_days', 8, 3)->default(0);
            $table->boolean('uses_service_tiers')->default(false);
            $table->string('tier_basis', 32)->default('prorata_by_anniversary');
            $table->decimal('override_max_carry_forward_days', 8, 3)->nullable();
            $table->timestamps();
            $table->unique(['leave_policy_id', 'leave_type_id']);
        });

        Schema::create('leave_policy_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_policy_row_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('min_service_months');
            $table->decimal('days', 8, 3);
            $table->timestamps();
            $table->unique(['leave_policy_row_id', 'min_service_months']);
        });

        Schema::create('leave_policy_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_policy_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('amended_from_id')->nullable()->constrained('leave_policy_assignments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['employee_id', 'status', 'effective_from']);
        });

        Schema::create('leave_aggregate_caps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->decimal('cap_days', 8, 3);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('leave_aggregate_cap_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_aggregate_cap_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unique(['leave_aggregate_cap_id', 'leave_type_id'], 'leave_cap_types_unique');
        });

        Schema::create('leave_block_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->boolean('applies_to_all_departments')->default(true);
            $table->foreignId('leave_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('leave_block_list_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_block_list_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('reason', 150)->nullable();
            $table->unique(['leave_block_list_id', 'date']);
        });

        Schema::create('leave_block_list_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_block_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->unique(['leave_block_list_id', 'department_id'], 'leave_block_departments_unique');
        });

        Schema::create('leave_block_list_exempt_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_block_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['leave_block_list_id', 'user_id'], 'leave_block_exempt_unique');
        });

        Schema::create('leave_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('default_leave_policy_id')->nullable()->constrained('leave_policies')->nullOnDelete();
            $table->unsignedSmallInteger('default_max_backdate_days')->default(7);
            $table->string('calendar_colleague_visibility', 32)->default('off');
            $table->unsignedSmallInteger('expiry_reminder_days')->default(30);
            $table->string('encashment_rate_basis', 32)->default('org_daily_rate');
            $table->string('applicant_may_cancel_approved', 32)->default('before_start');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_settings');
        Schema::dropIfExists('leave_block_list_exempt_users');
        Schema::dropIfExists('leave_block_list_departments');
        Schema::dropIfExists('leave_block_list_dates');
        Schema::dropIfExists('leave_block_lists');
        Schema::dropIfExists('leave_aggregate_cap_types');
        Schema::dropIfExists('leave_aggregate_caps');
        Schema::dropIfExists('leave_policy_assignments');
        Schema::dropIfExists('leave_policy_tiers');
        Schema::dropIfExists('leave_policy_rows');
        Schema::dropIfExists('leave_policies');
        Schema::dropIfExists('leave_periods');
        Schema::dropIfExists('leave_types');
    }
};
