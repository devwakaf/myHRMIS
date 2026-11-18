<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Condition = { attribute: string; operator: string; value: any };
type Step = {
    name: string;
    rule_type: string;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    rule_config: Record<string, any>;
    mode: string;
    skip_if_applicant: boolean;
    skip_if_same_as_previous: boolean;
    exclude_submitter: boolean;
    condition: Condition[];
    on_no_approver: string;
    remind_after_days: number | null;
};
type Workflow = {
    id: number;
    name: string;
    description: string | null;
    version: number;
    is_active: boolean;
    used: boolean;
    prevent_self_approval: boolean;
    allow_override: boolean;
    notify_applicant_each_step: boolean;
    published_at: string | null;
    steps: (Step & { rule_label: string })[];
};

const props = defineProps<{
    type: string;
    typeLabel: string;
    workflow: Workflow | null;
    history: Workflow[];
    catalogue: { attribute: string; type: string; label: string; options: string[] }[];
    operators: string[];
    ruleTypes: Option<string>[];
    modes: Option<string>[];
    onNoApprover: Option<string>[];
    roles: Option<string>[];
    users: Option<number>[];
    employees: Option<number>[];
    canManage: boolean;
}>();

function blankStep(): Step {
    return {
        name: '',
        rule_type: 'named_or_department',
        rule_config: { selection: 'applicant_chooses', walk_ancestors: true },
        mode: 'any_one',
        skip_if_applicant: true,
        skip_if_same_as_previous: true,
        exclude_submitter: true,
        condition: [],
        on_no_approver: 'fail',
        remind_after_days: null,
    };
}

const form = useForm({
    name: props.workflow?.name ?? '',
    description: props.workflow?.description ?? '',
    prevent_self_approval: props.workflow?.prevent_self_approval ?? true,
    allow_override: props.workflow?.allow_override ?? true,
    notify_applicant_each_step: props.workflow?.notify_applicant_each_step ?? false,
    steps: (props.workflow?.steps ?? [blankStep()]).map((s) => ({
        ...s,
        rule_config: { ...(s.rule_config ?? {}) },
        condition: Array.isArray(s.condition) ? s.condition.map((c) => ({ ...c })) : [],
    })) as Step[],
});

function move(index: number, delta: number) {
    const target = index + delta;
    if (target < 0 || target >= form.steps.length) return;
    const steps = [...form.steps];
    [steps[index], steps[target]] = [steps[target], steps[index]];
    form.steps = steps;
}

function addStep() {
    if (form.steps.length < 10) form.steps.push(blankStep());
}

function onRuleChange(step: Step) {
    step.rule_config = {
        named_approver: {},
        department_approvers: { selection: 'first', walk_ancestors: true },
        named_or_department: { selection: 'applicant_chooses', walk_ancestors: true },
        department_head: { walk_up: true },
        reports_to: { level: 1 },
        role: { role: 'pengurus_hr' },
        specific_user: { user_id: null },
    }[step.rule_type] ?? {};
}

function attributeMeta(attribute: string) {
    return props.catalogue.find((c) => c.attribute === attribute);
}

function addCondition(step: Step) {
    const first = props.catalogue[0];
    step.condition.push({ attribute: first?.attribute ?? '', operator: '=', value: '' });
}

function publish() {
    form
        .transform((data) => ({
            ...data,
            steps: data.steps.map((s) => ({
                ...s,
                condition: s.condition.map((c) => ({
                    ...c,
                    value: ['in', 'not_in'].includes(c.operator) && typeof c.value === 'string'
                        ? c.value.split(',').map((v) => v.trim()).filter(Boolean)
                        : attributeMeta(c.attribute)?.type === 'bool'
                          ? c.value === true || c.value === 'true'
                          : c.value,
                })),
            })),
        }))
        .put(`/settings/approval-workflows/${props.type}`, { preserveScroll: true });
}

const previewEmployee = ref<number | null>(null);
const previewAttributes = ref<Record<string, string>>({});
const preview = ref<null | { steps: { sequence: number; name: string; outcome: string; skip_reason: string | null; approvers: { id: number; name: string }[]; candidates: { id: number; name: string }[] }[]; blocked: boolean }>(null);
const previewError = ref<string | null>(null);

async function runPreview() {
    previewError.value = null;
    preview.value = null;
    const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
    const response = await fetch(`/settings/approval-workflows/${props.type}/preview`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ employee_id: previewEmployee.value, attributes: previewAttributes.value }),
    });
    const body = await response.json();
    if (!response.ok) {
        previewError.value = body.message ?? String(response.status);

        return;
    }
    preview.value = body;
}

const boolOptions: Option<string>[] = [
    { value: 'true', label: trans('common.yes') },
    { value: 'false', label: trans('common.no') },
];
</script>

