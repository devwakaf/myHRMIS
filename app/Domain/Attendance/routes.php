<?php

declare(strict_types=1);

use App\Domain\Attendance\Http\Controllers\AttendanceConfigController;
use App\Domain\Attendance\Http\Controllers\AttendanceRecordController;
use App\Domain\Attendance\Http\Controllers\AttendanceReportController;
use App\Domain\Attendance\Http\Controllers\AttendanceRequestController;
use App\Domain\Attendance\Http\Controllers\CheckinController;
use App\Domain\Attendance\Http\Controllers\OvertimeClaimController;
use App\Domain\Attendance\Http\Controllers\ShiftPlanningController;
use App\Domain\Attendance\Http\Controllers\ShiftRequestController;
use Illuminate\Support\Facades\Route;

// Loaded by AttendanceServiceProvider with ['web', 'auth', 'organisation', 'employment'].

// Self-service
Route::get('check-in', [CheckinController::class, 'show'])->name('attendance.checkin');
Route::post('check-in', [CheckinController::class, 'store'])->middleware('throttle:20,1')->name('attendance.checkin.store');
Route::post('check-in/consent', [CheckinController::class, 'consent'])->name('attendance.checkin.consent');
Route::post('check-in/consent/withdraw', [CheckinController::class, 'withdrawConsent'])->name('attendance.checkin.consent.withdraw');
Route::get('me/attendance', [CheckinController::class, 'me'])->name('attendance.me');

// Permohonan Kehadiran
Route::get('attendance-requests', [AttendanceRequestController::class, 'index'])->name('attendance.requests.index');
Route::post('attendance-requests/preview', [AttendanceRequestController::class, 'preview'])->name('attendance.requests.preview');
Route::post('attendance-requests', [AttendanceRequestController::class, 'store'])->name('attendance.requests.store');
Route::get('attendance-requests/{attendanceRequest}', [AttendanceRequestController::class, 'show'])->name('attendance.requests.show');
Route::put('attendance-requests/{attendanceRequest}', [AttendanceRequestController::class, 'update'])->name('attendance.requests.update');
Route::delete('attendance-requests/{attendanceRequest}', [AttendanceRequestController::class, 'destroy'])->name('attendance.requests.destroy');
Route::post('attendance-requests/{attendanceRequest}/submit', [AttendanceRequestController::class, 'submit'])->name('attendance.requests.submit');
Route::post('attendance-requests/{attendanceRequest}/withdraw', [AttendanceRequestController::class, 'withdraw'])->name('attendance.requests.withdraw');
Route::post('attendance-requests/{attendanceRequest}/cancel', [AttendanceRequestController::class, 'cancel'])->name('attendance.requests.cancel');

// Permohonan Shift
Route::get('shift-requests', [ShiftRequestController::class, 'index'])->name('attendance.shift-requests.index');
Route::post('shift-requests', [ShiftRequestController::class, 'store'])->name('attendance.shift-requests.store');
Route::get('shift-requests/{shiftRequest}', [ShiftRequestController::class, 'show'])->name('attendance.shift-requests.show');
Route::put('shift-requests/{shiftRequest}', [ShiftRequestController::class, 'update'])->name('attendance.shift-requests.update');
Route::delete('shift-requests/{shiftRequest}', [ShiftRequestController::class, 'destroy'])->name('attendance.shift-requests.destroy');
Route::post('shift-requests/{shiftRequest}/submit', [ShiftRequestController::class, 'submit'])->name('attendance.shift-requests.submit');
Route::post('shift-requests/{shiftRequest}/withdraw', [ShiftRequestController::class, 'withdraw'])->name('attendance.shift-requests.withdraw');
Route::post('shift-requests/{shiftRequest}/cancel', [ShiftRequestController::class, 'cancel'])->name('attendance.shift-requests.cancel');

// Configuration
Route::get('settings/attendance', [AttendanceConfigController::class, 'settings'])->name('attendance.settings');
Route::put('settings/attendance', [AttendanceConfigController::class, 'updateSettings'])->name('attendance.settings.update');
Route::get('attendance/shift-types', [AttendanceConfigController::class, 'shiftTypes'])->name('attendance.shift-types.index');
Route::post('attendance/shift-types/check', [AttendanceConfigController::class, 'checkShiftType'])->name('attendance.shift-types.check');
Route::post('attendance/shift-types', [AttendanceConfigController::class, 'storeShiftType'])->name('attendance.shift-types.store');
Route::put('attendance/shift-types/{shiftType}', [AttendanceConfigController::class, 'updateShiftType'])->name('attendance.shift-types.update');
Route::get('attendance/locations', [AttendanceConfigController::class, 'locations'])->name('attendance.locations.index');
Route::post('attendance/locations', [AttendanceConfigController::class, 'storeLocation'])->name('attendance.locations.store');
Route::put('attendance/locations/{location}', [AttendanceConfigController::class, 'updateLocation'])->name('attendance.locations.update');
Route::get('attendance/shift-schedules', [AttendanceConfigController::class, 'schedules'])->name('attendance.schedules.index');
Route::post('attendance/shift-schedules', [AttendanceConfigController::class, 'storeSchedule'])->name('attendance.schedules.store');
Route::put('attendance/shift-schedules/{schedule}', [AttendanceConfigController::class, 'updateSchedule'])->name('attendance.schedules.update');
Route::get('attendance/overtime-types', [AttendanceConfigController::class, 'overtimeTypes'])->name('attendance.overtime-types.index');
Route::post('attendance/overtime-types', [AttendanceConfigController::class, 'storeOvertimeType'])->name('attendance.overtime-types.store');
Route::put('attendance/overtime-types/{overtimeType}', [AttendanceConfigController::class, 'updateOvertimeType'])->name('attendance.overtime-types.update');
Route::get('attendance/employees/{employee}/profile', [AttendanceConfigController::class, 'profile'])->name('attendance.profile');
Route::put('attendance/employees/{employee}/profile', [AttendanceConfigController::class, 'updateProfile'])->name('attendance.profile.update');

