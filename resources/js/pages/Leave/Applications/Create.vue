<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TextArea from '@/components/app/TextArea.vue';
import DayBreakdown from '@/components/leave/DayBreakdown.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days, postJson } from '@/lib/leave';
import type { DayRow, LeaveTypeRow } from '@/lib/leave';
import type { Option } from '@/types';

type RouteStep = {
    workflow_step_id: number;
    sequence: number;
    name: string;
    outcome: string;
    approvers: { id: number; name: string }[];
    candidates: { id: number; name: string }[];
    default_choice: number | null;
};

type Preview = {
    breakdown: { total_days: string; unpaid_days: string; calendar_days: number; counts: Record<string, number>; days: DayRow[] } | null;
    errors: Record<string, string>;
    warnings: string[];
    block_hits: { list: string; date: string; reason: string | null }[];
    balance: { before: string; after: string | null; reserved: string; expiring: { remaining: string; date_to: string }[] } | null;
    suggested_to_date: string | null;
    route: { name: string; blocked: boolean; steps: RouteStep[] } | null;
};

const props = defineProps<{
    options: { types: LeaveTypeRow[]; employees: { id: number; full_name: string; employee_no: string }[]; sessions: Option<string>[] };
    own_employee_id: number | null;
    can: { create_for_others: boolean };
    settings: { default_max_backdate_days: number };
}>();

const form = useForm({
    employee_id: null as number | null,
    leave_type_id: null as number | null,
    from_date: '',
    to_date: '',
    half_day_date: '',
    half_day_session: 'am',
    event_date: '',
    reason: '',
    contact_during_leave: '',
    handover_note: '',
    block_list_acknowledged: false,
    choices: {} as Record<string, number | null>,
    attachments: [] as File[],
});

const preview = ref<Preview | null>(null);
const loading = ref(false);
const previewFailed = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let seq = 0;

