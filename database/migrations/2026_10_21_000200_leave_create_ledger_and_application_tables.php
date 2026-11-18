<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuti transactions (spec-02 §2.5–§2.11): Peruntukan Cuti, Ledger Cuti (immutable),
 * Jadual Kredit Cuti, Pelarasan, Gantian Tunai, Permohonan Cuti (+ days,
 * attachments), Permohonan Cuti Gantian and the HR review queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_period_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('source', 32);
            $table->foreignId('leave_policy_assignment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('leave_policy_row_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('entitlement_days', 8, 3)->default(0);
            $table->decimal('initial_days', 8, 3)->default(0); // manual allocations: days credited on submit
            $table->timestamp('carry_processed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('amended_from_id')->nullable()->constrained('leave_allocations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['employee_id', 'leave_type_id', 'leave_period_id', 'status'], 'leave_alloc_lookup');
        });

        Schema::create('leave_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_allocation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->decimal('days', 8, 3);
            $table->foreignId('lot_id')->nullable()->constrained('leave_movements')->restrictOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->date('posted_on');
            $table->decimal('unpaid_days', 8, 3)->default(0);
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reverses_movement_id')->nullable()->unique()->constrained('leave_movements')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['employee_id', 'leave_type_id', 'date_from'], 'leave_mov_emp_type_date');
            $table->index(['source_type', 'source_id'], 'leave_mov_source');
            $table->index(['organisation_id', 'kind', 'date_to'], 'leave_mov_kind_date');
        });

        Schema::create('leave_credit_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_allocation_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->decimal('days', 8, 3)->default(0);
            $table->string('kind', 32);
            $table->string('status', 32)->default('pending');
            $table->timestamp('attempted_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('movement_id')->nullable()->constrained('leave_movements')->nullOnDelete();
            $table->string('posted_via', 32)->nullable();
            $table->timestamps();
            $table->unique(['leave_allocation_id', 'due_date', 'kind'], 'leave_credit_sched_unique');
            $table->index(['organisation_id', 'status', 'due_date']);
        });

        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('leave_allocation_id')->constrained()->restrictOnDelete();
            $table->string('direction', 16);
            $table->decimal('days', 8, 3);
            $table->date('effective_date');
            $table->date('expires_on')->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('amended_from_id')->nullable()->constrained('leave_adjustments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
        });

        Schema::create('leave_encashments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_allocation_id')->constrained()->restrictOnDelete();
            $table->date('encashment_date');
            $table->decimal('eligible_days', 8, 3)->default(0);
            $table->decimal('days', 8, 3);
            $table->string('rate_basis', 32);
            $table->decimal('monthly_basic', 15, 2);
            $table->boolean('basic_entered_manually')->default(false);
            $table->decimal('daily_rate', 15, 6);
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('additional_salary_id')->nullable(); // FK added in M4
            $table->string('payout_status', 32)->default('awaiting_payroll');
            $table->string('purpose', 32)->default('other');
            $table->text('remarks')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('amended_from_id')->nullable()->constrained('leave_encashments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['organisation_id', 'payout_status']);
        });

        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->date('half_day_date')->nullable();
            $table->string('half_day_session', 8)->nullable();
            $table->date('event_date')->nullable();
            $table->text('reason')->nullable();
            $table->string('contact_during_leave', 30)->nullable();
            $table->text('handover_note')->nullable();
            $table->decimal('total_days', 8, 3)->default(0);
            $table->decimal('unpaid_days', 8, 3)->default(0);
            $table->unsignedSmallInteger('calendar_days')->default(0);
            $table->decimal('balance_at_submit', 8, 3)->nullable();
            $table->boolean('is_on_behalf')->default(false);
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('block_list_acknowledged')->default(false);
            $table->boolean('is_backdated')->default(false);
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('amended_from_id')->nullable()->constrained('leave_applications')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['employee_id', 'status', 'from_date']);
            $table->index(['organisation_id', 'status', 'leave_type_id']);
        });

        Schema::create('leave_application_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('day_kind', 32);
            $table->string('session', 8)->default('full');
            $table->decimal('portion', 4, 3);
            $table->decimal('unpaid_portion', 4, 3)->default(0);
            $table->foreignId('holiday_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('leave_allocation_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['leave_application_id', 'date']);
            $table->index(['date', 'holiday_list_id']);
        });

        Schema::create('leave_application_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();
            $table->string('file_path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('kind', 32)->default('mc');
            $table->string('mc_serial_no', 40)->nullable();
            $table->string('issuer_name', 150)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('compensatory_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('work_from');
            $table->date('work_to');
            $table->date('half_day_date')->nullable();
            $table->text('reason');
            $table->decimal('days_claimed', 8, 3)->default(0);
            $table->boolean('attendance_unverified')->default(false);
            $table->json('granted_movement_ids')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('amended_from_id')->nullable()->constrained('compensatory_leave_requests')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('leave_review_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 64);
            $table->json('details')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_review_tasks');
        Schema::dropIfExists('compensatory_leave_requests');
        Schema::dropIfExists('leave_application_attachments');
        Schema::dropIfExists('leave_application_days');
        Schema::dropIfExists('leave_applications');
        Schema::dropIfExists('leave_encashments');
        Schema::dropIfExists('leave_adjustments');
        Schema::dropIfExists('leave_credit_schedules');
        Schema::dropIfExists('leave_movements');
        Schema::dropIfExists('leave_allocations');
    }
};
