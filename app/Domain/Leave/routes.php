<?php

declare(strict_types=1);

use App\Domain\Leave\Http\Controllers\CompensatoryRequestController;
use App\Domain\Leave\Http\Controllers\LeaveAllocationController;
use App\Domain\Leave\Http\Controllers\LeaveApplicationController;
use App\Domain\Leave\Http\Controllers\LeaveConfigController;
use App\Domain\Leave\Http\Controllers\LeaveReportController;
use Illuminate\Support\Facades\Route;

// Loaded by LeaveServiceProvider with ['web', 'auth', 'organisation', 'employment'].

// Self-service and applications
Route::get('leave/me', [LeaveApplicationController::class, 'me'])->name('leave.me');
Route::get('leave/calendar', [LeaveApplicationController::class, 'calendar'])->name('leave.calendar');
Route::get('leave/employees/{employee}/balances', [LeaveApplicationController::class, 'balances'])->name('leave.balances');
Route::get('leave/applications', [LeaveApplicationController::class, 'index'])->name('leave.applications.index');
Route::get('leave/applications/create', [LeaveApplicationController::class, 'create'])->name('leave.applications.create');
Route::post('leave/applications/preview', [LeaveApplicationController::class, 'preview'])->name('leave.applications.preview');
Route::post('leave/applications', [LeaveApplicationController::class, 'store'])->name('leave.applications.store');
Route::get('leave/applications/{application}', [LeaveApplicationController::class, 'show'])->name('leave.applications.show');
Route::put('leave/applications/{application}', [LeaveApplicationController::class, 'update'])->name('leave.applications.update');
Route::delete('leave/applications/{application}', [LeaveApplicationController::class, 'destroy'])->name('leave.applications.destroy');
Route::post('leave/applications/{application}/submit', [LeaveApplicationController::class, 'submit'])->name('leave.applications.submit');
Route::post('leave/applications/{application}/withdraw', [LeaveApplicationController::class, 'withdraw'])->name('leave.applications.withdraw');
Route::post('leave/applications/{application}/cancel', [LeaveApplicationController::class, 'cancel'])->name('leave.applications.cancel');
Route::post('leave/applications/{application}/cancel-copy', [LeaveApplicationController::class, 'cancelCopy'])->name('leave.applications.cancel-copy');
Route::post('leave/applications/{application}/attachments', [LeaveApplicationController::class, 'storeAttachment'])->name('leave.applications.attachments.store');
Route::get('leave/attachments/{attachment}/download', [LeaveApplicationController::class, 'downloadAttachment'])->name('leave.attachments.download');

// Cuti Gantian
Route::get('leave/compensatory-requests', [CompensatoryRequestController::class, 'index'])->name('leave.compensatory.index');
Route::post('leave/compensatory-requests', [CompensatoryRequestController::class, 'store'])->name('leave.compensatory.store');
Route::put('leave/compensatory-requests/{compensatory}', [CompensatoryRequestController::class, 'update'])->name('leave.compensatory.update');
Route::delete('leave/compensatory-requests/{compensatory}', [CompensatoryRequestController::class, 'destroy'])->name('leave.compensatory.destroy');
Route::post('leave/compensatory-requests/{compensatory}/submit', [CompensatoryRequestController::class, 'submit'])->name('leave.compensatory.submit');
Route::post('leave/compensatory-requests/{compensatory}/cancel', [CompensatoryRequestController::class, 'cancel'])->name('leave.compensatory.cancel');

// Configuration
Route::get('leave/types', [LeaveConfigController::class, 'types'])->name('leave.types.index');
Route::post('leave/types', [LeaveConfigController::class, 'storeType'])->name('leave.types.store');
Route::put('leave/types/{type}', [LeaveConfigController::class, 'updateType'])->name('leave.types.update');
Route::get('leave/periods', [LeaveConfigController::class, 'periods'])->name('leave.periods.index');
Route::post('leave/periods', [LeaveConfigController::class, 'storePeriod'])->name('leave.periods.store');
Route::put('leave/periods/{period}', [LeaveConfigController::class, 'updatePeriod'])->name('leave.periods.update');
Route::delete('leave/periods/{period}', [LeaveConfigController::class, 'destroyPeriod'])->name('leave.periods.destroy');
Route::post('leave/periods/{period}/generate', [LeaveConfigController::class, 'generate'])->name('leave.periods.generate');
Route::get('leave/policies', [LeaveConfigController::class, 'policies'])->name('leave.policies.index');
Route::post('leave/policies', [LeaveConfigController::class, 'storePolicy'])->name('leave.policies.store');
Route::put('leave/policies/{policy}', [LeaveConfigController::class, 'updatePolicy'])->name('leave.policies.update');
Route::get('leave/aggregate-caps', [LeaveConfigController::class, 'caps'])->name('leave.caps.index');
Route::post('leave/aggregate-caps', [LeaveConfigController::class, 'storeCap'])->name('leave.caps.store');
Route::put('leave/aggregate-caps/{cap}', [LeaveConfigController::class, 'updateCap'])->name('leave.caps.update');
Route::get('leave/block-lists', [LeaveConfigController::class, 'blockLists'])->name('leave.block-lists.index');
Route::post('leave/block-lists', [LeaveConfigController::class, 'storeBlockList'])->name('leave.block-lists.store');
Route::put('leave/block-lists/{blockList}', [LeaveConfigController::class, 'updateBlockList'])->name('leave.block-lists.update');
Route::get('settings/leave', [LeaveConfigController::class, 'settings'])->name('leave.settings.edit');
Route::put('settings/leave', [LeaveConfigController::class, 'updateSettings'])->name('leave.settings.update');

