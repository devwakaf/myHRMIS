<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Core\Models\Department;
use App\Domain\Leave\Actions\AllocationActions;
use App\Domain\Leave\Actions\LeaveConfigActions;
use App\Domain\Leave\Enums\AccrualFrequency;
use App\Domain\Leave\Enums\AccrualTiming;
use App\Domain\Leave\Enums\ApplicantCancelRule;
use App\Domain\Leave\Enums\AttachmentRule;
use App\Domain\Leave\Enums\CalendarVisibility;
use App\Domain\Leave\Enums\EncashmentRateBasis;
use App\Domain\Leave\Enums\EntitlementBasis;
use App\Domain\Leave\Enums\NoticeEnforcement;
use App\Domain\Leave\Enums\PayMode;
use App\Domain\Leave\Enums\ProrationMethod;
use App\Domain\Leave\Enums\TierBasis;
use App\Domain\Leave\Http\LeavePresenter;
use App\Domain\Leave\Http\Requests\AggregateCapRequest;
use App\Domain\Leave\Http\Requests\BlockListRequest;
use App\Domain\Leave\Http\Requests\LeavePeriodRequest;
use App\Domain\Leave\Http\Requests\LeavePolicyRequest;
use App\Domain\Leave\Http\Requests\LeaveSettingsRequest;
use App\Domain\Leave\Http\Requests\LeaveTypeRequest;
use App\Domain\Leave\Models\LeaveAggregateCap;
use App\Domain\Leave\Models\LeaveBlockList;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jenis Cuti, Tempoh Cuti, Leave Policy, Had Gabungan Cuti, Senarai Sekatan Cuti
 * and Tetapan Cuti (spec-02 §6.3). Read: leave.config.manage|view; write: manage.
 */
final class LeaveConfigController extends Controller
{
    public function __construct(private readonly LeaveConfigActions $actions) {}

    // ------------------------------------------------------------------ types

    public function types(Request $request): Response
    {
        $this->requirePermission('leave.config.manage', 'leave.config.view');

        return Inertia::render('Leave/Types/Index', [
            'types' => LeaveType::query()->orderBy('sort_order')->orderBy('code')->get()->map(fn ($t) => LeavePresenter::type($t))->all(),
            'options' => [
                'entitlement_bases' => EntitlementBasis::options(),
                'pay_modes' => PayMode::options(),
                'attachment_rules' => AttachmentRule::options(),
                'notice_enforcements' => NoticeEnforcement::options(),
                'accrual_frequencies' => AccrualFrequency::options(),
                'accrual_timings' => AccrualTiming::options(),
                'proration_methods' => ProrationMethod::options(),
            ],
            'can' => ['manage' => $request->user()->can('leave.config.manage')],
        ]);
    }

    public function storeType(LeaveTypeRequest $request): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->saveType(null, $request->validated());

