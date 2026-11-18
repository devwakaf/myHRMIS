<?php

use App\Domain\Shared\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect('/dashboard') : redirect('/login'))->name('home');

// Language toggle (A3): saved on the user when logged in, else in the session.
Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

// Module routes (dashboard, Teras, approvals…) are registered by App\Providers\DomainServiceProvider.

require __DIR__.'/settings.php';
