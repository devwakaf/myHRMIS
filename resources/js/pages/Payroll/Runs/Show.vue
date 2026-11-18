<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Download, FileText, Lock } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CheckField from '@/components/app/CheckField.vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import RateSetBadge from '@/components/payroll/RateSetBadge.vue';
import UnverifiedBanner from '@/components/payroll/UnverifiedBanner.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel, trimZeros } from '@/lib/payroll';
import type { FileRow, RateSetRow } from '@/lib/payroll';

type RunEmployee = {
    id: number;
    employee_id: number;
    employee_no: string | null;
    full_name: string | null;
    included: boolean;
    exclusion_reason: string | null;
    generation_status: string;
    generation_label: string;
    error_message: string | null;
    warnings: string[];
    attempts: number;
    lwp_override_days: string | null;
    pcb_override_amount: string | null;
    slip: {
        id: number;
        number: string;
        payment_days: string;
        lwp_days: string;
        gross_pay: string;
        total_deductions: string;
        net_pay: string;
        employer_total: string;
        has_errors: boolean;
        payment_status: string;
    } | null;
};
type Preview = {
    eligible: { id: number; employee_no: string; full_name: string; warnings: string[] }[];
    no_assignment: { id: number; employee_no: string; full_name: string }[];
    filtered_out: number;
};
type Run = {
    id: number;
    number: string;
    period: string;
    payment_date: string;
    status: 'draft' | 'slips_generated' | 'submitted' | 'paid' | 'cancelled';
    status_label: string;
    processing_state: string;
    processing_label: string;
    processing_error: string | null;
    filters: Record<string, number[]>;
    notes: string | null;
    employee_count: number;
    withheld_count: number;
    gross_total: string;
    deduction_total: string;
    net_total: string;
    employer_total: string;
    submitted_at: string | null;
    paid_at: string | null;
    payment_reference: string | null;
    unverified_acknowledged: boolean;
};
type Progress = { status: string; processing_state: string; processing_error: string | null; counts: Record<string, number> | []; employee_count: number };

const props = defineProps<{
    run: Run;
    employees: RunEmployee[];
    preview: Preview | null;
    component_totals: { code: string; name: string; kind: string; amount: string }[];
    rate_sets: RateSetRow[];
    files: FileRow[];
    bank_accounts: { id: number; account_no: string; account_name: string }[];
    can: { prepare: boolean; submit: boolean; mark_paid: boolean; bank_file: boolean; cancel: boolean };
}>();

const STEPS = ['setup', 'employees', 'generate', 'review', 'submit', 'pay'] as const;

// ------------------------------------------------------------------ step state
const processing = computed(() => props.run.processing_state === 'queued' || props.run.processing_state === 'running');
const autoStep = computed(() => {
    if (processing.value || props.run.processing_state === 'failed') return 3;
    switch (props.run.status) {
        case 'draft':
            return props.employees.length ? 3 : 2;
        case 'slips_generated':
            return 4;
        case 'submitted':
        case 'paid':
            return 6;
        default:
            return 1;
    }
});
const step = ref<number>(autoStep.value);
watch(
    () => [props.run.status, props.run.processing_state],
    () => (step.value = autoStep.value),
);
const locked = computed(() => props.run.status === 'submitted' || props.run.status === 'paid' || props.run.status === 'cancelled');
const maxReachable = computed(() => (props.run.status === 'draft' ? (props.employees.length ? 3 : 2) : 6));

// ------------------------------------------------------------------ progress polling (every 2 s while queued/running)
const progress = ref<Progress | null>(null);
let timer: ReturnType<typeof setInterval> | null = null;

const counts = computed<Record<string, number>>(() => {
    const c = progress.value?.counts;

    return c && !Array.isArray(c) ? c : {};
});
const progressTotal = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0) || props.employees.filter((e) => e.included).length);
const progressDone = computed(() => progressTotal.value - (counts.value.pending ?? 0));
const percent = computed(() => (progressTotal.value === 0 ? 0 : Math.round((progressDone.value / progressTotal.value) * 100)));

async function poll() {
    try {
        const res = await fetch(`/payroll/runs/${props.run.id}/progress`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const body = (await res.json()) as Progress;
        progress.value = body;
        if (body.processing_state !== 'queued' && body.processing_state !== 'running') {
            stop();
            router.reload({ only: ['run', 'employees', 'component_totals', 'rate_sets', 'preview', 'files'] });
        }
    } catch {
        /* keep polling */
    }
}
function start() {
    if (timer === null) {
        poll();
        timer = setInterval(poll, 2000);
    }
}
function stop() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}
watch(processing, (on) => (on ? start() : stop()), { immediate: false });
onMounted(() => processing.value && start());
onBeforeUnmount(stop);

