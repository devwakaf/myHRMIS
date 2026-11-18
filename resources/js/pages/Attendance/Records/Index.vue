<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Play } from 'lucide-vue-next';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusChip from '@/components/attendance/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { hhmm, hoursMinutes } from '@/lib/attendance';
import type { AttendanceRow } from '@/lib/attendance';
import type { Option, Paginated } from '@/types';

const props = defineProps<{
    records: Paginated<AttendanceRow>;
    filters: Record<string, string | undefined>;
    options: { employees: { id: number; full_name: string; employee_no: string }[]; branches: { id: number; name: string }[]; departments: { id: number; name: string }[]; attendance_statuses: Option<string>[] };
    can: { manage: boolean };
}>();

const f = ref({
    employee_id: props.filters.employee_id ?? '',
    attendance_status: props.filters.attendance_status ?? '',
    status: props.filters.status ?? 'submitted',
    department_id: props.filters.department_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const processing = ref(false);

function apply() {
    router.get('/attendance/records', Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
}

function processNow() {
    processing.value = true;
    router.post('/attendance/process-now', {}, { preserveScroll: true, onFinish: () => (processing.value = false) });
}
</script>

<template>
    <Head :title="$t('attendance.pages.records')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.records', href: '/attendance/records' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.records')" :description="$t('attendance.record.description')">
                <template v-if="can.manage" #actions>
                    <Button variant="outline" :disabled="processing" data-testid="process-now" @click="processNow"><Play class="size-4" />{{ $t('attendance.record.process_now') }}</Button>
                    <Button as-child><Link href="/attendance/bulk-mark">{{ $t('attendance.pages.bulk_mark') }}</Link></Button>
                </template>
            </PageHeader>

            <form class="mb-4 grid gap-2 sm:grid-cols-4 lg:grid-cols-7" @submit.prevent="apply">
                <SelectInput v-model="f.employee_id" class="lg:col-span-2" :options="options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` }))" :placeholder="$t('attendance.common.all_employees')" />
                <SelectInput v-model="f.department_id" :options="options.departments.map((d) => ({ value: d.id, label: d.name }))" :placeholder="$t('attendance.common.all_departments')" />
                <SelectInput v-model="f.attendance_status" :options="options.attendance_statuses" :placeholder="$t('attendance.common.all_statuses')" />
                <SelectInput v-model="f.status" :nullable="false" :options="['submitted', 'superseded', 'cancelled'].map((s) => ({ value: s, label: $t(`attendance.enums.record_status.${s}`) }))" />
                <Input v-model="f.from" type="date" :aria-label="$t('attendance.common.from_date')" />
                <Input v-model="f.to" type="date" :aria-label="$t('attendance.common.to_date')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="records-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('attendance.common.date') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.shift') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.me.in') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.me.out') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('attendance.me.worked') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.source') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.me.flags') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in records.data" :key="r.id" class="border-t">
                            <td class="px-3 py-1.5"><Link :href="`/attendance/records/${r.id}`" class="text-primary hover:underline">{{ formatDate(r.attendance_date) }}</Link></td>
                            <td class="px-3 py-1.5">{{ r.employee?.full_name }} <span class="text-xs text-muted-foreground">{{ r.employee?.employee_no }}</span></td>
                            <td class="px-3 py-1.5">{{ r.shift?.code ?? '—' }}</td>
                            <td class="px-3 py-1.5"><StatusChip :status="r.attendance_status" :label="r.attendance_status_label" /></td>
                            <td class="px-3 py-1.5 tabular-nums">{{ hhmm(r.in_at) }}</td>
                            <td class="px-3 py-1.5 tabular-nums">{{ hhmm(r.out_at) }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ hoursMinutes(r.worked_minutes) }}</td>
                            <td class="px-3 py-1.5">{{ r.source_label }}</td>
                            <td class="px-3 py-1.5 text-xs">
                                <span v-if="r.is_late">{{ $t('attendance.me.late') }} {{ r.late_minutes }}m </span>
                                <span v-if="r.is_early_exit">{{ $t('attendance.me.early') }} {{ r.early_exit_minutes }}m </span>
                                <span v-if="r.review_flag" class="text-amber-700 dark:text-amber-300">{{ $t('attendance.me.flagged') }}</span>
                            </td>
                        </tr>
                        <tr v-if="!records.data.length"><td colspan="9" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="records.links" :from="records.from" :to="records.to" :total="records.total" />
        </div>
    </AppLayout>
</template>
