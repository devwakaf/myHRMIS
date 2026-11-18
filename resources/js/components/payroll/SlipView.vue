<script setup lang="ts">
import { computed } from 'vue';
import MoneyText from '@/components/app/MoneyText.vue';
import { formatDate } from '@/composables/useFormat';
import { monthLabel, trimZeros } from '@/lib/payroll';
import type { SlipData } from '@/lib/payroll';

/** Slip Gaji on screen (spec-04 §1.4): two columns Pendapatan | Potongan, totals, employer box, day counters, YTD. */
const props = defineProps<{ slip: SlipData; showEmployer?: boolean; showYtd?: boolean }>();

const visible = computed(() => props.slip.lines.filter((l) => l.show_on_slip));
const earnings = computed(() => visible.value.filter((l) => l.kind === 'earning'));
const deductions = computed(() => visible.value.filter((l) => l.kind === 'deduction'));
const employer = computed(() => visible.value.filter((l) => l.kind === 'employer_contribution'));
const ribbon = computed(() => {
    if (props.slip.status === 'draft' || props.slip.status === 'cancelled') return props.slip.status;
    if (props.slip.payment_status === 'withheld') return 'withheld';

    return null;
});
const ytdValue = (key: string) => (props.slip.ytd?.[key] as string | undefined) ?? '0.00';
</script>

<template>
    <div class="space-y-4" data-testid="slip-view">
        <div v-if="ribbon" class="rounded-md border-2 border-red-600 px-3 py-1 text-center text-sm font-bold tracking-widest text-red-700 dark:text-red-300">
            {{ $t(`payroll.slip.ribbon.${ribbon}`) }}
        </div>

        <div class="rounded-lg border p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="text-lg font-semibold">{{ slip.employee.full_name }}</p>
                    <p class="text-sm text-muted-foreground">{{ slip.employee.employee_no }} · {{ slip.employee.ic_no ?? slip.employee.passport_no ?? '—' }}</p>
                </div>
                <div class="text-right text-sm">
                    <p class="font-medium">{{ monthLabel(slip.period) }}</p>
                    <p class="text-muted-foreground">{{ slip.number }}</p>
                    <p class="text-muted-foreground">{{ $t('payroll.slip.payment_date') }}: {{ formatDate(slip.payment_date) }}</p>
                </div>
            </div>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.kwsp_no') }}</dt><dd>{{ slip.employee.kwsp_no ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.perkeso_no') }}</dt><dd>{{ slip.employee.perkeso_no ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.tin') }}</dt><dd>{{ slip.employee.tin ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.bank') }}</dt><dd>{{ slip.employee.bank_account_last4 ? `****${slip.employee.bank_account_last4}` : '—' }}</dd></div>
            </dl>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border">
                <h3 class="border-b bg-muted/40 px-3 py-2 text-sm font-semibold">{{ $t('payroll.slip.earnings') }}</h3>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="l in earnings" :key="l.code" class="border-b last:border-0">
                            <td class="px-3 py-1.5">{{ l.name }}<span v-if="l.note" class="block text-xs text-muted-foreground">{{ l.note }}</span></td>
                            <td class="px-3 py-1.5 text-right"><MoneyText :value="l.amount" /></td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="border-t font-semibold"><td class="px-3 py-2">{{ $t('payroll.slip.gross') }}</td><td class="px-3 py-2 text-right"><MoneyText :value="slip.gross_pay" /></td></tr></tfoot>
                </table>
            </div>
            <div class="rounded-lg border">
                <h3 class="border-b bg-muted/40 px-3 py-2 text-sm font-semibold">{{ $t('payroll.slip.deductions') }}</h3>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="l in deductions" :key="l.code" class="border-b last:border-0">
                            <td class="px-3 py-1.5">{{ l.name }}<span v-if="l.note" class="block text-xs text-muted-foreground">{{ l.note }}</span></td>
                            <td class="px-3 py-1.5 text-right"><MoneyText :value="l.amount" /></td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="border-t font-semibold"><td class="px-3 py-2">{{ $t('payroll.slip.total_deductions') }}</td><td class="px-3 py-2 text-right"><MoneyText :value="slip.total_deductions" /></td></tr></tfoot>
                </table>
            </div>
        </div>

        <div class="flex items-center justify-between rounded-lg border-2 border-primary/40 bg-primary/5 px-4 py-3">
            <span class="text-base font-semibold">{{ $t('payroll.slip.net') }}</span>
            <span class="text-xl font-bold" data-testid="slip-net"><MoneyText :value="slip.net_pay" /></span>
        </div>
        <p v-if="Number(slip.net_rounding_adjustment) !== 0" class="text-right text-xs text-muted-foreground">
            {{ $t('payroll.slip.rounding') }}: <MoneyText :value="slip.net_rounding_adjustment" />
        </p>

        <div class="grid gap-4 md:grid-cols-2">
            <div v-if="showEmployer && employer.length" class="rounded-lg border p-3">
                <h3 class="mb-2 text-sm font-semibold">{{ $t('payroll.slip.employer_box') }}</h3>
                <table class="w-full text-sm"><tbody>
                    <tr v-for="l in employer" :key="l.code"><td class="py-0.5">{{ l.name }}</td><td class="py-0.5 text-right"><MoneyText :value="l.amount" /></td></tr>
                </tbody></table>
            </div>
            <div class="rounded-lg border p-3">
                <h3 class="mb-2 text-sm font-semibold">{{ $t('payroll.slip.days_box') }}</h3>
                <table class="w-full text-sm"><tbody>
                    <tr><td class="py-0.5">{{ $t('payroll.slip.working_days') }}</td><td class="py-0.5 text-right">{{ trimZeros(slip.days.working_days) }}</td></tr>
                    <tr><td class="py-0.5">{{ $t('payroll.slip.lwp_days') }}</td><td class="py-0.5 text-right">{{ trimZeros(slip.days.lwp_days) }}</td></tr>
                    <tr><td class="py-0.5">{{ $t('payroll.slip.payment_days') }}</td><td class="py-0.5 text-right">{{ trimZeros(slip.days.payment_days) }}</td></tr>
                </tbody></table>
            </div>
        </div>

        <div v-if="showYtd && slip.ytd" class="rounded-lg border p-3">
            <h3 class="mb-2 text-sm font-semibold">{{ $t('payroll.slip.ytd_box') }}</h3>
            <dl class="grid gap-3 text-sm sm:grid-cols-5">
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.ytd_gross') }}</dt><dd><MoneyText :value="ytdValue('y_normal')" /></dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.ytd_additional') }}</dt><dd><MoneyText :value="ytdValue('y_additional')" /></dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.ytd_epf') }}</dt><dd><MoneyText :value="ytdValue('k')" /></dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.ytd_pcb') }}</dt><dd><MoneyText :value="ytdValue('x')" /></dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('payroll.slip.ytd_zakat') }}</dt><dd><MoneyText :value="ytdValue('z')" /></dd></div>
            </dl>
        </div>

        <p class="text-center text-xs text-muted-foreground">{{ $t('payroll.slip.footer') }}</p>
    </div>
</template>
