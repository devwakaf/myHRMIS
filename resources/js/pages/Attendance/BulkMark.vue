<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { firstError, jsonRequest } from '@/lib/attendance';
import type { AttendanceRow, EmployeeRef } from '@/lib/attendance';
import type { Option } from '@/types';

type Row = { employee: EmployeeRef; state: string; read_only: boolean; attendance: AttendanceRow | null };
type Summary = { created: number; superseded: number; refused: { employee_id: number; date: string; reason: string }[]; queued?: string };

const props = defineProps<{
    date: string;
    rows: Row[];
    filters: Record<string, string | undefined>;
    options: { employees: { id: number; full_name: string; employee_no: string }[]; branches: { id: number; name: string }[]; departments: { id: number; name: string }[]; statuses: Option<string>[]; shift_types: { id: number; code: string; name: string }[] };
    max_days: number;
}>();

const f = ref({ date: props.date, branch_id: props.filters.branch_id ?? '', department_id: props.filters.department_id ?? '' });
const selected = ref<number[]>([]);
const form = ref({ from_date: props.date, to_date: props.date, attendance_status: 'present', other_half_status: 'absent', half_day_session: 'am', shift_type_id: null as number | null, is_late: false, late_minutes: 0, is_early_exit: false, early_exit_minutes: 0, remarks: '' });
const errors = ref<string | null>(null);
const summary = ref<Summary | null>(null);
const busy = ref(false);
const queuedToken = ref<string | null>(null);
let poll: ReturnType<typeof setInterval> | null = null;

const selectable = computed(() => props.rows.filter((r) => !r.read_only && r.employee));
const allChecked = computed({
    get: () => selectable.value.length > 0 && selectable.value.every((r) => selected.value.includes(r.employee!.id)),
    set: (value: boolean) => (selected.value = value ? selectable.value.map((r) => r.employee!.id) : []),
});
const spanTooLong = computed(() => form.value.to_date !== '' && (Date.parse(form.value.to_date) - Date.parse(form.value.from_date)) / 86400000 + 1 > props.max_days);
const employeeName = (id: number) => props.options.employees.find((e) => e.id === id)?.full_name ?? `#${id}`;
const canSubmit = computed(() => selected.value.length > 0 && form.value.remarks.trim() !== '' && !spanTooLong.value && !busy.value);

function load() {
    router.get('/attendance/bulk-mark', Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '')), { preserveState: true, replace: true, onSuccess: () => ((form.value.from_date = f.value.date), (form.value.to_date = f.value.date), (selected.value = [])) });
}

function stateTone(state: string): string {
    return { unmarked: 'text-muted-foreground', needs_review: 'text-amber-700 dark:text-amber-300', on_leave_locked: 'text-sky-700 dark:text-sky-300', half_day_pending: 'text-amber-700 dark:text-amber-300', absent: 'text-red-700 dark:text-red-300' }[state] ?? '';
}

async function submit() {
    busy.value = true;
    errors.value = null;
    summary.value = null;
    try {
        const f2 = form.value;
        const { status, data } = await jsonRequest<Summary & { message?: string; errors?: Record<string, string[]> }>('POST', '/attendance/bulk-mark', {
            employee_ids: selected.value,
            from_date: f2.from_date,
            to_date: f2.to_date || f2.from_date,
            attendance_status: f2.attendance_status,
            other_half_status: f2.attendance_status === 'half_day' ? f2.other_half_status : null,
            half_day_session: f2.attendance_status === 'half_day' ? f2.half_day_session : null,
            shift_type_id: f2.shift_type_id,
            is_late: f2.is_late,
            late_minutes: f2.is_late ? f2.late_minutes : null,
            is_early_exit: f2.is_early_exit,
            early_exit_minutes: f2.is_early_exit ? f2.early_exit_minutes : null,
            remarks: f2.remarks,
        });
        if (status !== 200) {
            errors.value = firstError(data) ?? String(status);

            return;
        }
        if (data.queued) {
            queuedToken.value = data.queued;
            poll = setInterval(checkQueued, 2000);
        } else {
            finish(data);
        }
    } catch {
        errors.value = 'network';
    } finally {
        busy.value = false;
    }
}

async function checkQueued() {
    if (!queuedToken.value) return;
    const { data } = await jsonRequest<{ result: Summary | null }>('GET', `/attendance/bulk-mark/results/${queuedToken.value}`);
    if (data.result) {
        if (poll) clearInterval(poll);
        queuedToken.value = null;
        finish(data.result);
    }
}

function finish(result: Summary) {
    summary.value = result;
    selected.value = [];
    form.value.remarks = '';
    router.reload({ only: ['rows'] });
}

onBeforeUnmount(() => poll && clearInterval(poll));
</script>

