<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import CheckinTable from '@/components/attendance/CheckinTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CheckinRow } from '@/lib/attendance';
import type { Option, Paginated } from '@/types';

const props = defineProps<{
    checkins: Paginated<CheckinRow>;
    filters: Record<string, string | undefined>;
    options: { employees: { id: number; full_name: string; employee_no: string }[]; geofence_results: Option<string>[]; processing_states: Option<string>[] };
    can: { void: boolean; view_coordinates: boolean };
}>();

const f = ref({
    employee_id: props.filters.employee_id ?? '',
    geofence_result: props.filters.geofence_result ?? '',
    processing_state: props.filters.processing_state ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const voidTarget = ref<CheckinRow | null>(null);

function apply() {
    router.get('/attendance/checkins', Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
}

function confirmVoid(reason: string) {
    if (!voidTarget.value) return;
    router.post(`/attendance/checkins/${voidTarget.value.id}/void`, { reason }, { preserveScroll: true });
    voidTarget.value = null;
}
</script>

<template>
    <Head :title="$t('attendance.pages.checkins')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.checkins', href: '/attendance/checkins' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.checkins')" :description="can.view_coordinates ? $t('attendance.log.description_coordinates') : $t('attendance.log.description')" />

            <form class="mb-4 grid gap-2 sm:grid-cols-3 lg:grid-cols-6" @submit.prevent="apply">
                <SelectInput v-model="f.employee_id" class="lg:col-span-2" :options="options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` }))" :placeholder="$t('attendance.common.all_employees')" />
                <SelectInput v-model="f.geofence_result" :options="options.geofence_results" :placeholder="$t('attendance.log.all_geofence')" />
                <SelectInput v-model="f.processing_state" :options="options.processing_states" :placeholder="$t('attendance.log.all_states')" />
                <Input v-model="f.from" type="date" :aria-label="$t('attendance.common.from_date')" />
                <Input v-model="f.to" type="date" :aria-label="$t('attendance.common.to_date')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <CheckinTable :checkins="checkins.data" show-employee :show-coordinates="can.view_coordinates">
                <template v-if="can.void" #actions="{ checkin }">
                    <Button v-if="!checkin.voided_at" size="sm" variant="ghost" @click="voidTarget = checkin">{{ $t('attendance.log.void') }}</Button>
                </template>
            </CheckinTable>
            <Pagination :links="checkins.links" :from="checkins.from" :to="checkins.to" :total="checkins.total" />
        </div>

        <ReasonDialog :open="voidTarget !== null" :title="$t('attendance.log.void')" :destructive="true" @update:open="(v: boolean) => { if (!v) voidTarget = null }" @confirm="confirmVoid" />
    </AppLayout>
</template>
