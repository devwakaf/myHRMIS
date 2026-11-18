<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { ref } from 'vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel } from '@/lib/payroll';
import type { Paginated } from '@/types';

type Slip = {
    id: number;
    number: string;
    period: string;
    employee_no: string;
    full_name: string;
    gross_pay: string;
    total_deductions: string;
    net_pay: string;
    status: string;
    payment_status: string;
};

const props = defineProps<{ slips: Paginated<Slip>; filters: Record<string, string> }>();

const f = ref({ period: props.filters.period ?? '', status: props.filters.status ?? '', payment_status: props.filters.payment_status ?? '' });
const statusOptions = [
    { value: 'draft', label: 'common.document_status.draft' },
    { value: 'submitted', label: 'common.document_status.submitted' },
    { value: 'cancelled', label: 'common.document_status.cancelled' },
];
const paymentOptions = ['unpaid', 'withheld', 'paid'];

function apply() {
    router.get('/payroll/slips', Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '' && v !== null)), { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="$t('payroll.pages.slips')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.slips', href: '/payroll/slips' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.slips')" :description="$t('payroll.slips.description')" />

            <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="apply">
                <label class="grid gap-1 text-xs">{{ $t('payroll.runs.period') }}<Input v-model="f.period" type="month" class="w-44" /></label>
                <div class="w-44"><SelectInput v-model="f.status" :options="statusOptions.map((s) => ({ value: s.value, label: $t(s.label) }))" :placeholder="$t('payroll.filters.all_statuses')" /></div>
                <div class="w-44"><SelectInput v-model="f.payment_status" :options="paymentOptions.map((s) => ({ value: s, label: $t(`payroll.enums.payment_status.${s}`) }))" :placeholder="$t('payroll.filters.all_payments')" /></div>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('payroll.runs.period') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.slip.gross') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.slip.total_deductions') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.slip.net') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in slips.data" :key="s.id" class="border-t">
                            <td class="px-3 py-2">{{ monthLabel(s.period) }}</td>
                            <td class="px-3 py-2">{{ s.full_name }}<span class="block text-xs text-muted-foreground">{{ s.employee_no }}</span></td>
                            <td class="px-3 py-2 font-mono text-xs">{{ s.number }}</td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="s.gross_pay" /></td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="s.total_deductions" /></td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="s.net_pay" strong /></td>
                            <td class="space-x-1 px-3 py-2">
                                <StatusBadge :status="s.status" :label="$t(`common.document_status.${s.status}`)" />
                                <StatusBadge :status="s.payment_status" :label="$t(`payroll.enums.payment_status.${s.payment_status}`)" />
                            </td>
                            <td class="space-x-1 px-3 py-2 text-right">
                                <Button size="sm" variant="outline" as-child><Link :href="`/payroll/slips/${s.id}`">{{ $t('common.actions.view') }}</Link></Button>
                                <Button size="icon-sm" variant="ghost" as-child :aria-label="$t('payroll.slip.pdf')"><a :href="`/payroll/slips/${s.id}/pdf`"><Download class="size-4" /></a></Button>
                            </td>
                        </tr>
                        <tr v-if="!slips.data.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="slips.links" :from="slips.from" :to="slips.to" :total="slips.total" />
        </div>
    </AppLayout>
</template>