<template>
    <Head :title="$t('attendance.pages.bulk_mark')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.bulk_mark', href: '/attendance/bulk-mark' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.bulk_mark')" :description="$t('attendance.bulk.description')" />

            <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="load">
                <label class="grid gap-1 text-xs">{{ $t('attendance.common.date') }}<Input v-model="f.date" type="date" class="w-44" /></label>
                <SelectInput v-model="f.branch_id" class="w-48" :options="options.branches.map((b) => ({ value: b.id, label: b.name }))" :placeholder="$t('attendance.common.all_branches')" />
                <SelectInput v-model="f.department_id" class="w-48" :options="options.departments.map((d) => ({ value: d.id, label: d.name }))" :placeholder="$t('attendance.common.all_departments')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="overflow-x-auto rounded-lg border lg:col-span-2">
                    <table class="w-full text-sm" data-testid="bulk-table">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="w-8 px-3 py-2"><input v-model="allChecked" type="checkbox" :aria-label="$t('common.actions.select_all')" /></th>
                                <th class="px-3 py-2">{{ $t('attendance.common.employee') }}</th>
                                <th class="px-3 py-2">{{ $t('attendance.bulk.state') }}</th>
                                <th class="px-3 py-2">{{ $t('attendance.bulk.current') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.employee?.id" class="border-t" :class="r.read_only ? 'bg-muted/30' : ''">
                                <td class="px-3 py-1.5"><input v-model="selected" type="checkbox" :value="r.employee?.id" :disabled="r.read_only" /></td>
                                <td class="px-3 py-1.5">{{ r.employee?.full_name }} <span class="text-xs text-muted-foreground">{{ r.employee?.employee_no }}</span></td>
                                <td class="px-3 py-1.5" :class="stateTone(r.state)">{{ $t(`attendance.bulk.states.${r.state}`) }}</td>
                                <td class="px-3 py-1.5 text-xs text-muted-foreground">{{ r.attendance ? `${r.attendance.attendance_status_label} · ${r.attendance.source_label}` : '—' }}</td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="4" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <form class="space-y-3 rounded-lg border p-4" @submit.prevent="submit">
                    <h2 class="text-sm font-medium">{{ $t('attendance.bulk.mark_as') }} · {{ $t('attendance.assignment.selected_count', { count: String(selected.length) }) }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.common.from_date')" required><Input v-model="form.from_date" type="date" /></FormField>
                        <FormField :label="$t('attendance.common.to_date')" :error="spanTooLong ? $t('attendance.errors.range_too_long', { days: String(max_days) }) : undefined"><Input v-model="form.to_date" type="date" :min="form.from_date" /></FormField>
                    </div>
                    <FormField :label="$t('common.fields.status')" required><SelectInput v-model="form.attendance_status" :options="options.statuses" :nullable="false" /></FormField>
                    <div v-if="form.attendance_status === 'half_day'" class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.request.session')"><SelectInput v-model="form.half_day_session" :options="[{ value: 'am', label: $t('attendance.enums.half_day_session.am') }, { value: 'pm', label: $t('attendance.enums.half_day_session.pm') }]" :nullable="false" /></FormField>
                        <FormField :label="$t('attendance.bulk.other_half')"><SelectInput v-model="form.other_half_status" :options="[{ value: 'present', label: $t('attendance.enums.attendance_status.present') }, { value: 'absent', label: $t('attendance.enums.attendance_status.absent') }]" :nullable="false" /></FormField>
                    </div>
                    <FormField :label="$t('attendance.common.shift')" :hint="$t('attendance.bulk.shift_hint')"><SelectInput v-model="form.shift_type_id" :options="options.shift_types.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))" :placeholder="$t('attendance.bulk.shift_auto')" /></FormField>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_late" type="checkbox" />{{ $t('attendance.me.late') }}</label>
                        <Input v-if="form.is_late" v-model="form.late_minutes" type="number" min="0" :aria-label="$t('attendance.common.min')" />
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_early_exit" type="checkbox" />{{ $t('attendance.me.early') }}</label>
                        <Input v-if="form.is_early_exit" v-model="form.early_exit_minutes" type="number" min="0" :aria-label="$t('attendance.common.min')" />
                    </div>
                    <FormField :label="$t('common.fields.reason')" required><TextArea v-model="form.remarks" :rows="2" /></FormField>
                    <p v-if="errors" class="rounded-md bg-red-50 p-2 text-sm text-red-900 dark:bg-red-950 dark:text-red-100" role="alert">{{ errors }}</p>
                    <Button type="submit" class="w-full" :disabled="!canSubmit" data-testid="bulk-submit">{{ $t('attendance.bulk.apply') }}</Button>
                    <p v-if="queuedToken" class="text-xs text-muted-foreground" data-testid="bulk-queued">{{ $t('attendance.messages.bulk_queued') }}</p>
                    <div v-if="summary" class="rounded-md border p-3 text-sm" data-testid="bulk-summary">
                        <p>{{ $t('attendance.bulk.summary', { created: String(summary.created), superseded: String(summary.superseded), refused: String(summary.refused.length) }) }}</p>
                        <ul v-if="summary.refused.length" class="mt-1 list-disc space-y-0.5 pl-5 text-xs text-red-700 dark:text-red-300">
                            <li v-for="(r, i) in summary.refused" :key="i">{{ employeeName(r.employee_id) }} · {{ r.date }} · {{ r.reason }}</li>
                        </ul>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
