<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Senarai Cuti Umum, holidays and holiday list assignments (B1.13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('state', 32)->nullable();
            $table->json('weekly_off_days')->nullable();
            $table->json('half_day_weekly_off_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_list_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('kind', 16);
            $table->boolean('is_half_day')->default(false);
            $table->boolean('is_mandatory')->default(false);
            $table->foreignId('source_public_holiday_id')->nullable()->constrained('public_holidays')->nullOnDelete();
            $table->timestamps();
            $table->unique(['holiday_list_id', 'date']);
        });

        Schema::create('holiday_list_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('holiday_list_id')->constrained()->restrictOnDelete();
            $table->string('assignable_type', 16);
            $table->unsignedBigInteger('assignable_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('draft');
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('amended_from_id')->nullable()->constrained('holiday_list_assignments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['organisation_id', 'assignable_type', 'assignable_id', 'status'], 'hla_assignable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_list_assignments');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('holiday_lists');
    }
};
