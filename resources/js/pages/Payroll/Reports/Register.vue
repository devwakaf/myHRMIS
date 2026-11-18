<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CheckField from '@/components/app/CheckField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel, toQuery, trimZeros } from '@/lib/payroll';

type Column = { code: string; name: string; kind: string };

const props = defineProps<{
    columns: Column[];
    rows: Record<string, string | number | null>[];
    totals: Record<string, string>;
    filters: Record<string, string | number | boolean | null>;
    options: { departments: { id: number; name: string }[]; branches: { id: number; name: string }[]; runs: { id: number; number: string; period: string }[] };
}>();

const f = ref({
    period_from: String(props.filters.period_from ?? ''),
    period_to: String(props.filters.period_to ?? ''),
    payroll_run_id: props.filters.payroll_run_id ? Number(props.filters.payroll_run_id) : null,
    department_id: props.filters.department_id ? Number(props.filters.department_id) : null,
    branch_id: props.filters.branch_id ? Number(props.filters.branch_id) : null,
    payment_status: String(props.filters.payment_status ?? ''),
    include_employer: Boolean(props.filters.include_employer),
});

const query = computed(() => toQuery({ ...f.value, include_employer: f.value.include_employer ? 1 : '' }));

function apply() {
    router.get('/payroll/reports/register', Object.fromEntries(Object.entries({ ...f.value, include_employer: f.value.include_employer ? 1 : '' }).filter(([, v]) => v !== '' && v !== null)), { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="$t('payroll.pages.register')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.register', href: '/payroll/reports/register' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.register')" :description="$t('payroll.register.description')">
                <template #actions>
                    <Button variant="outline" as-child><a :href="`/payroll/reports/register?${query}&export=xlsx`" data-testid="export-xlsx"><Download class="size-4" />XLSX</a></Button>
                    <Button variant="outline" as-child><a :href="`/payroll/reports/register?${query}&export=csv`"><Download class="size-4" />CSV</a></Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="apply">
                <label class="grid gap-1 text-xs">{{ $t('payroll.register.from') }}<Input v-model="f.period_from" type="date" class="w-40" /></label>
                <label class="grid gap-1 text-xs">{{ $t('payroll.register.to') }}<Input v-model="f.period_to" type="date" class="w-40" /></label>
                <div class="w-44"><SelectInput v-model="f.payroll_run_id" :options="options.runs.map((r) => ({ value: r.id, label: `${r.number} (${r.period})` }))" :placeholder="$t('payroll.register.all_runs')" /></div>
                <div class="w-44"><SelectInput v-model="f.department_id" :options="options.departments.map((d) => ({ value: d.id, label: d.name }))" :placeholder="$t('payroll.register.all_departments')" /></div>
                <div class="w-40"><SelectInput v-model="f.branch_id" :options="options.branches.map((d) => ({ value: d.id, label: d.name }))" :placeholder="$t('payroll.register.all_branches')" /></div>
                <div class="w-40"><SelectInput v-model="f.payment_status" :options="['unpaid', 'withheld', 'paid'].map((s) => ({ value: s, label: $t(`payroll.enums.payment_status.${s}`) }))" :placeholder="$t('payroll.filters.all_payments')" /></div>
                <CheckField v-model="f.include_employer" :label="$t('payroll.register.include_employer')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="register-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="whitespace-nowrap px-3 py-2">{{ $t('payroll.register.columns.period') }}</th>
                            <th class="whitespace-nowrap px-3 py-2">{{ $t('payroll.register.columns.employee_no') }}</th>
                            <th class="whitespace-nowrap px-3 py-2">{{ $t('payroll.register.columns.full_name') }}</th>
                            <th class="whitespace-nowrap px-3 py-2">{{ $t('payroll.register.columns.department') }}</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">{{ $t('payroll.register.columns.payment_days') }}</th>
                            <th v-for="c in columns" :key="c.code" class="whitespace-nowrap px-3 py-2 text-right" :title="c.code">{{ c.name }}</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">{{ $t('payroll.register.columns.gross_pay') }}</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">{{ $t('payroll.register.columns.total_deductions') }}</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">{{ $t('payroll.register.columns.net_pay') }}</th>
                            <th class="whitespace-nowrap px-3 py-2">{{ $t('payroll.register.columns.payment_status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in rows" :key="i" class="border-t">
                            <td class="whitespace-nowrap px-3 py-1.5">{{ monthLabel(String(r.period)) }}</td>
                            <td class="px-3 py-1.5">{{ r.employee_no }}</td>
                            <td class="whitespace-nowrap px-3 py-1.5">{{ r.full_name }}</td>
                            <td class="px-3 py-1.5">{{ r.department }}</td>
                            <td class="px-3 py-1.5 text-right">{{ trimZeros(r.payment_days as string) }}</td>
                            <td v-for="c in columns" :key="c.code" class="px-3 py-1.5 text-right"><MoneyText :value="r[c.code] as string" /></td>
                            <td class="px-3 py-1.5 text-right"><MoneyText :value="r.gross_pay as string" /></td>
                            <td class="px-3 py-1.5 text-right"><MoneyText :value="r.total_deductions as string" /></td>
                            <td class="px-3 py-1.5 text-right"><MoneyText :value="r.net_pay as string" strong /></td>
                            <td class="px-3 py-1.5">{{ $t(`payroll.enums.payment_status.${r.payment_status}`) }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="columns.length + 9" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                    <tfoot v-if="rows.length">
                        <tr class="border-t-2 bg-muted/40 font-semibold">
                            <td colspan="5" class="px-3 py-2">{{ $t('payroll.pdf.total') }}</td>
                            <td v-for="c in columns" :key="c.code" class="px-3 py-2 text-right"><MoneyText :value="totals[c.code]" /></td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="totals.gross_pay" /></td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="totals.total_deductions" /></td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="totals.net_pay" /></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
