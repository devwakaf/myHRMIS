<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Redirect back with a success message and optional non-blocking warnings.
     *
     * @param  list<string>  $warnings
     */
    protected function done(string $messageKey, array $warnings = [], ?string $to = null): RedirectResponse
    {
        $response = $to === null ? back() : redirect($to);

        return $response->with('success', __($messageKey))->with('warnings', $warnings);
    }

    /** Abort unless the current user holds the permission in the active organisation. */
    protected function requirePermission(string ...$permissions): void
    {
        $user = request()->user();
        abort_if($user === null, 403);

        if ($user->is_super_admin) {
            return;
        }

        abort_unless(OrganisationContext::has(), 403);

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }

        abort(403);
    }
}
