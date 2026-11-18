<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import BulkAssignWizard from '@/components/attendance/BulkAssignWizard.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ShiftRef } from '@/lib/attendance';
import type { Option, Paginated } from '@/types';

type Assignment = {
    id: number;
    employee_id: number;
    employee: { id: number; employee_no: string; full_name: string } | null;
    shift_type_id: number;
    shift: ShiftRef;
    start_date: string;
    end_date: string | null;
    status: string;
    source: string;
    source_label: string;
    notes: string | null;
};

const props = defineProps<{
    assignments: Paginated<Assignment>;
    filters: Record<string, string | undefined>;
    options: {
        shift_types: { id: number; code: string; name: string; colour: string | null }[];
        schedules: { id: number; code: string; name: string }[];
        employees: { id: number; full_name: string; employee_no: string; branch_id: number | null; department_id: number | null }[];
        branches: { id: number; name: string }[];
        departments: { id: number; name: string }[];
        locations: { id: number; name: string }[];
        sources: Option<string>[];
    };
    can: { manage: boolean };
}>();

const f = ref({
    employee_id: props.filters.employee_id ?? '',
    shift_type_id: props.filters.shift_type_id ?? '',
    source: props.filters.source ?? '',
    branch_id: props.filters.branch_id ?? '',
    department_id: props.filters.department_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    status: props.filters.status ?? 'submitted',
});
const shiftOptions = computed(() => props.options.shift_types.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` })));
const employeeOptions = computed(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` })));

const dialog = ref<null | 'single' | 'bulk' | 'end'>(null);
const cancelTarget = ref<Assignment | null>(null);
const endTarget = ref<Assignment | null>(null);
const endDate = ref('');
const single = useForm({ employee_id: null as number | null, shift_type_id: null as number | null, start_date: '', end_date: '', location_ids: [] as number[], notes: '' });

function apply() {
    router.get('/attendance/shift-assignments', Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '' && v !== null)), { preserveState: true, replace: true });
}

function saveSingle() {
    single.transform((d) => ({ ...d, end_date: d.end_date || null, notes: d.notes || null })).post('/attendance/shift-assignments', {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = null;
            single.reset();
        },
    });
}

