<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pergerakan Kakitangan (B1.14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->date('effective_date');
            $table->foreignId('new_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('new_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('new_designation_id')->nullable()->constrained('designations')->restrictOnDelete();
            $table->foreignId('new_grade_id')->nullable()->constrained('grades')->restrictOnDelete();
            $table->foreignId('new_employment_type_id')->nullable()->constrained('employment_types')->restrictOnDelete();
            $table->foreignId('new_reports_to_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->date('new_contract_end_date')->nullable();
            $table->foreignId('target_organisation_id')->nullable()->constrained('organisations')->restrictOnDelete();
            $table->foreignId('target_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('reason')->nullable();
            $table->text('salary_note')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('amended_from_id')->nullable()->constrained('employee_movements')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['employee_id', 'status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_movements');
    }
};
