<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days, toQuery } from '@/lib/leave';
import type { Option } from '@/types';

const props = defineProps<{
    report: string;
    reports: string[];
    filters: Record<string, string>;
    options: { employees: Option<number>[]; types: Option<number>[]; kinds: Option<string>[] };
    columns: { key: string; label: string }[];
    rows: Record<string, unknown>[];
    truncated?: boolean;
}>();

const today = new Date().toISOString().slice(0, 10);
const f = ref<Record<string, string | number | null>>({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    date: props.filters.date ?? today,
    month: props.filters.month ?? today.slice(0, 7),
    employee_id: props.filters.employee_id ?? '',
    leave_type_id: props.filters.leave_type_id ?? '',
    kind: props.filters.kind ?? '',
});

const used = computed<string[]>(() => {
    switch (props.report) {
        case 'balances':
            return ['from', 'to', 'employee_id', 'leave_type_id'];
        case 'ledger':
            return ['from', 'to', 'employee_id', 'leave_type_id', 'kind'];
        case 'unpaid':
            return ['month'];
        default:
            return ['date'];
    }
});

const query = computed(() => toQuery(Object.fromEntries(used.value.map((k) => [k, f.value[k]]))));

function apply() {
    router.get(`/reports/leave/${props.report}`, Object.fromEntries(used.value.filter((k) => f.value[k] !== '' && f.value[k] !== null).map((k) => [k, f.value[k]])), { preserveState: true, replace: true });
}

const NUMERIC = new Set(['opening', 'credited', 'taken', 'encashed', 'reduced', 'carried_out', 'expired', 'closing', 'reserved', 'available', 'days', 'unpaid_days', 'pending_unpaid_days', 'cap_days', 'used', 'room', 'balance']);

function isNumeric(key: string): boolean {
    return NUMERIC.has(key) || key.startsWith('type_');
}

function cell(row: Record<string, unknown>, key: string): string {
    const value = row[key];
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value);
    if (isNumeric(key)) return days(String(value));

    return String(value);
}
</script>

<template>
    <Head :title="$t(`leave.reports.names.${report}`)" />
    <AppLayout :breadcrumbs="[{ title: 'leave.reports.title', href: `/reports/leave/${reports[0]}` }, { title: `leave.reports.names.${report}`, href: `/reports/leave/${report}` }]">
        <div class="p-4 md:p-6">
            <div class="mb-4 flex flex-wrap gap-1 border-b">
                <Link
                    v-for="r in reports"
                    :key="r"
                    :href="`/reports/leave/${r}`"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="r === report ? 'border-primary font-medium' : 'border-transparent text-muted-foreground'"
                >{{ $t(`leave.reports.names.${r}`) }}</Link>
            </div>

            <PageHeader :title="$t(`leave.reports.names.${report}`)" :description="$t(`leave.reports.descriptions.${report}`)">
                <template #actions>
                    <Button variant="outline" as-child><a :href="`/reports/leave/${report}/export?${query}&format=xlsx`"><Download class="size-4" />XLSX</a></Button>
                    <Button variant="outline" as-child><a :href="`/reports/leave/${report}/export?${query}&format=csv`"><Download class="size-4" />CSV</a></Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="apply">
                <label v-if="used.includes('from')" class="grid gap-1 text-xs">{{ $t('leave.reports.from') }}<Input v-model="f.from" type="date" class="w-44" /></label>
                <label v-if="used.includes('to')" class="grid gap-1 text-xs">{{ $t('leave.reports.to') }}<Input v-model="f.to" type="date" class="w-44" /></label>
                <label v-if="used.includes('date')" class="grid gap-1 text-xs">{{ $t('leave.reports.as_at') }}<Input v-model="f.date" type="date" class="w-44" /></label>
                <label v-if="used.includes('month')" class="grid gap-1 text-xs">{{ $t('leave.reports.month') }}<Input v-model="f.month" type="month" class="w-44" /></label>
                <SelectInput v-if="used.includes('employee_id')" v-model="f.employee_id" class="w-56" :options="options.employees" :placeholder="$t('leave.filters.all_employees')" />
                <SelectInput v-if="used.includes('leave_type_id')" v-model="f.leave_type_id" class="w-48" :options="options.types" :placeholder="$t('leave.filters.all_types')" />
                <SelectInput v-if="used.includes('kind')" v-model="f.kind" class="w-48" :options="options.kinds" :placeholder="$t('leave.reports.all_kinds')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr><th v-for="c in columns" :key="c.key" class="whitespace-nowrap px-3 py-2" :class="isNumeric(c.key) ? 'text-right' : ''">{{ c.label }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in rows" :key="i" class="border-t">
                            <td v-for="c in columns" :key="c.key" class="px-3 py-1.5" :class="[isNumeric(c.key) ? 'text-right tabular-nums' : '', c.key === 'balance' || (c.key === 'days' && Number(row[c.key]) < 0) ? 'text-red-600' : '']">{{ cell(row, c.key) }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="columns.length" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
                <p class="px-3 py-2 text-xs text-muted-foreground">
                    {{ $t('leave.reports.row_count', { count: String(rows.length) }) }}<span v-if="truncated"> · {{ $t('leave.reports.truncated') }}</span>
                </p>
            </div>
        </div>
    </AppLayout>
</template>
