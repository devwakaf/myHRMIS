<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeDocument;
use App\Domain\Core\Models\EmployeeMovement;
use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Core\Policies\EmployeeDocumentPolicy;
use App\Domain\Core\Policies\EmployeeMovementPolicy;
use App\Domain\Core\Policies\EmployeePolicy;
use App\Domain\Core\Policies\HolidayListAssignmentPolicy;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Listeners\SendApprovalNotifications;
use App\Domain\Shared\Approval\PhaseOneApprovalTypes;
use App\Domain\Shared\Approval\Services\ApproverResolver;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the domain modules: shared services, approval types, policies,
 * listeners and module route files (A2).
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApprovalTypeRegistry::class, function (): ApprovalTypeRegistry {
            $registry = new ApprovalTypeRegistry;
            PhaseOneApprovalTypes::register($registry);

            return $registry;
        });

        $this->app->scoped(RoleService::class);
        $this->app->scoped(OrganisationMembership::class);
        $this->app->scoped(ApproverResolver::class);
        $this->app->scoped(ApprovalService::class);
        $this->app->scoped(HolidayResolver::class);
    }

    public function boot(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            if (preg_match('/^App\\\\Domain\\\\(\w+)\\\\(?:\w+\\\\)*Models\\\\(\w+)$/', $modelName, $m) === 1) {
                return 'Database\\Factories\\'.$m[1].'\\'.$m[2].'Factory';
            }

            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });

        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->is_super_admin) {
                return true;
            }

            if (in_array($ability, PermissionMatrix::permissions(), true)) {
                return OrganisationContext::has()
                    && app(RoleService::class)->can($user, OrganisationContext::id(), $ability);
            }

            return null;
        });

        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(EmployeeDocument::class, EmployeeDocumentPolicy::class);
        Gate::policy(EmployeeMovement::class, EmployeeMovementPolicy::class);
        Gate::policy(HolidayListAssignment::class, HolidayListAssignmentPolicy::class);

        Event::subscribe(SendApprovalNotifications::class);

        Route::middleware(['web', 'auth', 'organisation', 'employment'])
            ->group(base_path('app/Domain/Shared/routes.php'));
        Route::middleware(['web', 'auth', 'organisation', 'employment'])
            ->group(base_path('app/Domain/Core/routes.php'));
    }
}
