<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kehadiran & Shift transactions (spec-03 §3.6, §3.9–§3.12): Permohonan Shift,
 * Permohonan Kehadiran (+ per-day outcomes), Rekod Kehadiran, Log Check-in,
 * rejected attempts, Tuntutan OT (+ lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_type_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->text('reason')->nullable();
            $table->string('status', 16)->default('draft');
            $table->dateTime('effects_applied_at')->nullable();
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
            $table->index(['employee_id', 'status', 'from_date']);
        });

        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('is_half_day')->default(false);
            $table->date('half_day_date')->nullable();
            $table->string('half_day_session', 4)->nullable();
            $table->boolean('include_rest_days')->default(false);
            $table->foreignId('shift_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('duty_location', 255)->nullable();
            $table->text('explanation');
            $table->string('status', 16)->default('draft');
            $table->dateTime('effects_applied_at')->nullable();
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
            $table->index(['employee_id', 'status', 'from_date']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date');
            $table->string('status', 16)->default('submitted');
            $table->string('attendance_status', 16);
            $table->string('half_day_basis', 16)->nullable();
            $table->string('other_half_status', 16)->nullable();
            $table->string('half_day_session', 4)->nullable();
            $table->string('day_type', 32)->default('normal');
            $table->boolean('is_half_day_off')->default(false);
            $table->string('holiday_label', 150)->nullable();
            // restrict: MySQL forbids SET NULL on a base column of a stored generated column (active_key)
            $table->foreignId('shift_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('shift_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('scheduled_start_at')->nullable();
            $table->dateTime('scheduled_end_at')->nullable();
            $table->unsignedSmallInteger('normal_minutes')->nullable();
            $table->dateTime('in_at')->nullable();
            $table->dateTime('out_at')->nullable();
            $table->unsignedSmallInteger('punch_count')->default(0);
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->decimal('worked_hours', 8, 2)->nullable();
            $table->boolean('is_late')->default(false);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->boolean('is_early_exit')->default(false);
            $table->unsignedSmallInteger('early_exit_minutes')->default(0);
            $table->unsignedInteger('ot_detected_minutes')->default(0);
            $table->foreignId('overtime_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);
            $table->foreignId('leave_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->string('review_flag', 32)->nullable();
            $table->text('remarks')->nullable();
            $table->string('active_key', 64)->nullable()->storedAs(
                "IF(`status` = 'submitted', CONCAT(`employee_id`, '|', `attendance_date`, '|', COALESCE(`shift_type_id`, 0)), NULL)"
            );
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('active_key');
            $table->index(['organisation_id', 'attendance_date', 'status']);
            $table->index(['employee_id', 'attendance_date', 'status']);
        });

        Schema::create('attendance_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_request_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('outcome', 32);
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('superseded_attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employee_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->dateTime('punched_at');
            $table->dateTime('received_at');
            $table->dateTime('client_reported_at')->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->char('client_request_id', 36)->nullable();
            $table->string('log_type', 8)->nullable();
            $table->string('source', 16);
            $table->string('device_code', 40)->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained('checkin_import_batches')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->foreignId('shift_location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('distance_m', 10, 2)->nullable();
            $table->string('geofence_result', 32);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreignId('location_consent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('shift_date')->nullable();
            $table->boolean('is_ambiguous')->default(false);
            $table->dateTime('scheduled_start_at')->nullable();
            $table->dateTime('scheduled_end_at')->nullable();
            $table->dateTime('window_start_at')->nullable();
            $table->dateTime('window_end_at')->nullable();
            $table->string('processing_state', 16)->default('pending');
            $table->string('review_reason', 32)->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'client_request_id']);
            $table->index(['organisation_id', 'processing_state', 'window_end_at'], 'checkin_processing');
            $table->index(['employee_id', 'punched_at']);
            $table->index('attendance_id');
        });

        Schema::create('checkin_attempt_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('attempted_at');
            $table->string('result', 32);
            $table->foreignId('nearest_location_id')->nullable()->constrained('shift_locations')->nullOnDelete();
            $table->unsignedInteger('distance_m')->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'attempted_at']);
        });

        Schema::create('overtime_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('period_month');
            $table->string('status', 16)->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('posted_payroll_run_id')->nullable()->index();
            $table->decimal('monthly_wage_snapshot', 15, 2)->nullable();
            $table->string('wage_source', 16)->nullable();
            $table->decimal('orp', 15, 6)->default(0);
            $table->decimal('hrp', 15, 6)->default(0);
            $table->decimal('contractual_daily_hours', 4, 2)->default(8);
            $table->string('eligibility', 32)->default('not_eligible');
            $table->unsignedInteger('total_ot_minutes')->default(0);
            $table->unsignedInteger('cap_counted_minutes')->default(0);
            $table->unsignedInteger('cap_minutes')->default(0);
            $table->boolean('cap_exceeded')->default(false);
            $table->text('cap_override_reason')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->dateTime('effects_applied_at')->nullable();
            $table->string('active_key', 40)->nullable()->storedAs(
                "IF(`status` IN ('cancelled', 'rejected'), NULL, CONCAT(`employee_id`, '|', `period_month`))"
            );
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
            $table->unique('active_key');
            $table->index(['organisation_id', 'period_month', 'status']);
        });

        Schema::create('overtime_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('attendance_hash', 40)->nullable();
            $table->date('work_date');
            $table->string('day_type', 32);
            $table->foreignId('overtime_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('normal_minutes')->default(0);
            $table->unsignedInteger('within_normal_minutes')->default(0);
            $table->unsignedInteger('beyond_normal_minutes')->default(0);
            $table->decimal('orp_days', 5, 2)->default(0);
            $table->decimal('multiplier', 6, 4)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedBigInteger('salary_component_id')->nullable();
            $table->boolean('counts_toward_cap')->default(true);
            $table->json('flags')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('active_attendance_key')->nullable()->storedAs('IF(`is_active`, `attendance_id`, NULL)');
            $table->timestamps();
            $table->unique('active_attendance_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_claim_lines');
        Schema::dropIfExists('overtime_claims');
        Schema::dropIfExists('checkin_attempt_logs');
        Schema::dropIfExists('employee_checkins');
        Schema::dropIfExists('attendance_request_days');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('attendance_requests');
        Schema::dropIfExists('shift_requests');
    }
};