// Penugasan Shift & Roster
Route::get('attendance/shift-assignments', [ShiftPlanningController::class, 'assignments'])->name('attendance.assignments.index');
Route::post('attendance/shift-assignments', [ShiftPlanningController::class, 'storeAssignment'])->name('attendance.assignments.store');
Route::post('attendance/shift-assignments/{assignment}/cancel', [ShiftPlanningController::class, 'cancelAssignment'])->name('attendance.assignments.cancel');
Route::post('attendance/shift-assignments/{assignment}/end', [ShiftPlanningController::class, 'endAssignment'])->name('attendance.assignments.end');
Route::get('attendance/roster', [ShiftPlanningController::class, 'roster'])->name('attendance.roster');
Route::post('attendance/roster', [ShiftPlanningController::class, 'rosterAction'])->name('attendance.roster.action');

// Records, bulk marking, review queue, check-in log
Route::get('attendance/records', [AttendanceRecordController::class, 'index'])->name('attendance.records.index');
Route::get('attendance/records/{attendance}', [AttendanceRecordController::class, 'show'])->name('attendance.records.show');
Route::post('attendance/records/{attendance}/cancel', [AttendanceRecordController::class, 'cancel'])->name('attendance.records.cancel');
Route::post('attendance/records/reprocess-date', [AttendanceRecordController::class, 'reprocessDate'])->name('attendance.records.reprocess-date');
Route::get('attendance/bulk-mark', [AttendanceRecordController::class, 'bulkMarkPage'])->name('attendance.bulk-mark');
Route::post('attendance/bulk-mark', [AttendanceRecordController::class, 'bulkMark'])->name('attendance.bulk-mark.store');
Route::get('attendance/bulk-mark/results/{token}', [AttendanceRecordController::class, 'bulkResult'])->name('attendance.bulk-mark.result');
Route::get('attendance/review', [AttendanceRecordController::class, 'review'])->name('attendance.review');
Route::post('attendance/review', [AttendanceRecordController::class, 'reviewAction'])->name('attendance.review.action');
Route::get('attendance/checkins', [AttendanceRecordController::class, 'checkins'])->name('attendance.checkins');
Route::post('attendance/checkins/{checkin}/void', [AttendanceRecordController::class, 'voidCheckin'])->name('attendance.checkins.void');
Route::post('attendance/process-now', [AttendanceRecordController::class, 'processNow'])->name('attendance.process-now');

// Tuntutan OT
Route::get('attendance/overtime-claims', [OvertimeClaimController::class, 'index'])->name('attendance.overtime-claims.index');
Route::post('attendance/overtime-claims/generate', [OvertimeClaimController::class, 'generate'])->name('attendance.overtime-claims.generate');
Route::get('attendance/overtime-claims/{claim}', [OvertimeClaimController::class, 'show'])->name('attendance.overtime-claims.show');
Route::put('attendance/overtime-claims/{claim}', [OvertimeClaimController::class, 'update'])->name('attendance.overtime-claims.update');
Route::delete('attendance/overtime-claims/{claim}', [OvertimeClaimController::class, 'destroy'])->name('attendance.overtime-claims.destroy');
Route::delete('attendance/overtime-claims/{claim}/lines/{line}', [OvertimeClaimController::class, 'destroyLine'])->name('attendance.overtime-claims.lines.destroy');
Route::post('attendance/overtime-claims/{claim}/submit', [OvertimeClaimController::class, 'submit'])->name('attendance.overtime-claims.submit');
Route::post('attendance/overtime-claims/{claim}/cancel', [OvertimeClaimController::class, 'cancel'])->name('attendance.overtime-claims.cancel');

// Laporan Kehadiran
Route::get('reports/attendance/{report}', [AttendanceReportController::class, 'show'])
    ->whereIn('report', AttendanceReportController::REPORTS)->name('attendance.reports.show');
Route::get('reports/attendance/{report}/export', [AttendanceReportController::class, 'export'])
    ->whereIn('report', AttendanceReportController::REPORTS)->name('attendance.reports.export');
