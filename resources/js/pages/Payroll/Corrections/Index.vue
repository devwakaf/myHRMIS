<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel, monthStart, postJson, trimZeros } from '@/lib/payroll';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    number: string | null;
    employee: string | null;
    slip_number: string | null;
    type: string;
    type_label: string;
    lwp_days_reversed: string | null;
    payout_period: string;
    reason: string;
    status: string;
    lines: { component: string; amount: string; basis: string | null }[];
};
type SlipOption = { id: number; number: string; period: string; employee_no: string; full_name: string; lwp_days: string };
type RefundLine = { salary_component_id: number; amount: string; basis?: string | null };

const props = defineProps<{
    rows: Paginated<Row>;
    slips: SlipOption[];
    options: { components: { id: number; code: string; name: string }[] };
    can: { manage: boolean };
}>();

const slipOptions = computed<Option<number>[]>(() => props.slips.map((s) => ({ value: s.id, label: `${s.full_name} — ${monthLabel(s.period)} (${s.number})` })));
const componentOptions = computed<Option<number>[]>(() => props.options.components.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })));
const codeOf = (id: number) => props.options.components.find((c) => c.id === id)?.code ?? String(id);

const open = ref(false);
const form = useForm({
    salary_slip_id: null as number | null,
    type: 'lwp_reversal',
    payout_period: '',
    reason: '',
    lwp_days_reversed: '' as string | number,
    lines: [] as { salary_component_id: number | null; amount: string; basis: string }[],
});
const slip = computed(() => props.slips.find((s) => s.id === form.salary_slip_id) ?? null);
const preview = ref<RefundLine[] | null>(null);
const previewError = ref<string | null>(null);

function startNew() {
    form.defaults({ salary_slip_id: null, type: 'lwp_reversal', payout_period: '', reason: '', lwp_days_reversed: '', lines: [] }).reset();
    form.clearErrors();
    preview.value = null;
    previewError.value = null;
    open.value = true;
}

let timer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => [form.salary_slip_id, form.lwp_days_reversed, form.type],
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(async () => {
            preview.value = null;
            previewError.value = null;
            if (form.type !== 'lwp_reversal' || !form.salary_slip_id || !form.lwp_days_reversed || Number(form.lwp_days_reversed) <= 0) return;
            try {
                const body = await postJson<{ lines?: RefundLine[]; message?: string }>('/payroll/corrections/preview', { salary_slip_id: form.salary_slip_id, days: String(form.lwp_days_reversed) });
                if (body.lines) preview.value = body.lines;
                else previewError.value = body.message ?? null;
            } catch {
                previewError.value = null;
            }
        }, 350);
    },
);

function addLine() {
    form.lines.push({ salary_component_id: null, amount: '', basis: '' });
}

function save() {
    form.transform((d) => ({
        salary_slip_id: d.salary_slip_id,
        type: d.type,
        payout_period: monthStart(d.payout_period),
        reason: d.reason,
        ...(d.type === 'lwp_reversal'
            ? { lwp_days_reversed: d.lwp_days_reversed }
            : { lines: d.lines.filter((l) => l.salary_component_id !== null && l.amount !== '').map((l) => ({ salary_component_id: l.salary_component_id, amount: l.amount, basis: l.basis || null })) }),
    })).post('/payroll/corrections', { preserveScroll: true, onSuccess: () => (open.value = false) });
}

const cancelTarget = ref<Row | null>(null);
function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/payroll/corrections/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}
</script>

