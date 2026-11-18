<?php

namespace App\Http\Middleware;

use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared props (A3, A4, A7): locale, active Organisasi + switcher list,
     * the user's abilities in the active org (UI hints only), flash messages.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Shared props are resolved lazily: the organisation context is set by a
        // route middleware that runs after this one.
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => fn () => App::getLocale(),
            'locales' => config('myhrmis.locales'),
            'auth' => fn () => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'is_super_admin' => $user->is_super_admin,
                    'email_verified_at' => $user->email_verified_at,
                    'employee_id' => OrganisationContext::has() ? $user->employee(OrganisationContext::id())?->id : null,
                ],
                'abilities' => $user === null
                    ? []
                    : (OrganisationContext::has()
                        ? app(RoleService::class)->permissionsOf($user, OrganisationContext::id())
                        : ($user->is_super_admin ? ['system.organisations.manage'] : [])),
            ],
            'organisation' => fn () => OrganisationContext::has() ? OrganisationContext::current()->only(['id', 'name', 'short_code']) : null,
            'organisations' => fn () => $user === null ? [] : app(OrganisationMembership::class)
                ->organisationsFor($user)
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'short_code' => $o->short_code])
                ->values(),
            'attendanceReviewCount' => fn () => $user === null || ! OrganisationContext::has() || ! $user->can('attendance.review.manage') ? 0 : EmployeeCheckin::query()->notVoided()->where('processing_state', ProcessingState::NeedsReview->value)->count(),
            'pendingApprovals' => fn () => $user === null || ! OrganisationContext::has() ? 0 : app(ApprovalService::class)->pendingFor($user)->count(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warnings' => fn () => $request->session()->get('warnings', []),
                'status' => fn () => $request->session()->get('status'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
