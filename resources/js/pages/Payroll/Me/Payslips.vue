<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel } from '@/lib/payroll';

type Row = { id: number; number: string; period: string; net_pay: string; payment_status: string; payment_status_label: string };

defineProps<{ slips: Row[] }>();
</script>

<template>
    <Head :title="$t('payroll.pages.my_payslips')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.my_payslips', href: '/me/payslips' }]">
        <div class="mx-auto max-w-3xl p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.my_payslips')" :description="$t('payroll.me.description')">
                <template #actions>
                    <Button variant="outline" as-child><Link href="/me/ea">{{ $t('payroll.pages.my_ea') }}</Link></Button>
                </template>
            </PageHeader>

            <ul class="divide-y rounded-lg border" data-testid="my-slips">
                <li v-for="s in slips" :key="s.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div>
                        <p class="font-medium">{{ monthLabel(s.period) }}</p>
                        <p class="text-xs text-muted-foreground">{{ s.number }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <StatusBadge :status="s.payment_status" :label="s.payment_status_label" />
                        <span class="text-sm font-semibold"><MoneyText :value="s.net_pay" /></span>
                        <Button size="sm" variant="outline" as-child><Link :href="`/me/payslips/${s.id}`">{{ $t('common.actions.view') }}</Link></Button>
                        <Button size="icon-sm" variant="ghost" as-child :aria-label="$t('payroll.slip.pdf')"><a :href="`/me/payslips/${s.id}/pdf`"><Download class="size-4" /></a></Button>
                    </div>
                </li>
                <li v-if="!slips.length" class="px-4 py-8 text-center text-sm text-muted-foreground">{{ $t('payroll.me.empty') }}</li>
            </ul>
        </div>
    </AppLayout>
</template>