<template>
    <Head :title="typeLabel" />
    <AppLayout
        :breadcrumbs="[
            { title: 'common.nav.approval_workflows', href: '/settings/approval-workflows' },
            { title: typeLabel, href: `/settings/approval-workflows/${type}` },
        ]"
    >
        <div class="grid gap-6 p-4 md:p-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <PageHeader :title="typeLabel" :description="$t('approval.workflows.editor_description')">
                    <template #meta>
                        <p v-if="workflow" class="text-xs text-muted-foreground">
                            {{ $t('approval.workflows.current_version', { version: String(workflow.version) }) }}
                            <span v-if="workflow.used"> · {{ $t('approval.workflows.used_note') }}</span>
                        </p>
                    </template>
                    <template #actions>
                        <Button v-if="canManage" :disabled="form.processing" @click="publish">{{ $t('approval.workflows.publish') }}</Button>
                    </template>
                </PageHeader>

                <div class="grid gap-4 rounded-lg border p-4">
                    <FormField :label="$t('approval.fields.workflow_name')" :error="form.errors.name" required>
                        <Input v-model="form.name" :disabled="!canManage" />
                    </FormField>
                    <FormField :label="$t('common.fields.description')" :error="form.errors.description">
                        <TextArea v-model="form.description" :rows="2" :disabled="!canManage" />
                    </FormField>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2"><input v-model="form.prevent_self_approval" type="checkbox" :disabled="!canManage" /> {{ $t('approval.fields.prevent_self_approval') }}</label>
                        <label class="flex items-center gap-2"><input v-model="form.allow_override" type="checkbox" :disabled="!canManage" /> {{ $t('approval.fields.allow_override') }}</label>
                        <label class="flex items-center gap-2"><input v-model="form.notify_applicant_each_step" type="checkbox" :disabled="!canManage" /> {{ $t('approval.fields.notify_applicant_each_step') }}</label>
                    </div>
                    <p v-if="form.errors.steps" class="text-sm text-red-600">{{ form.errors.steps }}</p>
                </div>

                <div v-for="(step, i) in form.steps" :key="i" class="mt-4 rounded-lg border p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="font-medium">{{ $t('approval.workflows.step_n', { n: String(i + 1) }) }}</h3>
                        <div v-if="canManage" class="flex gap-1">
                            <Button size="icon" variant="ghost" :aria-label="$t('common.actions.move_up')" @click="move(i, -1)"><ArrowUp class="size-4" /></Button>
                            <Button size="icon" variant="ghost" :aria-label="$t('common.actions.move_down')" @click="move(i, 1)"><ArrowDown class="size-4" /></Button>
                            <Button size="icon" variant="ghost" :aria-label="$t('common.actions.delete')" :disabled="form.steps.length === 1" @click="form.steps.splice(i, 1)"><Trash2 class="size-4" /></Button>
                        </div>
                    </div>
                    <fieldset class="grid gap-3 md:grid-cols-2" :disabled="!canManage">
                        <FormField :label="$t('approval.fields.step_name')" :error="form.errors[`steps.${i}.name`]" required>
                            <Input v-model="step.name" :placeholder="$t('approval.workflows.step_name_hint')" />
                        </FormField>
                        <FormField :label="$t('approval.fields.rule_type')" :error="form.errors[`steps.${i}.rule_type`]" required>
                            <SelectInput v-model="step.rule_type" :options="ruleTypes" :nullable="false" @update:model-value="onRuleChange(step)" />
                        </FormField>

                        <template v-if="['department_approvers', 'named_or_department'].includes(step.rule_type)">
                            <FormField :label="$t('approval.fields.selection')">
                                <SelectInput
                                    v-model="step.rule_config.selection"
                                    :nullable="false"
                                    :options="[
                                        { value: 'first', label: $t('approval.selection.first') },
                                        { value: 'all', label: $t('approval.selection.all') },
                                        { value: 'applicant_chooses', label: $t('approval.selection.applicant_chooses') },
                                    ]"
                                />
                            </FormField>
                            <label class="flex items-center gap-2 self-end text-sm">
                                <input v-model="step.rule_config.walk_ancestors" type="checkbox" /> {{ $t('approval.fields.walk_ancestors') }}
                            </label>
                        </template>
                        <label v-if="step.rule_type === 'department_head'" class="flex items-center gap-2 text-sm">
                            <input v-model="step.rule_config.walk_up" type="checkbox" /> {{ $t('approval.fields.walk_up') }}
                        </label>
                        <FormField v-if="step.rule_type === 'reports_to'" :label="$t('approval.fields.level')">
                            <SelectInput v-model="step.rule_config.level" :nullable="false" :options="[1, 2, 3].map((n) => ({ value: n, label: String(n) }))" />
                        </FormField>
                        <FormField v-if="step.rule_type === 'role'" :label="$t('approval.fields.role')" :error="form.errors[`steps.${i}.rule_config.role`]">
                            <SelectInput v-model="step.rule_config.role" :options="roles" :nullable="false" />
                        </FormField>
                        <FormField v-if="step.rule_type === 'specific_user'" :label="$t('approval.fields.user')" :error="form.errors[`steps.${i}.rule_config.user_id`]">
                            <SelectInput v-model="step.rule_config.user_id" :options="users" />
                        </FormField>

                        <FormField :label="$t('approval.fields.mode')">
                            <SelectInput v-model="step.mode" :options="modes" :nullable="false" />
                        </FormField>
                        <FormField :label="$t('approval.fields.on_no_approver')">
                            <SelectInput v-model="step.on_no_approver" :options="onNoApprover" :nullable="false" />
                        </FormField>
                        <FormField :label="$t('approval.fields.remind_after_days')" :error="form.errors[`steps.${i}.remind_after_days`]">
                            <Input v-model.number="step.remind_after_days" type="number" min="1" max="60" />
                        </FormField>
                        <div class="grid gap-1 text-sm">
                            <label class="flex items-center gap-2"><input v-model="step.skip_if_applicant" type="checkbox" /> {{ $t('approval.fields.skip_if_applicant') }}</label>
                            <label class="flex items-center gap-2"><input v-model="step.skip_if_same_as_previous" type="checkbox" /> {{ $t('approval.fields.skip_if_same_as_previous') }}</label>
                            <label class="flex items-center gap-2"><input v-model="step.exclude_submitter" type="checkbox" /> {{ $t('approval.fields.exclude_submitter') }}</label>
                        </div>
                    </fieldset>

                    <div class="mt-3">
                        <p class="mb-1 text-sm font-medium">{{ $t('approval.fields.condition') }}</p>
                        <p v-if="form.errors[`steps.${i}.condition`]" class="text-xs text-red-600">{{ form.errors[`steps.${i}.condition`] }}</p>
                        <div v-for="(cond, c) in step.condition" :key="c" class="mb-2 grid gap-2 sm:grid-cols-[2fr_1fr_2fr_auto]">
                            <SelectInput v-model="cond.attribute" :nullable="false" :options="catalogue.map((a) => ({ value: a.attribute, label: a.label }))" :disabled="!canManage" />
                            <SelectInput v-model="cond.operator" :nullable="false" :options="operators.map((o) => ({ value: o, label: o }))" :disabled="!canManage" />
                            <SelectInput
                                v-if="attributeMeta(cond.attribute)?.type === 'bool'"
                                v-model="cond.value"
                                :nullable="false"
                                :options="boolOptions"
                                :disabled="!canManage"
                            />
                            <Input
                                v-else
                                v-model="cond.value"
                                :placeholder="attributeMeta(cond.attribute)?.options?.join(', ') || ''"
                                :disabled="!canManage"
                            />
                            <Button v-if="canManage" size="icon" variant="ghost" :aria-label="$t('common.actions.delete')" @click="step.condition.splice(c, 1)"><Trash2 class="size-4" /></Button>
                        </div>
                        <Button v-if="canManage" size="sm" variant="outline" @click="addCondition(step)"><Plus class="size-4" />{{ $t('approval.workflows.add_condition') }}</Button>
                    </div>
                </div>

                <Button v-if="canManage && form.steps.length < 10" class="mt-4" variant="outline" @click="addStep"><Plus class="size-4" />{{ $t('approval.workflows.add_step') }}</Button>
            </div>

            <div class="space-y-6">
                <div class="rounded-lg border p-4">
                    <h3 class="mb-2 font-medium">{{ $t('approval.workflows.preview') }}</h3>
                    <p class="mb-2 text-xs text-muted-foreground">{{ $t('approval.workflows.preview_hint') }}</p>
                    <div class="grid gap-2">
                        <SelectInput v-model="previewEmployee" :options="employees" :placeholder="$t('approval.workflows.pick_employee')" />
                        <div v-for="attr in catalogue.filter((a) => !a.attribute.startsWith('applicant.'))" :key="attr.attribute" class="grid grid-cols-2 items-center gap-2 text-xs">
                            <span>{{ attr.label }}</span>
                            <Input v-model="previewAttributes[attr.attribute]" class="h-8" />
                        </div>
                        <Button size="sm" :disabled="!previewEmployee" @click="runPreview">{{ $t('approval.workflows.run_preview') }}</Button>
                    </div>
                    <p v-if="previewError" class="mt-2 text-sm text-red-600">{{ previewError }}</p>
                    <ol v-if="preview" class="mt-3 space-y-2 text-sm">
                        <li v-for="s in preview.steps" :key="s.sequence">
                            <p class="font-medium">{{ s.sequence }}. {{ s.name }} — {{ $t(`approval.preview.outcomes.${s.outcome}`) }}</p>
                            <p v-if="s.approvers.length" class="text-muted-foreground">{{ s.approvers.map((a) => a.name).join(', ') }}</p>
                            <p v-if="s.candidates.length" class="text-xs text-muted-foreground">{{ $t('approval.preview.candidates') }}: {{ s.candidates.map((a) => a.name).join(', ') }}</p>
                        </li>
                    </ol>
                </div>

                <div class="rounded-lg border p-4">
                    <h3 class="mb-2 font-medium">{{ $t('approval.workflows.history') }}</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="h in history" :key="h.id">
                            <span class="font-medium">v{{ h.version }}</span> · {{ h.name }}
                            <span v-if="h.is_active" class="ml-1 rounded bg-emerald-100 px-1 text-xs text-emerald-800">{{ $t('common.active') }}</span>
                            <p class="text-xs text-muted-foreground">{{ formatDateTime(h.published_at) }} · {{ h.steps.map((s) => s.name).join(' → ') }}</p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
