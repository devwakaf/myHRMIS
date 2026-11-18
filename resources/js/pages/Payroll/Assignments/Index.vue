<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Users } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import CheckField from '@/components/app/CheckField.vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option, Paginated } from '@/types';

type Assignment = {
    id: number;
    number: string | null;
    employee_id: number;
    employee: string | null;
    employee_no: string | null;
    salary_structure_id: number;
    structure: string | null;
    effective_from: string;
    effective_to: string | null;
    base_salary: string;
    reason: string;
    reason_label: string;
    status: string;
    status_label: string;
    epf_employee_rate_override: string | null;
    epf_employer_rate_override: string | null;
    opening_year: number | null;
    opening: Record<string, string>;
    amounts: { salary_component_id: number; code: string | null; amount: string }[];
};
type StructureOption = { id: number; code: string; name_ms: string; components: { id: number; code: string; name: string; calculation: string }[] };
type EmployeeOption = { id: number; employee_no: string; full_name: string };

const props = defineProps<{
    assignments: Paginated<Assignment>;
    filters: Record<string, string>;
    options: { structures: StructureOption[]; employees: EmployeeOption[]; reasons: Option<string>[] };
    can: { manage: boolean };
}>();

const OPENING = ['opening_gross_normal', 'opening_gross_additional', 'opening_epf_employee', 'opening_pcb', 'opening_zakat', 'opening_cp38', 'opening_socso_employee', 'opening_eis_employee', 'opening_skbbk_employee', 'opening_lp'] as const;

