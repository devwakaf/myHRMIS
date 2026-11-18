<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
import { days } from '@/lib/leave';
import type { Option, Paginated } from '@/types';

type Allocation = {
    id: number;
    number: string | null;
    employee: string | null;
    leave_type: string | null;
    period: string | null;
    from_date: string;
    to_date: string;
    source: string;
    source_label: string;
    entitlement_days: string;
    initial_days: string;
    status: string;
    status_label: string;
};

const props = defineProps<{
    allocations: Paginated<Allocation>;
    filters: Record<string, string | undefined>;
    options: {
        employees: { id: number; full_name: string; employee_no: string }[];
        types: (Option<number> & { has_balance: boolean })[];
        periods: { id: number; name: string }[];
    };
}>();

const f = ref({ employee_id: props.filters.employee_id ?? '', leave_type_id: props.filters.leave_type_id ?? '', leave_period_id: props.filters.leave_period_id ?? '' });
const employeeOptions = computed(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` })));
const typeOptions = computed(() => props.options.types.filter((t) => t.has_balance));
const periodOptions = computed(() => props.options.periods.map((p) => ({ value: p.id, label: p.name })));

function apply() {
    router.get('/leave/allocations', { ...f.value }, { preserveState: true, replace: true });
}

const open = ref(false);
const form = useForm({ employee_id: null as number | null, leave_type_id: null as number | null, leave_period_id: null as number | null, from_date: '', to_date: '', initial_days: '', remarks: '' });

function save(submit: boolean) {
    form.transform((d) => ({ ...d, submit, from_date: d.from_date || null, to_date: d.to_date || null, remarks: d.remarks || null }))
        .post('/leave/allocations', { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });
}

const cancelTarget = ref<Allocation | null>(null);

function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/leave/allocations/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}
</script>

<template>
    <Head :title="$t('leave.pages.allocations')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.allocations', href: '/leave/allocations' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.allocations')" :description="$t('leave.allocations.description')">
                <template #actions>
                    <Button @click="open = true"><Plus class="size-4" />{{ $t('leave.allocations.manual') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 grid gap-2 sm:grid-cols-4" @submit.prevent="apply">
                <SelectInput v-model="f.employee_id" :options="employeeOptions" :placeholder="$t('leave.filters.all_employees')" />
                <SelectInput v-model="f.leave_type_id" :options="options.types" :placeholder="$t('leave.filters.all_types')" />
                <SelectInput v-model="f.leave_period_id" :options="periodOptions" :placeholder="$t('leave.filters.all_periods')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.leave_type') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.period') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.allocations.entitlement') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.allocations.source') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in allocations.data" :key="a.id" class="border-t">
                            <td class="px-3 py-2"><Link :href="`/leave/allocations/${a.id}`" class="font-mono text-primary hover:underline">{{ a.number ?? `#${a.id}` }}</Link></td>
                            <td class="px-3 py-2">{{ a.employee }}</td>
                            <td class="px-3 py-2">{{ a.leave_type }}</td>
                            <td class="px-3 py-2">{{ a.period }} <span class="text-xs text-muted-foreground">{{ formatDate(a.from_date) }} – {{ formatDate(a.to_date) }}</span></td>
                            <td class="px-3 py-2 text-right">{{ days(a.entitlement_days) }}</td>
                            <td class="px-3 py-2">{{ a.source_label }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="a.status" :label="a.status_label" /></td>
                            <td class="px-3 py-2 text-right"><Button v-if="a.status === 'submitted'" size="sm" variant="outline" @click="cancelTarget = a">{{ $t('common.actions.cancel_doc') }}</Button></td>
                        </tr>
                        <tr v-if="!allocations.data.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="allocations.links" :from="allocations.from" :to="allocations.to" :total="allocations.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('leave.allocations.manual') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save(true)">
                    <FormField :label="$t('leave.fields.employee')" :error="form.errors.employee_id" required><SelectInput v-model="form.employee_id" :options="employeeOptions" /></FormField>
                    <FormField :label="$t('leave.fields.leave_type')" :error="form.errors.leave_type_id" required><SelectInput v-model="form.leave_type_id" :options="typeOptions" /></FormField>
                    <FormField :label="$t('leave.fields.period')" :error="form.errors.leave_period_id" required><SelectInput v-model="form.leave_period_id" :options="periodOptions" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('leave.fields.from_date')" :error="form.errors.from_date" :hint="$t('leave.allocations.dates_hint')"><Input v-model="form.from_date" type="date" /></FormField>
                        <FormField :label="$t('leave.fields.to_date')" :error="form.errors.to_date"><Input v-model="form.to_date" type="date" /></FormField>
                    </div>
                    <FormField :label="$t('leave.allocations.initial_days')" :error="form.errors.initial_days" required><Input v-model="form.initial_days" type="number" step="0.5" min="0" /></FormField>
                    <FormField :label="$t('common.fields.remarks')" :error="form.errors.remarks"><Input v-model="form.remarks" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="save(false)">{{ $t('leave.apply.save_draft') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.submit') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
