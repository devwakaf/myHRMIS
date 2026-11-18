<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Controllers;

use App\Domain\Shared\Approval\ApprovalPresenter;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Approval\Enums\Decision;
use App\Domain\Shared\Approval\Enums\RequestStatus;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Domain\Shared\Support\DomainError;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Kelulusan Saya" (A6.10): inbox tabs, decision drawer, decisions, HR override,
 * reassignment and withdrawal. Access to a request is always decided by
 * ApprovalService::canView, never by the pelulus role.
 */
final class ApprovalInboxController extends Controller
{
    public const TABS = ['mine', 'processed', 'submitted', 'all_pending', 'no_approver'];

    public function __construct(
        private readonly ApprovalService $service,
        private readonly ApprovalPresenter $presenter,
        private readonly ApprovalTypeRegistry $registry,
        private readonly RoleService $roles,
    ) {}

    public function index(Request $request): Response
    {
        $this->requirePermission('approvals.inbox');
        $user = $request->user();
        $hrTypes = $this->hrTypes($user);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'mine';
        if (in_array($tab, ['all_pending', 'no_approver'], true) && $hrTypes === []) {
            $tab = 'mine';
        }

        $query = match ($tab) {
            'mine' => $this->service->pendingFor($user),
            'processed' => ApprovalRequest::query()->whereHas('steps.assignees', fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->whereIn('decision', [AssigneeDecision::Approved->value, AssigneeDecision::Rejected->value])),
            'submitted' => ApprovalRequest::query()->where(fn (Builder $q) => $q
                ->where('submitted_by', $user->id)
                ->orWhereHas('applicant', fn (Builder $a) => $a->where('user_id', $user->id))),
            'all_pending' => ApprovalRequest::query()->where('status', RequestStatus::Pending->value)->whereIn('approval_type', $hrTypes),
            'no_approver' => ApprovalRequest::query()->where('status', RequestStatus::Pending->value)
                ->whereIn('approval_type', $hrTypes)
                ->whereHas('steps', fn (Builder $s) => $s->where('status', StepStatus::Active->value)
                    ->whereDoesntHave('assignees', fn (Builder $a) => $a->where('decision', AssigneeDecision::Pending->value))),
        };

        $type = (string) $request->query('type', '');
        $applicant = trim((string) $request->query('applicant', ''));
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $query->when($type !== '', fn (Builder $q) => $q->where('approval_type', $type))
            ->when($applicant !== '', fn (Builder $q) => $q->whereHas('applicant', fn (Builder $a) => $a
                ->where('full_name', 'like', "%$applicant%")->orWhere('employee_no', 'like', "%$applicant%")))
            ->when($from !== '', fn (Builder $q) => $q->whereDate('submitted_at', '>=', $from))
            ->when($to !== '', fn (Builder $q) => $q->whereDate('submitted_at', '<=', $to));

        $requests = $query->with(['steps', 'applicant'])
            ->latest('submitted_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ApprovalRequest $r) => $this->presenter->row($r));

        $selected = null;
        $selectedId = $request->integer('request');
        if ($selectedId > 0) {
            $candidate = ApprovalRequest::query()->find($selectedId);
            if ($candidate !== null && $this->service->canView($user, $candidate)) {
                $selected = $this->presenter->detail($candidate, $user);
            }
        }

        return Inertia::render('Approvals/Index', [
            'tab' => $tab,
            'tabs' => $hrTypes === [] ? ['mine', 'processed', 'submitted'] : self::TABS,
            'requests' => $requests,
            'selected' => $selected,
            'filters' => ['type' => $type, 'applicant' => $applicant, 'from' => $from, 'to' => $to],
            'typeOptions' => array_map(fn (string $k) => ['value' => $k, 'label' => $this->presenter->typeLabel($k)], $this->registry->keys()),
            'members' => $hrTypes === [] ? [] : $this->memberOptions(),
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->guardDecide($request->user(), $approvalRequest);
        $this->service->approve($approvalRequest, $request->user(), $data['note'] ?? null);

        return $this->done('approval.flash.approved');
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $this->guardDecide($request->user(), $approvalRequest);
        $this->service->reject($approvalRequest, $request->user(), $data['note']);

        return $this->done('approval.flash.rejected');
    }

    public function override(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::enum(Decision::class)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        abort_unless($this->service->canOverride($request->user(), $approvalRequest), 403);

        $this->service->override($approvalRequest, $request->user(), Decision::from($data['decision']), $data['reason']);

        return $this->done('approval.flash.overridden');
    }

    public function reassign(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        abort_unless($this->service->canOverride($request->user(), $approvalRequest), 403);

        $this->service->reassign($approvalRequest, $request->user(), array_map('intval', $data['user_ids']), $data['reason']);

        return $this->done('approval.flash.reassigned');
    }

    /** Withdraw (Tarik Balik) a pending request: applicant or submitter. */
    public function cancel(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $user = $request->user();
        $own = $approvalRequest->submitted_by === $user->id || $approvalRequest->applicant?->user_id === $user->id;
        abort_unless($approvalRequest->isPending() && $own, 403);

        $this->service->cancel($approvalRequest, $user, $data['reason']);

        return $this->done('approval.flash.withdrawn');
    }

    /** Bulk "Lulus" for selected rows of one type; each row is validated on its own. */
    public function bulkApprove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();
        $requests = ApprovalRequest::query()->whereIn('id', $data['ids'])->get();

        if ($requests->pluck('approval_type')->unique()->count() > 1) {
            throw ValidationException::withMessages(['ids' => __('approval.errors.bulk_one_type')]);
        }

        $ok = 0;
        $failures = [];
        foreach ($requests as $approvalRequest) {
            try {
                if (! $this->service->canDecide($user, $approvalRequest)) {
                    $failures[] = '#'.$approvalRequest->id.': '.__('approval.errors.not_assignee');

                    continue;
                }
                $this->service->approve($approvalRequest, $user, $data['note'] ?? null);
                $ok++;
            } catch (DomainError $e) {
                $failures[] = '#'.$approvalRequest->id.': '.$e->userMessage();
            } catch (ValidationException $e) {
                $failures[] = '#'.$approvalRequest->id.': '.collect($e->errors())->flatten()->first();
            }
        }

        return back()
            ->with('success', __('approval.flash.bulk_approved', ['count' => $ok]))
            ->with('warnings', $failures);
    }

    private function guardDecide(User $user, ApprovalRequest $approvalRequest): void
    {
        // Assignees and HR override holders can act; everyone else gets 403 (B8 #54).
        abort_unless($this->service->canView($user, $approvalRequest), 403);
        $isAssigneeEver = $approvalRequest->steps()->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
        abort_unless($isAssigneeEver, 403);
    }

    /**
     * Types where the user holds approve-any or view-all (HR tabs).
     *
     * @return list<string>
     */
    private function hrTypes(User $user): array
    {
        $types = [];
        foreach ($this->registry->all() as $key => $definition) {
            if ($this->roles->can($user, OrganisationContext::id(), $definition->approveAnyPermission)
                || $this->roles->can($user, OrganisationContext::id(), $definition->viewAllPermission)) {
                $types[] = $key;
            }
        }

        return $types;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function memberOptions(): array
    {
        $ids = app(OrganisationMembership::class)->memberUserIds(OrganisationContext::id());

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all();
    }
}
