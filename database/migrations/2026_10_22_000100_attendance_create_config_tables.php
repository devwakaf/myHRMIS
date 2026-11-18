<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kehadiran & Shift configuration (spec-03 §3.1–§3.8, §3.12–§3.14): settings, profiles,
 * Jenis Shift, Lokasi Shift, Corak Roster, Penugasan Shift / Roster, hari rehat,
 * Akta Kerja parameters, Jenis OT, consents and the reserved import-batch table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('geofence_enabled')->default(true);
            $table->string('no_location_policy', 32)->default('allow');
            $table->string('outside_geofence_action', 32)->default('reject');
            $table->unsignedSmallInteger('default_accuracy_threshold_m')->default(100);
            $table->unsignedSmallInteger('min_punch_interval_seconds')->default(60);
            $table->unsignedSmallInteger('location_notice_version')->default(1);
            $table->unsignedSmallInteger('location_retention_months')->default(12);
            $table->unsignedSmallInteger('attempt_log_retention_days')->default(90);
            $table->boolean('allow_multiple_assignments')->default(false);
            $table->unsignedSmallInteger('sync_lag_minutes')->default(0);
            $table->unsignedSmallInteger('absent_lookback_days')->default(35);
            $table->string('organisation_holiday_day_type', 32)->default('public_holiday');
            $table->unsignedBigInteger('default_overtime_type_id')->nullable();
            $table->decimal('ot_monthly_cap_hours', 6, 2)->nullable();
            $table->boolean('ot_generate_drafts_monthly')->default(true);
            $table->unsignedSmallInteger('roster_horizon_days')->default(60);
            $table->unsignedTinyInteger('notify_roster_changes_days')->default(7);
            $table->boolean('remind_missing_checkin')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shift_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->time('break_start_time')->nullable();
            $table->unsignedSmallInteger('open_before_minutes')->default(60);
            $table->unsignedSmallInteger('close_after_minutes')->default(120);
            $table->unsignedSmallInteger('late_grace_minutes')->default(10);
            $table->unsignedSmallInteger('early_grace_minutes')->default(0);
            $table->boolean('track_late')->default(true);
            $table->boolean('track_early')->default(true);
            $table->unsignedSmallInteger('half_day_threshold_minutes')->default(240);
            $table->unsignedSmallInteger('absent_threshold_minutes')->default(120);
            $table->string('hours_method', 32)->default('first_last');
            $table->string('single_punch_policy', 32)->default('review');
            $table->boolean('auto_attendance')->default(true);
            $table->date('process_from')->nullable();
            $table->unsignedTinyInteger('absent_buffer_days')->default(1);
            $table->foreignId('holiday_list_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('overtime_enabled')->default(false);
            $table->unsignedBigInteger('overtime_type_id')->nullable();
            $table->boolean('count_pre_shift_minutes')->default(false);
            $table->char('colour', 7)->default('#3b82f6');
            $table->unsignedSmallInteger('normal_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('default_shift_type_id')->references('id')->on('shift_types')->nullOnDelete();
        });

        Schema::create('employee_attendance_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('checkin_required')->default(true);
            $table->boolean('geofence_exempt')->default(false);
            $table->boolean('is_manual_labour')->default(false);
            $table->string('ot_eligibility', 32)->default('auto');
            $table->text('ot_eligibility_note')->nullable();
            $table->string('device_user_code', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'device_user_code'], 'att_profile_device_unique');
        });

        Schema::create('shift_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('radius_m')->default(150);
            $table->unsignedSmallInteger('accuracy_threshold_m')->nullable();
            $table->string('address_note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('shift_location_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_location_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 32);
            $table->unsignedBigInteger('linkable_id');
            $table->timestamps();
            $table->unique(['shift_location_id', 'linkable_type', 'linkable_id'], 'shift_loc_link_unique');
            $table->index(['linkable_type', 'linkable_id']);
        });

        Schema::create('shift_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->unsignedTinyInteger('cycle_length_days');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });

        Schema::create('shift_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_index');
            $table->string('kind', 16);
            $table->foreignId('shift_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['shift_schedule_id', 'day_index']);
        });

        Schema::create('shift_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_schedule_id')->constrained()->restrictOnDelete();
            $table->date('anchor_date');
            $table->date('end_date')->nullable();
            $table->date('generated_through')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('overtime_type_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'is_enabled']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 16)->default('submitted');
            $table->string('source', 16)->default('manual');
            $table->unsignedBigInteger('shift_request_id')->nullable()->index();
            $table->foreignId('shift_schedule_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('overtime_type_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'start_date', 'end_date'], 'shift_assign_lookup');
        });

        Schema::create('shift_assignment_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('cause_type', 32);
            $table->unsignedBigInteger('cause_id');
            $table->foreignId('shift_assignment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_rest_day_id')->nullable();
            $table->string('action', 32);
            $table->json('before')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['cause_type', 'cause_id']);
        });

        Schema::create('employee_rest_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('rest_date');
            $table->string('source', 16);
            $table->foreignId('shift_schedule_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'rest_date']);
        });

        Schema::create('akta_kerja_parameters', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('ot_wage_threshold', 15, 2);
            $table->decimal('orp_divisor', 5, 2);
            $table->decimal('normal_day_multiplier', 6, 4);
            $table->decimal('rest_day_beyond_multiplier', 6, 4);
            $table->decimal('ph_beyond_multiplier', 6, 4);
            $table->decimal('ph_worked_extra_orp_days', 5, 2);
            $table->decimal('rest_day_half_ratio', 5, 4);
            $table->decimal('rest_day_half_orp_days', 5, 2);
            $table->decimal('rest_day_full_orp_days', 5, 2);
            $table->decimal('monthly_ot_cap_hours', 6, 2);
            $table->decimal('max_daily_hours', 4, 2);
            $table->decimal('normal_daily_hours', 4, 2);
            $table->decimal('normal_weekly_hours', 5, 2);
            $table->string('source_url', 255)->nullable();
            $table->string('source_ref', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Seeded from findings-statutory-my §6.2; the monthly cap stays unverified.
        DB::table('akta_kerja_parameters')->insert([
            'effective_from' => '2022-09-01',
            'effective_to' => null,
            'ot_wage_threshold' => '4000.00',
            'orp_divisor' => '26.00',
            'normal_day_multiplier' => '1.5000',
            'rest_day_beyond_multiplier' => '2.0000',
            'ph_beyond_multiplier' => '3.0000',
            'ph_worked_extra_orp_days' => '2.00',
            'rest_day_half_ratio' => '0.5000',
            'rest_day_half_orp_days' => '0.50',
            'rest_day_full_orp_days' => '1.00',
            'monthly_ot_cap_hours' => '104.00',
            'max_daily_hours' => '12.00',
            'normal_daily_hours' => '8.00',
            'normal_weekly_hours' => '45.00',
            'source_ref' => 'Akta Kerja 1955 (pindaan 2022) — UNVERIFIED-STAT untuk had bulanan',
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('overtime_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('basis', 16)->default('statutory');
            $table->unsignedSmallInteger('rounding_unit_minutes')->default(1);
            $table->unsignedSmallInteger('min_minutes_per_day')->default(0);
            $table->unsignedSmallInteger('daily_max_minutes')->nullable();
            $table->unsignedBigInteger('salary_component_id')->nullable(); // FK added in M4
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });

        Schema::create('overtime_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_type_id')->constrained()->cascadeOnDelete();
            $table->string('day_type', 32);
            $table->decimal('beyond_normal_multiplier', 6, 4)->nullable();
            $table->string('within_normal_mode', 32)->nullable();
            $table->decimal('within_normal_orp_days', 5, 2)->nullable();
            $table->unsignedBigInteger('salary_component_id')->nullable();
            $table->timestamps();
            $table->unique(['overtime_type_id', 'day_type']);
        });

        Schema::create('location_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('notice_version');
            $table->boolean('accepted');
            $table->dateTime('decided_at');
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'id']);
        });

        Schema::create('checkin_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('source', 16);
            $table->string('file_path', 255)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->string('error_report_path', 255)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkin_import_batches');
        Schema::dropIfExists('location_consents');
        Schema::dropIfExists('overtime_rules');
        Schema::dropIfExists('overtime_types');
        Schema::dropIfExists('akta_kerja_parameters');
        Schema::dropIfExists('employee_rest_days');
        Schema::dropIfExists('shift_assignment_changes');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shift_schedule_assignments');
        Schema::dropIfExists('shift_schedule_days');
        Schema::dropIfExists('shift_schedules');
        Schema::dropIfExists('shift_location_links');
        Schema::dropIfExists('shift_locations');
        Schema::dropIfExists('employee_attendance_profiles');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['default_shift_type_id']);
        });
        Schema::dropIfExists('shift_types');
        Schema::dropIfExists('attendance_settings');
    }
};