function cancel(reason: string) {
    if (!cancelTarget.value) return;
    router.post(`/attendance/shift-assignments/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}

function openEnd(a: Assignment) {
    endTarget.value = a;
    endDate.value = a.end_date ?? new Date().toISOString().slice(0, 10);
    dialog.value = 'end';
}

function saveEnd() {
    if (!endTarget.value) return;
    router.post(`/attendance/shift-assignments/${endTarget.value.id}/end`, { end_date: endDate.value }, { preserveScroll: true, onSuccess: () => (dialog.value = null) });
}

function bulkDone() {
    dialog.value = null;
    router.reload({ only: ['assignments'] });
}
</script>

<template>
    <Head :title="$t('attendance.pages.shift_assignments')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.shift_assignments', href: '/attendance/shift-assignments' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.shift_assignments')" :description="$t('attendance.assignment.description')">
                <template v-if="can.manage" #actions>
                    <Button variant="outline" data-testid="open-bulk" @click="dialog = 'bulk'"><Users class="size-4" />{{ $t('attendance.assignment.bulk') }}</Button>
                    <Button @click="dialog = 'single'"><Plus class="size-4" />{{ $t('attendance.assignment.new') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 grid gap-2 sm:grid-cols-4 lg:grid-cols-8" @submit.prevent="apply">
                <SelectInput v-model="f.employee_id" :options="employeeOptions" :placeholder="$t('attendance.common.all_employees')" class="lg:col-span-2" />
                <SelectInput v-model="f.shift_type_id" :options="shiftOptions" :placeholder="$t('attendance.common.all_shifts')" />
                <SelectInput v-model="f.branch_id" :options="options.branches.map((b) => ({ value: b.id, label: b.name }))" :placeholder="$t('attendance.common.all_branches')" />
                <SelectInput v-model="f.department_id" :options="options.departments.map((d) => ({ value: d.id, label: d.name }))" :placeholder="$t('attendance.common.all_departments')" />
                <SelectInput v-model="f.source" :options="options.sources" :placeholder="$t('attendance.assignment.all_sources')" />
                <Input v-model="f.from" type="date" :aria-label="$t('attendance.common.from_date')" />
                <Input v-model="f.to" type="date" :aria-label="$t('attendance.common.to_date')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="assignments-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('attendance.common.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.shift') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.from_date') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.to_date') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.source') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in assignments.data" :key="a.id" class="border-t">
                            <td class="px-3 py-2">{{ a.employee?.full_name }} <span class="text-xs text-muted-foreground">{{ a.employee?.employee_no }}</span></td>
                            <td class="px-3 py-2"><span class="mr-1 inline-block size-3 rounded-sm align-middle" :style="{ backgroundColor: a.shift?.colour ?? '#94a3b8' }" />{{ a.shift?.code }} <span class="text-xs text-muted-foreground">{{ a.shift?.time }}</span></td>
                            <td class="px-3 py-2">{{ formatDate(a.start_date) }}</td>
                            <td class="px-3 py-2">{{ a.end_date ? formatDate(a.end_date) : $t('attendance.assignment.open') }}</td>
                            <td class="px-3 py-2">{{ a.source_label }}<StatusBadge v-if="a.status !== 'submitted'" class="ml-1" :status="a.status" :label="$t(`common.document_status.${a.status}`)" /></td>
                            <td class="space-x-1 px-3 py-2 text-right">
                                <template v-if="can.manage && a.status === 'submitted'">
                                    <Button size="sm" variant="outline" @click="openEnd(a)">{{ $t('attendance.assignment.end') }}</Button>
                                    <Button size="sm" variant="ghost" @click="cancelTarget = a">{{ $t('common.actions.cancel') }}</Button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!assignments.data.length"><td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="assignments.links" :from="assignments.from" :to="assignments.to" :total="assignments.total" />
        </div>

        <Dialog :open="dialog === 'single'" @update:open="(v: boolean) => { if (!v) dialog = null }">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ $t('attendance.assignment.new') }}</DialogTitle><DialogDescription>{{ $t('attendance.assignment.single_description') }}</DialogDescription></DialogHeader>
                <form class="space-y-3" @submit.prevent="saveSingle">
                    <FormField :label="$t('attendance.common.employee')" :error="single.errors.employee_id" required><SelectInput v-model="single.employee_id" :options="employeeOptions" /></FormField>
                    <FormField :label="$t('attendance.common.shift')" :error="single.errors.shift_type_id" required><SelectInput v-model="single.shift_type_id" :options="shiftOptions" /></FormField>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.common.from_date')" :error="single.errors.start_date" required><Input v-model="single.start_date" type="date" /></FormField>
                        <FormField :label="$t('attendance.common.to_date')" :error="single.errors.end_date" :hint="$t('attendance.assignment.open_ended')"><Input v-model="single.end_date" type="date" :min="single.start_date" /></FormField>
                    </div>
                    <fieldset v-if="options.locations.length" class="rounded-md border p-3">
                        <legend class="px-1 text-xs text-muted-foreground">{{ $t('attendance.assignment.locations') }}</legend>
                        <label v-for="l in options.locations" :key="l.id" class="mr-4 inline-flex items-center gap-1 text-sm"><input v-model="single.location_ids" type="checkbox" :value="l.id" />{{ l.name }}</label>
                    </fieldset>
                    <FormField :label="$t('common.fields.note')" :error="single.errors.notes"><Input v-model="single.notes" /></FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialog = null">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="single.processing || !single.employee_id || !single.shift_type_id || !single.start_date">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="dialog === 'bulk'" @update:open="(v: boolean) => { if (!v) dialog = null }">
            <DialogContent class="max-h-[92vh] max-w-3xl overflow-y-auto sm:max-w-3xl">
                <DialogHeader><DialogTitle>{{ $t('attendance.assignment.bulk') }}</DialogTitle><DialogDescription>{{ $t('attendance.assignment.bulk_description') }}</DialogDescription></DialogHeader>
                <BulkAssignWizard v-if="dialog === 'bulk'" :employees="options.employees" :shift-types="options.shift_types" :branches="options.branches" :departments="options.departments" :locations="options.locations" @done="bulkDone" @cancel="dialog = null" />
            </DialogContent>
        </Dialog>

        <Dialog :open="dialog === 'end'" @update:open="(v: boolean) => { if (!v) dialog = null }">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader><DialogTitle>{{ $t('attendance.assignment.end') }}</DialogTitle><DialogDescription>{{ $t('attendance.assignment.end_description') }}</DialogDescription></DialogHeader>
                <FormField :label="$t('attendance.common.to_date')" required><Input v-model="endDate" type="date" /></FormField>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="dialog = null">{{ $t('common.actions.cancel') }}</Button>
                    <Button type="button" :disabled="!endDate" @click="saveEnd">{{ $t('common.actions.save') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('attendance.assignment.cancel')" :destructive="true" @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="cancel" />
    </AppLayout>
</template>
