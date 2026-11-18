<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Lock, LockOpen } from 'lucide-vue-next';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel } from '@/lib/payroll';

type Period = {
    period: string;
    start: string;
    end: string;
    payment_date: string;
    locked: boolean;
    run: { id: number; number: string; status: string; status_label: string; employee_count: number; net_total: string } | null;
};

defineProps<{ periods: Period[]; next_period: string; can: { prepare: boolean } }>();
</script>

<template>
    <Head :title="$t('payroll.pages.periods')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.periods', href: '/payroll/periods' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.periods')" :description="$t('payroll.periods.description')" />
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="periods">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('payroll.runs.period') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.periods.range') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.slip.payment_date') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.periods.lock') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.pages.runs') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.slip.net') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in periods" :key="p.period" class="border-t">
                            <td class="px-3 py-2 font-medium">{{ monthLabel(p.period) }}</td>
                            <td class="px-3 py-2">{{ formatDate(p.start) }} – {{ formatDate(p.end) }}</td>
                            <td class="px-3 py-2">{{ formatDate(p.payment_date) }}</td>
                            <td class="px-3 py-2">
                                <span v-if="p.locked" class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300"><Lock class="size-4" />{{ $t('payroll.periods.locked') }}</span>
                                <span v-else class="inline-flex items-center gap-1 text-muted-foreground"><LockOpen class="size-4" />{{ $t('payroll.periods.open') }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="p.run"><StatusBadge :status="p.run.status" :label="p.run.status_label" /> <span class="font-mono text-xs">{{ p.run.number }}</span></template>
                                <span v-else class="text-muted-foreground">{{ $t('payroll.periods.no_run') }}</span>
                            </td>
                            <td class="px-3 py-2 text-right"><MoneyText v-if="p.run" :value="p.run.net_total" /></td>
                            <td class="px-3 py-2 text-right">
                                <Button v-if="p.run" size="sm" variant="outline" as-child><Link :href="`/payroll/runs/${p.run.id}`">{{ $t('common.actions.view') }}</Link></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
