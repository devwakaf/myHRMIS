<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A4: load the user's active Organisasi (users.current_organisation_id, mirrored
 * in session), verify membership (else the first member org, else 403), then
 * set OrganisationContext and the spatie team id. Runs before route-model binding.
 */
final class SetActiveOrganisation
{
    public function __construct(private readonly OrganisationMembership $membership) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $user->forgetCachedRoles();
        $organisations = $this->membership->organisationsFor($user);
        $current = $organisations->firstWhere('id', $user->current_organisation_id)
            ?? $organisations->firstWhere('id', (int) $request->session()->get('organisation_id'))
            ?? $organisations->first();

        if ($current === null) {
            OrganisationContext::clear();
            if ($user->is_super_admin || $mode === 'optional') {
                // Super Admin before any Organisasi exists: only global screens work.
                return $next($request);
            }

            abort(403, (string) __('common.errors.no_organisation'));
        }

        /** @var Organisation $current */
        if ($user->current_organisation_id !== $current->id) {
            $user->forceFill(['current_organisation_id' => $current->id])->saveQuietly();
        }
        $request->session()->put('organisation_id', $current->id);

        OrganisationContext::set($current);
        $user->forgetCachedRoles();

        return $next($request);
    }
}
