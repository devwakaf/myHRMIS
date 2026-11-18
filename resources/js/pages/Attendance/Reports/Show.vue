<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Printer } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { hoursMinutes, toQuery } from '@/lib/attendance';
import type { Option } from '@/types';

type Col = { key: string; label: string };
type Cell = { d: number; c: string; f: string; t: string; h: string; colour?: string | null };
type Row = Record<string, unknown> & { cells?: Cell[] };
type Day = { day: number; date: string; weekday: number };

const props = defineProps<{
    report: string;
    reports: string[];
    kind: 'table' | 'matrix';
    filters: Record<string, string>;
    options: Record<string, Option<string | number>[]>;
    columns: Col[];
    total_columns?: Col[];
    days?: Day[];
    rows: Row[];
    summary?: Record<string, string>;
    rollup?: Row[];
    rollup_columns?: Col[];
    details?: Row[];
    detail_columns?: Col[];
    truncated?: boolean;
}>();

const today = new Date().toISOString().slice(0, 10);
const f = ref<Record<string, string>>({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    month: props.filters.month ?? today.slice(0, 7),
    employee_id: props.filters.employee_id ?? '',
    branch_id: props.filters.branch_id ?? '',
    department_id: props.filters.department_id ?? '',
    min_count: props.filters.min_count ?? '1',
    no_grace: props.filters.no_grace === '1' || props.filters.no_grace === 'true' ? '1' : '',
    geofence_result: props.filters.geofence_result ?? '',
    status: props.filters.status ?? '',
});

const used = computed<string[]>(() => {
    switch (props.report) {
        case 'monthly':
            return ['month', 'branch_id', 'department_id', 'employee_id'];
        case 'shift':
            return ['from', 'to', 'department_id', 'employee_id', 'no_grace'];
        case 'late':
            return ['from', 'to', 'department_id', 'min_count', 'employee_id'];
        case 'overtime':
            return ['month', 'department_id', 'status'];
        case 'roster':
            return ['from', 'to', 'branch_id', 'department_id'];
        default:
            return ['from', 'to', 'employee_id', 'department_id', 'geofence_result'];
    }
});

function clean(): Record<string, string> {
    return Object.fromEntries(used.value.filter((k) => f.value[k] !== '' && f.value[k] !== null && f.value[k] !== undefined).map((k) => [k, String(f.value[k])]));
}

const noGrace = computed({
    get: () => f.value.no_grace === "1",
    set: (value: boolean) => (f.value.no_grace = value ? "1" : ""),
});

function print() {
    window.print();
}

const query = computed(() => toQuery(clean()));

function apply() {
    router.get(`/reports/attendance/${props.report}`, clean(), { preserveState: true, replace: true });
}

function drill(employeeId: unknown) {
    f.value.employee_id = String(employeeId);
    apply();
}

const NUMERIC = new Set(['worked_hours', 'late_minutes', 'early_minutes', 'late_count', 'early_count', 'employees', 'distance_m', 'accuracy_m', 'clock_skew_seconds', 'present', 'wfh', 'on_duty', 'absent', 'half_days', 'leave_days', 'public_holidays', 'rest_days', 'unmarked', 'review', 'normal_minutes', 'rest_minutes', 'holiday_minutes', 'total_hours', 'cap_percent', 'flagged_lines', 'total_amount']);
const MINUTES = new Set(['normal_minutes', 'rest_minutes', 'holiday_minutes']);

function cell(row: Row, key: string): string {
    const value = row[key];
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value);
    if (MINUTES.has(key)) return hoursMinutes(Number(value));

    return String(value);
}

function codeTone(c: Cell): string {
    const code = c.c;
    if (code === 'H') return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-100';
    if (code === 'TH') return 'bg-red-100 text-red-900 dark:bg-red-900/50 dark:text-red-100';
    if (code === 'BR') return 'bg-violet-100 text-violet-900 dark:bg-violet-900/50 dark:text-violet-100';
    if (code === 'TL') return 'bg-indigo-100 text-indigo-900 dark:bg-indigo-900/50 dark:text-indigo-100';
    if (code === 'C') return 'bg-sky-100 text-sky-900 dark:bg-sky-900/50 dark:text-sky-100';
    if (code === 'CU') return 'bg-cyan-100 text-cyan-900 dark:bg-cyan-900/50 dark:text-cyan-100';
    if (code === 'R') return 'bg-muted text-muted-foreground';
    if (code === '?') return 'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-100';
    if (code.startsWith('½')) return 'bg-orange-100 text-orange-900 dark:bg-orange-900/50 dark:text-orange-100';

    return '';
}

function cellStyle(c: Cell): Record<string, string> {
    return props.report === 'roster' && c.colour ? { backgroundColor: `${c.colour}40` } : {};
}

