<?php

declare(strict_types=1);

use App\Domain\Payroll\Http\Controllers\EmployeePayrollController;
use App\Domain\Payroll\Http\Controllers\PayrollAdjustmentController;
use App\Domain\Payroll\Http\Controllers\PayrollConfigController;
use App\Domain\Payroll\Http\Controllers\PayrollReportController;
use App\Domain\Payroll\Http\Controllers\PayrollRunController;
use App\Domain\Payroll\Http\Controllers\SalarySlipController;
use Illuminate\Support\Facades\Route;

// Loaded by PayrollServiceProvider with ['web', 'auth', 'organisation', 'employment'].

// Configuration (spec-04 §1.1)
Route::get('payroll/components', [PayrollConfigController::class, 'components'])->name('payroll.components.index');
Route::post('payroll/components', [PayrollConfigController::class, 'storeComponent'])->name('payroll.components.store');
Route::put('payroll/components/{component}', [PayrollConfigController::class, 'updateComponent'])->name('payroll.components.update');
Route::post('payroll/formula/test', [PayrollConfigController::class, 'testFormula'])->name('payroll.formula.test');
Route::get('payroll/structures', [PayrollConfigController::class, 'structures'])->name('payroll.structures.index');
Route::get('payroll/structures/create', [PayrollConfigController::class, 'editStructure'])->name('payroll.structures.create');
Route::post('payroll/structures/dry-run', [PayrollConfigController::class, 'dryRun'])->name('payroll.structures.dry-run');
Route::post('payroll/structures', [PayrollConfigController::class, 'storeStructure'])->name('payroll.structures.store');
Route::get('payroll/structures/{structure}/edit', [PayrollConfigController::class, 'editStructure'])->name('payroll.structures.edit');
Route::put('payroll/structures/{structure}', [PayrollConfigController::class, 'updateStructure'])->name('payroll.structures.update');
Route::post('payroll/structures/{structure}/copy', [PayrollConfigController::class, 'copyStructure'])->name('payroll.structures.copy');
Route::get('payroll/assignments', [PayrollConfigController::class, 'assignments'])->name('payroll.assignments.index');
Route::post('payroll/assignments', [PayrollConfigController::class, 'storeAssignment'])->name('payroll.assignments.store');
Route::post('payroll/assignments/bulk', [PayrollConfigController::class, 'bulkAssignments'])->name('payroll.assignments.bulk');
Route::put('payroll/assignments/{assignment}', [PayrollConfigController::class, 'updateAssignment'])->name('payroll.assignments.update');
Route::post('payroll/assignments/{assignment}/submit', [PayrollConfigController::class, 'submitAssignment'])->name('payroll.assignments.submit');
Route::post('payroll/assignments/{assignment}/cancel', [PayrollConfigController::class, 'cancelAssignment'])->name('payroll.assignments.cancel');
Route::post('payroll/assignments/{assignment}/prefill-rehire', [PayrollConfigController::class, 'prefillRehire'])->name('payroll.assignments.prefill');
Route::get('payroll/settings', [PayrollConfigController::class, 'settings'])->name('payroll.settings.edit');
Route::put('payroll/settings', [PayrollConfigController::class, 'updateSettings'])->name('payroll.settings.update');
Route::post('payroll/bank-accounts', [PayrollConfigController::class, 'storeBankAccount'])->name('payroll.bank-accounts.store');
Route::put('payroll/bank-accounts/{account}', [PayrollConfigController::class, 'updateBankAccount'])->name('payroll.bank-accounts.update');

// Payroll Run wizard (§1.2, §5)
Route::get('payroll/runs', [PayrollRunController::class, 'index'])->name('payroll.runs.index');
Route::post('payroll/runs', [PayrollRunController::class, 'store'])->name('payroll.runs.store');
Route::get('payroll/runs/{run}', [PayrollRunController::class, 'show'])->name('payroll.runs.show');
Route::put('payroll/runs/{run}', [PayrollRunController::class, 'update'])->name('payroll.runs.update');
Route::delete('payroll/runs/{run}', [PayrollRunController::class, 'destroy'])->name('payroll.runs.destroy');
Route::post('payroll/runs/{run}/generate', [PayrollRunController::class, 'generate'])->name('payroll.runs.generate');
Route::get('payroll/runs/{run}/progress', [PayrollRunController::class, 'progress'])->name('payroll.runs.progress');
Route::post('payroll/runs/{run}/employees/{employee}/exclude', [PayrollRunController::class, 'exclude'])->whereNumber('employee')->name('payroll.runs.exclude');
Route::post('payroll/runs/{run}/employees/{employee}/include', [PayrollRunController::class, 'include'])->whereNumber('employee')->name('payroll.runs.include');
Route::post('payroll/runs/{run}/employees/{employee}/override-lwp', [PayrollRunController::class, 'overrideLwp'])->whereNumber('employee')->name('payroll.runs.override-lwp');
Route::post('payroll/runs/{run}/employees/{employee}/override-pcb', [PayrollRunController::class, 'overridePcb'])->whereNumber('employee')->name('payroll.runs.override-pcb');
Route::post('payroll/runs/{run}/discard', [PayrollRunController::class, 'discard'])->name('payroll.runs.discard');
Route::post('payroll/runs/{run}/submit', [PayrollRunController::class, 'submit'])->name('payroll.runs.submit');
Route::post('payroll/runs/{run}/mark-paid', [PayrollRunController::class, 'markPaid'])->name('payroll.runs.mark-paid');
Route::post('payroll/runs/{run}/cancel', [PayrollRunController::class, 'cancel'])->name('payroll.runs.cancel');
Route::post('payroll/runs/{run}/bank-file', [PayrollRunController::class, 'bankFile'])->name('payroll.runs.bank-file');
Route::post('payroll/runs/{run}/summary-csv', [PayrollRunController::class, 'summaryCsv'])->name('payroll.runs.summary-csv');
Route::get('payroll/runs/{run}/cash-list', [PayrollRunController::class, 'cashList'])->name('payroll.runs.cash-list');
Route::get('payroll/runs/{run}/cash-list/pdf', [PayrollRunController::class, 'cashListPdf'])->name('payroll.runs.cash-list-pdf');
Route::get('payroll/periods', [PayrollRunController::class, 'periods'])->name('payroll.periods.index');
Route::get('payroll/files/{file}/download', [PayrollRunController::class, 'download'])->name('payroll.files.download');

