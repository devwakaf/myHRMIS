<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';
import type { Option, Paginated } from '@/types';

type Request = {
    id: number;
    number: string | null;
    employee: string | null;
    work_from: string;
    work_to: string;
    half_day_date: string | null;
    reason: string;
    days_claimed: string;
    attendance_unverified: boolean;
    status: string;
    status_label: string;
};

const props = defineProps<{
    requests: Paginated<Request>;
    options: { types: Option<number>[]; employees: { id: number; full_name: string; employee_no: string }[] };
    can: { request: boolean };
}>();

const open = ref(false);
const employeeOptions = computed(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` })));
const form = useForm({ employee_id: null as number | null, leave_type_id: (props.options.types[0]?.value ?? null) as number | null, work_from: '', work_to: '', half_day_date: '', reason: '' });
const cancelTarget = ref<Request | null>(null);

function save(submit: boolean) {
    form.transform((d) => ({ ...d, submit, work_to: d.work_to || d.work_from, half_day_date: d.half_day_date || null }))
        .post('/leave/compensatory-requests', { preserveScroll: true, onSuccess: () => { open.value = false; form.reset('work_from', 'work_to', 'half_day_date', 'reason'); } });
}

function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/leave/compensatory-requests/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}
</script>

<template>
    <Head :title="$t('leave.pages.compensatory')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.compensatory', href: '/leave/compensatory-requests' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.compensatory')" :description="$t('leave.compensatory.description')">
                <template #actions>
                    <Button v-if="can.request" @click="open = true"><Plus class="size-4" />{{ $t('leave.compensatory.new') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.work_period') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.fields.days_claimed') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.reason') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in requests.data" :key="r.id" class="border-t">
                            <td class="px-3 py-2 font-mono">{{ r.number ?? `#${r.id}` }}</td>
                            <td class="px-3 py-2">{{ r.employee }}</td>
                            <td class="px-3 py-2">{{ formatDate(r.work_from) }} – {{ formatDate(r.work_to) }}</td>
                            <td class="px-3 py-2 text-right">{{ days(r.days_claimed) }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ r.reason }}<span v-if="r.attendance_unverified" class="ml-1 text-xs text-amber-700 dark:text-amber-300">({{ $t('leave.compensatory.unverified') }})</span></td>
                            <td class="px-3 py-2"><StatusBadge :status="r.status" :label="r.status_label" /></td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <Button v-if="r.status === 'draft'" size="sm" @click="router.post(`/leave/compensatory-requests/${r.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                <Button v-if="r.status === 'draft'" size="sm" variant="outline" @click="router.delete(`/leave/compensatory-requests/${r.id}`, { preserveScroll: true })">{{ $t('common.actions.delete') }}</Button>
                                <Button v-if="r.status === 'pending' || r.status === 'approved'" size="sm" variant="outline" @click="cancelTarget = r">{{ $t('common.actions.cancel_doc') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!requests.data.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="requests.links" :from="requests.from" :to="requests.to" :total="requests.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('leave.compensatory.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save(true)">
                    <FormField v-if="options.employees.length" :label="$t('leave.fields.employee')" :error="form.errors.employee_id" :hint="$t('leave.apply.on_behalf_hint')">
                        <SelectInput v-model="form.employee_id" :options="employeeOptions" :placeholder="$t('leave.apply.myself')" />
                    </FormField>
                    <FormField :label="$t('leave.fields.leave_type')" :error="form.errors.leave_type_id" required><SelectInput v-model="form.leave_type_id" :options="options.types" :nullable="false" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('leave.compensatory.work_from')" :error="form.errors.work_from" required><Input v-model="form.work_from" type="date" /></FormField>
                        <FormField :label="$t('leave.compensatory.work_to')" :error="form.errors.work_to"><Input v-model="form.work_to" type="date" :min="form.work_from" /></FormField>
                    </div>
                    <FormField :label="$t('leave.compensatory.half_day_date')" :error="form.errors.half_day_date" :hint="$t('leave.compensatory.half_day_hint')"><Input v-model="form.half_day_date" type="date" /></FormField>
                    <FormField :label="$t('common.fields.reason')" :error="form.errors.reason" required><TextArea v-model="form.reason" :rows="2" /></FormField>
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
