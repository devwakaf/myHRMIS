<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Plus } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
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
    employee_id: number;
    employee: string | null;
    salary_component_id: number;
    component: string | null;
    kind: string;
    period: string | null;
    from_period: string | null;
    to_period: string | null;
    amount: string;
    mode: string;
    prorate: boolean;
    source_label: string;
    source_ref: string | null;
    reason: string;
    status: string;
    status_label: string;
    applications: { period: string; amount: string; slip_id: number | null; slip_number: string | null }[];
};

const props = defineProps<{
    rows: Paginated<Row>;
    filters: Record<string, string>;
    options: { employees: { id: number; employee_no: string; full_name: string }[]; components: { id: number; code: string; name: string; kind: string }[] };
    can: { manage: boolean };
}>();

const employeeOptions = computed<Option<number>[]>(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.employee_no} — ${e.full_name}` })));
const componentOptions = computed<Option<number>[]>(() => props.options.components.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })));
const filterEmployee = ref<number | string | null>(props.filters.employee_id ? Number(props.filters.employee_id) : null);
const filterStatus = ref<string | null>(props.filters.status ?? null);
const expanded = reactive<Record<number, boolean>>({});

function applyFilters() {
    router.get('/payroll/additional-salaries', { employee_id: filterEmployee.value || undefined, status: filterStatus.value || undefined }, { preserveState: true, replace: true });
}

const open = ref(false);
const editing = ref<Row | null>(null);
const form = useForm({
    employee_id: null as number | null,
    salary_component_id: null as number | null,
    kind: 'one_off',
    period: '',
    from_period: '',
    to_period: '',
    amount: '' as string | number,
    mode: 'add',
    prorate: false,
    reason: '',
});

function startNew() {
    editing.value = null;
    form.defaults({ employee_id: null, salary_component_id: null, kind: 'one_off', period: '', from_period: '', to_period: '', amount: '', mode: 'add', prorate: false, reason: '' }).reset();
    form.clearErrors();
    open.value = true;
}

function startEdit(r: Row) {
    editing.value = r;
    form.defaults({
        employee_id: r.employee_id,
        salary_component_id: r.salary_component_id,
        kind: r.kind,
        period: r.period ?? '',
        from_period: r.from_period ?? '',
        to_period: r.to_period ?? '',
        amount: r.amount,
        mode: r.mode,
        prorate: r.prorate,
        reason: r.reason,
    }).reset();
    form.clearErrors();
    open.value = true;
}

function save(submit: boolean) {
    form.transform((d) => ({
        ...d,
        period: d.kind === 'one_off' && d.period ? monthStart(d.period) : null,
        from_period: d.kind === 'recurring' && d.from_period ? monthStart(d.from_period) : null,
        to_period: d.kind === 'recurring' && d.to_period ? monthStart(d.to_period) : null,
        submit,
    }))[editing.value ? 'put' : 'post'](editing.value ? `/payroll/additional-salaries/${editing.value.id}` : '/payroll/additional-salaries', {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

const cancelTarget = ref<Row | null>(null);
function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/payroll/additional-salaries/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}

const whenLabel = (r: Row) => (r.kind === 'one_off' ? (r.period ? monthLabel(r.period) : '—') : `${r.from_period ? monthLabel(r.from_period) : ''} – ${r.to_period ? monthLabel(r.to_period) : ''}`);
const kindOptions = computed(() => [
    { value: 'one_off', label: 'payroll.enums.additional_kind.one_off' },
    { value: 'recurring', label: 'payroll.enums.additional_kind.recurring' },
]);
const modeOptions = computed(() => [
    { value: 'add', label: 'payroll.enums.additional_mode.add' },
    { value: 'replace', label: 'payroll.enums.additional_mode.replace' },
]);
</script>

<template>
    <Head :title="$t('payroll.pages.additional_salaries')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.additional_salaries', href: '/payroll/additional-salaries' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.additional_salaries')" :description="$t('payroll.additional.description')">
                <template #actions>
                    <Button v-if="can.manage" data-testid="additional-new" @click="startNew"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="applyFilters">
                <div class="w-72"><SelectInput v-model="filterEmployee" :options="employeeOptions" :placeholder="$t('payroll.filters.all_employees')" /></div>
                <div class="w-44"><SelectInput v-model="filterStatus" :options="['draft', 'submitted', 'cancelled'].map((s) => ({ value: s, label: $t(`common.document_status.${s}`) }))" :placeholder="$t('payroll.filters.all_statuses')" /></div>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="w-8 px-2 py-2"></th>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.additional.component') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.additional.when') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.fields.amount') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.additional.mode') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.additional.source') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="r in rows.data" :key="r.id">
                            <tr class="border-t">
                                <td class="px-2 py-2"><button type="button" class="text-muted-foreground" :aria-label="$t('payroll.additional.applications')" @click="expanded[r.id] = !expanded[r.id]"><component :is="expanded[r.id] ? ChevronDown : ChevronRight" class="size-4" /></button></td>
                                <td class="px-3 py-2 font-mono text-xs">{{ r.number ?? '—' }}</td>
                                <td class="px-3 py-2">{{ r.employee }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ r.component }}</td>
                                <td class="px-3 py-2">{{ whenLabel(r) }}<span class="block text-xs text-muted-foreground">{{ $t(`payroll.enums.additional_kind.${r.kind}`) }}</span></td>
                                <td class="px-3 py-2 text-right"><MoneyText :value="r.amount" /></td>
                                <td class="px-3 py-2">{{ $t(`payroll.enums.additional_mode.${r.mode}`) }}</td>
                                <td class="px-3 py-2">{{ r.source_label }}<span v-if="r.source_ref" class="block text-xs text-muted-foreground">{{ r.source_ref }}</span></td>
                                <td class="px-3 py-2"><StatusBadge :status="r.status" :label="r.status_label" /></td>
                                <td class="space-x-1 px-3 py-2 text-right">
                                    <Button v-if="can.manage && r.status === 'draft'" size="sm" variant="outline" @click="startEdit(r)">{{ $t('common.actions.edit') }}</Button>
                                    <Button v-if="can.manage && r.status === 'draft'" size="sm" @click="router.post(`/payroll/additional-salaries/${r.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                    <Button v-if="can.manage && r.status === 'submitted'" size="sm" variant="ghost" @click="cancelTarget = r">{{ $t('common.actions.cancel_doc') }}</Button>
                                </td>
                            </tr>
                            <tr v-if="expanded[r.id]" class="border-t bg-muted/20">
                                <td></td>
                                <td colspan="9" class="px-3 py-2 text-xs">
                                    <p class="mb-1"><span class="font-medium">{{ $t('common.fields.reason') }}:</span> {{ r.reason }}</p>
                                    <p class="font-medium">{{ $t('payroll.additional.applications') }}</p>
                                    <ul v-if="r.applications.length" class="list-disc pl-5">
                                        <li v-for="(a, n) in r.applications" :key="n">{{ monthLabel(a.period) }} — <MoneyText :value="a.amount" /> <span v-if="a.slip_number" class="text-muted-foreground">({{ a.slip_number }})</span></li>
                                    </ul>
                                    <p v-else class="text-muted-foreground">{{ $t('payroll.additional.not_consumed') }}</p>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!rows.data.length"><td colspan="10" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rows.links" :from="rows.from" :to="rows.to" :total="rows.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ editing ? $t('payroll.additional.edit') : $t('payroll.additional.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save(false)">
                    <FormField :label="$t('payroll.fields.employee')" :error="form.errors.employee_id" required><SelectInput v-model="form.employee_id" :options="employeeOptions" /></FormField>
                    <FormField :label="$t('payroll.additional.component')" :error="form.errors.salary_component_id" required><SelectInput v-model="form.salary_component_id" :options="componentOptions" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.additional.kind')" :error="form.errors.kind"><SelectInput v-model="form.kind" :options="kindOptions.map((o) => ({ value: o.value, label: $t(o.label) }))" :nullable="false" /></FormField>
                        <FormField :label="$t('payroll.additional.mode')" :error="form.errors.mode" :hint="$t('payroll.additional.mode_hint')"><SelectInput v-model="form.mode" :options="modeOptions.map((o) => ({ value: o.value, label: $t(o.label) }))" :nullable="false" /></FormField>
                    </div>
                    <FormField v-if="form.kind === 'one_off'" :label="$t('payroll.additional.month')" :error="form.errors.period" required><Input v-model="form.period" type="month" /></FormField>
                    <div v-else class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.additional.from_month')" :error="form.errors.from_period" required><Input v-model="form.from_period" type="month" /></FormField>
                        <FormField :label="$t('payroll.additional.to_month')" :error="form.errors.to_period" required><Input v-model="form.to_period" type="month" /></FormField>
                    </div>
                    <FormField :label="$t('payroll.fields.amount')" :error="form.errors.amount" required><Input v-model="form.amount" type="number" step="0.01" min="0.01" /></FormField>
                    <CheckField v-model="form.prorate" :label="$t('payroll.additional.prorate')" />
                    <FormField :label="$t('common.fields.reason')" :error="form.errors.reason" required><TextArea v-model="form.reason" :rows="2" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="save(false)">{{ $t('payroll.actions.save_draft') }}</Button>
                        <Button v-if="!editing" type="button" :disabled="form.processing" data-testid="additional-submit" @click="save(true)">{{ $t('common.actions.submit') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
