<?php

declare(strict_types=1);

namespace App\Domain\Leave;

use App\Domain\Leave\Adapters\NullLeaveAttendancePort;
use App\Domain\Leave\Adapters\NullPayrollAdapters;
use App\Domain\Leave\Contracts\AdditionalPayPort;
use App\Domain\Leave\Contracts\CompensationQuery;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Leave\Listeners\LeaveEventSubscriber;
use App\Domain\Leave\Models\CompensatoryLeaveRequest;
use App\Domain\Leave\Models\LeaveAdjustment;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Policies\LeaveApplicationPolicy;
use App\Domain\Leave\Services\LeaveIntegrationQueries;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Contracts\NullPayrollLockQuery;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Cuti module (spec-02): port bindings (Null adapters until M3/M4 replace them
 * with their own bind() calls), approval types, morph aliases, policies,
 * listeners and routes.
 */
final class LeaveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Implemented by Attendance (M3) / Payroll (M4); bindIf so those modules win.
        $this->app->bindIf(LeaveAttendancePort::class, NullLeaveAttendancePort::class);
        $this->app->bindIf(PayrollLockQuery::class, NullPayrollLockQuery::class);
        $this->app->bindIf(AdditionalPayPort::class, NullPayrollAdapters::class);
        $this->app->bindIf(CompensationQuery::class, NullPayrollAdapters::class);

        // Implemented by Leave, consumed by Attendance / Payroll.
        $this->app->singleton(LeaveIntegrationQueries::class);
        $this->app->bind(UnpaidLeaveQuery::class, LeaveIntegrationQueries::class);
        $this->app->bind(LeaveQuery::class, LeaveIntegrationQueries::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(ApprovalTypeRegistry::class);
        $registry->bindModel('leave', LeaveApplication::class);
        $registry->bindModel('compensatory_leave', CompensatoryLeaveRequest::class);

        Relation::morphMap([
            'leave_allocation' => LeaveAllocation::class,
            'leave_adjustment' => LeaveAdjustment::class,
            'leave_encashment' => LeaveEncashment::class,
            'leave_credit_schedule' => LeaveCreditSchedule::class,
            'leave_policy_assignment' => LeavePolicyAssignment::class,
        ]);

        Gate::policy(LeaveApplication::class, LeaveApplicationPolicy::class);
        Event::subscribe(LeaveEventSubscriber::class);

        Route::middleware(['web', 'auth', 'organisation', 'employment'])
            ->group(base_path('app/Domain/Leave/routes.php'));
    }
}