const LEGEND = ['H', 'TH', 'BR', 'TL', 'C', 'half_leave', 'half_work', 'CU', 'R', '?'];
const summaryKeys = ['present', 'remote', 'leave', 'absent', 'unmarked', 'review'];
const dayHeader = (d: Day) => ([6, 7].includes(d.weekday) ? 'text-muted-foreground' : '');
const printable = computed(() => ['monthly', 'roster'].includes(props.report));
</script>

<template>
    <Head :title="$t(`attendance.reports.names.${report}`)" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.reports.title', href: `/reports/attendance/${reports[0]}` }, { title: `attendance.reports.names.${report}`, href: `/reports/attendance/${report}` }]">
        <div class="p-4 md:p-6">
            <div class="mb-4 flex flex-wrap gap-1 border-b print:hidden">
                <Link v-for="r in reports" :key="r" :href="`/reports/attendance/${r}`" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="r === report ? 'border-primary font-medium' : 'border-transparent text-muted-foreground'">{{ $t(`attendance.reports.names.${r}`) }}</Link>
            </div>

            <PageHeader :title="$t(`attendance.reports.names.${report}`)" :description="$t(`attendance.reports.descriptions.${report}`)">
                <template #actions>
                    <Button v-if="printable" variant="outline" class="print:hidden" @click="print"><Printer class="size-4" />{{ $t('attendance.reports.print') }}</Button>
                    <Button variant="outline" as-child class="print:hidden"><a :href="`/reports/attendance/${report}/export?${query}&format=xlsx`" data-testid="export-xlsx"><Download class="size-4" />XLSX</a></Button>
                    <Button variant="outline" as-child class="print:hidden"><a :href="`/reports/attendance/${report}/export?${query}&format=csv`" data-testid="export-csv"><Download class="size-4" />CSV</a></Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap items-end gap-2 print:hidden" @submit.prevent="apply">
                <label v-if="used.includes('month')" class="grid gap-1 text-xs">{{ $t('attendance.reports.month') }}<Input v-model="f.month" type="month" class="w-44" /></label>
                <label v-if="used.includes('from')" class="grid gap-1 text-xs">{{ $t('attendance.common.from_date') }}<Input v-model="f.from" type="date" class="w-44" /></label>
                <label v-if="used.includes('to')" class="grid gap-1 text-xs">{{ $t('attendance.common.to_date') }}<Input v-model="f.to" type="date" class="w-44" /></label>
                <SelectInput v-if="used.includes('branch_id')" v-model="f.branch_id" class="w-44" :options="options.branches" :placeholder="$t('attendance.common.all_branches')" />
                <SelectInput v-if="used.includes('department_id')" v-model="f.department_id" class="w-44" :options="options.departments" :placeholder="$t('attendance.common.all_departments')" />
                <SelectInput v-if="used.includes('employee_id')" v-model="f.employee_id" class="w-56" :options="options.employees" :placeholder="$t('attendance.common.all_employees')" />
                <SelectInput v-if="used.includes('status')" v-model="f.status" class="w-44" :options="options.claim_statuses" :placeholder="$t('attendance.common.all_statuses')" />
                <SelectInput v-if="used.includes('geofence_result')" v-model="f.geofence_result" class="w-52" :options="options.geofence_results" :placeholder="$t('attendance.log.all_geofence')" />
                <label v-if="used.includes('min_count')" class="grid gap-1 text-xs">{{ $t('attendance.reports.min_count') }}<Input v-model="f.min_count" type="number" min="1" class="w-28" /></label>
                <label v-if="used.includes('no_grace')" class="flex items-center gap-2 pb-2 text-sm"><input v-model="noGrace" type="checkbox" />{{ $t('attendance.reports.no_grace') }}</label>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div v-if="report === 'shift' && summary" class="mb-4 grid grid-cols-3 gap-2 sm:grid-cols-6" data-testid="shift-summary">
                <div v-for="k in summaryKeys" :key="k" class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">{{ $t(`attendance.reports.buckets.${k}`) }}</p>
                    <p class="text-xl font-semibold tabular-nums">{{ summary[k] }}</p>
                </div>
            </div>

            <div v-if="kind === 'matrix' && report === 'monthly'" class="mb-3 flex flex-wrap gap-2 text-xs print:hidden" data-testid="matrix-legend">
                <span v-for="k in LEGEND" :key="k" class="rounded px-1.5 py-0.5" :class="codeTone({ d: 0, c: k === 'half_leave' ? '½C·H' : k === 'half_work' ? '½K·TH' : k, f: '', t: '', h: '' })">{{ k === 'half_leave' ? '½C·H' : k === 'half_work' ? '½K·TH' : k }} = {{ $t(`attendance.reports.codes.${k}`) }}</span>
                <span class="rounded px-1.5 py-0.5 ring-1 ring-amber-500">{{ $t('attendance.reports.codes.worked_off_day') }}</span>
                <span>ʟ = {{ $t('attendance.me.late') }} · ᴀ = {{ $t('attendance.me.early') }}</span>
            </div>

            <div v-if="kind === 'matrix'" class="overflow-x-auto rounded-lg border">
                <table class="border-collapse text-xs" data-testid="matrix-table">
                    <thead>
                        <tr class="bg-muted/50">
                            <th v-for="c in columns" :key="c.key" class="sticky left-0 whitespace-nowrap bg-muted px-2 py-1 text-left">{{ c.label }}</th>
                            <th v-for="d in days" :key="d.day" class="min-w-8 px-1 py-1 text-center font-medium" :class="dayHeader(d)">{{ d.day }}</th>
                            <th v-for="c in total_columns" :key="c.key" class="whitespace-nowrap px-2 py-1 text-right">{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in rows" :key="i" class="border-t">
                            <td v-for="c in columns" :key="c.key" class="whitespace-nowrap bg-background px-2 py-1">{{ row[c.key] }}</td>
                            <td v-for="c in row.cells" :key="c.d" class="border-l p-0 text-center" :title="c.h">
                                <span class="flex h-7 min-w-8 items-center justify-center whitespace-nowrap px-0.5 font-medium" :class="[codeTone(c), c.t ? 'ring-1 ring-inset ring-amber-500' : '']" :style="cellStyle(c)">{{ c.c }}<sup v-if="c.f.includes('L')" class="text-[9px]">ʟ</sup><sup v-if="c.f.includes('A')" class="text-[9px]">ᴀ</sup></span>
                            </td>
                            <td v-for="c in total_columns" :key="c.key" class="whitespace-nowrap border-l px-2 py-1 text-right tabular-nums">{{ row[c.key] }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="columns.length + (days?.length ?? 0) + (total_columns?.length ?? 0)" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="report-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="c in columns" :key="c.key" class="whitespace-nowrap px-3 py-2" :class="NUMERIC.has(c.key) ? 'text-right' : ''">{{ c.label }}</th>
                            <th v-if="report === 'late'" class="px-3 py-2 print:hidden"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in rows" :key="i" class="border-t">
                            <td v-for="c in columns" :key="c.key" class="px-3 py-1.5" :class="[NUMERIC.has(c.key) ? 'text-right tabular-nums' : '', c.key === 'cap_exceeded' && row[c.key] === $t('common.yes') ? 'text-red-600' : '']">{{ cell(row, c.key) }}</td>
                            <td v-if="report === 'late'" class="px-3 py-1.5 text-right print:hidden"><Button size="sm" variant="ghost" @click="drill(row.employee_id)">{{ $t('attendance.reports.drill') }}</Button></td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="columns.length + (report === 'late' ? 1 : 0)" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-muted-foreground">
                {{ $t('attendance.reports.row_count', { count: String(rows.length) }) }}<span v-if="truncated"> · {{ $t('attendance.reports.truncated') }}</span>
            </p>

            <section v-if="report === 'late' && rollup?.length" class="mt-6 space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.reports.rollup') }}</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm" data-testid="rollup-table">
                        <thead class="bg-muted/50 text-left"><tr><th v-for="c in rollup_columns" :key="c.key" class="px-3 py-2" :class="NUMERIC.has(c.key) ? 'text-right' : ''">{{ c.label }}</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, i) in rollup" :key="i" class="border-t"><td v-for="c in rollup_columns" :key="c.key" class="px-3 py-1.5" :class="NUMERIC.has(c.key) ? 'text-right tabular-nums' : ''">{{ cell(row, c.key) === '—' && c.key === 'department' ? $t('attendance.reports.no_department') : cell(row, c.key) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="report === 'late' && details?.length" class="mt-6 space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.reports.details') }}</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm" data-testid="details-table">
                        <thead class="bg-muted/50 text-left"><tr><th v-for="c in detail_columns" :key="c.key" class="px-3 py-2" :class="NUMERIC.has(c.key) ? 'text-right' : ''">{{ c.label }}</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, i) in details" :key="i" class="border-t"><td v-for="c in detail_columns" :key="c.key" class="px-3 py-1.5" :class="NUMERIC.has(c.key) ? 'text-right tabular-nums' : ''">{{ cell(row, c.key) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    [data-slot='sidebar'],
    [data-slot='sidebar-gap'],
    [data-slot='sidebar-container'] {
        display: none !important;
    }
}
</style>
