<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';

type Form = { id: number; tax_year: number; serial_no: string; published_at: string | null; data: { gross?: string; pcb?: string; epf_employee?: string } };

defineProps<{ forms: Form[] }>();
</script>

<template>
    <Head :title="$t('payroll.pages.my_ea')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.my_payslips', href: '/me/payslips' }, { title: 'payroll.pages.my_ea', href: '/me/ea' }]">
        <div class="mx-auto max-w-3xl p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.my_ea')" :description="$t('payroll.me.ea_description')">
                <template #actions>
                    <Button variant="outline" as-child><Link href="/me/payslips">{{ $t('payroll.pages.my_payslips') }}</Link></Button>
                </template>
            </PageHeader>
            <ul class="divide-y rounded-lg border" data-testid="my-ea">
                <li v-for="f in forms" :key="f.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div>
                        <p class="font-medium">{{ $t('payroll.me.ea_year', { year: String(f.tax_year) }) }}</p>
                        <p class="text-xs text-muted-foreground">{{ f.serial_no }} · {{ formatDate(f.published_at) }}</p>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        <span class="text-muted-foreground">{{ $t('payroll.pdf.ea.gross') }}: <MoneyText :value="f.data.gross" /></span>
                        <span class="text-muted-foreground">PCB: <MoneyText :value="f.data.pcb" /></span>
                        <Button size="sm" variant="outline" as-child><a :href="`/me/ea/${f.id}/pdf`"><Download class="size-4" />PDF</a></Button>
                    </div>
                </li>
                <li v-if="!forms.length" class="px-4 py-8 text-center text-sm text-muted-foreground">{{ $t('payroll.me.ea_empty') }}</li>
            </ul>
        </div>
    </AppLayout>
</template>
