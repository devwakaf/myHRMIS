<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalTimeline from '@/components/app/ApprovalTimeline.vue';
import type { TimelineData } from '@/components/app/ApprovalTimeline.vue';
import DetailList from '@/components/app/DetailList.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import PlanTable from '@/components/attendance/PlanTable.vue';
import RequestForm from '@/components/attendance/RequestForm.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AttendanceRequestRow, PlanDay } from '@/lib/attendance';

const props = defineProps<{
    request: AttendanceRequestRow;
    preview: PlanDay[];
    timeline: TimelineData | null;
    approval_request_id: number | null;
    can: { update: boolean; withdraw: boolean; cancel: boolean };
}>();

const dialog = ref<null | 'withdraw' | 'cancel' | 'edit'>(null);
const planDays = computed<PlanDay[]>(() => (props.request.days.length ? props.request.days.map((d) => ({ date: d.work_date, outcome: d.outcome, outcome_label: d.outcome_label })) : props.preview));

function submit() {
    router.post(`/attendance-requests/${props.request.id}/submit`, {}, { preserveScroll: true });
}

function destroy() {
    router.delete(`/attendance-requests/${props.request.id}`);
}

function confirm(reason: string) {
    const action = dialog.value;
    dialog.value = null;
    if (action === 'withdraw') router.post(`/attendance-requests/${props.request.id}/withdraw`, { reason }, { preserveScroll: true });
    if (action === 'cancel') router.post(`/attendance-requests/${props.request.id}/cancel`, { reason }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('attendance.pages.request_show')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.requests', href: '/attendance-requests' }, { title: 'attendance.pages.request_show', href: `/attendance-requests/${request.id}` }]">
        <div class="space-y-6 p-4 md:p-6">
            <PageHeader :title="`${request.kind_label} · ${request.number ?? '#' + request.id}`" :description="request.employee ? `${request.employee.full_name} · ${request.employee.employee_no}` : undefined">
                <template #meta><StatusBadge :status="request.status" :label="request.status_label" /></template>
                <template #actions>
                    <Button v-if="can.update" @click="submit">{{ $t('attendance.common.submit_for_approval') }}</Button>
                    <Button v-if="can.update" variant="outline" @click="dialog = 'edit'">{{ $t('common.actions.edit') }}</Button>
                    <Button v-if="can.update" variant="outline" @click="destroy">{{ $t('common.actions.delete') }}</Button>
                    <Button v-if="can.withdraw" variant="outline" @click="dialog = 'withdraw'">{{ $t('attendance.common.withdraw') }}</Button>
                    <Button v-if="can.cancel" variant="destructive" @click="dialog = 'cancel'">{{ $t('common.actions.cancel_doc') }}</Button>
                    <Button variant="ghost" as-child><Link href="/attendance-requests">{{ $t('common.actions.back') }}</Link></Button>
                </template>
            </PageHeader>

            <DetailList
                :items="[
                    { label: $t('attendance.common.from_date'), value: formatDate(request.from_date) },
                    { label: $t('attendance.common.to_date'), value: formatDate(request.to_date) },
                    { label: $t('attendance.request.half_day'), value: request.is_half_day ? `${formatDate(request.half_day_date)} · ${request.half_day_session ? $t(`attendance.enums.half_day_session.${request.half_day_session}`) : ''}` : $t('common.no') },
                    { label: $t('attendance.request.include_rest_days'), value: request.include_rest_days ? $t('common.yes') : $t('common.no') },
                    { label: $t('attendance.request.duty_location'), value: request.duty_location, hidden: request.kind !== 'on_duty' },
                    { label: $t('attendance.request.explanation'), value: request.explanation },
                ]"
            />

            <section v-if="planDays.length" class="space-y-2">
                <h2 class="text-sm font-medium">{{ request.days.length ? $t('attendance.request.outcomes') : $t('attendance.request.preview') }}</h2>
                <PlanTable :days="planDays" />
            </section>

            <section v-if="timeline" class="space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.common.approval_progress') }}</h2>
                <ApprovalTimeline :timeline="timeline" />
            </section>
        </div>

        <ReasonDialog :open="dialog === 'withdraw' || dialog === 'cancel'" :title="dialog === 'cancel' ? $t('common.actions.cancel_doc') : $t('attendance.common.withdraw')" :destructive="true" @update:open="(v: boolean) => { if (!v) dialog = null }" @confirm="confirm" />

        <Dialog :open="dialog === 'edit'" @update:open="(v: boolean) => { if (!v) dialog = null }">
            <DialogContent class="max-h-[92vh] max-w-5xl overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>{{ $t('common.actions.edit') }}</DialogTitle>
                    <DialogDescription>{{ $t('attendance.request.form_description') }}</DialogDescription>
                </DialogHeader>
                <RequestForm
                    :options="{
                        kinds: [{ value: 'wfh', label: $t('attendance.enums.request_kind.wfh') }, { value: 'on_duty', label: $t('attendance.enums.request_kind.on_duty') }],
                        sessions: [{ value: 'am', label: $t('attendance.enums.half_day_session.am') }, { value: 'pm', label: $t('attendance.enums.half_day_session.pm') }],
                        employees: [],
                    }"
                    :can-for-others="false"
                    :request="request"
                    @done="dialog = null"
                    @cancel="dialog = null"
                />
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
