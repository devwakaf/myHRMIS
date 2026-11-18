<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import RequestForm from '@/components/attendance/RequestForm.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AttendanceRequestRow } from '@/lib/attendance';
import type { Option, Paginated } from '@/types';

const props = defineProps<{
    requests: Paginated<AttendanceRequestRow>;
    filters: { status?: string };
    options: { kinds: Option<string>[]; sessions: Option<string>[]; shift_types: { id: number; code: string; name: string }[]; employees: { id: number; full_name: string; employee_no: string }[] };
    own_employee_id: number | null;
    can: { create: boolean; create_for_others: boolean };
}>();

const status = ref(props.filters.status ?? '');
const statuses = computed(() => ['draft', 'pending', 'approved', 'rejected', 'cancelled'].map((s) => ({ value: s, label: `common.approval_status.${s}` })));
const formOpen = ref(false);
const prefill = ref<string | null>(null);

onMounted(() => {
    const from = new URLSearchParams(window.location.search).get('from');
    if (from && props.can.create) {
        prefill.value = from;
        formOpen.value = true;
    }
});

function apply() {
    router.get('/attendance-requests', { status: status.value || undefined }, { preserveState: true, replace: true });
}

function openForm() {
    prefill.value = null;
    formOpen.value = true;
}
</script>

<template>
    <Head :title="$t('attendance.pages.requests')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.requests', href: '/attendance-requests' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.requests')" :description="$t('attendance.request.description')">
                <template #actions>
                    <Button v-if="can.create" @click="openForm"><Plus class="size-4" />{{ $t('attendance.request.new') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap gap-2" @submit.prevent="apply">
                <SelectInput v-model="status" class="w-48" :options="statuses.map((s) => ({ ...s, label: $t(s.label) }))" :placeholder="$t('attendance.common.all_statuses')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="requests-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.request.kind') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.from_date') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.to_date') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in requests.data" :key="r.id" class="border-t">
                            <td class="px-3 py-2"><Link :href="`/attendance-requests/${r.id}`" class="font-mono text-primary hover:underline">{{ r.number ?? `#${r.id}` }}</Link></td>
                            <td class="px-3 py-2">{{ r.employee?.full_name }} <span class="text-xs text-muted-foreground">{{ r.employee?.employee_no }}</span></td>
                            <td class="px-3 py-2">{{ r.kind_label }}<span v-if="r.is_half_day" class="ml-1 text-xs text-muted-foreground">({{ $t('attendance.request.half_day_short') }})</span></td>
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

        <Dialog v-model:open="formOpen">
            <DialogContent class="max-h-[92vh] max-w-5xl overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>{{ $t('attendance.request.new') }}</DialogTitle>
                    <DialogDescription>{{ $t('attendance.request.form_description') }}</DialogDescription>
                </DialogHeader>
                <RequestForm :options="options" :can-for-others="can.create_for_others" :prefill-from="prefill" @done="formOpen = false" @cancel="formOpen = false" />
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
