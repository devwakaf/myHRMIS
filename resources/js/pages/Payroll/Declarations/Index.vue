<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CheckField from '@/components/app/CheckField.vue';
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
import { monthLabel, monthStart } from '@/lib/payroll';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    number: string | null;
    employee: string | null;
    employee_id: number;
    type: string;
    type_label: string;
    period: string;
    tax_year: number;
    is_correction: boolean;
    status: string;
    prev_employer_name: string | null;
    prev_employer_e_no: string | null;
    prev_from: string | null;
    prev_to: string | null;
    prev_gross_normal: string;
    prev_gross_additional: string;
    prev_epf: string;
    prev_pcb: string;
    prev_zakat: string;
    prev_cp38: string;
    prev_lp: string;
    zakat_amount: string;
    zakat_authority: string | null;
    receipt_no: string | null;
    receipt_date: string | null;
    remarks: string | null;
    lines: { relief_code: string; amount: string; reason: string | null }[];
};

const props = defineProps<{
    rows: Paginated<Row>;
    options: { employees: { id: number; employee_no: string; full_name: string }[]; relief_codes: { code: string; name: string; annual_cap: string | null }[] };
    can: { manage: boolean };
}>();

const employeeOptions = computed<Option<number>[]>(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.employee_no} — ${e.full_name}` })));
const reliefOptions = computed<Option<string>[]>(() => props.options.relief_codes.map((r) => ({ value: r.code, label: `${r.name}${r.annual_cap ? ` (≤ RM ${r.annual_cap})` : ''}` })));
const typeOptions = computed(() => ['tp1', 'tp3', 'zakat_external'].map((t) => ({ value: t, label: `payroll.enums.declaration_type.${t}` })));

const TP3 = ['prev_gross_normal', 'prev_gross_additional', 'prev_epf', 'prev_pcb', 'prev_zakat', 'prev_cp38', 'prev_lp'] as const;

const open = ref(false);
const editing = ref<Row | null>(null);
const blank = () => ({
    employee_id: null as number | null,
    type: 'tp1',
    period: '',
    is_correction: false,
    prev_employer_name: '',
    prev_employer_e_no: '',
    prev_from: '',
    prev_to: '',
    prev_gross_normal: '' as string | number,
    prev_gross_additional: '' as string | number,
    prev_epf: '' as string | number,
    prev_pcb: '' as string | number,
    prev_zakat: '' as string | number,
    prev_cp38: '' as string | number,
    prev_lp: '' as string | number,
    zakat_amount: '' as string | number,
    zakat_authority: '',
    receipt_no: '',
    receipt_date: '',
    remarks: '',
    lines: [] as { relief_code: string | null; amount: string; reason: string }[],
});
const form = useForm(blank());

function startNew() {
    editing.value = null;
    form.defaults(blank()).reset();
    form.clearErrors();
    open.value = true;
}

function startEdit(r: Row) {
    editing.value = r;
    form.defaults({
        ...blank(),
        employee_id: r.employee_id,
        type: r.type,
        period: r.period,
        is_correction: r.is_correction,
        prev_employer_name: r.prev_employer_name ?? '',
        prev_employer_e_no: r.prev_employer_e_no ?? '',
        prev_from: r.prev_from ?? '',
        prev_to: r.prev_to ?? '',
        prev_gross_normal: r.prev_gross_normal,
        prev_gross_additional: r.prev_gross_additional,
        prev_epf: r.prev_epf,
        prev_pcb: r.prev_pcb,
        prev_zakat: r.prev_zakat,
        prev_cp38: r.prev_cp38,
        prev_lp: r.prev_lp,
        zakat_amount: r.zakat_amount,
        zakat_authority: r.zakat_authority ?? '',
        receipt_no: r.receipt_no ?? '',
        receipt_date: r.receipt_date ?? '',
        remarks: r.remarks ?? '',
        lines: r.lines.map((l) => ({ relief_code: l.relief_code, amount: l.amount, reason: l.reason ?? '' })),
    }).reset();
    form.clearErrors();
    open.value = true;
}

function save(submit: boolean) {
    form.transform((d) => {
        const data: Record<string, unknown> = { employee_id: d.employee_id, type: d.type, period: monthStart(d.period), is_correction: d.is_correction, remarks: d.remarks || null, submit };
        if (d.type === 'tp1') {
            data.lines = d.lines.filter((l) => l.relief_code && l.amount !== '').map((l) => ({ relief_code: l.relief_code, amount: l.amount, reason: l.reason || null }));
        } else if (d.type === 'tp3') {
            data.prev_employer_name = d.prev_employer_name || null;
            data.prev_employer_e_no = d.prev_employer_e_no || null;
            data.prev_from = d.prev_from || null;
            data.prev_to = d.prev_to || null;
            for (const k of TP3) data[k] = d[k] === '' ? null : d[k];
        } else {
            data.zakat_amount = d.zakat_amount;
            data.zakat_authority = d.zakat_authority || null;
            data.receipt_no = d.receipt_no || null;
            data.receipt_date = d.receipt_date || null;
        }

        return data;
    })[editing.value ? 'put' : 'post'](editing.value ? `/payroll/declarations/${editing.value.id}` : '/payroll/declarations', { preserveScroll: true, onSuccess: () => (open.value = false) });
}

const cancelTarget = ref<Row | null>(null);
function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/payroll/declarations/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}

function summary(r: Row): string {
    if (r.type === 'tp1') return `${r.lines.length} ${props.options.relief_codes.length ? '' : ''}`.trim();
    if (r.type === 'zakat_external') return r.zakat_authority ?? '';

    return r.prev_employer_name ?? '';
}
</script>

<template>
    <Head :title="$t('payroll.pages.declarations')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.declarations', href: '/payroll/declarations' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.declarations')" :description="$t('payroll.declarations.description')">
                <template #actions>
                    <Button v-if="can.manage" data-testid="declaration-new" @click="startNew"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.declarations.type') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.declarations.month') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.declarations.detail') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows.data" :key="r.id" class="border-t">
                            <td class="px-3 py-2 font-mono text-xs">{{ r.number ?? '—' }}</td>
                            <td class="px-3 py-2">{{ r.employee }}</td>
                            <td class="px-3 py-2">{{ r.type_label }}<span v-if="r.is_correction" class="ml-1 text-xs text-muted-foreground">({{ $t('payroll.declarations.correction') }})</span></td>
                            <td class="px-3 py-2">{{ monthLabel(r.period) }}</td>
                            <td class="px-3 py-2 text-xs">
                                <template v-if="r.type === 'tp1'"><div v-for="(l, n) in r.lines" :key="n" class="flex justify-between gap-4"><span class="font-mono">{{ l.relief_code }}</span><MoneyText :value="l.amount" /></div></template>
                                <template v-else-if="r.type === 'zakat_external'"><MoneyText :value="r.zakat_amount" /> {{ summary(r) }}</template>
                                <template v-else>{{ summary(r) }} — <MoneyText :value="r.prev_gross_normal" /></template>
                            </td>
                            <td class="px-3 py-2"><StatusBadge :status="r.status" :label="$t(`common.document_status.${r.status}`)" /></td>
                            <td class="space-x-1 px-3 py-2 text-right">
                                <Button v-if="can.manage && r.status === 'draft'" size="sm" variant="outline" @click="startEdit(r)">{{ $t('common.actions.edit') }}</Button>
                                <Button v-if="can.manage && r.status === 'draft'" size="sm" @click="router.post(`/payroll/declarations/${r.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                <Button v-if="can.manage && r.status !== 'cancelled'" size="sm" variant="ghost" @click="cancelTarget = r">{{ $t('common.actions.cancel_doc') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!rows.data.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rows.links" :from="rows.from" :to="rows.to" :total="rows.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ editing ? $t('payroll.declarations.edit') : $t('payroll.declarations.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save(false)">
                    <FormField :label="$t('payroll.fields.employee')" :error="form.errors.employee_id" required><SelectInput v-model="form.employee_id" :options="employeeOptions" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.declarations.type')" :error="form.errors.type"><SelectInput v-model="form.type" :options="typeOptions.map((o) => ({ value: o.value, label: $t(o.label) }))" :nullable="false" /></FormField>
                        <FormField :label="$t('payroll.declarations.month')" :error="form.errors.period" :hint="$t('payroll.declarations.month_hint')" required><Input v-model="form.period" type="month" /></FormField>
                    </div>
                    <CheckField v-model="form.is_correction" :label="$t('payroll.declarations.correction')" :hint="$t('payroll.declarations.correction_hint')" />

                    <div v-if="form.type === 'tp1'" class="space-y-2">
                        <p class="text-sm font-medium">{{ $t('payroll.declarations.relief_lines') }}</p>
                        <p class="text-xs text-muted-foreground">{{ $t('payroll.declarations.relief_hint') }}</p>
                        <div v-for="(l, n) in form.lines" :key="n" class="grid grid-cols-[1fr_8rem_auto] items-start gap-2">
                            <div><SelectInput v-model="l.relief_code" :options="reliefOptions" /><p v-if="form.errors[`lines.${n}.relief_code`]" class="mt-1 text-xs text-red-600">{{ form.errors[`lines.${n}.relief_code`] }}</p></div>
                            <div><Input v-model="l.amount" type="number" step="0.01" /><p v-if="form.errors[`lines.${n}.amount`]" class="mt-1 text-xs text-red-600">{{ form.errors[`lines.${n}.amount`] }}</p></div>
                            <Button type="button" size="icon-sm" variant="ghost" :aria-label="$t('common.actions.delete')" @click="form.lines.splice(n, 1)"><Trash2 class="size-4" /></Button>
                        </div>
                        <Button type="button" size="sm" variant="outline" @click="form.lines.push({ relief_code: null, amount: '', reason: '' })"><Plus class="size-4" />{{ $t('payroll.corrections.add_line') }}</Button>
                    </div>

                    <div v-if="form.type === 'tp3'" class="grid gap-3">
                        <div class="grid grid-cols-2 gap-3">
                            <FormField :label="$t('payroll.declarations.prev_employer')" :error="form.errors.prev_employer_name"><Input v-model="form.prev_employer_name" /></FormField>
                            <FormField :label="$t('payroll.declarations.prev_employer_no')" :error="form.errors.prev_employer_e_no"><Input v-model="form.prev_employer_e_no" /></FormField>
                            <FormField :label="$t('payroll.declarations.prev_from')" :error="form.errors.prev_from"><Input v-model="form.prev_from" type="date" /></FormField>
                            <FormField :label="$t('payroll.declarations.prev_to')" :error="form.errors.prev_to"><Input v-model="form.prev_to" type="date" /></FormField>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField v-for="k in TP3" :key="k" :label="$t(`payroll.declarations.${k}`)" :error="form.errors[k]"><Input v-model="form[k]" type="number" step="0.01" /></FormField>
                        </div>
                    </div>

                    <div v-if="form.type === 'zakat_external'" class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.declarations.zakat_amount')" :error="form.errors.zakat_amount" required><Input v-model="form.zakat_amount" type="number" step="0.01" min="0.01" /></FormField>
                        <FormField :label="$t('payroll.declarations.zakat_authority')" :error="form.errors.zakat_authority"><Input v-model="form.zakat_authority" /></FormField>
                        <FormField :label="$t('payroll.declarations.receipt_no')" :error="form.errors.receipt_no"><Input v-model="form.receipt_no" /></FormField>
                        <FormField :label="$t('payroll.declarations.receipt_date')" :error="form.errors.receipt_date"><Input v-model="form.receipt_date" type="date" /></FormField>
                    </div>

                    <FormField :label="$t('common.fields.remarks')" :error="form.errors.remarks"><TextArea v-model="form.remarks" :rows="2" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="save(false)">{{ $t('payroll.actions.save_draft') }}</Button>
                        <Button v-if="!editing" type="button" :disabled="form.processing" data-testid="declaration-submit" @click="save(true)">{{ $t('common.actions.submit') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
