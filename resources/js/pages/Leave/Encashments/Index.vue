<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatMoney } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days, postJson } from '@/lib/leave';
import type { Option, Paginated } from '@/types';

type Encashment = {
    id: number;
    number: string | null;
    employee: string | null;
    encashment_date: string;
    eligible_days: string;
    days: string;
    rate_basis_label: string;
    monthly_basic: string;
    daily_rate: string;
    amount: string;
    payout_status: string;
    payout_status_label: string;
    status: string;
    status_label: string;
};
type RatePreview = { eligible_days: string; rate: { daily_rate: string; amount: string; orp_daily_rate: string; below_orp: boolean } | null };

const props = defineProps<{
    encashments: Paginated<Encashment>;
    can: { manage: boolean; submit: boolean };
    options: { allocations: Option<number>[]; rate_bases: Option<string>[]; purposes: Option<string>[]; default_rate_basis: string };
}>();

const open = ref(false);
const form = useForm({ leave_allocation_id: null as number | null, encashment_date: '', days: '', rate_basis: props.options.default_rate_basis, monthly_basic: '', purpose: 'other', remarks: '' });
const preview = ref<RatePreview | null>(null);
const previewError = ref(false);
const cancelTarget = ref<Encashment | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => [form.leave_allocation_id, form.encashment_date, form.days, form.rate_basis, form.monthly_basic],
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(async () => {
            if (!form.leave_allocation_id || !form.encashment_date || !form.days) {
                preview.value = null;

                return;
            }
            previewError.value = false;
            try {
                preview.value = await postJson<RatePreview>('/leave/encashments/preview', {
                    leave_allocation_id: form.leave_allocation_id,
                    encashment_date: form.encashment_date,
                    days: form.days,
                    rate_basis: form.rate_basis,
                    monthly_basic: form.monthly_basic || undefined,
                });
            } catch {
                previewError.value = true;
                preview.value = null;
            }
        }, 350);
    },
);

function save(submit: boolean) {
    form.transform((d) => ({ ...d, submit, monthly_basic: d.monthly_basic || null, remarks: d.remarks || null }))
        .post('/leave/encashments', { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); preview.value = null; } });
}

function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/leave/encashments/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}
</script>

<template>
    <Head :title="$t('leave.pages.encashments')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.encashments', href: '/leave/encashments' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.encashments')" :description="$t('leave.encashments.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="open = true"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.encashments.date') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.fields.days') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.encashments.daily_rate') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.encashments.amount') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.encashments.payout') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in encashments.data" :key="e.id" class="border-t">
                            <td class="px-3 py-2 font-mono">{{ e.number ?? '—' }}</td>
                            <td class="px-3 py-2">{{ e.employee }}</td>
                            <td class="px-3 py-2">{{ formatDate(e.encashment_date) }}</td>
                            <td class="px-3 py-2 text-right">{{ days(e.days) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatMoney(e.daily_rate) }}</td>
                            <td class="px-3 py-2 text-right font-medium">{{ formatMoney(e.amount) }}</td>
                            <td class="px-3 py-2">{{ e.payout_status_label }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="e.status" :label="e.status_label" /></td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <Button v-if="can.submit && e.status === 'draft'" size="sm" @click="router.post(`/leave/encashments/${e.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                <Button v-if="can.submit && e.status === 'submitted'" size="sm" variant="outline" @click="cancelTarget = e">{{ $t('common.actions.cancel_doc') }}</Button>
                                <Button v-if="can.manage && e.status === 'draft'" size="sm" variant="outline" @click="router.delete(`/leave/encashments/${e.id}`, { preserveScroll: true })">{{ $t('common.actions.delete') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!encashments.data.length"><td colspan="9" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="encashments.links" :from="encashments.from" :to="encashments.to" :total="encashments.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>{{ $t('leave.encashments.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save(can.submit)">
                    <FormField :label="$t('leave.adjustments.allocation')" :error="form.errors.leave_allocation_id" required><SelectInput v-model="form.leave_allocation_id" :options="options.allocations" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('leave.encashments.date')" :error="form.errors.encashment_date" required><Input v-model="form.encashment_date" type="date" /></FormField>
                        <FormField :label="$t('leave.fields.days')" :error="form.errors.days" required><Input v-model="form.days" type="number" step="0.5" min="0" /></FormField>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('leave.encashments.rate_basis')" :error="form.errors.rate_basis"><SelectInput v-model="form.rate_basis" :options="options.rate_bases" :nullable="false" /></FormField>
                        <FormField :label="$t('leave.encashments.monthly_basic')" :error="form.errors.monthly_basic" :hint="$t('leave.encashments.basic_hint')"><Input v-model="form.monthly_basic" type="number" step="0.01" min="0" /></FormField>
                    </div>
                    <FormField :label="$t('leave.encashments.purpose')" :error="form.errors.purpose"><SelectInput v-model="form.purpose" :options="options.purposes" :nullable="false" /></FormField>
                    <FormField :label="$t('common.fields.remarks')" :error="form.errors.remarks"><Input v-model="form.remarks" /></FormField>

                    <div v-if="preview" class="rounded-md border bg-muted/30 p-3 text-sm" data-testid="encashment-preview">
                        <p>{{ $t('leave.encashments.eligible') }}: <b>{{ days(preview.eligible_days) }}</b></p>
                        <template v-if="preview.rate">
                            <p>{{ $t('leave.encashments.daily_rate') }}: <b>{{ formatMoney(preview.rate.daily_rate) }}</b></p>
                            <p>{{ $t('leave.encashments.amount') }}: <b>{{ formatMoney(preview.rate.amount) }}</b></p>
                            <p v-if="preview.rate.below_orp && form.rate_basis === 'org_daily_rate'" class="mt-1 text-amber-700 dark:text-amber-300">{{ $t('leave.warnings.encash_below_orp', { rate: preview.rate.orp_daily_rate }) }}</p>
                        </template>
                    </div>
                    <p v-if="previewError" class="text-xs text-red-600">{{ $t('leave.apply.preview_failed') }}</p>

                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="save(false)">{{ $t('leave.apply.save_draft') }}</Button>
                        <Button v-if="can.submit" type="submit" :disabled="form.processing">{{ $t('common.actions.submit') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
