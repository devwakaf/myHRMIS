<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
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

type Row = {
    id: number; number: string; holiday_list: string | null; assignable_type: string; assignable_type_label: string; assignable: string;
    effective_from: string; effective_to: string | null; status: string; status_label: string; remarks: string | null; cancel_reason: string | null;
};

const props = defineProps<{
    assignments: Paginated<Row>;
    filters: { level: string; status: string };
    options: Record<string, Option[]>;
    can: { manage: boolean; cancel: boolean };
}>();

const filters = ref({ ...props.filters });
const dialog = ref<null | 'create' | 'bulk'>(null);
const cancelling = ref<{ row: Row; duplicate: boolean } | null>(null);

const form = useForm({ holiday_list_id: null as number | null, assignable_type: 'organisation', assignable_id: null as number | null, effective_from: '', remarks: '', submit_now: true });
const bulkForm = useForm({ holiday_list_id: null as number | null, effective_from: '', branch_id: null as number | null, department_id: null as number | null, employment_type_id: null as number | null, remarks: '' });

function apply() {
    router.get('/holiday-assignments', filters.value, { preserveState: true, replace: true });
}

function confirmCancel(reason: string) {
    if (!cancelling.value) return;
    const { row, duplicate } = cancelling.value;
    router.post(`/holiday-assignments/${row.id}/${duplicate ? 'cancel-duplicate' : 'cancel'}`, { reason }, { preserveScroll: true });
    cancelling.value = null;
}

const opts = { preserveScroll: true, onSuccess: () => (dialog.value = null) };
</script>

<template>
    <Head :title="$t('common.nav.holiday_assignments')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.holiday_assignments', href: '/holiday-assignments' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.holiday_assignments')" :description="$t('core.holiday_assignment.description')">
                <template #actions>
                    <template v-if="can.manage">
                        <Button variant="outline" @click="dialog = 'bulk'">{{ $t('core.holiday_assignment.bulk') }}</Button>
                        <Button @click="dialog = 'create'">{{ $t('common.actions.add') }}</Button>
                    </template>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap gap-2" @submit.prevent="apply">
                <SelectInput v-model="filters.level" class="max-w-48" :options="options.levels" :placeholder="$t('core.holiday_assignment.all_levels')" />
                <SelectInput v-model="filters.status" class="max-w-48" :options="options.statuses" :placeholder="$t('core.holiday_assignment.all_statuses')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday_assignment.list') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday_assignment.level') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday_assignment.assignable') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday_assignment.effective') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in assignments.data" :key="a.id" class="border-t">
                            <td class="px-3 py-2 font-mono">{{ a.number }}</td>
                            <td class="px-3 py-2">{{ a.holiday_list }}</td>
                            <td class="px-3 py-2">{{ a.assignable_type_label }}</td>
                            <td class="px-3 py-2">{{ a.assignable }}</td>
                            <td class="px-3 py-2">{{ formatDate(a.effective_from) }} – {{ formatDate(a.effective_to) || '…' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="a.status" :label="a.status_label" /></td>
                            <td class="space-x-1 px-3 py-2 text-right whitespace-nowrap">
                                <template v-if="a.status === 'draft' && can.manage">
                                    <Button size="sm" @click="router.post(`/holiday-assignments/${a.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                    <Button size="sm" variant="ghost" @click="router.delete(`/holiday-assignments/${a.id}`, { preserveScroll: true })">{{ $t('common.actions.delete') }}</Button>
                                </template>
                                <template v-if="a.status === 'submitted' && can.cancel">
                                    <Button size="sm" variant="outline" @click="cancelling = { row: a, duplicate: false }">{{ $t('common.actions.cancel_doc') }}</Button>
                                    <Button size="sm" variant="outline" @click="cancelling = { row: a, duplicate: true }">{{ $t('common.actions.cancel_duplicate') }}</Button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!assignments.data.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="assignments.links" :from="assignments.from" :to="assignments.to" :total="assignments.total" />
        </div>

        <Dialog :open="dialog !== null" @update:open="(o: boolean) => { if (!o) dialog = null }">
            <DialogContent class="max-w-md">
                <template v-if="dialog === 'create'">
                    <DialogHeader><DialogTitle>{{ $t('common.nav.holiday_assignments') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('core.holiday_assignment.list')" :error="form.errors.holiday_list_id" required><SelectInput v-model="form.holiday_list_id" :options="options.lists" /></FormField>
                        <FormField :label="$t('core.holiday_assignment.level')" :error="form.errors.assignable_type" required><SelectInput v-model="form.assignable_type" :options="options.levels" :nullable="false" /></FormField>
                        <FormField v-if="form.assignable_type === 'branch'" :label="$t('validation.attributes.branch_id')" :error="form.errors.assignable_id" required><SelectInput v-model="form.assignable_id" :options="options.branches" /></FormField>
                        <FormField v-if="form.assignable_type === 'employee'" :label="$t('core.holiday_assignment.employee')" :error="form.errors.assignable_id" required><SelectInput v-model="form.assignable_id" :options="options.employees" /></FormField>
                        <FormField :label="$t('validation.attributes.effective_from')" :error="form.errors.effective_from" required><Input v-model="form.effective_from" type="date" /></FormField>
                        <FormField :label="$t('common.fields.remarks')"><Input v-model="form.remarks" /></FormField>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.submit_now" type="checkbox" /> {{ $t('core.holiday_assignment.submit_now') }}</label>
                    </div>
                    <DialogFooter><Button :disabled="form.processing" @click="form.transform((d) => ({ ...d, submit: d.submit_now })).post('/holiday-assignments', opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>
                <template v-if="dialog === 'bulk'">
                    <DialogHeader><DialogTitle>{{ $t('core.holiday_assignment.bulk') }}</DialogTitle></DialogHeader>
                    <p class="text-xs text-muted-foreground">{{ $t('core.holiday_assignment.bulk_hint') }}</p>
                    <div class="grid gap-3">
                        <FormField :label="$t('core.holiday_assignment.list')" :error="bulkForm.errors.holiday_list_id" required><SelectInput v-model="bulkForm.holiday_list_id" :options="options.lists" /></FormField>
                        <FormField :label="$t('validation.attributes.effective_from')" :error="bulkForm.errors.effective_from" required><Input v-model="bulkForm.effective_from" type="date" /></FormField>
                        <FormField :label="$t('validation.attributes.branch_id')"><SelectInput v-model="bulkForm.branch_id" :options="options.branches" /></FormField>
                        <FormField :label="$t('validation.attributes.department_id')"><SelectInput v-model="bulkForm.department_id" :options="options.departments" /></FormField>
                        <FormField :label="$t('validation.attributes.employment_type_id')"><SelectInput v-model="bulkForm.employment_type_id" :options="options.employment_types" /></FormField>
                    </div>
                    <DialogFooter><Button :disabled="bulkForm.processing" @click="bulkForm.post('/holiday-assignments/bulk', opts)">{{ $t('common.actions.submit') }}</Button></DialogFooter>
                </template>
            </DialogContent>
        </Dialog>

        <ReasonDialog
            :open="cancelling !== null"
            :title="cancelling?.duplicate ? $t('common.actions.cancel_duplicate') : $t('common.actions.cancel_doc')"
            destructive
            @update:open="(o: boolean) => { if (!o) cancelling = null }"
            @confirm="confirmCancel"
        />
    </AppLayout>
</template>
