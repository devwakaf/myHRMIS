<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ShiftRequestRow } from '@/lib/attendance';
import type { Paginated } from '@/types';

const props = defineProps<{
    requests: Paginated<ShiftRequestRow>;
    filters: { status?: string };
    options: { shift_types: { id: number; code: string; name: string; start_time: string; end_time: string; colour: string | null }[]; employees: { id: number; full_name: string; employee_no: string }[]; max_days: number };
    own_employee_id: number | null;
    can: { create: boolean; create_for_others: boolean };
}>();

const status = ref(props.filters.status ?? '');
const statuses = ['draft', 'pending', 'approved', 'rejected', 'cancelled'];
const open = ref(false);
const form = useForm({ employee_id: null as number | null, shift_type_id: null as number | null, from_date: '', to_date: '', reason: '' });

const shiftOptions = computed(() => props.options.shift_types.map((s) => ({ value: s.id, label: `${s.code} · ${s.name} (${s.start_time.slice(0, 5)}–${s.end_time.slice(0, 5)})` })));
const employeeOptions = computed(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` })));
const tooLong = computed(() => form.from_date !== '' && form.to_date !== '' && (Date.parse(form.to_date) - Date.parse(form.from_date)) / 86400000 + 1 > props.options.max_days);

onMounted(() => {
    const from = new URLSearchParams(window.location.search).get('from');
    if (from && props.can.create) {
        form.from_date = from;
        form.to_date = from;
        open.value = true;
    }
});

function apply() {
    router.get('/shift-requests', { status: status.value || undefined }, { preserveState: true, replace: true });
}

function send(submit: boolean) {
    form.transform((data) => ({ ...data, submit, to_date: data.to_date || data.from_date, reason: data.reason || null }))
        .post('/shift-requests', { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Head :title="$t('attendance.pages.shift_requests')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.shift_requests', href: '/shift-requests' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.shift_requests')" :description="$t('attendance.shift_request.description', { days: String(options.max_days) })">
                <template #actions>
                    <Button v-if="can.create" @click="open = true"><Plus class="size-4" />{{ $t('attendance.shift_request.new') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap gap-2" @submit.prevent="apply">
                <SelectInput v-model="status" class="w-48" :options="statuses.map((s) => ({ value: s, label: $t(`common.approval_status.${s}`) }))" :placeholder="$t('attendance.common.all_statuses')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="shift-requests-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.shift') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.from_date') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.to_date') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in requests.data" :key="r.id" class="border-t">
                            <td class="px-3 py-2"><Link :href="`/shift-requests/${r.id}`" class="font-mono text-primary hover:underline">{{ r.number ?? `#${r.id}` }}</Link></td>
                            <td class="px-3 py-2">{{ r.employee?.full_name }} <span class="text-xs text-muted-foreground">{{ r.employee?.employee_no }}</span></td>
                            <td class="px-3 py-2">{{ r.shift?.code }} <span class="text-xs text-muted-foreground">{{ r.shift?.time }}</span></td>
                            <td class="px-3 py-2">{{ formatDate(r.from_date) }}</td>
                            <td class="px-3 py-2">{{ formatDate(r.to_date) }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="r.status" :label="r.status_label" /></td>
                        </tr>
                        <tr v-if="!requests.data.length"><td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="requests.links" :from="requests.from" :to="requests.to" :total="requests.total" />
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[92vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ $t('attendance.shift_request.new') }}</DialogTitle>
                    <DialogDescription>{{ $t('attendance.shift_request.form_description') }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="send(true)">
                    <FormField v-if="can.create_for_others" :label="$t('attendance.common.employee')" :error="form.errors.employee_id">
                        <SelectInput v-model="form.employee_id" :options="employeeOptions" :placeholder="$t('attendance.request.myself')" />
                    </FormField>
                    <FormField :label="$t('attendance.shift_request.shift')" :error="form.errors.shift_type_id" required>
                        <SelectInput v-model="form.shift_type_id" :options="shiftOptions" />
                    </FormField>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField :label="$t('attendance.common.from_date')" :error="form.errors.from_date" required><Input v-model="form.from_date" type="date" /></FormField>
                        <FormField :label="$t('attendance.common.to_date')" :error="form.errors.to_date || (tooLong ? $t('attendance.shift_request.too_long', { days: String(options.max_days) }) : undefined)"><Input v-model="form.to_date" type="date" :min="form.from_date" /></FormField>
                    </div>
                    <FormField :label="$t('attendance.shift_request.reason')" :error="form.errors.reason"><TextArea v-model="form.reason" :rows="3" /></FormField>
                    <p class="text-xs text-muted-foreground">{{ $t('attendance.shift_request.route_note') }}</p>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="ghost" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing || !form.shift_type_id || !form.from_date" @click="send(false)">{{ $t('attendance.common.save_draft') }}</Button>
                        <Button type="submit" :disabled="form.processing || !form.shift_type_id || !form.from_date || tooLong">{{ $t('attendance.common.submit_for_approval') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
