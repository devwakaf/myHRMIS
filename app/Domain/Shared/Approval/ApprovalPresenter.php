<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval;

use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Models\ApprovalAction;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Approval\Models\ApprovalRequestStep;
use App\Domain\Shared\Approval\Models\ApprovalStepAssignee;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Arrays for the inbox rows, the decision drawer and <ApprovalTimeline> (A6.10).
 * Approvers only ever get the applicant's name, number, department and
 * designation plus decisionContext() — never the full profile (A6.8).
 */
final class ApprovalPresenter
{
    public function __construct(
        private readonly ApprovalTypeRegistry $registry,
        private readonly ApprovalService $service,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function row(ApprovalRequest $request): array
    {
        $steps = $request->steps;
        $active = $steps->firstWhere('status', StepStatus::Active);
        $submitted = $request->submitted_at === null ? null : CarbonImmutable::parse($request->submitted_at);

        return [
            'id' => $request->id,
            'type' => $request->approval_type,
            'type_label' => $this->typeLabel($request->approval_type),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'applicant' => $request->applicant?->full_name,
            'applicant_no' => $request->applicant?->employee_no,
            'summary' => $this->summary($request),
            'step' => $active === null ? null : [
                'sequence' => $active->sequence,
                'total' => $steps->count(),
                'name' => $active->name,
            ],
            'submitted_at' => $submitted?->format('Y-m-d H:i'),
            'waiting_days' => $submitted === null ? null : (int) $submitted->startOfDay()->diffInDays(now()->startOfDay()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(ApprovalRequest $request, User $viewer): array
    {
        $request->loadMissing(['steps.assignees.user', 'actions.actor', 'applicant.department', 'applicant.designation', 'workflow']);
        $doc = $request->approvable()->withoutGlobalScopes()->first();

        return array_merge($this->row($request), [
            'workflow' => ['name' => $request->workflow->name, 'version' => $request->workflow->version],
            'applicant_card' => [
                'name' => $request->applicant?->full_name,
                'employee_no' => $request->applicant?->employee_no,
                'department' => $request->applicant?->department?->displayName(),
                'designation' => $request->applicant?->designation?->displayName(),
            ],
            'decision_context' => $doc instanceof Approvable ? $doc->decisionContext($viewer) : [],
            'cancel_reason' => $request->cancel_reason,
            'timeline' => $this->timeline($request),
            'can' => [
                'decide' => $this->service->canDecide($viewer, $request),
                'override' => $this->service->canOverride($viewer, $request),
                'withdraw' => $request->isPending() && ($request->submitted_by === $viewer->id || $request->applicant?->user_id === $viewer->id),
            ],
        ]);
    }

    /**
     * @return array{steps: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public function timeline(ApprovalRequest $request): array
    {
        $request->loadMissing(['steps.assignees.user', 'actions.actor']);

        return [
            'steps' => $request->steps->map(fn (ApprovalRequestStep $s) => [
                'id' => $s->id,
                'sequence' => $s->sequence,
                'name' => $s->name,
                'mode' => $s->mode->value,
                'mode_label' => $s->mode->label(),
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'skip_reason' => $s->skip_reason?->value,
                'skip_reason_label' => $s->skip_reason?->label(),
                'activated_at' => $s->activated_at?->format('Y-m-d H:i'),
                'completed_at' => $s->completed_at?->format('Y-m-d H:i'),
                'assignees' => $s->assignees->map(fn (ApprovalStepAssignee $a) => [
                    'user_id' => $a->user_id,
                    'name' => $a->user?->name,
                    'origin' => $a->origin->value,
                    'decision' => $a->decision->value,
                    'decision_label' => $a->decision->label(),
                    'decided_at' => $a->decided_at?->format('Y-m-d H:i'),
                    'note' => $a->note,
                ])->values()->all(),
            ])->values()->all(),
            'actions' => $request->actions->map(fn (ApprovalAction $a) => [
                'id' => $a->id,
                'action' => $a->action->value,
                'action_label' => $a->action->label(),
                'actor' => $a->actor?->name,
                'note' => $a->note,
                'step_id' => $a->approval_request_step_id,
                'created_at' => $a->created_at?->format('Y-m-d H:i'),
            ])->values()->all(),
        ];
    }

    public function typeLabel(string $type): string
    {
        return $this->registry->has($type) ? $this->registry->get($type)->label() : $type;
    }

    private function summary(ApprovalRequest $request): string
    {
        $doc = $request->approvable()->withoutGlobalScopes()->first();
        if (! $doc instanceof Approvable) {
            return '';
        }

        $summary = $doc->approvalSummary();

        return (string) __($summary['key'], array_map(fn ($v) => (string) $v, $summary['params'] ?? []));
    }
}
