<?php

declare(strict_types=1);

use App\Domain\Core\Http\Controllers\DepartmentController;
use App\Domain\Core\Http\Controllers\EmployeeController;
use App\Domain\Core\Http\Controllers\EmployeeDocumentController;
use App\Domain\Core\Http\Controllers\EmployeeMovementController;
use App\Domain\Core\Http\Controllers\EmployeeRecordController;
use App\Domain\Core\Http\Controllers\HolidayAssignmentController;
use App\Domain\Core\Http\Controllers\HolidayListController;
use App\Domain\Core\Http\Controllers\HrSettingsController;
use App\Domain\Core\Http\Controllers\MasterController;
use App\Domain\Core\Http\Controllers\OrganisationController;
use App\Domain\Core\Http\Controllers\ProfileController;
use App\Domain\Core\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Loaded by DomainServiceProvider with ['web', 'auth', 'organisation', 'employment'].

Route::get('organisations', [OrganisationController::class, 'index'])->name('core.organisations.index');
Route::get('organisations/create', [OrganisationController::class, 'create'])->name('core.organisations.create');
Route::post('organisations', [OrganisationController::class, 'store'])->name('core.organisations.store');
Route::get('organisations/{organisation}/edit', [OrganisationController::class, 'edit'])->name('core.organisations.edit');
Route::put('organisations/{organisation}', [OrganisationController::class, 'update'])->name('core.organisations.update');

// Simple masters: branches, designations, grades, employment-types, document-types
Route::get('{master}', [MasterController::class, 'index'])
    ->whereIn('master', MasterController::KINDS)->name('core.masters.index');
Route::post('{master}', [MasterController::class, 'store'])
    ->whereIn('master', MasterController::KINDS)->name('core.masters.store');
Route::put('{master}/{id}', [MasterController::class, 'update'])
    ->whereIn('master', MasterController::KINDS)->whereNumber('id')->name('core.masters.update');

Route::get('departments', [DepartmentController::class, 'index'])->name('core.departments.index');
Route::post('departments', [DepartmentController::class, 'store'])->name('core.departments.store');
Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('core.departments.update');
Route::put('departments/{department}/approvers', [DepartmentController::class, 'approvers'])->name('core.departments.approvers');

Route::get('employees/export', [EmployeeController::class, 'export'])->name('core.employees.export');
Route::get('employees', [EmployeeController::class, 'index'])->name('core.employees.index');
Route::get('employees/create', [EmployeeController::class, 'create'])->name('core.employees.create');
Route::post('employees', [EmployeeController::class, 'store'])->name('core.employees.store');
Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('core.employees.show');
Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('core.employees.edit');
Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('core.employees.update');

Route::post('employees/{employee}/correction', [EmployeeRecordController::class, 'correct'])->name('core.employees.correct');
Route::post('employees/{employee}/separation', [EmployeeRecordController::class, 'separate'])->name('core.employees.separate');
Route::post('employees/{employee}/reassign-reports', [EmployeeRecordController::class, 'reassignReports'])->name('core.employees.reassign-reports');
Route::post('employees/{employee}/user-account', [EmployeeRecordController::class, 'createUser'])->name('core.employees.user-account');
Route::post('employees/{employee}/rehire', [EmployeeRecordController::class, 'rehire'])->name('core.employees.rehire');
Route::put('employees/{employee}/approvers', [EmployeeRecordController::class, 'approvers'])->name('core.employees.approvers');
Route::put('employees/{employee}/emergency-contacts', [EmployeeRecordController::class, 'emergencyContacts'])->name('core.employees.emergency-contacts');
Route::post('employees/{employee}/qualifications', [EmployeeRecordController::class, 'addQualification'])->name('core.employees.qualifications.store');
Route::delete('employees/{employee}/qualifications/{qualification}', [EmployeeRecordController::class, 'deleteQualification'])->name('core.employees.qualifications.destroy');
Route::post('employees/{employee}/previous-employments', [EmployeeRecordController::class, 'addPreviousEmployment'])->name('core.employees.previous.store');
Route::delete('employees/{employee}/previous-employments/{previous}', [EmployeeRecordController::class, 'deletePreviousEmployment'])->name('core.employees.previous.destroy');
Route::post('employees/{employee}/lindung24', [EmployeeRecordController::class, 'lindung24'])->name('core.employees.lindung24');

Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->name('core.documents.store');
Route::get('employee-documents/{document}/download', [EmployeeDocumentController::class, 'download'])->name('core.documents.download');
Route::delete('employee-documents/{document}', [EmployeeDocumentController::class, 'destroy'])->name('core.documents.destroy');

