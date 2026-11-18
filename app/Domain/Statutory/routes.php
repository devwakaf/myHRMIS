<?php

declare(strict_types=1);

use App\Domain\Statutory\Http\Controllers\StatutoryFileController;
use App\Domain\Statutory\Http\Controllers\StatutoryListingController;
use App\Domain\Statutory\Http\Controllers\StatutoryRateController;
use Illuminate\Support\Facades\Route;

// Loaded by PayrollServiceProvider with ['web', 'auth', 'organisation', 'employment'].

// Kadar Statutori (spec-04 §1.1, §7.1)
Route::get('statutory/rates', [StatutoryRateController::class, 'index'])->name('statutory.rates.index');
Route::get('statutory/rates/{set}', [StatutoryRateController::class, 'show'])->name('statutory.rates.show');
Route::post('statutory/rates/{set}/copy', [StatutoryRateController::class, 'copy'])->name('statutory.rates.copy');
Route::post('statutory/rates/{set}/import', [StatutoryRateController::class, 'import'])->name('statutory.rates.import');
Route::post('statutory/rates/{set}/fixtures', [StatutoryRateController::class, 'fixtures'])->name('statutory.rates.fixtures');
Route::post('statutory/rates/{set}/publish', [StatutoryRateController::class, 'publish'])->name('statutory.rates.publish');
Route::post('statutory/rates/{set}/verify', [StatutoryRateController::class, 'verify'])->name('statutory.rates.verify');
Route::delete('statutory/rates/{set}', [StatutoryRateController::class, 'destroy'])->name('statutory.rates.destroy');

// Files, remittances, EA, PCB 2(II) (§6)
Route::get('statutory/files', [StatutoryFileController::class, 'index'])->name('statutory.files.index');
Route::post('statutory/files/{kind}', [StatutoryFileController::class, 'generate'])->whereIn('kind', ['perkeso', 'cp39', 'kwsp'])->name('statutory.files.generate');
Route::post('statutory/files/{file}/submitted', [StatutoryFileController::class, 'markSubmitted'])->whereNumber('file')->name('statutory.files.submitted');
Route::get('statutory/remittances', [StatutoryFileController::class, 'remittances'])->name('statutory.remittances.index');
Route::post('statutory/remittances', [StatutoryFileController::class, 'storeRemittance'])->name('statutory.remittances.store');
Route::get('statutory/ea', [StatutoryFileController::class, 'ea'])->name('statutory.ea.index');
Route::post('statutory/ea/generate', [StatutoryFileController::class, 'generateEa'])->name('statutory.ea.generate');
Route::post('statutory/ea/{form}/publish', [StatutoryFileController::class, 'publishEa'])->name('statutory.ea.publish');
Route::get('statutory/ea/{form}/pdf', [StatutoryFileController::class, 'eaPdf'])->name('statutory.ea.pdf');
Route::get('statutory/pcb2ii/{employee}/pdf', [StatutoryFileController::class, 'pcb2iiPdf'])->whereNumber('employee')->name('statutory.pcb2ii-pdf');
Route::get('statutory/listings/{kind}', [StatutoryListingController::class, 'show'])->name('statutory.listings.show');
Route::get('statutory/listings/{kind}/export', [StatutoryListingController::class, 'export'])->name('statutory.listings.export');
Route::get('statutory/pcb2ii/{employee}', [StatutoryFileController::class, 'pcb2ii'])->whereNumber('employee')->name('statutory.pcb2ii');