const selectedType = computed(() => props.options.types.find((t) => t.id === form.leave_type_id) ?? null);
const typeOptions = computed(() => props.options.types.map((t) => ({ value: t.id, label: `${t.display_name} (${t.code})` })));
const employeeOptions = computed(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` })));
const multiDay = computed(() => form.from_date !== '' && form.to_date !== '' && form.to_date !== form.from_date);
const halfDayChoices = computed(() => {
    const dates = [form.from_date, form.to_date].filter((d, i, a) => d !== '' && a.indexOf(d) === i);

    return dates.map((d) => ({ value: d, label: formatDate(d) }));
});
const needsAttachment = computed(() => {
    const t = selectedType.value;
    if (!t) return false;
    if (t.requires_attachment === 'always') return true;
    if (t.requires_attachment === 'over_days') return Number(preview.value?.breakdown?.total_days ?? 0) > Number(t.attachment_over_days ?? 0);

    return false;
});
const errorList = computed(() => Object.values(preview.value?.errors ?? {}));
const needsAck = computed(() => (preview.value?.block_hits.length ?? 0) > 0);
const blocked = computed(() => errorList.value.length > 0 || preview.value?.route?.blocked === true);
const pickerSteps = computed(() => (preview.value?.route?.steps ?? []).filter((s) => s.candidates.length > 0));

function payload(): FormData {
    const data = new FormData();
    const add = (key: string, value: unknown) => {
        if (value !== null && value !== '' && value !== undefined) data.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    };
    add('employee_id', form.employee_id);
    add('leave_type_id', form.leave_type_id);
    add('from_date', form.from_date);
    add('to_date', form.to_date || form.from_date);
    add('half_day_date', selectedType.value?.allow_half_day ? form.half_day_date : '');
    add('half_day_session', form.half_day_date ? form.half_day_session : '');
    add('event_date', form.event_date);
    add('reason', form.reason);
    add('block_list_acknowledged', form.block_list_acknowledged);
    for (const [k, v] of Object.entries(form.choices)) add(`choices[${k}]`, v);
    form.attachments.forEach((file) => data.append('attachments[]', file));

    return data;
}

async function refresh() {
    if (!form.leave_type_id || !form.from_date) {
        preview.value = null;

        return;
    }
    const mine = ++seq;
    loading.value = true;
    previewFailed.value = false;
    try {
        const result = await postJson<Preview & { errors?: Record<string, unknown> }>('/leave/applications/preview', payload());
        if (mine !== seq) return;
        // 422 answers carry Laravel's {message, errors:{field:[...]}} shape
        const errs: Record<string, string> = {};
        for (const [k, v] of Object.entries(result.errors ?? {})) errs[k] = Array.isArray(v) ? String(v[0]) : String(v);
        preview.value = { ...result, errors: errs, warnings: result.warnings ?? [], block_hits: result.block_hits ?? [] };
        for (const step of preview.value.route?.steps ?? []) {
            const key = String(step.workflow_step_id);
            if (step.candidates.length && form.choices[key] === undefined) form.choices[key] = step.default_choice;
        }
    } catch {
        if (mine === seq) previewFailed.value = true;
    } finally {
        if (mine === seq) loading.value = false;
    }
}

function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(refresh, 350);
}

watch(
    () => [form.employee_id, form.leave_type_id, form.from_date, form.to_date, form.half_day_date, form.half_day_session, form.event_date, form.block_list_acknowledged, form.attachments.length, JSON.stringify(form.choices)],
    schedule,
);
onBeforeUnmount(() => timer && clearTimeout(timer));

watch(
    () => form.leave_type_id,
    () => {
        form.half_day_date = '';
        form.to_date = '';
        preview.value = null;
    },
);

watch(
    () => form.from_date,
    (value) => {
        if (value && (!form.to_date || form.to_date < value)) {
            form.to_date = preview.value?.suggested_to_date ?? value;
        }
    },
);

watch(
    () => preview.value?.suggested_to_date,
    (value) => {
        if (value && selectedType.value?.entitlement_basis === 'per_event' && form.to_date !== value && !form.to_date) form.to_date = value;
    },
);

function onFiles(event: Event) {
    form.attachments = Array.from((event.target as HTMLInputElement).files ?? []);
}

function send(submit: boolean) {
    form.transform((data) => ({
        ...data,
        submit,
        to_date: data.to_date || data.from_date,
        half_day_date: selectedType.value?.allow_half_day && data.half_day_date ? data.half_day_date : null,
        half_day_session: data.half_day_date ? data.half_day_session : null,
        event_date: data.event_date || null,
        attachments: data.attachments.length ? data.attachments : undefined,
    })).post('/leave/applications', { forceFormData: true, preserveScroll: true });
}

function cancel() {
    router.visit('/leave/me');
}
</script>

<template>
    <Head :title="$t('leave.pages.apply')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.me', href: '/leave/me' }, { title: 'leave.pages.apply', href: '/leave/applications/create' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.apply')" :description="$t('leave.apply.description')" />

            <div class="grid gap-6 lg:grid-cols-5">
                <form class="space-y-4 lg:col-span-3" @submit.prevent="send(true)">
                    <FormField v-if="can.create_for_others" :label="$t('leave.fields.employee')" :error="form.errors.employee_id" :hint="$t('leave.apply.on_behalf_hint')">
                        <SelectInput v-model="form.employee_id" :options="employeeOptions" :placeholder="$t('leave.apply.myself')" />
                    </FormField>

                    <FormField :label="$t('leave.fields.leave_type')" :error="form.errors.leave_type_id" required>
                        <SelectInput v-model="form.leave_type_id" :options="typeOptions" />
                    </FormField>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField :label="$t('leave.fields.from_date')" :error="form.errors.from_date" required><Input v-model="form.from_date" type="date" /></FormField>
                        <FormField :label="$t('leave.fields.to_date')" :error="form.errors.to_date" required><Input v-model="form.to_date" type="date" :min="form.from_date" /></FormField>
                    </div>

                    <div v-if="selectedType?.allow_half_day" class="grid gap-4 rounded-md border p-3 sm:grid-cols-2">
                        <FormField :label="$t('leave.apply.half_day_date')" :error="form.errors.half_day_date" :hint="multiDay ? $t('leave.apply.half_day_hint') : undefined">
                            <SelectInput v-model="form.half_day_date" :options="halfDayChoices" :placeholder="$t('leave.apply.no_half_day')" />
                        </FormField>
                        <FormField v-if="form.half_day_date" :label="$t('leave.fields.session')" :error="form.errors.half_day_session">
                            <SelectInput v-model="form.half_day_session" :options="options.sessions" :nullable="false" />
                        </FormField>
                    </div>

                    <FormField v-if="selectedType?.requires_event_date" :label="$t('leave.fields.event_date')" :error="form.errors.event_date" required>
                        <Input v-model="form.event_date" type="date" />
                    </FormField>

                    <FormField :label="$t('leave.fields.reason')" :error="form.errors.reason" :required="Boolean(selectedType?.requires_reason)">
                        <TextArea v-model="form.reason" :rows="3" />
                    </FormField>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField :label="$t('leave.fields.contact_during_leave')" :error="form.errors.contact_during_leave"><Input v-model="form.contact_during_leave" maxlength="30" /></FormField>
                        <FormField :label="$t('leave.fields.handover_note')" :error="form.errors.handover_note"><Input v-model="form.handover_note" /></FormField>
                    </div>

                    <FormField :label="$t('leave.apply.attachments')" :error="form.errors.attachments" :required="needsAttachment" :hint="$t('leave.apply.attachments_hint')">
                        <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" class="text-sm" @change="onFiles" />
                    </FormField>

                    <div v-if="pickerSteps.length" class="space-y-3 rounded-md border p-3">
                        <h3 class="text-sm font-medium">{{ $t('leave.apply.choose_approver') }}</h3>
                        <FormField v-for="step in pickerSteps" :key="step.workflow_step_id" :label="step.name" :error="form.errors[`choices.${step.workflow_step_id}`]">
                            <SelectInput v-model="form.choices[String(step.workflow_step_id)]" :options="step.candidates.map((c) => ({ value: c.id, label: c.name }))" />
                        </FormField>
                    </div>

                    <label v-if="needsAck" class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950">
                        <input v-model="form.block_list_acknowledged" type="checkbox" class="mt-0.5" />
                        <span>{{ $t('leave.apply.block_ack') }}</span>
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" :disabled="form.processing || !form.leave_type_id || !form.from_date || blocked">{{ $t('leave.apply.submit') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing || !form.leave_type_id || !form.from_date" @click="send(false)">{{ $t('leave.apply.save_draft') }}</Button>
                        <Button type="button" variant="ghost" @click="cancel">{{ $t('common.actions.cancel') }}</Button>
                    </div>
                </form>

                <aside class="space-y-4 lg:col-span-2" aria-live="polite">
                    <div class="rounded-lg border p-4">
                        <h2 class="mb-2 text-sm font-medium">{{ $t('leave.apply.summary') }}</h2>
                        <p v-if="!preview && !loading" class="text-sm text-muted-foreground">{{ $t('leave.apply.pick_dates') }}</p>
                        <p v-if="loading" class="text-xs text-muted-foreground">{{ $t('leave.apply.calculating') }}</p>
                        <p v-if="previewFailed" class="text-sm text-red-600">{{ $t('leave.apply.preview_failed') }}</p>

                        <template v-if="preview">
                            <ul v-if="errorList.length" class="mb-3 list-disc space-y-1 rounded-md bg-red-50 p-3 pl-6 text-sm text-red-900 dark:bg-red-950 dark:text-red-100" role="alert" data-testid="preview-errors">
                                <li v-for="(e, i) in errorList" :key="i">{{ e }}</li>
                            </ul>
                            <template v-if="preview.breakdown">
                                <dl class="mb-3 grid grid-cols-3 gap-2 text-sm" data-testid="preview-counts">
                                    <div><dt class="text-xs text-muted-foreground">{{ $t('leave.apply.deducted_days') }}</dt><dd class="text-2xl font-semibold">{{ days(preview.breakdown.total_days) }}</dd></div>
                                    <div><dt class="text-xs text-muted-foreground">{{ $t('leave.apply.unpaid_days') }}</dt><dd class="text-2xl font-semibold">{{ days(preview.breakdown.unpaid_days) }}</dd></div>
                                    <div><dt class="text-xs text-muted-foreground">{{ $t('leave.apply.calendar_days') }}</dt><dd class="text-2xl font-semibold">{{ preview.breakdown.calendar_days }}</dd></div>
                                </dl>
                                <p class="mb-2 text-xs text-muted-foreground">
                                    {{ $t('leave.apply.working') }}: {{ preview.breakdown.counts.working ?? 0 }} · {{ $t('leave.apply.weekly_off') }}: {{ preview.breakdown.counts.weekly_off ?? 0 }} · {{ $t('leave.apply.holiday') }}: {{ preview.breakdown.counts.holiday ?? 0 }}
                                </p>
                                <DayBreakdown :rows="preview.breakdown.days" />
                            </template>

                            <div v-if="preview.balance && selectedType?.has_balance" class="mt-3 rounded-md border p-3 text-sm" data-testid="preview-balance">
                                <p>{{ $t('leave.apply.balance_before') }}: <b>{{ days(preview.balance.before) }}</b></p>
                                <p>{{ $t('leave.apply.balance_after') }}: <b :class="Number(preview.balance.after ?? 0) < 0 ? 'text-red-600' : ''">{{ days(preview.balance.after) }}</b></p>
                                <p class="text-xs text-muted-foreground">{{ $t('leave.balance.reserved') }}: {{ days(preview.balance.reserved) }}</p>
                                <p v-for="(lot, i) in preview.balance.expiring" :key="i" class="text-xs text-amber-700 dark:text-amber-300">
                                    {{ $t('leave.me.expiring', { days: days(lot.remaining), date: formatDate(lot.date_to) }) }}
                                </p>
                            </div>

                            <ul v-if="preview.warnings.length" class="mt-3 list-disc space-y-1 rounded-md bg-amber-50 p-3 pl-6 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                <li v-for="(w, i) in preview.warnings" :key="i">{{ w }}</li>
                            </ul>
                            <ul v-if="preview.block_hits.length" class="mt-3 list-disc space-y-1 rounded-md bg-amber-50 p-3 pl-6 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                <li v-for="(h, i) in preview.block_hits" :key="i">{{ $t('leave.apply.block_hit', { date: formatDate(h.date), list: h.list }) }}<span v-if="h.reason"> — {{ h.reason }}</span></li>
                            </ul>
                        </template>
                    </div>

                    <div v-if="preview?.route" class="rounded-lg border p-4">
                        <h2 class="mb-2 text-sm font-medium">{{ $t('leave.apply.approval_route') }}<span v-if="preview.route.name"> · {{ preview.route.name }}</span></h2>
                        <ol class="space-y-2 text-sm">
                            <li v-for="step in preview.route.steps" :key="step.workflow_step_id">
                                <span class="font-medium">{{ step.sequence }}. {{ step.name }}</span>
                                <span v-if="step.outcome === 'skipped'" class="ml-2 text-xs text-muted-foreground">{{ $t('leave.route.skipped') }}</span>
                                <span v-else-if="step.approvers.length" class="ml-2 text-muted-foreground">{{ step.approvers.map((a) => a.name).join(', ') }}</span>
                                <span v-else class="ml-2 text-xs text-amber-700 dark:text-amber-300">{{ $t(`leave.route.${step.outcome}`) }}</span>
                            </li>
                        </ol>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
