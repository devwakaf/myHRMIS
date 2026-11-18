<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Controllers;

use App\Domain\Core\Http\Resources\Lookup;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\Actions\PublishApprovalWorkflow;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Enums\OnNoApprover;
use App\Domain\Shared\Approval\Enums\RuleType;
use App\Domain\Shared\Approval\Enums\StepMode;
use App\Domain\Shared\Approval\Models\ApprovalWorkflow;
use App\Domain\Shared\Approval\Models\ApprovalWorkflowStep;
use App\Domain\Shared\Approval\Services\ConditionEvaluator;
use App\Domain\Shared\Approval\Support\WorkflowPreviewSubject;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainError;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aliran Kelulusan (A6.4, B4): one card per request type; editor publishes a new
 * version when the current one has been used; version history read-only.
 */
final class ApprovalWorkflowController extends Controller
{
    public function __construct(private readonly ApprovalTypeRegistry $registry) {}

    public function index(): Response
    {
        $this->requirePermission('core.approval-workflows.manage', 'core.approval-workflows.view');

        $cards = [];
        foreach ($this->registry->all() as $key => $definition) {
            $active = ApprovalWorkflow::query()->activeFor($key)->with('steps')->first();
            $cards[] = [
                'type' => $key,
                'label' => $definition->label(),
                'bound' => $definition->modelClass !== null,
                'workflow' => $active === null ? null : [
                    'id' => $active->id,
                    'name' => $active->name,
                    'version' => $active->version,
                    'published_at' => $active->published_at?->format('Y-m-d H:i'),
                    'steps' => $active->steps->map(fn (ApprovalWorkflowStep $s) => [
                        'sequence' => $s->sequence,
                        'name' => $s->name,
                        'rule_label' => $s->rule_type->label(),
                        'mode_label' => $s->mode->label(),
                        'has_condition' => ! empty($s->condition),
                    ])->values()->all(),
                ],
            ];
        }

        return Inertia::render('settings/ApprovalWorkflows/Index', ['cards' => $cards]);
    }

    public function edit(string $type): Response
    {
        $this->requirePermission('core.approval-workflows.manage', 'core.approval-workflows.view');
        abort_unless($this->registry->has($type), 404);

        $active = ApprovalWorkflow::query()->activeFor($type)->with('steps')->first();
        $history = ApprovalWorkflow::query()->where('approval_type', $type)->orderByDesc('version')->with('steps')->get();

        $catalogue = [];
        $enumOptions = $this->registry->enumOptions($type);
        foreach ($this->registry->catalogue($type) as $attribute => $attrType) {
            $catalogue[] = [
                'attribute' => $attribute,
                'type' => $attrType->value,
                'label' => __('approval.attributes.'.$attribute),
                'options' => $enumOptions[$attribute] ?? [],
            ];
        }

        return Inertia::render('settings/ApprovalWorkflows/Edit', [
            'type' => $type,
            'typeLabel' => $this->registry->get($type)->label(),
            'workflow' => $active === null ? null : $this->workflowArray($active),
            'history' => $history->map(fn (ApprovalWorkflow $w) => $this->workflowArray($w))->values()->all(),
            'catalogue' => $catalogue,
            'operators' => ConditionEvaluator::OPERATORS,
            'ruleTypes' => RuleType::options(),
            'modes' => StepMode::options(),
            'onNoApprover' => OnNoApprover::options(),
            'roles' => array_map(fn ($r) => ['value' => $r, 'label' => __('common.roles.'.$r)], PermissionMatrix::roles()),
            'users' => Lookup::members(),
            'employees' => Lookup::employees(),
            'canManage' => request()->user()->can('core.approval-workflows.manage'),
        ]);
    }

    public function update(Request $request, string $type, PublishApprovalWorkflow $publish): RedirectResponse
    {
        $this->requirePermission('core.approval-workflows.manage');
        abort_unless($this->registry->has($type), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'prevent_self_approval' => ['boolean'],
            'allow_override' => ['boolean'],
            'notify_applicant_each_step' => ['boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.rule_type' => ['required', 'string'],
            'steps.*.rule_config' => ['nullable', 'array'],
            'steps.*.mode' => ['required', 'string'],
            'steps.*.skip_if_applicant' => ['boolean'],
            'steps.*.skip_if_same_as_previous' => ['boolean'],
            'steps.*.exclude_submitter' => ['boolean'],
            'steps.*.condition' => ['nullable', 'array'],
            'steps.*.on_no_approver' => ['required', 'string'],
            'steps.*.remind_after_days' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $workflow = $publish->handle(OrganisationContext::id(), $type, $data, $request->user());

        return redirect("/settings/approval-workflows/{$type}")
            ->with('success', __('approval.flash.published', ['version' => $workflow->version]));
    }

    public function preview(Request $request, string $type, ApprovalService $service): JsonResponse
    {
        $this->requirePermission('core.approval-workflows.manage', 'core.approval-workflows.view');
        abort_unless($this->registry->has($type), 404);

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'attributes' => ['nullable', 'array'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $catalogue = array_keys($this->registry->catalogue($type));
        $attributes = array_intersect_key($data['attributes'] ?? [], array_flip($catalogue));

        try {
            $route = $service->preview(new WorkflowPreviewSubject($type, $employee, $attributes));
        } catch (DomainError $e) {
            return new JsonResponse(['message' => $e->userMessage()], 422);
        }

        return new JsonResponse($route->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowArray(ApprovalWorkflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'version' => $workflow->version,
            'is_active' => $workflow->is_active,
            'used' => $workflow->isUsed(),
            'prevent_self_approval' => $workflow->prevent_self_approval,
            'allow_override' => $workflow->allow_override,
            'notify_applicant_each_step' => $workflow->notify_applicant_each_step,
            'published_at' => $workflow->published_at?->format('Y-m-d H:i'),
            'steps' => $workflow->steps->map(fn (ApprovalWorkflowStep $s) => [
                'name' => $s->name,
                'rule_type' => $s->rule_type->value,
                'rule_label' => $s->rule_type->label(),
                'rule_config' => $s->rule_config ?? (object) [],
                'mode' => $s->mode->value,
                'skip_if_applicant' => $s->skip_if_applicant,
                'skip_if_same_as_previous' => $s->skip_if_same_as_previous,
                'exclude_submitter' => $s->exclude_submitter,
                'condition' => $s->condition ?? [],
                'on_no_approver' => $s->on_no_approver->value,
                'remind_after_days' => $s->remind_after_days,
            ])->values()->all(),
        ];
    }
}
