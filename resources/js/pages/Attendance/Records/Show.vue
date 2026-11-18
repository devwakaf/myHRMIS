<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import DetailList from '@/components/app/DetailList.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import CheckinTable from '@/components/attendance/CheckinTable.vue';
import StatusChip from '@/components/attendance/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { hhmm, hoursMinutes } from '@/lib/attendance';
import type { AttendanceRow, CheckinRow } from '@/lib/attendance';

const props = defineProps<{
    record: AttendanceRow;
    history: AttendanceRow[];
    superseded_by: number | null;
    checkins: CheckinRow[];
    can: { manage: boolean };
}>();

const cancelOpen = ref(false);

function cancel(reason: string) {
    cancelOpen.value = false;
    router.post(`/attendance/records/${props.record.id}/cancel`, { reason }, { preserveScroll: true });
}

function reprocess() {
    router.post('/attendance/records/reprocess-date', { employee_id: props.record.employee_id, date: props.record.attendance_date }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('attendance.pages.record_show')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.records', href: '/attendance/records' }, { title: 'attendance.pages.record_show', href: `/attendance/records/${record.id}` }]">
        <div class="space-y-6 p-4 md:p-6">
            <PageHeader :title="`${record.employee?.full_name ?? ''} · ${formatDate(record.attendance_date)}`" :description="record.employee?.employee_no">
                <template #meta>
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusChip :status="record.attendance_status" :label="record.attendance_status_label" />
                        <span class="text-xs text-muted-foreground">{{ record.status_label }}</span>
                        <Link v-if="superseded_by" :href="`/attendance/records/${superseded_by}`" class="text-xs text-primary hover:underline">{{ $t('attendance.record.superseded_by') }}</Link>
                    </div>
                </template>
                <template #actions>
                    <Button v-if="can.manage" variant="outline" @click="reprocess">{{ $t('attendance.record.reprocess_date') }}</Button>
                    <Button v-if="can.manage && record.status === 'submitted'" variant="outline" as-child><Link :href="`/attendance/bulk-mark?date=${record.attendance_date}`">{{ $t('attendance.record.change') }}</Link></Button>
                    <Button v-if="can.manage && record.status === 'submitted'" variant="destructive" @click="cancelOpen = true">{{ $t('common.actions.cancel_doc') }}</Button>
                    <Button variant="ghost" as-child><Link href="/attendance/records">{{ $t('common.actions.back') }}</Link></Button>
                </template>
            </PageHeader>

            <DetailList
                :items="[
                    { label: $t('attendance.common.shift'), value: record.shift ? `${record.shift.code} · ${record.shift.time}` : null },
                    { label: $t('attendance.me.scheduled'), value: `${hhmm(record.scheduled_start_at)} – ${hhmm(record.scheduled_end_at)}` },
                    { label: $t('attendance.me.actual'), value: `${hhmm(record.in_at)} – ${hhmm(record.out_at)}` },
                    { label: $t('attendance.me.worked'), value: hoursMinutes(record.worked_minutes) },
                    { label: $t('attendance.claim.day_type'), value: record.day_type_label },
                    { label: $t('attendance.common.source'), value: record.source_label },
                    { label: $t('attendance.me.late'), value: record.is_late ? `${record.late_minutes} ${$t('attendance.common.min')}` : null },
                    { label: $t('attendance.me.early'), value: record.is_early_exit ? `${record.early_exit_minutes} ${$t('attendance.common.min')}` : null },
                    { label: $t('attendance.me.ot_detected'), value: record.ot_detected_minutes > 0 ? hoursMinutes(record.ot_detected_minutes) : null },
                    { label: $t('attendance.record.remarks'), value: record.remarks },
                    { label: $t('attendance.record.cancel_reason'), value: record.cancel_reason, hidden: !record.cancel_reason },
                    { label: $t('attendance.me.flagged'), value: record.review_flag ? $t(`attendance.enums.review_reason.${record.review_flag}`) : null, hidden: !record.review_flag },
                ]"
            />
            <p v-if="record.leave_application_id"><Link :href="`/leave/applications/${record.leave_application_id}`" class="text-sm text-primary hover:underline">{{ $t('attendance.me.linked_leave') }}</Link></p>
            <p v-if="record.attendance_request_id"><Link :href="`/attendance-requests/${record.attendance_request_id}`" class="text-sm text-primary hover:underline">{{ $t('attendance.record.linked_request') }}</Link></p>

            <section class="space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.me.punches') }}</h2>
                <CheckinTable :checkins="checkins" :show-coordinates="checkins.some((c) => c.latitude !== null)" />
            </section>

            <section v-if="history.length" class="space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.record.history') }}</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm" data-testid="history-table">
                        <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">#</th><th class="px-3 py-2">{{ $t('common.fields.status') }}</th><th class="px-3 py-2">{{ $t('attendance.common.source') }}</th><th class="px-3 py-2">{{ $t('attendance.me.actual') }}</th><th class="px-3 py-2">{{ $t('attendance.record.remarks') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="h in history" :key="h.id" class="border-t">
                                <td class="px-3 py-1.5"><Link :href="`/attendance/records/${h.id}`" class="text-primary hover:underline">#{{ h.id }}</Link></td>
                                <td class="px-3 py-1.5">{{ h.attendance_status_label }} <span class="text-xs text-muted-foreground">({{ h.status_label }})</span></td>
                                <td class="px-3 py-1.5">{{ h.source_label }}</td>
                                <td class="px-3 py-1.5 tabular-nums">{{ hhmm(h.in_at) }} – {{ hhmm(h.out_at) }}</td>
                                <td class="px-3 py-1.5 text-xs text-muted-foreground">{{ h.remarks ?? h.cancel_reason }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <ReasonDialog :open="cancelOpen" :title="$t('common.actions.cancel_doc')" :destructive="true" @update:open="(v: boolean) => (cancelOpen = v)" @confirm="cancel" />
    </AppLayout>
</template>
