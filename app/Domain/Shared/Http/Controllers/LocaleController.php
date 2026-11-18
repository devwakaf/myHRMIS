<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BM/EN toggle (A3): persists to users.locale, or the session for guests.
 */
final class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(config('myhrmis.locales', ['ms', 'en']))],
        ]);

        $request->session()->put('locale', $data['locale']);
        $request->user()?->forceFill(['locale' => $data['locale']])->save();

        return back();
    }
}
