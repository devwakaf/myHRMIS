<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-level approval engine (A6.3–A6.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('approval_type', 40);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'approval_type']);
        });

        Schema::create('department_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('approval_type', 40);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['department_id', 'approval_type', 'user_id']);
        });

        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('approval_type', 40);
            $table->string('name');
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('prevent_self_approval')->default(true);
            $table->boolean('allow_override')->default(true);
            $table->boolean('notify_applicant_each_step')->default(false);
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'approval_type', 'version']);
            $table->index(['organisation_id', 'approval_type', 'is_active']);
        });

        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('name');
            $table->string('rule_type', 32);
            $table->json('rule_config')->nullable();
            $table->string('mode', 16)->default('any_one');
            $table->boolean('skip_if_applicant')->default(true);
            $table->boolean('skip_if_same_as_previous')->default(true);
            $table->boolean('exclude_submitter')->default(true);
            $table->json('condition')->nullable();
            $table->string('on_no_approver', 16)->default('fail');
            $table->unsignedTinyInteger('remind_after_days')->nullable();
            $table->timestamps();
            $table->unique(['approval_workflow_id', 'sequence']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('approvable_type', 60);
            $table->unsignedBigInteger('approvable_id');
            $table->string('approval_type', 40);
            $table->foreignId('approval_workflow_id')->constrained()->restrictOnDelete();
            $table->foreignId('applicant_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('current_sequence')->nullable();
            $table->json('frozen_attributes')->nullable(); // spec name: attributes (renamed: clashes with Eloquent internals)
            $table->json('choices')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['approvable_type', 'approvable_id']);
            $table->index(['organisation_id', 'status', 'approval_type']);
        });

        Schema::create('approval_request_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_workflow_step_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('name');
            $table->string('mode', 16);
            $table->string('status', 16)->default('waiting');
            $table->string('skip_reason', 32)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamp('no_approver_alerted_at')->nullable();
            $table->timestamps();
            $table->unique(['approval_request_id', 'sequence']);
            $table->index(['status', 'activated_at']);
        });

        Schema::create('approval_step_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('origin', 20)->default('rule');
            $table->string('decision', 16)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['approval_request_step_id', 'user_id']);
            $table->index(['user_id', 'decision']);
        });

        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_request_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['approval_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_step_assignees');
        Schema::dropIfExists('approval_request_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
        Schema::dropIfExists('department_approvers');
        Schema::dropIfExists('employee_approvers');
    }
};