const employeeOptions = computed<Option<number>[]>(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.employee_no} — ${e.full_name}` })));
const structureOptions = computed<Option<number>[]>(() => props.options.structures.map((s) => ({ value: s.id, label: `${s.code} — ${s.name_ms}` })));
const filterEmployee = ref<number | string | null>(props.filters.employee_id ? Number(props.filters.employee_id) : null);
const filterStatus = ref<string | null>(props.filters.status ?? null);
const statusOptions = [
    { value: 'draft', label: 'common.document_status.draft' },
    { value: 'submitted', label: 'common.document_status.submitted' },
    { value: 'cancelled', label: 'common.document_status.cancelled' },
];

function applyFilters() {
    router.get('/payroll/assignments', { employee_id: filterEmployee.value || undefined, status: filterStatus.value || undefined }, { preserveState: true, replace: true });
}

// ------------------------------------------------------------------ single assignment
const open = ref(false);
const editing = ref<Assignment | null>(null);
const showOpening = ref(false);
const form = useForm({
    employee_id: null as number | null,
    salary_structure_id: null as number | null,
    effective_from: '',
    base_salary: '' as string | number,
    reason: 'new_hire',
    epf_employee_rate_override: '' as string | number | null,
    epf_employer_rate_override: '' as string | number | null,
    amounts: {} as Record<number, string>,
    opening_year: '' as string | number | null,
    opening: {} as Record<string, string>,
    remarks: '',
});

const structure = computed(() => props.options.structures.find((s) => s.id === form.salary_structure_id) ?? null);
const overridable = computed(() => (structure.value?.components ?? []).filter((c) => c.calculation === 'fixed' || c.calculation === 'input'));

function startNew() {
    editing.value = null;
    form.defaults({ employee_id: null, salary_structure_id: props.options.structures[0]?.id ?? null, effective_from: '', base_salary: '', reason: 'new_hire', epf_employee_rate_override: '', epf_employer_rate_override: '', amounts: {}, opening_year: '', opening: {}, remarks: '' }).reset();
    form.clearErrors();
    showOpening.value = false;
    open.value = true;
}

function startEdit(a: Assignment) {
    editing.value = a;
    form.defaults({
        employee_id: a.employee_id,
        salary_structure_id: a.salary_structure_id,
        effective_from: a.effective_from,
        base_salary: a.base_salary,
        reason: a.reason,
        epf_employee_rate_override: a.epf_employee_rate_override ?? '',
        epf_employer_rate_override: a.epf_employer_rate_override ?? '',
        amounts: Object.fromEntries(a.amounts.map((x) => [x.salary_component_id, x.amount])),
        opening_year: a.opening_year ?? '',
        opening: { ...a.opening },
        remarks: '',
    }).reset();
    form.clearErrors();
    showOpening.value = a.opening_year !== null;
    open.value = true;
}

function save(submit: boolean) {
    form.transform((d) => {
        const data: Record<string, unknown> = {
            employee_id: d.employee_id,
            salary_structure_id: d.salary_structure_id,
            effective_from: d.effective_from,
            base_salary: d.base_salary,
            reason: d.reason,
            epf_employee_rate_override: d.epf_employee_rate_override === '' ? null : d.epf_employee_rate_override,
            epf_employer_rate_override: d.epf_employer_rate_override === '' ? null : d.epf_employer_rate_override,
            amounts: Object.fromEntries(Object.entries(d.amounts).filter(([id, v]) => v !== '' && overridable.value.some((c) => String(c.id) === id))),
            remarks: d.remarks || null,
            submit,
        };
        if (showOpening.value && d.opening_year !== '' && d.opening_year !== null) {
            data.opening_year = d.opening_year;
            for (const k of OPENING) data[k] = d.opening[k] === '' || d.opening[k] === undefined ? null : d.opening[k];
        }

        return data;
    })[editing.value ? 'put' : 'post'](editing.value ? `/payroll/assignments/${editing.value.id}` : '/payroll/assignments', { preserveScroll: true, onSuccess: () => (open.value = false) });
}

const cancelTarget = ref<Assignment | null>(null);
function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/payroll/assignments/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}

/** Back-dated: effective before the first day of the current month -> offer "Jana Tunggakan". */
function backdated(a: Assignment): boolean {
    const first = new Date();
    first.setDate(1);

    return a.status === 'submitted' && a.reason !== 'new_hire' && a.effective_from < first.toISOString().slice(0, 10);
}

// ------------------------------------------------------------------ bulk wizard
const bulkOpen = ref(false);
const step = ref(1);
const bulk = useForm({ salary_structure_id: null as number | null, effective_from: '', reason: 'increment', submit_now: true });
const search = ref('');
const picked = reactive<Record<number, string | null>>({});

const visibleEmployees = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.options.employees.filter((e) => q === '' || e.full_name.toLowerCase().includes(q) || e.employee_no.toLowerCase().includes(q)).slice(0, 200);
});
const pickedIds = computed(() => Object.keys(picked).filter((k) => picked[Number(k)] !== null).map(Number));
const missingBase = computed(() => pickedIds.value.filter((id) => !picked[id]));

function startBulk() {
    for (const k of Object.keys(picked)) delete picked[Number(k)];
    bulk.defaults({ salary_structure_id: props.options.structures[0]?.id ?? null, effective_from: '', reason: 'increment', submit_now: true }).reset();
    bulk.clearErrors();
    step.value = 1;
    search.value = '';
    bulkOpen.value = true;
}

function toggle(id: number, on: boolean) {
    if (on) picked[id] = '';
    else delete picked[id];
}

function runBulk() {
    bulk.transform((d) => ({ salary_structure_id: d.salary_structure_id, effective_from: d.effective_from, reason: d.reason, submit: d.submit_now, items: pickedIds.value.map((id) => ({ employee_id: id, base_salary: picked[id] ?? '0' })) })).post('/payroll/assignments/bulk', {
        preserveScroll: true,
        onSuccess: () => (bulkOpen.value = false),
    });
}
</script>

<template>
    <Head :title="$t('payroll.pages.assignments')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.assignments', href: '/payroll/assignments' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.assignments')" :description="$t('payroll.assignments.description')">
                <template #actions>
                    <Button v-if="can.manage" variant="outline" data-testid="assignment-bulk" @click="startBulk"><Users class="size-4" />{{ $t('payroll.assignments.bulk') }}</Button>
                    <Button v-if="can.manage" data-testid="assignment-new" @click="startNew"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="applyFilters">
                <div class="w-72"><SelectInput v-model="filterEmployee" :options="employeeOptions" :placeholder="$t('payroll.filters.all_employees')" /></div>
                <div class="w-44"><SelectInput v-model="filterStatus" :options="statusOptions.map((s) => ({ value: s.value, label: $t(s.label) }))" :placeholder="$t('payroll.filters.all_statuses')" /></div>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.assignments.structure') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.effective_from') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.effective_to') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.assignments.base_salary') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.assignments.reason') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in assignments.data" :key="a.id" class="border-t">
                            <td class="px-3 py-2 font-mono text-xs">{{ a.number ?? '—' }}</td>
                            <td class="px-3 py-2">{{ a.employee }}<span class="block text-xs text-muted-foreground">{{ a.employee_no }}</span></td>
                            <td class="px-3 py-2">{{ a.structure }}</td>
                            <td class="px-3 py-2">{{ formatDate(a.effective_from) }}</td>
                            <td class="px-3 py-2">{{ formatDate(a.effective_to) || '—' }}</td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="a.base_salary" /></td>
                            <td class="px-3 py-2">{{ a.reason_label }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="a.status" :label="a.status_label" /></td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <Button v-if="can.manage && a.status === 'draft'" size="sm" variant="outline" @click="startEdit(a)">{{ $t('common.actions.edit') }}</Button>
                                <Button v-if="can.manage && a.status === 'draft'" size="sm" @click="router.post(`/payroll/assignments/${a.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                <Button v-if="can.manage && a.status !== 'cancelled'" size="sm" variant="ghost" @click="cancelTarget = a">{{ $t('common.actions.cancel_doc') }}</Button>
                                <Button v-if="can.manage && backdated(a)" size="sm" variant="outline" as-child>
                                    <Link :href="`/payroll/arrears?assignment=${a.id}`" data-testid="arrear-link">{{ $t('payroll.assignments.generate_arrears') }}</Link>
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="!assignments.data.length"><td colspan="9" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="assignments.links" :from="assignments.from" :to="assignments.to" :total="assignments.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ editing ? $t('payroll.assignments.edit') : $t('payroll.assignments.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save(false)">
                    <FormField :label="$t('payroll.fields.employee')" :error="form.errors.employee_id" required><SelectInput v-model="form.employee_id" :options="employeeOptions" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.assignments.structure')" :error="form.errors.salary_structure_id" required><SelectInput v-model="form.salary_structure_id" :options="structureOptions" :nullable="false" /></FormField>
                        <FormField :label="$t('payroll.fields.effective_from')" :error="form.errors.effective_from" required><Input v-model="form.effective_from" type="date" /></FormField>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.assignments.base_salary')" :error="form.errors.base_salary" required><Input v-model="form.base_salary" type="number" step="0.01" min="0" /></FormField>
                        <FormField :label="$t('payroll.assignments.reason')" :error="form.errors.reason"><SelectInput v-model="form.reason" :options="options.reasons" :nullable="false" /></FormField>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.assignments.epf_employee_rate')" :error="form.errors.epf_employee_rate_override" :hint="$t('payroll.assignments.epf_rate_hint')"><Input v-model="form.epf_employee_rate_override" type="number" step="0.01" min="0" max="100" /></FormField>
                        <FormField :label="$t('payroll.assignments.epf_employer_rate')" :error="form.errors.epf_employer_rate_override"><Input v-model="form.epf_employer_rate_override" type="number" step="0.01" min="0" max="100" /></FormField>
                    </div>

                    <div v-if="overridable.length" class="rounded-md border p-3">
                        <p class="mb-2 text-sm font-medium">{{ $t('payroll.assignments.overrides') }}</p>
                        <p class="mb-2 text-xs text-muted-foreground">{{ $t('payroll.assignments.overrides_hint') }}</p>
                        <div class="grid grid-cols-2 gap-2">
                            <label v-for="c in overridable" :key="c.id" class="grid gap-0.5 text-xs">
                                <span>{{ c.name }} <span class="font-mono text-muted-foreground">{{ c.code }}</span></span>
                                <Input v-model="form.amounts[c.id]" type="number" step="0.01" min="0" />
                            </label>
                        </div>
                        <p v-if="form.errors.amounts" class="mt-1 text-xs text-red-600">{{ form.errors.amounts }}</p>
                    </div>

                    <div class="rounded-md border p-3">
                        <CheckField v-model="showOpening" :label="$t('payroll.assignments.opening')" :hint="$t('payroll.assignments.opening_hint')" />
                        <div v-if="showOpening" class="mt-3 grid grid-cols-2 gap-2">
                            <label class="grid gap-0.5 text-xs">{{ $t('payroll.assignments.opening_year') }}<Input v-model="form.opening_year" type="number" step="1" /></label>
                            <label v-for="k in OPENING" :key="k" class="grid gap-0.5 text-xs">{{ $t(`payroll.assignments.${k}`) }}<Input v-model="form.opening[k]" type="number" step="0.01" /></label>
                        </div>
                    </div>

                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="save(false)">{{ $t('payroll.actions.save_draft') }}</Button>
                        <Button type="button" :disabled="form.processing" data-testid="assignment-submit" @click="save(true)">{{ $t('common.actions.submit') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="bulkOpen">
            <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ $t('payroll.assignments.bulk_title') }} — {{ $t('payroll.assignments.step', { n: String(step), total: '3' }) }}</DialogTitle></DialogHeader>

                <div v-if="step === 1" class="grid gap-3">
                    <FormField :label="$t('payroll.assignments.structure')" :error="bulk.errors.salary_structure_id" required><SelectInput v-model="bulk.salary_structure_id" :options="structureOptions" :nullable="false" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.fields.effective_from')" :error="bulk.errors.effective_from" required><Input v-model="bulk.effective_from" type="date" /></FormField>
                        <FormField :label="$t('payroll.assignments.reason')"><SelectInput v-model="bulk.reason" :options="options.reasons" :nullable="false" /></FormField>
                    </div>
                    <CheckField v-model="bulk.submit_now" :label="$t('payroll.assignments.bulk_submit')" :hint="$t('payroll.assignments.bulk_submit_hint')" />
                </div>

                <div v-if="step === 2" class="grid gap-3">
                    <Input v-model="search" :placeholder="$t('payroll.assignments.search_employee')" />
                    <div class="max-h-80 overflow-y-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-muted/60 text-left"><tr><th class="w-8 px-2 py-1.5"></th><th class="px-2 py-1.5">{{ $t('payroll.fields.employee') }}</th><th class="w-40 px-2 py-1.5">{{ $t('payroll.assignments.base_salary') }}</th></tr></thead>
                            <tbody>
                                <tr v-for="e in visibleEmployees" :key="e.id" class="border-t">
                                    <td class="px-2 py-1"><input type="checkbox" class="size-4 accent-primary" :checked="picked[e.id] !== undefined" :aria-label="e.full_name" @change="toggle(e.id, ($event.target as HTMLInputElement).checked)" /></td>
                                    <td class="px-2 py-1">{{ e.full_name }} <span class="text-xs text-muted-foreground">{{ e.employee_no }}</span></td>
                                    <td class="px-2 py-1"><Input v-if="picked[e.id] !== undefined" v-model="picked[e.id]" type="number" step="0.01" min="0" class="h-8" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ $t('payroll.assignments.picked', { count: String(pickedIds.length) }) }}</p>
                </div>

                <div v-if="step === 3" class="grid gap-2 text-sm">
                    <p>{{ $t('payroll.assignments.bulk_review', { count: String(pickedIds.length) }) }}</p>
                    <ul class="max-h-60 list-disc overflow-y-auto pl-5">
                        <li v-for="id in pickedIds" :key="id">{{ options.employees.find((e) => e.id === id)?.full_name }} — <MoneyText :value="picked[id] ?? '0'" /></li>
                    </ul>
                    <p v-if="(bulk.errors as Record<string, string>).items" class="text-xs text-red-600">{{ (bulk.errors as Record<string, string>).items }}</p>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="step === 1 ? (bulkOpen = false) : (step -= 1)">{{ step === 1 ? $t('common.actions.cancel') : $t('common.actions.back') }}</Button>
                    <Button v-if="step === 1" type="button" :disabled="!bulk.salary_structure_id || !bulk.effective_from" @click="step = 2">{{ $t('common.actions.continue') }}</Button>
                    <Button v-if="step === 2" type="button" :disabled="!pickedIds.length || missingBase.length > 0" @click="step = 3">{{ $t('common.actions.continue') }}</Button>
                    <Button v-if="step === 3" type="button" :disabled="bulk.processing" data-testid="bulk-run" @click="runBulk">{{ $t('payroll.assignments.bulk_run') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