// ------------------------------------------------------------------ step 1: setup
const setup = useForm({ payment_date: props.run.payment_date, notes: props.run.notes ?? '' });
const reload = { preserveScroll: true } as const;

function saveSetup() {
    setup.transform((d) => ({ payment_date: d.payment_date, notes: d.notes || null })).put(`/payroll/runs/${props.run.id}`, reload);
}

// ------------------------------------------------------------------ step 2: employees
const rowOf = (employeeId: number) => props.employees.find((e) => e.employee_id === employeeId);
const excludeTarget = ref<number | null>(null);

function confirmExclude(reason: string) {
    if (excludeTarget.value !== null) router.post(`/payroll/runs/${props.run.id}/employees/${excludeTarget.value}/exclude`, { reason }, reload);
    excludeTarget.value = null;
}
const eligibleCount = computed(() => (props.preview?.eligible ?? []).filter((e) => rowOf(e.id)?.included !== false).length);

// ------------------------------------------------------------------ step 3: generate
function generate(options: { employee_ids?: number[]; failed_only?: boolean } = {}) {
    router.post(`/payroll/runs/${props.run.id}/generate`, options, reload);
}
const included = computed(() => props.employees.filter((e) => e.included));
const failed = computed(() => included.value.filter((e) => e.generation_status === 'failed'));
const skipped = computed(() => props.employees.filter((e) => e.included && e.generation_status === 'skipped'));

// ------------------------------------------------------------------ step 4: review
const slips = computed(() => props.employees.filter((e) => e.slip !== null && e.included));
const overrideTarget = ref<{ kind: 'lwp' | 'pcb'; row: RunEmployee } | null>(null);
const override = useForm({ value: '' as string | number, reason: '' });

function startOverride(kind: 'lwp' | 'pcb', row: RunEmployee) {
    overrideTarget.value = { kind, row };
    override.defaults({ value: (kind === 'lwp' ? row.lwp_override_days : row.pcb_override_amount) ?? '', reason: '' }).reset();
    override.clearErrors();
}

function saveOverride(clear: boolean) {
    const t = overrideTarget.value;
    if (!t) return;
    const url = `/payroll/runs/${props.run.id}/employees/${t.row.employee_id}/${t.kind === 'lwp' ? 'override-lwp' : 'override-pcb'}`;
    override.transform((d) => (clear ? { [t.kind === 'lwp' ? 'days' : 'amount']: null } : { [t.kind === 'lwp' ? 'days' : 'amount']: d.value, reason: d.reason })).post(url, {
        preserveScroll: true,
        onSuccess: () => (overrideTarget.value = null),
    });
}

function variance(row: RunEmployee): string | null {
    const hit = row.warnings.find((w) => /\d\s*%/.test(w));

    return hit ? (hit.match(/(\d+(?:[.,]\d+)?)\s*%/)?.[1] ?? null) : null;
}

const discardOpen = ref(false);
const deleteOpen = ref(false);

// ------------------------------------------------------------------ step 5: submit & lock
const unverifiedSets = computed(() => props.rate_sets.filter((s) => s.status !== 'verified'));
const acknowledge = ref(false);
const checklist = computed(() => [
    { key: 'no_failures', ok: included.value.length > 0 && included.value.every((e) => e.slip !== null && e.generation_status !== 'failed' && e.generation_status !== 'pending') },
    { key: 'no_errors', ok: !slips.value.some((e) => e.slip?.has_errors) },
    { key: 'rates', ok: unverifiedSets.value.length === 0 || acknowledge.value },
    { key: 'sod', ok: props.can.submit },
]);
const submitOpen = ref(false);

function submitRun() {
    router.post(`/payroll/runs/${props.run.id}/submit`, { acknowledge_unverified: acknowledge.value || unverifiedSets.value.length === 0 }, { ...reload, onSuccess: () => (submitOpen.value = false) });
}

const cancelOpen = ref(false);
function confirmCancel(reason: string) {
    router.post(`/payroll/runs/${props.run.id}/cancel`, { reason }, { ...reload, onSuccess: () => (cancelOpen.value = false) });
}

