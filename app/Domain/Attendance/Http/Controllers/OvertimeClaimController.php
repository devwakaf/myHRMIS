<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Actions\OvertimeClaimActions;
use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Http\AttendancePresenter;
use App\Domain\Attendance\Http\Requests\OvertimeClaimRequest;
use App\Domain\Attendance\Http\Requests\ReasonRequest;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\OvertimeClaimLine;
use App\Domain\Attendance\Services\OvertimeClaimApproval;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalPresenter;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Support\DomainError;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tuntutan OT (spec-03 §2.14, §4.11). HR generates / edits drafts and submits;
 * Kakitangan see their own claims; amounts only with core.employee.view-compensation.
 */
final class OvertimeClaimController extends Controller
{
    public function __construct(private readonly OvertimeClaimActions $actions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $all = $user->can('attendance.overtime.view-all');
        $own = Employee::query()->where('user_id', $user->id)->pluck('id')->all();
        $month = $request->query('month');
        $showPay = $user->can('core.employee.view-compensation');

        return Inertia::render('Attendance/OvertimeClaims/Index', [
            'claims' => OvertimeClaim::query()->with('employee')
                ->when(! $all, fn ($q) => $q->whereIn('employee_id', $own))
                ->when($month, fn ($q, $v) => $q->whereDate('period_month', CarbonImmutable::parse($v.'-01')->toDateString()))
                ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
                ->orderByDesc('period_month')->orderBy('employee_id')->paginate(50)->withQueryString()
                ->through(fn (OvertimeClaim $c) => AttendancePresenter::claim($c, $showPay || $c->employee_id === ($own[0] ?? null))),
            'filters' => $request->only(['month', 'status']),
            'can' => [
                'manage' => $user->can('attendance.overtime.manage'),
                'submit' => $user->can('attendance.overtime.submit-for-approval'),
                'show_amounts' => $showPay,
            ],
        ]);
    }

    /** "Jana Draf OT" for a month (optionally one employee). */
    public function generate(Request $request): RedirectResponse|JsonResponse
    {
        $this->requirePermission('attendance.overtime.manage');
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'employee_id' => ['nullable', 'integer']]);
        $claims = $this->actions->generate(CarbonImmutable::parse($data['month'].'-01'), isset($data['employee_id']) ? (int) $data['employee_id'] : null);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['generated' => count($claims)]);
        }

        return $this->done('attendance.messages.claims_generated');
    }

    public function show(Request $request, OvertimeClaim $claim, ApprovalPresenter $approvals, ApprovalService $service, OvertimeClaimApproval $hooks): Response
    {
        $this->authorize('view', $claim);
        $user = $request->user();
        $own = Employee::query()->whereKey($claim->employee_id)->value('user_id') === $user->id;
        $showPay = $user->can('core.employee.view-compensation') || $own;
        $approval = $claim->approvalRequest()->first();
        $claim->load(['employee', 'lines']);

        return Inertia::render('Attendance/OvertimeClaims/Show', [
            'claim' => AttendancePresenter::claim($claim, $showPay, true),
            'needs_compliance_review' => OvertimeClaimApproval::needsComplianceReview($claim),
            'route' => $claim->isDraft() ? $this->safeRoute($service, $claim, $user) : null,
            'timeline' => $approval === null ? null : $approvals->timeline($approval),
            'approval_request_id' => $approval?->id,
            'options' => ['day_types' => DayType::options()],
            'can' => [
                'manage' => $user->can('manage', $claim),
                'submit' => $user->can('submit', $claim),
                'cancel' => ! $claim->isDraft() && $claim->posted_payroll_run_id === null && $user->can('cancel', $claim)
                    && in_array($claim->status->value, ['pending', 'approved'], true),
            ],
        ]);
    }

    /** Draft edits: manual wage, cap override reason, a manual line, or refresh. */
    public function update(OvertimeClaimRequest $request, OvertimeClaim $claim): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $data = $request->validated();
        if ($request->has('monthly_wage_snapshot')) {
            $this->actions->setManualWage($claim, isset($data['monthly_wage_snapshot']) ? (string) $data['monthly_wage_snapshot'] : null);
        }
        if ($request->has('cap_override_reason')) {
            $this->actions->setCapReason($claim, $data['cap_override_reason'] ?? null);
        }
        if (isset($data['line'])) {
            $this->actions->addManualLine($claim, $data['line']);
        }

        return $this->done('common.saved');
    }

    public function destroyLine(OvertimeClaim $claim, OvertimeClaimLine $line): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $this->actions->removeManualLine($claim, $line);

        return $this->done('common.deleted');
    }

    public function submit(Request $request, OvertimeClaim $claim): RedirectResponse
    {
        $this->authorize('submit', $claim);
        $data = $request->validate(['cap_override_reason' => ['nullable', 'string', 'max:2000'], 'choices' => ['nullable', 'array']]);
        $this->actions->submit($claim, $request->user(), $data['cap_override_reason'] ?? null, (array) ($data['choices'] ?? []));

        return $this->done('attendance.messages.submitted');
    }

    public function cancel(ReasonRequest $request, OvertimeClaim $claim): RedirectResponse
    {
        $this->authorize('cancel', $claim);
        $this->actions->cancel($claim, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function destroy(OvertimeClaim $claim): RedirectResponse
    {
        $this->authorize('manage', $claim);
        $this->actions->deleteDraft($claim);

        return $this->done('common.deleted', [], '/attendance/overtime-claims');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeRoute(ApprovalService $service, OvertimeClaim $claim, User $user): ?array
    {
        try {
            return $service->preview($claim, [], $user)->toArray();
        } catch (DomainError) {
            return null;
        }
    }
}
