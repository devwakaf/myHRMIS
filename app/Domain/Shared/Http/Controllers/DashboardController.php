<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Controllers;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Reports\UpcomingDatesReport;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, ApprovalService $approvals): Response
    {
        $user = $request->user();

        if (! OrganisationContext::has()) {
            return Inertia::render('Dashboard', ['stats' => null, 'upcoming' => [], 'me' => null]);
        }

        $canSeeStaff = $user->can('core.employee.view');
        $stats = [
            'pending_approvals' => $approvals->pendingFor($user)->count(),
            'active_employees' => $canSeeStaff ? Employee::query()->where('status', EmployeeStatus::Active->value)->count() : null,
            'joiners_this_month' => $canSeeStaff
                ? Employee::query()->whereBetween('date_of_joining', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count()
                : null,
            'leaving_this_month' => $canSeeStaff
                ? Employee::query()->whereBetween('relieving_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count()
                : null,
        ];

        $upcoming = $user->can('core.reports.view')
            ? array_slice(app(UpcomingDatesReport::class)->rows(['days' => 30]), 0, 8)
            : [];

        $employee = $user->employee();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'upcoming' => $upcoming,
            'me' => $employee === null ? null : [
                'id' => $employee->id,
                'name' => $employee->displayName(),
                'employee_no' => $employee->employee_no,
                'designation' => $employee->designation?->displayName(),
                'department' => $employee->department?->displayName(),
            ],
        ]);
    }
}