// ------------------------------------------------------------------ step 6: pay
const paidOpen = ref(false);
const paid = useForm({ paid_on: new Date().toISOString().slice(0, 10), reference: '' });
const supplementary = ref(false);

function markPaid() {
    paid.transform((d) => ({ paid_on: d.paid_on, reference: d.reference || null })).post(`/payroll/runs/${props.run.id}/mark-paid`, { ...reload, onSuccess: () => (paidOpen.value = false) });
}
function bankFile(accountId: number) {
    router.post(`/payroll/runs/${props.run.id}/bank-file`, { account_id: accountId, supplementary: supplementary.value }, reload);
}
const withheld = computed(() => props.employees.filter((e) => e.slip?.payment_status === 'withheld'));
const bankFiles = computed(() => props.files);

const stepDone = (n: number) => {
    switch (n) {
        case 1:
        case 2:
            return props.run.status !== 'draft' || props.employees.length > 0;
        case 3:
            return props.run.status !== 'draft';
        case 4:
            return props.run.status === 'submitted' || props.run.status === 'paid';
        case 5:
            return props.run.status === 'submitted' || props.run.status === 'paid';
        default:
            return props.run.status === 'paid';
    }
};
</script>

<template>
    <Head :title="`${$t('payroll.pages.run')} ${run.number}`" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.runs', href: '/payroll/runs' }, { title: 'payroll.pages.run', href: `/payroll/runs/${run.id}` }]">
        <div class="space-y-4 p-4 md:p-6">
            <PageHeader :title="`${monthLabel(run.period)} — ${run.number}`" :description="$t('payroll.wizard.description')">
                <template #meta>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <StatusBadge :status="run.status" :label="run.status_label" data-testid="run-status" />
                        <span v-if="processing" class="text-xs text-muted-foreground">{{ run.processing_label }}</span>
                        <Lock v-if="locked" class="size-4 text-muted-foreground" />
                    </div>
                </template>
                <template #actions>
                    <Button variant="outline" as-child><Link href="/payroll/runs">{{ $t('common.actions.back') }}</Link></Button>
                    <Button v-if="can.prepare && run.status === 'draft'" variant="outline" @click="deleteOpen = true">{{ $t('payroll.wizard.delete_draft') }}</Button>
                    <Button v-if="can.cancel && (run.status === 'submitted' || run.status === 'slips_generated')" variant="outline" data-testid="run-cancel" @click="cancelOpen = true">{{ $t('payroll.wizard.cancel_run') }}</Button>
                </template>
            </PageHeader>

            <UnverifiedBanner v-if="unverifiedSets.length" :title="$t('payroll.wizard.unverified_title')" :message="$t('payroll.wizard.unverified_message', { codes: unverifiedSets.map((s) => s.code).join(', ') })" />

            <ol class="flex flex-wrap gap-2" data-testid="wizard-steps">
                <li v-for="(key, i) in STEPS" :key="key">
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm"
                        :class="step === i + 1 ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent'"
                        :disabled="i + 1 > maxReachable && !stepDone(i + 1)"
                        :data-step="i + 1"
                        @click="step = i + 1"
                    >
                        <span class="flex size-5 items-center justify-center rounded-full text-xs" :class="step === i + 1 ? 'bg-primary-foreground text-primary' : 'bg-muted'">
                            <Check v-if="stepDone(i + 1) && step !== i + 1" class="size-3" /><template v-else>{{ i + 1 }}</template>
                        </span>
                        {{ $t(`payroll.wizard.steps.${key}`) }}
                    </button>
                </li>
            </ol>

            <!-- summary -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">{{ $t('payroll.runs.employees') }}</p><p class="text-lg font-semibold">{{ run.employee_count }}</p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">{{ $t('payroll.slip.gross') }}</p><p class="text-lg font-semibold"><MoneyText :value="run.gross_total" /></p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">{{ $t('payroll.slip.total_deductions') }}</p><p class="text-lg font-semibold"><MoneyText :value="run.deduction_total" /></p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">{{ $t('payroll.slip.net') }}</p><p class="text-lg font-semibold"><MoneyText :value="run.net_total" /></p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">{{ $t('payroll.slip.employer_box') }}</p><p class="text-lg font-semibold"><MoneyText :value="run.employer_total" /></p></div>
            </div>

            <!-- 1 Tetapan -->
            <section v-if="step === 1" class="rounded-lg border p-4" data-testid="step-setup">
                <h2 class="mb-3 font-semibold">{{ $t('payroll.wizard.steps.setup') }}</h2>
                <dl class="mb-3 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.runs.period') }}</dt><dd>{{ monthLabel(run.period) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.payment_date') }}</dt><dd>{{ formatDate(run.payment_date) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.wizard.filters') }}</dt><dd>{{ Object.keys(run.filters ?? {}).length ? Object.entries(run.filters).map(([k, v]) => `${$t(`payroll.wizard.filter_names.${k}`)}: ${v.length}`).join(', ') : $t('payroll.wizard.no_filters') }}</dd></div>
                </dl>
                <form v-if="can.prepare && run.status === 'draft'" class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveSetup">
                    <FormField :label="$t('payroll.slip.payment_date')" :error="setup.errors.payment_date"><Input v-model="setup.payment_date" type="date" /></FormField>
                    <FormField :label="$t('common.fields.remarks')" :error="setup.errors.notes"><Input v-model="setup.notes" /></FormField>
                    <div class="sm:col-span-2"><Button type="submit" :disabled="setup.processing">{{ $t('common.actions.save') }}</Button></div>
                </form>
                <p v-else-if="run.notes" class="text-sm text-muted-foreground">{{ run.notes }}</p>
                <div class="mt-4"><Button variant="outline" @click="step = 2">{{ $t('common.actions.continue') }}</Button></div>
            </section>

            <!-- 2 Pilih Kakitangan -->
            <section v-if="step === 2" class="space-y-3 rounded-lg border p-4" data-testid="step-employees">
                <h2 class="font-semibold">{{ $t('payroll.wizard.steps.employees') }}</h2>
                <p v-if="!preview" class="text-sm text-muted-foreground">{{ $t('payroll.wizard.locked_employees') }}</p>
                <template v-else>
                    <p class="text-sm text-muted-foreground">{{ $t('payroll.wizard.eligible_summary', { count: String(eligibleCount), filtered: String(preview.filtered_out) }) }}</p>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th><th class="px-3 py-2">{{ $t('payroll.wizard.warnings') }}</th><th class="px-3 py-2"></th></tr></thead>
                            <tbody>
                                <tr v-for="e in preview.eligible" :key="e.id" class="border-t" :class="rowOf(e.id)?.included === false ? 'opacity-50' : ''">
                                    <td class="px-3 py-2">{{ e.full_name }}<span class="block text-xs text-muted-foreground">{{ e.employee_no }}</span></td>
                                    <td class="px-3 py-2">
                                        <span v-for="w in e.warnings" :key="w" class="mr-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">{{ $t(`payroll.wizard.chips.${w}`) }}</span>
                                        <span v-if="rowOf(e.id)?.included === false" class="text-xs text-muted-foreground">{{ $t('payroll.wizard.excluded') }}: {{ rowOf(e.id)?.exclusion_reason }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <Button v-if="can.prepare && rowOf(e.id)?.included !== false" size="sm" variant="outline" @click="excludeTarget = e.id">{{ $t('payroll.wizard.exclude') }}</Button>
                                        <Button v-if="can.prepare && rowOf(e.id)?.included === false" size="sm" variant="outline" @click="router.post(`/payroll/runs/${run.id}/employees/${e.id}/include`, {}, reload)">{{ $t('payroll.wizard.include') }}</Button>
                                    </td>
                                </tr>
                                <tr v-if="!preview.eligible.length"><td colspan="3" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="preview.no_assignment.length" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950">
                        <p class="font-medium">{{ $t('payroll.wizard.no_assignment', { count: String(preview.no_assignment.length) }) }}</p>
                        <p class="text-xs">{{ preview.no_assignment.map((e) => `${e.full_name} (${e.employee_no})`).join(', ') }}</p>
                        <Link href="/payroll/assignments" class="text-xs text-primary hover:underline">{{ $t('payroll.pages.assignments') }}</Link>
                    </div>
                </template>
                <div class="flex gap-2">
                    <Button variant="outline" @click="step = 1">{{ $t('common.actions.back') }}</Button>
                    <Button @click="step = 3">{{ $t('common.actions.continue') }}</Button>
                </div>
            </section>

            <!-- 3 Jana Slip -->
            <section v-if="step === 3" class="space-y-3 rounded-lg border p-4" data-testid="step-generate">
                <h2 class="font-semibold">{{ $t('payroll.wizard.steps.generate') }}</h2>
                <div v-if="processing" class="space-y-2" data-testid="progress">
                    <div class="h-3 w-full overflow-hidden rounded-full bg-muted"><div class="h-full bg-primary transition-all" :style="{ width: `${percent}%` }" /></div>
                    <p class="text-sm">{{ $t('payroll.wizard.progress', { done: String(progressDone), total: String(progressTotal) }) }}</p>
                </div>
                <p v-if="run.processing_state === 'failed'" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ run.processing_error ?? $t('payroll.wizard.generation_failed') }}</p>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.prepare && (run.status === 'draft' || run.status === 'slips_generated')" :disabled="processing" data-testid="generate" @click="generate()">
                        {{ run.status === 'draft' ? $t('payroll.wizard.generate') : $t('payroll.wizard.regenerate_all') }}
                    </Button>
                    <Button v-if="can.prepare && failed.length" variant="outline" :disabled="processing" data-testid="retry-failed" @click="generate({ failed_only: true })">{{ $t('payroll.wizard.retry_failed', { count: String(failed.length) }) }}</Button>
                </div>

                <div v-if="included.length" class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th><th class="px-3 py-2">{{ $t('common.fields.status') }}</th><th class="px-3 py-2">{{ $t('payroll.wizard.message') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="e in included" :key="e.id" class="border-t">
                                <td class="px-3 py-2">{{ e.full_name }}<span class="block text-xs text-muted-foreground">{{ e.employee_no }}</span></td>
                                <td class="px-3 py-2"><StatusBadge :status="e.generation_status" :label="e.generation_label" /></td>
                                <td class="px-3 py-2 text-xs">
                                    <span v-if="e.error_message" class="text-red-700 dark:text-red-300">{{ e.error_message }}</span>
                                    <ul v-if="e.warnings.length" class="list-disc pl-4 text-amber-800 dark:text-amber-300"><li v-for="(w, n) in e.warnings" :key="n">{{ w }}</li></ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="skipped.length" class="text-xs text-muted-foreground">{{ $t('payroll.wizard.skipped', { count: String(skipped.length) }) }}</p>
                <div class="flex gap-2">
                    <Button variant="outline" @click="step = 2">{{ $t('common.actions.back') }}</Button>
                    <Button v-if="run.status !== 'draft'" @click="step = 4">{{ $t('common.actions.continue') }}</Button>
                </div>
            </section>

            <!-- 4 Semak Slip -->
            <section v-if="step === 4" class="space-y-3 rounded-lg border p-4" data-testid="step-review">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">{{ $t('payroll.wizard.steps.review') }}</h2>
                    <div class="flex gap-2">
                        <Button v-if="can.prepare && run.status === 'slips_generated'" size="sm" variant="outline" :disabled="processing" @click="generate()">{{ $t('payroll.wizard.regenerate_all') }}</Button>
                        <Button v-if="can.prepare && run.status === 'slips_generated'" size="sm" variant="outline" @click="discardOpen = true">{{ $t('payroll.wizard.discard') }}</Button>
                    </div>
                </div>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('payroll.slip.payment_days') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('payroll.slip.gross') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('payroll.slip.total_deductions') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('payroll.slip.net') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('payroll.slip.employer_box') }}</th>
                                <th class="px-3 py-2">{{ $t('payroll.wizard.flags') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in slips" :key="e.id" class="border-t align-top" :class="e.slip?.has_errors ? 'bg-red-50/60 dark:bg-red-950/20' : ''">
                                <td class="px-3 py-2">{{ e.full_name }}<span class="block text-xs text-muted-foreground">{{ e.employee_no }}</span></td>
                                <td class="px-3 py-2 text-right">{{ trimZeros(e.slip?.payment_days) }}<span v-if="Number(e.slip?.lwp_days) > 0" class="block text-xs text-muted-foreground">{{ $t('payroll.slip.lwp_days') }}: {{ trimZeros(e.slip?.lwp_days) }}</span></td>
                                <td class="px-3 py-2 text-right"><MoneyText :value="e.slip?.gross_pay" /></td>
                                <td class="px-3 py-2 text-right"><MoneyText :value="e.slip?.total_deductions" /></td>
                                <td class="px-3 py-2 text-right"><MoneyText :value="e.slip?.net_pay" strong /></td>
                                <td class="px-3 py-2 text-right"><MoneyText :value="e.slip?.employer_total" muted /></td>
                                <td class="px-3 py-2 text-xs">
                                    <span v-if="variance(e)" class="mr-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200" data-testid="variance-flag">{{ $t('payroll.wizard.variance', { percent: variance(e) ?? '' }) }}</span>
                                    <span v-if="e.slip?.has_errors" class="mr-1 rounded bg-red-100 px-1.5 py-0.5 text-red-800 dark:bg-red-900/40 dark:text-red-200">{{ $t('payroll.wizard.has_errors') }}</span>
                                    <span v-if="e.lwp_override_days !== null" class="mr-1 rounded bg-sky-100 px-1.5 py-0.5 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">{{ $t('payroll.wizard.lwp_overridden', { days: trimZeros(e.lwp_override_days) }) }}</span>
                                    <span v-if="e.pcb_override_amount !== null" class="mr-1 rounded bg-sky-100 px-1.5 py-0.5 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">{{ $t('payroll.wizard.pcb_overridden') }}</span>
                                    <ul v-if="e.warnings.length" class="mt-1 list-disc pl-4 text-amber-800 dark:text-amber-300"><li v-for="(w, n) in e.warnings" :key="n">{{ w }}</li></ul>
                                </td>
                                <td class="space-y-1 px-3 py-2 text-right">
                                    <Button v-if="e.slip" size="sm" variant="outline" as-child><Link :href="`/payroll/slips/${e.slip.id}`">{{ $t('payroll.wizard.view_slip') }}</Link></Button>
                                    <template v-if="can.prepare && run.status === 'slips_generated'">
                                        <Button size="sm" variant="ghost" :disabled="processing" @click="generate({ employee_ids: [e.employee_id] })">{{ $t('payroll.wizard.regenerate') }}</Button>
                                        <Button size="sm" variant="ghost" data-testid="override-lwp" @click="startOverride('lwp', e)">{{ $t('payroll.wizard.override_lwp') }}</Button>
                                        <Button size="sm" variant="ghost" @click="startOverride('pcb', e)">{{ $t('payroll.wizard.override_pcb') }}</Button>
                                        <Button size="sm" variant="ghost" @click="excludeTarget = e.employee_id">{{ $t('payroll.wizard.exclude') }}</Button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!slips.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('payroll.wizard.no_slips') }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="component_totals.length" class="rounded-md border p-3">
                    <h3 class="mb-2 text-sm font-semibold">{{ $t('payroll.wizard.component_totals') }}</h3>
                    <div class="grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="c in component_totals" :key="c.code" class="flex justify-between border-b border-dashed py-0.5">
                            <span>{{ c.name }} <span class="text-xs text-muted-foreground">({{ $t(`payroll.enums.component_kind.${c.kind}`) }})</span></span><MoneyText :value="c.amount" />
                        </div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" @click="step = 3">{{ $t('common.actions.back') }}</Button>
                    <Button @click="step = 5">{{ $t('common.actions.continue') }}</Button>
                </div>
            </section>

            <!-- 5 Hantar & Kunci -->
            <section v-if="step === 5" class="space-y-3 rounded-lg border p-4" data-testid="step-submit">
                <h2 class="font-semibold">{{ $t('payroll.wizard.steps.submit') }}</h2>
                <div v-if="locked" class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm dark:border-emerald-900 dark:bg-emerald-950">
                    <Lock class="mr-1 inline size-4" />{{ $t('payroll.wizard.submitted_at', { at: formatDateTime(run.submitted_at) }) }}
                </div>
                <template v-else>
                    <ul class="space-y-2" data-testid="checklist">
                        <li v-for="c in checklist" :key="c.key" class="flex items-start gap-2 text-sm">
                            <span class="mt-0.5 flex size-5 items-center justify-center rounded-full text-xs" :class="c.ok ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'">{{ c.ok ? '✓' : '!' }}</span>
                            <span>{{ $t(`payroll.wizard.checklist.${c.key}`) }}</span>
                        </li>
                    </ul>
                    <div v-if="unverifiedSets.length" class="rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950">
                        <p class="text-sm font-medium">{{ $t('payroll.wizard.unverified_title') }}</p>
                        <ul class="my-2 space-y-1 text-sm">
                            <li v-for="s in unverifiedSets" :key="s.id" class="flex items-center gap-2"><RateSetBadge :status="s.status" :label="s.status_label" :placeholder="s.is_placeholder" />{{ s.scheme_label }} — {{ s.code }}</li>
                        </ul>
                        <CheckField v-model="acknowledge" :label="$t('payroll.wizard.acknowledge')" />
                    </div>
                    <p v-if="!can.submit" class="text-sm text-muted-foreground" data-testid="submit-not-allowed">{{ $t('payroll.wizard.submit_not_allowed') }}</p>
                    <Button v-if="can.submit && run.status === 'slips_generated'" :disabled="!checklist.every((c) => c.ok)" data-testid="submit-run" @click="submitOpen = true"><Lock class="size-4" />{{ $t('payroll.wizard.submit') }}</Button>
                </template>
                <div class="flex gap-2">
                    <Button variant="outline" @click="step = 4">{{ $t('common.actions.back') }}</Button>
                    <Button v-if="locked" @click="step = 6">{{ $t('common.actions.continue') }}</Button>
                </div>
            </section>

            <!-- 6 Pembayaran -->
            <section v-if="step === 6" class="space-y-4 rounded-lg border p-4" data-testid="step-pay">
                <h2 class="font-semibold">{{ $t('payroll.wizard.steps.pay') }}</h2>
                <p v-if="!locked" class="text-sm text-muted-foreground">{{ $t('payroll.wizard.pay_after_submit') }}</p>
                <template v-else>
                    <div v-if="run.status === 'paid'" class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm dark:border-emerald-900 dark:bg-emerald-950">
                        {{ $t('payroll.wizard.paid_on', { date: formatDate(run.paid_at), reference: run.payment_reference ?? '—' }) }}
                    </div>

                    <div v-if="can.bank_file" class="space-y-2">
                        <h3 class="text-sm font-semibold">{{ $t('payroll.wizard.bank_files') }}</h3>
                        <CheckField v-model="supplementary" :label="$t('payroll.wizard.supplementary')" :hint="$t('payroll.wizard.supplementary_hint')" />
                        <div class="flex flex-wrap gap-2">
                            <Button v-for="a in bank_accounts" :key="a.id" variant="outline" size="sm" :data-testid="`bank-file-${a.id}`" @click="bankFile(a.id)"><FileText class="size-4" />{{ a.account_name }} · {{ a.account_no }}</Button>
                            <p v-if="!bank_accounts.length" class="text-sm text-muted-foreground">{{ $t('payroll.wizard.no_bank_accounts') }} <Link href="/payroll/settings#bank" class="text-primary hover:underline">{{ $t('payroll.pages.settings') }}</Link></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" as-child><a :href="`/payroll/runs/${run.id}/cash-list/pdf`" data-testid="cash-list-pdf"><Download class="size-4" />{{ $t('payroll.wizard.cash_list') }}</a></Button>
                            <Button variant="outline" size="sm" @click="router.post(`/payroll/runs/${run.id}/summary-csv`, {}, reload)">{{ $t('payroll.wizard.summary_csv') }}</Button>
                        </div>
                    </div>

                    <div v-if="bankFiles.length" class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('payroll.wizard.file') }}</th><th class="px-3 py-2">{{ $t('payroll.wizard.records') }}</th><th class="px-3 py-2 text-right">{{ $t('payroll.fields.amount') }}</th><th class="px-3 py-2"></th></tr></thead>
                            <tbody>
                                <tr v-for="f in bankFiles" :key="f.id" class="border-t">
                                    <td class="px-3 py-2">{{ f.kind_label }}<span class="block text-xs text-muted-foreground">{{ f.file_name }} · {{ formatDateTime(f.generated_at) }}</span></td>
                                    <td class="px-3 py-2">{{ f.record_count ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right"><MoneyText :value="f.total_amount" /></td>
                                    <td class="px-3 py-2 text-right"><Button size="sm" variant="outline" as-child><a :href="f.download_url"><Download class="size-4" />{{ $t('payroll.actions.download') }}</a></Button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="withheld.length" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950">
                        <p class="font-medium">{{ $t('payroll.wizard.withheld_title', { count: String(withheld.length) }) }}</p>
                        <p class="text-xs">{{ withheld.map((w) => w.full_name).join(', ') }}</p>
                        <Link href="/payroll/withholdings" class="text-xs text-primary hover:underline">{{ $t('payroll.wizard.release_in_withholdings') }}</Link>
                    </div>

                    <div class="space-y-1">
                        <h3 class="text-sm font-semibold">{{ $t('payroll.wizard.statutory_links') }}</h3>
                        <div class="flex flex-wrap gap-3 text-sm">
                            <Link :href="`/statutory/files?period=${run.period}`" class="text-primary hover:underline">{{ $t('statutory.pages.files') }}</Link>
                            <Link :href="`/statutory/listings/kwsp?period=${run.period}`" class="text-primary hover:underline">{{ $t('statutory.listings.names.kwsp') }}</Link>
                            <Link :href="`/payroll/reports/register?payroll_run_id=${run.id}`" class="text-primary hover:underline">{{ $t('payroll.pages.register') }}</Link>
                        </div>
                    </div>

                    <Button v-if="can.mark_paid && run.status === 'submitted'" data-testid="mark-paid" @click="paidOpen = true">{{ $t('payroll.wizard.mark_paid') }}</Button>
                </template>
                <div><Button variant="outline" @click="step = locked ? 5 : 4">{{ $t('common.actions.back') }}</Button></div>
            </section>
        </div>

        <ReasonDialog :open="excludeTarget !== null" :title="$t('payroll.wizard.exclude')" @update:open="(v: boolean) => { if (!v) excludeTarget = null }" @confirm="confirmExclude" />
        <ReasonDialog v-model:open="cancelOpen" :title="$t('payroll.wizard.cancel_run')" :description="$t('payroll.wizard.cancel_hint')" destructive @confirm="confirmCancel" />

        <Dialog v-model:open="discardOpen">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('payroll.wizard.discard') }}</DialogTitle><DialogDescription>{{ $t('payroll.wizard.discard_hint') }}</DialogDescription></DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="discardOpen = false">{{ $t('common.actions.cancel') }}</Button>
                    <Button variant="destructive" @click="router.post(`/payroll/runs/${run.id}/discard`, {}, { ...reload, onSuccess: () => (discardOpen = false) })">{{ $t('payroll.wizard.discard') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteOpen">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('payroll.wizard.delete_draft') }}</DialogTitle><DialogDescription>{{ $t('payroll.wizard.delete_hint') }}</DialogDescription></DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="deleteOpen = false">{{ $t('common.actions.cancel') }}</Button>
                    <Button variant="destructive" @click="router.delete(`/payroll/runs/${run.id}`)">{{ $t('common.actions.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="submitOpen">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('payroll.wizard.submit') }}</DialogTitle><DialogDescription>{{ $t('payroll.wizard.submit_confirm', { count: String(run.employee_count) }) }}</DialogDescription></DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="submitOpen = false">{{ $t('common.actions.cancel') }}</Button>
                    <Button data-testid="submit-confirm" @click="submitRun">{{ $t('payroll.wizard.submit') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="paidOpen">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('payroll.wizard.mark_paid') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="markPaid">
                    <FormField :label="$t('payroll.wizard.paid_date')" :error="paid.errors.paid_on" required><Input v-model="paid.paid_on" type="date" /></FormField>
                    <FormField :label="$t('payroll.wizard.reference')" :error="paid.errors.reference"><Input v-model="paid.reference" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="paidOpen = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="paid.processing">{{ $t('payroll.wizard.mark_paid') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="overrideTarget !== null" @update:open="(v: boolean) => { if (!v) overrideTarget = null }">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ overrideTarget?.kind === 'lwp' ? $t('payroll.wizard.override_lwp') : $t('payroll.wizard.override_pcb') }} — {{ overrideTarget?.row.full_name }}</DialogTitle>
                    <DialogDescription>{{ overrideTarget?.kind === 'lwp' ? $t('payroll.wizard.override_lwp_hint') : $t('payroll.wizard.override_pcb_hint') }}</DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="saveOverride(false)">
                    <FormField :label="overrideTarget?.kind === 'lwp' ? $t('payroll.wizard.lwp_days') : $t('payroll.wizard.pcb_amount')" :error="(override.errors as Record<string, string>).days ?? (override.errors as Record<string, string>).amount" required>
                        <Input v-model="override.value" type="number" step="0.01" min="0" />
                    </FormField>
                    <FormField :label="$t('common.fields.reason')" :error="override.errors.reason" required><Input v-model="override.reason" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="overrideTarget = null">{{ $t('common.actions.cancel') }}</Button>
                        <Button v-if="(overrideTarget?.kind === 'lwp' ? overrideTarget?.row.lwp_override_days : overrideTarget?.row.pcb_override_amount) !== null" type="button" variant="outline" @click="saveOverride(true)">{{ $t('payroll.wizard.clear_override') }}</Button>
                        <Button type="submit" :disabled="override.processing || override.value === '' || override.reason === ''">{{ $t('payroll.wizard.apply_regenerate') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
