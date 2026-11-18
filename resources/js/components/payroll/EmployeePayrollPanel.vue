<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import MoneyText from '@/components/app/MoneyText.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDate } from '@/composables/useFormat';
import { monthLabel } from '@/lib/payroll';

/** "Payroll" tab of Butiran Kakitangan (sensitive; authorised roles only): assignments, latest slips, withholding. */
type Summary = {
    assignments: { id: number; number: string | null; structure: string | null; effective_from: string; effective_to: string | null; base_salary: string; reason_label: string }[];
    slips: { id: number; number: string; period: string; gross_pay: string; net_pay: string; payment_status: string; payment_status_label: string }[];
    withheld: boolean;
    can: { config: boolean; assign: boolean; adjust: boolean };
};

const props = defineProps<{ employeeId: number }>();
const data = ref<Summary | null>(null);
const failed = ref(false);

onMounted(async () => {
    try {
        const res = await fetch(`/payroll/employees/${props.employeeId}/summary`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error(String(res.status));
        data.value = (await res.json()) as Summary;
    } catch {
        failed.value = true;
    }
});
</script>

<template>
    <div class="space-y-5" data-testid="employee-payroll-panel">
        <p v-if="failed" class="text-sm text-red-600">{{ $t('payroll.employee_tab.failed') }}</p>
        <p v-else-if="!data" class="text-sm text-muted-foreground">…</p>
        <template v-else>
            <p v-if="data.withheld" class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">{{ $t('payroll.employee_tab.withheld') }}</p>

            <section v-if="data.can.config">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-semibold">{{ $t('payroll.pages.assignments') }}</h3>
                    <Link v-if="data.can.assign" :href="`/payroll/assignments?employee_id=${employeeId}`" class="text-xs text-primary hover:underline">{{ $t('payroll.employee_tab.manage') }}</Link>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground"><tr><th class="py-1">{{ $t('payroll.assignments.structure') }}</th><th>{{ $t('payroll.fields.effective_from') }}</th><th>{{ $t('payroll.fields.effective_to') }}</th><th class="text-right">{{ $t('payroll.assignments.base_salary') }}</th><th class="pl-3">{{ $t('payroll.assignments.reason') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="a in data.assignments" :key="a.id" class="border-t">
                            <td class="py-1">{{ a.structure }}</td><td>{{ formatDate(a.effective_from) }}</td><td>{{ formatDate(a.effective_to) || '—' }}</td>
                            <td class="text-right"><MoneyText :value="a.base_salary" /></td><td class="pl-3">{{ a.reason_label }}</td>
                        </tr>
                        <tr v-if="!data.assignments.length"><td colspan="5" class="py-3 text-center text-muted-foreground">{{ $t('payroll.employee_tab.no_assignment') }}</td></tr>
                    </tbody>
                </table>
            </section>

            <section>
                <h3 class="mb-2 text-sm font-semibold">{{ $t('payroll.employee_tab.recent_slips') }}</h3>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground"><tr><th class="py-1">{{ $t('payroll.runs.period') }}</th><th class="text-right">{{ $t('payroll.slip.gross') }}</th><th class="text-right">{{ $t('payroll.slip.net') }}</th><th class="pl-3">{{ $t('common.fields.status') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="s in data.slips" :key="s.id" class="border-t">
                            <td class="py-1">{{ monthLabel(s.period) }}</td>
                            <td class="text-right"><MoneyText :value="s.gross_pay" /></td><td class="text-right"><MoneyText :value="s.net_pay" strong /></td>
                            <td class="pl-3"><StatusBadge :status="s.payment_status" :label="s.payment_status_label" /></td>
                            <td class="text-right"><Link :href="`/payroll/slips/${s.id}`" class="text-xs text-primary hover:underline">{{ $t('common.actions.view') }}</Link></td>
                        </tr>
                        <tr v-if="!data.slips.length"><td colspan="5" class="py-3 text-center text-muted-foreground">{{ $t('payroll.employee_tab.no_slips') }}</td></tr>
                    </tbody>
                </table>
            </section>
        </template>
    </div>
</template>
