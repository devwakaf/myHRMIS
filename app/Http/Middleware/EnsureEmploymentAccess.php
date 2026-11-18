<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * B2 / USER DECISION: access to an Organisasi ends when the relieving date ends
 * (the last working day is still usable). Refuses access from the next day,
 * before ProcessDueSeparations has run. Inactive users are logged out.
 */
final class EnsureEmploymentAccess
{
    public function __construct(private readonly OrganisationMembership $membership) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->logout($request);
        }

        if ($user->is_super_admin || ! OrganisationContext::has()) {
            return $next($request);
        }

        if (! $this->relievedIn($user, OrganisationContext::id())) {
            return $next($request);
        }

        // Move to another organisation where access is still valid, if any.
        foreach ($this->membership->organisationsFor($user) as $organisation) {
            if ($organisation->id !== OrganisationContext::id() && ! $this->relievedIn($user, $organisation->id)) {
                $user->forceFill(['current_organisation_id' => $organisation->id])->saveQuietly();
                $request->session()->put('organisation_id', $organisation->id);

                return redirect('/dashboard');
            }
        }

        return $this->logout($request);
    }

    private function relievedIn(User $user, int $organisationId): bool
    {
        return Employee::acrossOrganisations()
            ->where('organisation_id', $organisationId)
            ->where('user_id', $user->id)
            ->whereNotNull('relieving_date')
            ->whereDate('relieving_date', '<', today()->toDateString())
            ->exists();
    }

    private function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, (string) __('auth.access_ended'));
        }

        return redirect('/login')->with('status', __('auth.access_ended'));
    }
}
