<?php

use App\Domain\Shared\Organisation\MissingOrganisationContext;
use App\Http\Middleware\EnsureEmploymentAccess;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetActiveOrganisation;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'organisation' => SetActiveOrganisation::class,
            'employment' => EnsureEmploymentAccess::class,
        ]);

        // The organisation context must exist before route-model binding runs,
        // so org-scoped bindings return 404 for other organisations (A4).
        $middleware->prependToPriorityList(SubstituteBindings::class, SetActiveOrganisation::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureEmploymentAccess::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (MissingOrganisationContext $e, Request $request) {
            if ($request->user()?->is_super_admin && ! $request->expectsJson()) {
                return redirect('/organisations')->with('error', __('common.errors.create_organisation_first'));
            }

            return null;
        });
    })->create();
