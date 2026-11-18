<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale order (A3): authenticated user → session → BM.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('myhrmis.locales', ['ms', 'en']);
        $user = $request->user();

        $locale = $user->locale ?? $request->session()->get('locale') ?? config('app.locale', 'ms');
        if (! in_array($locale, $supported, true)) {
            $locale = 'ms';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
