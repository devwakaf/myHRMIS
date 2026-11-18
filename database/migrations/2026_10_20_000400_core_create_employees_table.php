<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kakitangan (B1.7) and its child tables (B1.9–B1.12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();

            // Peribadi
            $table->string('employee_no', 30);
            $table->string('full_name', 150);
            $table->string('preferred_name', 60)->nullable();
            $table->string('identity_type', 32);
            $table->char('ic_no', 12)->nullable();
            $table->string('old_ic_no', 12)->nullable();
            $table->string('passport_no', 20)->nullable();
            $table->char('passport_country', 2)->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('other_id_no', 20)->nullable();
            $table->date('date_of_birth');
            $table->string('gender', 16);
            $table->string('citizenship_status', 32);
            $table->char('nationality', 2)->default('MY');
            $table->string('race', 32)->nullable();
            $table->string('religion', 32)->nullable();
            $table->string('marital_status', 16)->default('single');
            $table->string('blood_type', 8)->nullable();
            $table->boolean('is_disabled')->default(false);
            $table->string('photo_path')->nullable();

            // Hubungan
            $table->string('mobile_phone', 30);
            $table->string('personal_email')->nullable();
            $table->string('work_email')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_line3')->nullable();
            $table->char('postcode', 5)->nullable();
            $table->string('city')->nullable();
            $table->string('state', 32)->nullable();
            $table->char('country', 2)->default('MY');
            $table->boolean('permanent_same_as_current')->default(true);
            $table->string('permanent_address_line1')->nullable();
            $table->string('permanent_address_line2')->nullable();
            $table->string('permanent_address_line3')->nullable();
            $table->char('permanent_postcode', 5)->nullable();
            $table->string('permanent_city')->nullable();
            $table->string('permanent_state', 32)->nullable();
            $table->char('permanent_country', 2)->nullable();

            // Pekerjaan
            $table->string('status', 16)->default('active');
            $table->date('date_of_joining');
            $table->date('continuous_service_from')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('designation_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employment_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->date('probation_end_date')->nullable();
            $table->date('confirmed_on')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->date('retirement_date')->nullable();
            $table->unsignedBigInteger('default_shift_type_id')->nullable(); // FK added in M3
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('previous_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // Pemberhentian
            $table->date('resignation_date')->nullable();
            $table->date('relieving_date')->nullable();
            $table->string('separation_reason', 32)->nullable();
            $table->text('separation_note')->nullable();
            $table->boolean('eligible_for_rehire')->nullable();

            // Statutori
            $table->string('kwsp_no', 20)->nullable();
            $table->boolean('kwsp_exempt')->default(false);
            $table->string('kwsp_exempt_reason')->nullable();
            $table->decimal('kwsp_employee_rate_override', 9, 4)->nullable();
            $table->string('perkeso_no', 20)->nullable();
            $table->date('perkeso_first_insured_on')->nullable();
            $table->boolean('eis_exempt')->default(false);
            $table->string('work_pass_type', 40)->nullable();
            $table->string('work_pass_no', 30)->nullable();
            $table->date('work_pass_expiry')->nullable();

            // Bank
            $table->string('salary_payment_mode', 20)->default('bank_transfer');
            $table->foreignId('bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('bank_account_no', 20)->nullable();
            $table->string('bank_account_name', 150)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'employee_no']);
            $table->unique(['organisation_id', 'user_id']);
            $table->index(['organisation_id', 'ic_no']);
            $table->index(['organisation_id', 'passport_no', 'passport_country']);
            $table->index(['organisation_id', 'status']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('head_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('employee_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('tin', 14)->nullable();
            $table->string('tax_residency', 16)->default('resident');
            $table->string('mtd_scheme', 32)->default('normal');
            $table->unsignedTinyInteger('pcb_category')->default(1);
            $table->string('pcb_category_override_reason')->nullable();
            $table->boolean('spouse_working')->nullable();
            $table->boolean('spouse_disabled')->default(false);
            $table->unsignedTinyInteger('children_basic')->default(0);
            $table->unsignedTinyInteger('children_higher_ed')->default(0);
            $table->unsignedTinyInteger('children_disabled')->default(0);
            $table->unsignedTinyInteger('children_disabled_higher_ed')->default(0);
            $table->boolean('claims_child_relief')->default(true);
            $table->boolean('zakat_via_payroll')->default(false);
            $table->decimal('zakat_monthly_amount', 15, 2)->nullable();
            $table->string('zakat_authority')->nullable();
            $table->string('zakat_ref_no')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employee_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relationship', 16);
            $table->string('phone', 30);
            $table->string('alt_phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedSmallInteger('remind_before_days')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organisation_id', 'expires_on']);
        });

        Schema::create('employee_work_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'from_date']);
            $table->index(['organisation_id', 'from_date', 'to_date']);
        });

        Schema::create('employee_previous_employments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('employer_name');
            $table->string('position')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->decimal('last_salary', 15, 2)->nullable();
            $table->string('reason_left')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('level', 20);
            $table->string('institution');
            $table->string('field')->nullable();
            $table->unsignedSmallInteger('year_completed')->nullable();
            $table->string('grade_result', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('lindung24_elections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->date('effective_month');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lindung24_elections');
        Schema::dropIfExists('employee_qualifications');
        Schema::dropIfExists('employee_previous_employments');
        Schema::dropIfExists('employee_work_history');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_emergency_contacts');
        Schema::dropIfExists('employee_tax_profiles');
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['head_employee_id']);
        });
        Schema::dropIfExists('employees');
    }
};