        return $this->done('common.saved');
    }

    public function updateType(LeaveTypeRequest $request, LeaveType $type): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->saveType($type, $request->validated());

        return $this->done('common.saved');
    }

    // ------------------------------------------------------------------ periods

    public function periods(Request $request): Response
    {
        $this->requirePermission('leave.config.manage', 'leave.config.view', 'leave.allocation.manage');

        return Inertia::render('Leave/Periods/Index', [
            'periods' => LeavePeriod::query()->orderByDesc('from_date')->get()->map(fn (LeavePeriod $p) => [
                'id' => $p->id, 'name' => $p->name, 'from_date' => $p->from_date->toDateString(), 'to_date' => $p->to_date->toDateString(),
                'notes' => $p->notes, 'allocations_generated_at' => $p->allocations_generated_at?->format('Y-m-d H:i'),
            ])->all(),
            'can' => ['manage' => $request->user()->can('leave.config.manage'), 'generate' => $request->user()->can('leave.allocation.manage')],
        ]);
    }

    public function storePeriod(LeavePeriodRequest $request): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->savePeriod(null, $request->validated());

        return $this->done('common.saved');
    }

    public function updatePeriod(LeavePeriodRequest $request, LeavePeriod $period): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->savePeriod($period, $request->validated());

        return $this->done('common.saved');
    }

    public function destroyPeriod(LeavePeriod $period): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->deletePeriod($period);

        return $this->done('common.deleted');
    }

    /** "Jana Peruntukan" */
    public function generate(LeavePeriod $period, AllocationActions $allocations): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $count = $allocations->generateForPeriod($period);

        return back()->with('success', __('leave.messages.generated', ['count' => $count]));
    }

    // ------------------------------------------------------------------ policies

    public function policies(Request $request): Response
    {
        $this->requirePermission('leave.config.manage', 'leave.config.view');

        return Inertia::render('Leave/Policies/Index', [
            'policies' => LeavePolicy::query()->with('rows.tiers')->orderBy('name')->get()->map(fn (LeavePolicy $p) => [
                'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'is_active' => $p->is_active,
                'rows' => $p->rows->map(fn ($r) => [
                    'id' => $r->id, 'leave_type_id' => $r->leave_type_id, 'annual_days' => (string) $r->annual_days,
                    'uses_service_tiers' => $r->uses_service_tiers, 'tier_basis' => $r->tier_basis->value,
                    'override_max_carry_forward_days' => $r->override_max_carry_forward_days,
                    'tiers' => $r->tiers->map(fn ($t) => ['min_service_months' => $t->min_service_months, 'days' => (string) $t->days])->values()->all(),
                ])->values()->all(),
            ])->all(),
            'options' => [
                'types' => LeaveType::query()->whereIn('entitlement_basis', [EntitlementBasis::PerPeriod->value, EntitlementBasis::Earned->value])
                    ->orderBy('sort_order')->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->displayName()])->all(),
                'tier_bases' => TierBasis::options(),
            ],
            'can' => ['manage' => $request->user()->can('leave.config.manage')],
        ]);
    }

    public function storePolicy(LeavePolicyRequest $request): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->savePolicy(null, $request->validated());

        return $this->done('common.saved');
    }

    public function updatePolicy(LeavePolicyRequest $request, LeavePolicy $policy): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->savePolicy($policy, $request->validated());

        return $this->done('common.saved');
    }

    // ------------------------------------------------------------------ aggregate caps

    public function caps(Request $request): Response
    {
        $this->requirePermission('leave.config.manage', 'leave.config.view');

        return Inertia::render('Leave/AggregateCaps/Index', [
            'caps' => LeaveAggregateCap::query()->with('types:id,code,name,name_en')->orderBy('name')->get()->map(fn (LeaveAggregateCap $c) => [
                'id' => $c->id, 'name' => $c->name, 'cap_days' => (string) $c->cap_days, 'is_active' => $c->is_active,
                'leave_type_ids' => $c->types->pluck('id')->all(),
            ])->all(),
            'options' => ['types' => LeaveType::query()->orderBy('sort_order')->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->displayName()])->all()],
            'can' => ['manage' => $request->user()->can('leave.config.manage')],
        ]);
    }

    public function storeCap(AggregateCapRequest $request): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->saveCap(null, $request->validated());

        return $this->done('common.saved');
    }

    public function updateCap(AggregateCapRequest $request, LeaveAggregateCap $cap): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->saveCap($cap, $request->validated());

        return $this->done('common.saved');
    }

    // ------------------------------------------------------------------ block lists

    public function blockLists(Request $request, OrganisationMembership $membership): Response
    {
        $this->requirePermission('leave.config.manage', 'leave.config.view');

        return Inertia::render('Leave/BlockLists/Index', [
            'lists' => LeaveBlockList::query()->with(['dates', 'departments:id', 'exemptUsers:id'])->orderBy('name')->get()->map(fn (LeaveBlockList $l) => [
                'id' => $l->id, 'name' => $l->name, 'applies_to_all_departments' => $l->applies_to_all_departments,
                'leave_type_id' => $l->leave_type_id, 'is_active' => $l->is_active,
                'dates' => $l->dates->map(fn ($d) => ['date' => $d->date->toDateString(), 'reason' => $d->reason])->values()->all(),
                'department_ids' => $l->departments->pluck('id')->all(),
                'exempt_user_ids' => $l->exemptUsers->pluck('id')->all(),
            ])->all(),
            'options' => [
                'types' => LeaveType::query()->orderBy('sort_order')->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->displayName()])->all(),
                'departments' => Department::query()->orderBy('path')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->displayName()])->all(),
                'users' => User::query()->whereIn('id', $membership->memberUserIds(OrganisationContext::id()))->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all(),
            ],
            'can' => ['manage' => $request->user()->can('leave.config.manage')],
        ]);
    }

    public function storeBlockList(BlockListRequest $request): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->saveBlockList(null, $request->validated());

        return $this->done('common.saved');
    }

    public function updateBlockList(BlockListRequest $request, LeaveBlockList $blockList): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->saveBlockList($blockList, $request->validated());

        return $this->done('common.saved');
    }

    // ------------------------------------------------------------------ settings

    public function settings(Request $request): Response
    {
        $this->requirePermission('leave.config.manage', 'leave.config.view');

        return Inertia::render('Leave/Settings', [
            'settings' => LeaveSettings::current()->only([
                'default_leave_policy_id', 'default_max_backdate_days', 'calendar_colleague_visibility',
                'expiry_reminder_days', 'encashment_rate_basis', 'applicant_may_cancel_approved',
            ]),
            'options' => [
                'policies' => LeavePolicy::query()->where('is_active', true)->orderBy('name')->get()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all(),
                'calendar_visibilities' => CalendarVisibility::options(),
                'encashment_rate_bases' => EncashmentRateBasis::options(),
                'applicant_cancel_rules' => ApplicantCancelRule::options(),
            ],
            'can' => ['manage' => $request->user()->can('leave.config.manage')],
        ]);
    }

    public function updateSettings(LeaveSettingsRequest $request): RedirectResponse
    {
        $this->requirePermission('leave.config.manage');
        $this->actions->updateSettings($request->validated());

        return $this->done('common.saved');
    }
}
