<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel } from '@/lib/payroll';
import type { Option, Paginated } from '@/types';

type Row = { id: number; scheme: string; period: string; amount: string; paid_on: string; receipt_no: string | null; notes: string | null };

defineProps<{ remittances: Paginated<Row>; options: { schemes: Option<string>[] }; can: { manage: boolean } }>();

const open = ref(false);
const form = useForm({ scheme: 'kwsp', period: '', amount: '' as string | number, paid_on: '', receipt_no: '', notes: '' });

function startNew() {
    form.defaults({ scheme: 'kwsp', period: '', amount: '', paid_on: '', receipt_no: '', notes: '' }).reset();
    form.clearErrors();
    open.value = true;
}
function save() {
    form.transform((d) => ({ ...d, receipt_no: d.receipt_no || null, notes: d.notes || null })).post('/statutory/remittances', { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Head :title="$t('statutory.pages.remittances')" />
    <AppLayout :breadcrumbs="[{ title: 'statutory.pages.remittances', href: '/statutory/remittances' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('statutory.pages.remittances')" :description="$t('statutory.remittances.description')">
                <template #actions>
                    <Button v-if="can.manage" data-testid="remittance-new" @click="startNew"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('payroll.runs.period') }}</th><th class="px-3 py-2">{{ $t('statutory.remittances.scheme') }}</th><th class="px-3 py-2 text-right">{{ $t('payroll.fields.amount') }}</th><th class="px-3 py-2">{{ $t('statutory.remittances.paid_on') }}</th><th class="px-3 py-2">{{ $t('statutory.remittances.receipt_no') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="r in remittances.data" :key="r.id" class="border-t">
                            <td class="px-3 py-2">{{ monthLabel(r.period.slice(0, 7)) }}</td>
                            <td class="px-3 py-2">{{ $t(`payroll.enums.remittance_scheme.${r.scheme}`) }}</td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="r.amount" /></td>
                            <td class="px-3 py-2">{{ formatDate(r.paid_on) }}</td>
                            <td class="px-3 py-2">{{ r.receipt_no ?? '—' }}</td>
                        </tr>
                        <tr v-if="!remittances.data.length"><td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="remittances.links" :from="remittances.from" :to="remittances.to" :total="remittances.total" />
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>{{ $t('statutory.remittances.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save">
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('statutory.remittances.scheme')" :error="form.errors.scheme" required><SelectInput v-model="form.scheme" :options="options.schemes" :nullable="false" /></FormField>
                        <FormField :label="$t('payroll.runs.period')" :error="form.errors.period" required><Input v-model="form.period" type="month" /></FormField>
                        <FormField :label="$t('payroll.fields.amount')" :error="form.errors.amount" required><Input v-model="form.amount" type="number" step="0.01" min="0" /></FormField>
                        <FormField :label="$t('statutory.remittances.paid_on')" :error="form.errors.paid_on" required><Input v-model="form.paid_on" type="date" /></FormField>
                    </div>
                    <FormField :label="$t('statutory.remittances.receipt_no')" :error="form.errors.receipt_no"><Input v-model="form.receipt_no" /></FormField>
                    <FormField :label="$t('common.fields.remarks')" :error="form.errors.notes"><TextArea v-model="form.notes" :rows="2" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing" data-testid="remittance-save">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