<template>
    <Head :title="$t('payroll.pages.corrections')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.corrections', href: '/payroll/corrections' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.corrections')" :description="$t('payroll.corrections.description')">
                <template #actions>
                    <Button v-if="can.manage" data-testid="correction-new" @click="startNew"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.corrections.slip') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.corrections.type') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.corrections.lines') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.arrears.payout') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows.data" :key="r.id" class="border-t align-top">
                            <td class="px-3 py-2 font-mono text-xs">{{ r.number ?? '—' }}</td>
                            <td class="px-3 py-2">{{ r.employee }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ r.slip_number }}</td>
                            <td class="px-3 py-2">{{ r.type_label }}<span v-if="r.lwp_days_reversed" class="block text-xs text-muted-foreground">{{ trimZeros(r.lwp_days_reversed) }} {{ $t('payroll.corrections.days') }}</span></td>
                            <td class="px-3 py-2 text-xs"><div v-for="(l, n) in r.lines" :key="n" class="flex justify-between gap-4"><span class="font-mono">{{ l.component }}</span><MoneyText :value="l.amount" /></div></td>
                            <td class="px-3 py-2">{{ monthLabel(r.payout_period) }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="r.status" :label="$t(`common.document_status.${r.status}`)" /></td>
                            <td class="space-x-1 px-3 py-2 text-right">
                                <Button v-if="can.manage && r.status === 'draft'" size="sm" @click="router.post(`/payroll/corrections/${r.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                <Button v-if="can.manage && r.status !== 'cancelled'" size="sm" variant="ghost" @click="cancelTarget = r">{{ $t('common.actions.cancel_doc') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!rows.data.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rows.links" :from="rows.from" :to="rows.to" :total="rows.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ $t('payroll.corrections.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save">
                    <FormField :label="$t('payroll.corrections.slip')" :error="form.errors.salary_slip_id" :hint="$t('payroll.corrections.slip_hint')" required><SelectInput v-model="form.salary_slip_id" :options="slipOptions" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.corrections.type')" :error="form.errors.type"><SelectInput v-model="form.type" :options="[{ value: 'lwp_reversal', label: $t('payroll.enums.correction_type.lwp_reversal') }, { value: 'amount', label: $t('payroll.enums.correction_type.amount') }]" :nullable="false" /></FormField>
                        <FormField :label="$t('payroll.arrears.payout')" :error="form.errors.payout_period" required><Input v-model="form.payout_period" type="month" /></FormField>
                    </div>

                    <template v-if="form.type === 'lwp_reversal'">
                        <FormField :label="$t('payroll.corrections.lwp_days')" :error="form.errors.lwp_days_reversed" :hint="slip ? $t('payroll.corrections.lwp_days_max', { days: trimZeros(slip.lwp_days) }) : undefined" required>
                            <Input v-model="form.lwp_days_reversed" type="number" step="0.5" min="0.5" />
                        </FormField>
                        <div v-if="preview" class="rounded-md border bg-muted/30 p-3 text-sm" data-testid="refund-preview">
                            <p class="mb-1 font-medium">{{ $t('payroll.corrections.refund_preview') }}</p>
                            <div v-for="(l, n) in preview" :key="n" class="flex justify-between"><span class="font-mono text-xs">{{ codeOf(l.salary_component_id) }}</span><MoneyText :value="l.amount" /></div>
                        </div>
                        <p v-if="previewError" class="text-xs text-red-600">{{ previewError }}</p>
                    </template>
                    <template v-else>
                        <div class="space-y-2">
                            <p class="text-sm font-medium">{{ $t('payroll.corrections.lines') }}</p>
                            <p class="text-xs text-muted-foreground">{{ $t('payroll.corrections.amount_hint') }}</p>
                            <div v-for="(l, n) in form.lines" :key="n" class="grid grid-cols-[1fr_8rem_auto] items-end gap-2">
                                <SelectInput v-model="l.salary_component_id" :options="componentOptions" />
                                <Input v-model="l.amount" type="number" step="0.01" :placeholder="$t('payroll.fields.amount')" />
                                <Button type="button" size="icon-sm" variant="ghost" :aria-label="$t('common.actions.delete')" @click="form.lines.splice(n, 1)"><Trash2 class="size-4" /></Button>
                            </div>
                            <Button type="button" size="sm" variant="outline" @click="addLine"><Plus class="size-4" />{{ $t('payroll.corrections.add_line') }}</Button>
                            <p v-if="form.errors.lines" class="text-xs text-red-600">{{ form.errors.lines }}</p>
                        </div>
                    </template>

                    <FormField :label="$t('common.fields.reason')" :error="form.errors.reason" required><TextArea v-model="form.reason" :rows="2" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing" data-testid="correction-save">{{ $t('payroll.actions.save_draft') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
