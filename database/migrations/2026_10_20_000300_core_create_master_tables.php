<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teras masters (B1.2–B1.6, B1.10 document types, B1.15 hr_settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->char('postcode', 5)->nullable();
            $table->string('city')->nullable();
            $table->string('state', 32);
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('path', 500)->default('/');
            $table->unsignedInteger('depth')->default(0);
            $table->string('cost_centre_code', 30)->nullable();
            $table->unsignedBigInteger('head_employee_id')->nullable(); // FK added after employees
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
            $table->index('path');
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->smallInteger('rank')->default(0);
            $table->decimal('salary_min', 15, 2)->nullable();
            $table->decimal('salary_max', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });

        Schema::create('employment_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('category', 32);
            $table->boolean('requires_end_date')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->boolean('tracks_expiry')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });

        Schema::create('hr_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('employee_no_format', 40)->default('{CODE}-{#####}');
            $table->string('employee_no_mode', 16)->default('auto');
            $table->unsignedTinyInteger('retirement_age')->default(60);
            $table->unsignedTinyInteger('default_probation_months')->default(3);
            $table->decimal('standard_daily_hours', 4, 2)->default(8);
            $table->decimal('standard_weekly_hours', 5, 2)->default(45);
            $table->unsignedTinyInteger('min_employment_age')->default(14);
            $table->boolean('birthday_digest_to_hr')->default(true);
            $table->boolean('birthday_digest_to_colleagues')->default(false);
            $table->boolean('anniversary_digest')->default(true);
            $table->unsignedSmallInteger('remind_probation_days')->default(14);
            $table->unsignedSmallInteger('remind_contract_days')->default(30);
            $table->unsignedSmallInteger('remind_work_pass_days')->default(90);
            $table->unsignedSmallInteger('remind_document_days')->default(30);
            $table->unsignedSmallInteger('remind_retirement_days')->default(180);
            $table->string('holiday_notice', 16)->default('off');
            $table->json('self_service_editable')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_settings');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('employment_types');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
    }
};
