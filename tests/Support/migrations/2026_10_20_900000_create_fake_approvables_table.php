<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only table for Tests\Support\FakeApprovable (spec-00 B8 #43–61).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_approvables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained();
            $table->foreignId('employee_id')->constrained();
            $table->decimal('days', 8, 3)->default(1);
            $table->string('note')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('amended_from_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_approvables');
    }
};