// Slips (§1.4)
Route::get('payroll/slips', [SalarySlipController::class, 'index'])->name('payroll.slips.index');
Route::get('payroll/slips/{slip}', [SalarySlipController::class, 'show'])->name('payroll.slips.show');
Route::get('payroll/slips/{slip}/pdf', [SalarySlipController::class, 'pdf'])->name('payroll.slips.pdf');
Route::post('payroll/slips/{slip}/resend', [SalarySlipController::class, 'resend'])->name('payroll.slips.resend');
Route::post('payroll/slips/{slip}/mark-paid', [SalarySlipController::class, 'markPaid'])->name('payroll.slips.mark-paid');
Route::get('me/payslips', [SalarySlipController::class, 'mine'])->name('payroll.me.payslips');
Route::get('me/payslips/{slip}', [SalarySlipController::class, 'mineShow'])->name('payroll.me.payslip');
Route::get('me/payslips/{slip}/pdf', [SalarySlipController::class, 'minePdf'])->name('payroll.me.payslip-pdf');
Route::get('me/ea', [SalarySlipController::class, 'myEa'])->name('payroll.me.ea');
Route::get('me/ea/{form}/pdf', [SalarySlipController::class, 'myEaPdf'])->name('payroll.me.ea-pdf');

// Adjustments (§1.3)
Route::get('payroll/additional-salaries', [PayrollAdjustmentController::class, 'additional'])->name('payroll.additional.index');
Route::post('payroll/additional-salaries', [PayrollAdjustmentController::class, 'storeAdditional'])->name('payroll.additional.store');
Route::put('payroll/additional-salaries/{additional}', [PayrollAdjustmentController::class, 'updateAdditional'])->name('payroll.additional.update');
Route::post('payroll/additional-salaries/{additional}/submit', [PayrollAdjustmentController::class, 'submitAdditional'])->name('payroll.additional.submit');
Route::post('payroll/additional-salaries/{additional}/cancel', [PayrollAdjustmentController::class, 'cancelAdditional'])->name('payroll.additional.cancel');
Route::get('payroll/arrears', [PayrollAdjustmentController::class, 'arrears'])->name('payroll.arrears.index');
Route::post('payroll/arrears', [PayrollAdjustmentController::class, 'storeArrear'])->name('payroll.arrears.store');
Route::post('payroll/arrears/{arrear}/submit', [PayrollAdjustmentController::class, 'submitArrear'])->name('payroll.arrears.submit');
Route::post('payroll/arrears/{arrear}/cancel', [PayrollAdjustmentController::class, 'cancelArrear'])->name('payroll.arrears.cancel');
Route::get('payroll/corrections', [PayrollAdjustmentController::class, 'corrections'])->name('payroll.corrections.index');
Route::post('payroll/corrections/preview', [PayrollAdjustmentController::class, 'previewCorrection'])->name('payroll.corrections.preview');
Route::post('payroll/corrections', [PayrollAdjustmentController::class, 'storeCorrection'])->name('payroll.corrections.store');
Route::post('payroll/corrections/{correction}/submit', [PayrollAdjustmentController::class, 'submitCorrection'])->name('payroll.corrections.submit');
Route::post('payroll/corrections/{correction}/cancel', [PayrollAdjustmentController::class, 'cancelCorrection'])->name('payroll.corrections.cancel');
Route::get('payroll/withholdings', [PayrollAdjustmentController::class, 'withholdings'])->name('payroll.withholdings.index');
Route::post('payroll/withholdings', [PayrollAdjustmentController::class, 'storeWithholding'])->name('payroll.withholdings.store');
Route::post('payroll/withholdings/{withholding}/cancel', [PayrollAdjustmentController::class, 'cancelWithholding'])->name('payroll.withholdings.cancel');
Route::post('payroll/withholding-cycles/{cycle}/release', [PayrollAdjustmentController::class, 'releaseCycle'])->name('payroll.withholdings.release');
Route::get('payroll/declarations', [PayrollAdjustmentController::class, 'declarations'])->name('payroll.declarations.index');
Route::post('payroll/declarations', [PayrollAdjustmentController::class, 'storeDeclaration'])->name('payroll.declarations.store');
Route::put('payroll/declarations/{declaration}', [PayrollAdjustmentController::class, 'updateDeclaration'])->name('payroll.declarations.update');
Route::post('payroll/declarations/{declaration}/submit', [PayrollAdjustmentController::class, 'submitDeclaration'])->name('payroll.declarations.submit');
Route::post('payroll/declarations/{declaration}/cancel', [PayrollAdjustmentController::class, 'cancelDeclaration'])->name('payroll.declarations.cancel');

// Tab "Payroll" on Butiran Kakitangan (sensitive; JSON)
Route::get('payroll/employees/{employee}/summary', [EmployeePayrollController::class, 'show'])->whereNumber('employee')->name('payroll.employees.summary');

// Reports (§6.7)
Route::get('payroll/reports/register', [PayrollReportController::class, 'register'])->name('payroll.reports.register');
