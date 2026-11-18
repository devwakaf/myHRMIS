<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\Attendance\Actions\RecordWebCheckin;
use App\Domain\Attendance\Adapters\LeaveAttendanceAdapter;
use App\Domain\Attendance\Adapters\NullOtWageBaseProvider;
use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Contracts\CheckinIngestor;
use App\Domain\Attendance\Contracts\OtWageBaseProvider;
use App\Domain\Attendance\Listeners\AttendanceEventSubscriber;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftRequest;
use App\Domain\Attendance\Models\ShiftScheduleAssignment;
use App\Domain\Attendance\Policies\AttendancePolicy;
use App\Domain\Attendance\Policies\AttendanceRequestPolicy;
use App\Domain\Attendance\Policies\OvertimeClaimPolicy;
use App\Domain\Attendance\Policies\ShiftRequestPolicy;
use App\Domain\Attendance\Services\AttendancePayrollQueries;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Contracts\NullPayrollLockQuery;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Kehadiran & Shift module (spec-03): replaces Leave's Null attendance port with
 * the real adapter, binds the Payroll-facing contracts (Null wage provider until
 * M4), registers the three Approvable models, policies, listeners and routes.
 * Must be registered after LeaveServiceProvider (bootstrap/providers.php).
 */
final class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Attendance implements Leave's port (spec-02 §5.1): bind() wins over Leave's bindIf Null adapter.
        $this->app->bind(LeaveAttendancePort::class, LeaveAttendanceAdapter::class);

        // Payroll-implemented contracts: Null until M4 binds its own.
        $this->app->bindIf(OtWageBaseProvider::class, NullOtWageBaseProvider::class);
        $this->app->bindIf(PayrollLockQuery::class, NullPayrollLockQuery::class);

        // Implemented here, consumed by Payroll / HTTP.
        $this->app->bind(AttendanceForPayroll::class, AttendancePayrollQueries::class);
        $this->app->bind(CheckinIngestor::class, RecordWebCheckin::class);

        $this->app->scoped(ShiftCalendar::class);
        $this->app->scoped(DayTypeResolver::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(ApprovalTypeRegistry::class);
        $registry->bindModel('attendance_request', AttendanceRequest::class);
        $registry->bindModel('shift_request', ShiftRequest::class);
        $registry->bindModel('overtime_claim', OvertimeClaim::class);

        Relation::morphMap([
            'attendance' => Attendance::class,
            'employee_checkin' => EmployeeCheckin::class,
            'shift_assignment' => ShiftAssignment::class,
            'shift_schedule_assignment' => ShiftScheduleAssignment::class,
        ]);

        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(AttendanceRequest::class, AttendanceRequestPolicy::class);
        Gate::policy(ShiftRequest::class, ShiftRequestPolicy::class);
        Gate::policy(OvertimeClaim::class, OvertimeClaimPolicy::class);
        Event::subscribe(AttendanceEventSubscriber::class);

        Route::middleware(['web', 'auth', 'organisation', 'employment'])
            ->group(base_path('app/Domain/Attendance/routes.php'));
    }
}
