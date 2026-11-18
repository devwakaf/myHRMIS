<?php

declare(strict_types=1);

use App\Domain\Shared\Http\Controllers\ApprovalInboxController;
use App\Domain\Shared\Http\Controllers\ApprovalWorkflowController;
use App\Domain\Shared\Http\Controllers\AuditLogController;
use App\Domain\Shared\Http\Controllers\DashboardController;
use App\Domain\Shared\Http\Controllers\OrganisationSwitchController;
use App\Domain\Shared\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Loaded by DomainServiceProvider with ['web', 'auth', 'organisation', 'employment'].

Route::get('dashboard', DashboardController::class)->name('dashboard');
Route::put('organisation/current', [OrganisationSwitchController::class, 'update'])->name('organisation.switch');

Route::get('users', [UserController::class, 'index'])->name('system.users.index');
Route::post('users', [UserController::class, 'store'])->name('system.users.store');
Route::put('users/{user}', [UserController::class, 'update'])->name('system.users.update');

Route::get('audit', [AuditLogController::class, 'index'])->name('system.audit.index');

Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');
Route::post('approvals/bulk-approve', [ApprovalInboxController::class, 'bulkApprove'])->name('approvals.bulk-approve');
Route::post('approvals/{approvalRequest}/approve', [ApprovalInboxController::class, 'approve'])->name('approvals.approve');
Route::post('approvals/{approvalRequest}/reject', [ApprovalInboxController::class, 'reject'])->name('approvals.reject');
Route::post('approvals/{approvalRequest}/override', [ApprovalInboxController::class, 'override'])->name('approvals.override');
Route::post('approvals/{approvalRequest}/reassign', [ApprovalInboxController::class, 'reassign'])->name('approvals.reassign');
Route::post('approvals/{approvalRequest}/cancel', [ApprovalInboxController::class, 'cancel'])->name('approvals.cancel');

Route::get('settings/approval-workflows', [ApprovalWorkflowController::class, 'index'])->name('approval-workflows.index');
Route::get('settings/approval-workflows/{type}', [ApprovalWorkflowController::class, 'edit'])->name('approval-workflows.edit');
Route::put('settings/approval-workflows/{type}', [ApprovalWorkflowController::class, 'update'])->name('approval-workflows.update');
Route::post('settings/approval-workflows/{type}/preview', [ApprovalWorkflowController::class, 'preview'])->name('approval-workflows.preview');
