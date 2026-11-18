<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Download, Mail } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SlipView from '@/components/payroll/SlipView.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel } from '@/lib/payroll';
import type { SlipData } from '@/lib/payroll';

const props = defineProps<{ slip: SlipData; can: { trace: boolean; resend: boolean; mark_paid: boolean } }>();

const showTrace = ref(false);
const paidOpen = ref(false);
const paid = useForm({ paid_on: new Date().toISOString().slice(0, 10) });
const st = props.slip.statutory;

function markPaid() {
    paid.post(`/payroll/slips/${props.slip.id}/mark-paid`, { preserveScroll: true, onSuccess: () => (paidOpen.value = false) });
}
</script>

<template>
    <Head :title="`${$t('payroll.pages.slip')} ${slip.number}`" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.slips', href: '/payroll/slips' }, { title: 'payroll.pages.slip', href: `/payroll/slips/${slip.id}` }]">
        <div class="mx-auto max-w-4xl space-y-4 p-4 md:p-6">
            <PageHeader :title="`${$t('payroll.pages.slip')} — ${monthLabel(slip.period)}`" :description="slip.number">
                <template #actions>
                    <Button variant="outline" as-child><Link :href="`/payroll/runs/${slip.payroll_run_id}`">{{ $t('payroll.slip.back_to_run') }}</Link></Button>
                    <Button variant="outline" as-child><a :href="`/payroll/slips/${slip.id}/pdf`" data-testid="slip-pdf"><Download class="size-4" />{{ $t('payroll.slip.pdf') }}</a></Button>
                    <Button v-if="can.resend && slip.status === 'submitted'" variant="outline" data-testid="slip-resend" @click="router.post(`/payroll/slips/${slip.id}/resend`, {}, { preserveScroll: true })"><Mail class="size-4" />{{ $t('payroll.slip.email') }}</Button>
                    <Button v-if="can.mark_paid && slip.status === 'submitted' && slip.payment_status === 'unpaid'" variant="outline" @click="paidOpen = true">{{ $t('payroll.slip.mark_paid') }}</Button>
                </template>
            </PageHeader>

            <ul v-if="slip.warnings.length" class="list-disc space-y-1 rounded-md border border-amber-300 bg-amber-50 py-2 pl-7 pr-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                <li v-for="(w, i) in slip.warnings" :key="i">{{ w }}</li>
            </ul>

            <SlipView :slip="slip" show-employer show-ytd />

            <section v-if="can.trace && slip.trace" class="rounded-lg border" data-testid="trace">
                <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-semibold" @click="showTrace = !showTrace">
                    <component :is="showTrace ? ChevronDown : ChevronRight" class="size-4" />{{ $t('payroll.slip.trace') }}
                </button>
                <div v-if="showTrace" class="space-y-3 border-t p-3 text-xs">
                    <p class="text-muted-foreground">{{ $t('payroll.slip.trace_hint') }}</p>
                    <div v-if="st" class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                        <div v-for="(v, k) in st" :key="k" class="flex justify-between border-b border-dashed py-0.5"><span class="text-muted-foreground">{{ k }}</span><span class="font-mono">{{ String(v) }}</span></div>
                    </div>
                    <pre class="max-h-96 overflow-auto rounded bg-muted/40 p-2">{{ JSON.stringify(slip.trace, null, 2) }}</pre>
                </div>
            </section>
        </div>

        <Dialog v-model:open="paidOpen">
            <DialogContent class="max-w-sm">
                <DialogHeader><DialogTitle>{{ $t('payroll.slip.mark_paid') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="markPaid">
                    <FormField :label="$t('payroll.wizard.paid_date')" :error="paid.errors.paid_on" :hint="$t('payroll.slip.mark_paid_hint')"><Input v-model="paid.paid_on" type="date" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="paidOpen = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="paid.processing">{{ $t('common.actions.confirm') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