Route::get('holiday-lists', [HolidayListController::class, 'index'])->name('core.holiday-lists.index');
Route::post('holiday-lists', [HolidayListController::class, 'store'])->name('core.holiday-lists.store');
Route::get('holiday-lists/{holidayList}', [HolidayListController::class, 'show'])->name('core.holiday-lists.show');
Route::put('holiday-lists/{holidayList}', [HolidayListController::class, 'update'])->name('core.holiday-lists.update');
Route::post('holiday-lists/{holidayList}/holidays', [HolidayListController::class, 'storeHoliday'])->name('core.holidays.store');
Route::put('holiday-lists/{holidayList}/holidays/{holiday}', [HolidayListController::class, 'updateHoliday'])->name('core.holidays.update');
Route::delete('holiday-lists/{holidayList}/holidays/{holiday}', [HolidayListController::class, 'destroyHoliday'])->name('core.holidays.destroy');
Route::post('holiday-lists/{holidayList}/weekly-offs', [HolidayListController::class, 'generateWeeklyOffs'])->name('core.holiday-lists.weekly-offs');
Route::post('holiday-lists/{holidayList}/import', [HolidayListController::class, 'import'])->name('core.holiday-lists.import');
Route::post('holiday-lists/{holidayList}/copy', [HolidayListController::class, 'copy'])->name('core.holiday-lists.copy');

Route::get('holiday-assignments', [HolidayAssignmentController::class, 'index'])->name('core.holiday-assignments.index');
Route::post('holiday-assignments', [HolidayAssignmentController::class, 'store'])->name('core.holiday-assignments.store');
Route::post('holiday-assignments/bulk', [HolidayAssignmentController::class, 'bulk'])->name('core.holiday-assignments.bulk');
Route::put('holiday-assignments/{assignment}', [HolidayAssignmentController::class, 'update'])->name('core.holiday-assignments.update');
Route::delete('holiday-assignments/{assignment}', [HolidayAssignmentController::class, 'destroy'])->name('core.holiday-assignments.destroy');
Route::post('holiday-assignments/{assignment}/submit', [HolidayAssignmentController::class, 'submit'])->name('core.holiday-assignments.submit');
Route::post('holiday-assignments/{assignment}/cancel', [HolidayAssignmentController::class, 'cancel'])->name('core.holiday-assignments.cancel');
Route::post('holiday-assignments/{assignment}/cancel-duplicate', [HolidayAssignmentController::class, 'cancelDuplicate'])->name('core.holiday-assignments.cancel-duplicate');

Route::get('employee-movements', [EmployeeMovementController::class, 'index'])->name('core.movements.index');
Route::get('employee-movements/create', [EmployeeMovementController::class, 'create'])->name('core.movements.create');
Route::post('employee-movements', [EmployeeMovementController::class, 'store'])->name('core.movements.store');
Route::get('employee-movements/{movement}', [EmployeeMovementController::class, 'show'])->name('core.movements.show');
Route::get('employee-movements/{movement}/edit', [EmployeeMovementController::class, 'edit'])->name('core.movements.edit');
Route::put('employee-movements/{movement}', [EmployeeMovementController::class, 'update'])->name('core.movements.update');
Route::delete('employee-movements/{movement}', [EmployeeMovementController::class, 'destroy'])->name('core.movements.destroy');
Route::post('employee-movements/{movement}/submit', [EmployeeMovementController::class, 'submit'])->name('core.movements.submit');
Route::post('employee-movements/{movement}/cancel', [EmployeeMovementController::class, 'cancel'])->name('core.movements.cancel');
Route::post('employee-movements/{movement}/cancel-duplicate', [EmployeeMovementController::class, 'cancelDuplicate'])->name('core.movements.cancel-duplicate');

Route::get('settings/hr', [HrSettingsController::class, 'edit'])->name('core.settings.edit');
Route::put('settings/hr', [HrSettingsController::class, 'update'])->name('core.settings.update');

Route::get('me', [ProfileController::class, 'show'])->name('core.me.show');
Route::put('me', [ProfileController::class, 'update'])->name('core.me.update');

Route::get('reports/core/{report}', [ReportController::class, 'show'])
    ->whereIn('report', ReportController::REPORTS)->name('core.reports.show');
Route::get('reports/core/{report}/export', [ReportController::class, 'export'])
    ->whereIn('report', ReportController::REPORTS)->name('core.reports.export');