// Ledger administration (HR)
Route::get('leave/policy-assignments', [LeaveAllocationController::class, 'assignments'])->name('leave.assignments.index');
Route::post('leave/policy-assignments', [LeaveAllocationController::class, 'storeAssignment'])->name('leave.assignments.store');
Route::post('leave/policy-assignments/{assignment}/submit', [LeaveAllocationController::class, 'submitAssignment'])->name('leave.assignments.submit');
Route::post('leave/policy-assignments/{assignment}/cancel', [LeaveAllocationController::class, 'cancelAssignment'])->name('leave.assignments.cancel');
Route::delete('leave/policy-assignments/{assignment}', [LeaveAllocationController::class, 'destroyAssignment'])->name('leave.assignments.destroy');
Route::get('leave/allocations', [LeaveAllocationController::class, 'allocations'])->name('leave.allocations.index');
Route::post('leave/allocations', [LeaveAllocationController::class, 'storeAllocation'])->name('leave.allocations.store');
Route::get('leave/allocations/{allocation}', [LeaveAllocationController::class, 'showAllocation'])->name('leave.allocations.show');
Route::post('leave/allocations/{allocation}/submit', [LeaveAllocationController::class, 'submitAllocation'])->name('leave.allocations.submit');
Route::post('leave/allocations/{allocation}/cancel', [LeaveAllocationController::class, 'cancelAllocation'])->name('leave.allocations.cancel');
Route::post('leave/allocations/{allocation}/recompute-carry', [LeaveAllocationController::class, 'recomputeCarry'])->name('leave.allocations.recompute-carry');
Route::delete('leave/allocations/{allocation}', [LeaveAllocationController::class, 'destroyAllocation'])->name('leave.allocations.destroy');
Route::get('leave/credit-schedules', [LeaveAllocationController::class, 'creditSchedules'])->name('leave.credit-schedules.index');
Route::post('leave/credit-schedules/{schedule}/retry', [LeaveAllocationController::class, 'retryCredit'])->name('leave.credit-schedules.retry');
Route::get('leave/adjustments', [LeaveAllocationController::class, 'adjustments'])->name('leave.adjustments.index');
Route::post('leave/adjustments', [LeaveAllocationController::class, 'storeAdjustment'])->name('leave.adjustments.store');
Route::post('leave/adjustments/{adjustment}/submit', [LeaveAllocationController::class, 'submitAdjustment'])->name('leave.adjustments.submit');
Route::post('leave/adjustments/{adjustment}/cancel', [LeaveAllocationController::class, 'cancelAdjustment'])->name('leave.adjustments.cancel');
Route::delete('leave/adjustments/{adjustment}', [LeaveAllocationController::class, 'destroyAdjustment'])->name('leave.adjustments.destroy');
Route::get('leave/encashments', [LeaveAllocationController::class, 'encashments'])->name('leave.encashments.index');
Route::post('leave/encashments/preview', [LeaveAllocationController::class, 'previewEncashment'])->name('leave.encashments.preview');
Route::post('leave/encashments', [LeaveAllocationController::class, 'storeEncashment'])->name('leave.encashments.store');
Route::post('leave/encashments/{encashment}/submit', [LeaveAllocationController::class, 'submitEncashment'])->name('leave.encashments.submit');
Route::post('leave/encashments/{encashment}/cancel', [LeaveAllocationController::class, 'cancelEncashment'])->name('leave.encashments.cancel');
Route::delete('leave/encashments/{encashment}', [LeaveAllocationController::class, 'destroyEncashment'])->name('leave.encashments.destroy');
Route::get('leave/review-tasks', [LeaveAllocationController::class, 'reviewTasks'])->name('leave.review-tasks.index');
Route::post('leave/review-tasks/{task}/resolve', [LeaveAllocationController::class, 'resolveReviewTask'])->name('leave.review-tasks.resolve');

// Laporan Cuti (spec-02 §6.2)
Route::get('reports/leave/{report}', [LeaveReportController::class, 'show'])
    ->whereIn('report', LeaveReportController::REPORTS)->name('leave.reports.show');
Route::get('reports/leave/{report}/export', [LeaveReportController::class, 'export'])
    ->whereIn('report', LeaveReportController::REPORTS)->name('leave.reports.export');
