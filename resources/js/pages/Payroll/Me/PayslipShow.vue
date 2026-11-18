<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import PageHeader from '@/components/app/PageHeader.vue';
import SlipView from '@/components/payroll/SlipView.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel } from '@/lib/payroll';
import type { SlipData } from '@/lib/payroll';

defineProps<{ slip: SlipData }>();
</script>

<template>
    <Head :title="$t('payroll.pages.my_payslip')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.my_payslips', href: '/me/payslips' }, { title: 'payroll.pages.my_payslip', href: `/me/payslips/${slip.id}` }]">
        <div class="mx-auto max-w-4xl space-y-4 p-4 md:p-6">
            <PageHeader :title="`${$t('payroll.pages.my_payslip')} — ${monthLabel(slip.period)}`" :description="slip.number">
                <template #actions>
                    <Button variant="outline" as-child><Link href="/me/payslips">{{ $t('common.actions.back') }}</Link></Button>
                    <Button as-child><a :href="`/me/payslips/${slip.id}/pdf`" data-testid="slip-pdf"><Download class="size-4" />{{ $t('payroll.slip.pdf') }}</a></Button>
                </template>
            </PageHeader>
            <SlipView :slip="slip" show-employer show-ytd />
        </div>
    </AppLayout>
</template>
