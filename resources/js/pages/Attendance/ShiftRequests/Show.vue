<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ApprovalTimeline from '@/components/app/ApprovalTimeline.vue';
import type { TimelineData } from '@/components/app/ApprovalTimeline.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import DecisionContext from '@/components/attendance/DecisionContext.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ShiftRequestRow } from '@/lib/attendance';

const props = defineProps<{
    request: ShiftRequestRow;
    context: Record<string, unknown>;
    timeline: TimelineData | null;
    approval_request_id: number | null;
    can: { update: boolean; withdraw: boolean; cancel: boolean };
}>();

const dialog = ref<null | 'withdraw' | 'cancel'>(null);

function submit() {
    router.post(`/shift-requests/${props.request.id}/submit`, {}, { preserveScroll: true });
}

function destroy() {
    router.delete(`/shift-requests/${props.request.id}`);
}

function confirm(reason: string) {
    const action = dialog.value;
    dialog.value = null;
    if (action) router.post(`/shift-requests/${props.request.id}/${action}`, { reason }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('attendance.pages.shift_request_show')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.shift_requests', href: '/shift-requests' }, { title: 'attendance.pages.shift_request_show', href: `/shift-requests/${request.id}` }]">
        <div class="space-y-6 p-4 md:p-6">
            <PageHeader :title="`${request.shift?.code ?? ''} · ${request.number ?? '#' + request.id}`" :description="request.employee ? `${request.employee.full_name} · ${request.employee.employee_no}` : undefined">
                <template #meta><StatusBadge :status="request.status" :label="request.status_label" /></template>
                <template #actions>
                    <Button v-if="can.update" @click="submit">{{ $t('attendance.common.submit_for_approval') }}</Button>
                    <Button v-if="can.update" variant="outline" @click="destroy">{{ $t('common.actions.delete') }}</Button>
                    <Button v-if="can.withdraw" variant="outline" @click="dialog = 'withdraw'">{{ $t('attendance.common.withdraw') }}</Button>
                    <Button v-if="can.cancel" variant="destructive" @click="dialog = 'cancel'">{{ $t('common.actions.cancel_doc') }}</Button>
                    <Button variant="ghost" as-child><Link href="/shift-requests">{{ $t('common.actions.back') }}</Link></Button>
                </template>
            </PageHeader>

            <section class="max-w-2xl rounded-lg border p-4">
                <h2 class="mb-2 text-sm font-medium">{{ $t('attendance.decision.title') }}</h2>
                <DecisionContext type="shift_request" :context="context" />
            </section>

            <section v-if="timeline" class="space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.common.approval_progress') }}</h2>
                <ApprovalTimeline :timeline="timeline" />
            </section>
        </div>

        <ReasonDialog :open="dialog !== null" :title="dialog === 'cancel' ? $t('common.actions.cancel_doc') : $t('attendance.common.withdraw')" :destructive="true" @update:open="(v: boolean) => { if (!v) dialog = null }" @confirm="confirm" />
    </AppLayout>
</template>
