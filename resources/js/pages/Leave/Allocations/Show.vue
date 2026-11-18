<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import DetailList from '@/components/app/DetailList.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';

type Lot = { id: number; kind: string; kind_label: string; days: string; remaining: string; date_from: string; date_to: string };
type Movement = { id: number; kind: string; kind_label: string; days: string; lot_id: number | null; posted_on: string; reverses_movement_id: number | null; note: string | null; date_from: string; date_to: string };
type Schedule = { id: number; due_date: string; kind: string; days: string; status: string; status_label: string; failure_reason: string | null };
type Allocation = {
    id: number;
    number: string | null;
    employee: string | null;
    leave_type: string | null;
    period: string | null;
    from_date: string;
    to_date: string;
    source_label: string;
    entitlement_days: string;
    initial_days: string;
    carry_processed_at: string | null;
    status: string;
    status_label: string;
    remarks: string | null;
    lots: Lot[];
    ledger: Movement[];
    credit_schedules: Schedule[];
};

const props = defineProps<{ allocation: Allocation }>();
const a = props.allocation;
const cancelOpen = ref(false);

function post(path: string, data: Record<string, string> = {}) {
    router.post(`/leave/allocations/${a.id}/${path}`, data, { preserveScroll: true });
}

function confirmCancel(reason: string) {
    post('cancel', { reason });
    cancelOpen.value = false;
}

function retry(s: Schedule) {
    router.post(`/leave/credit-schedules/${s.id}/retry`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${$t('leave.pages.allocation')} ${a.number ?? ''}`" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.allocations', href: '/leave/allocations' }, { title: 'leave.pages.allocation', href: `/leave/allocations/${a.id}` }]">
        <div class="space-y-6 p-4 md:p-6">
            <PageHeader :title="`${a.leave_type} · ${a.employee}`" :description="`${a.number ?? '#' + a.id} · ${a.period ?? ''}`">
                <template #meta><StatusBadge :status="a.status" :label="a.status_label" /></template>
                <template #actions>
                    <Button v-if="a.status === 'draft'" @click="post('submit')">{{ $t('common.actions.submit') }}</Button>
                    <Button v-if="a.status === 'submitted'" variant="outline" @click="post('recompute-carry')">{{ $t('leave.allocations.recompute_carry') }}</Button>
                    <Button v-if="a.status === 'submitted'" variant="outline" @click="cancelOpen = true">{{ $t('common.actions.cancel_doc') }}</Button>
                </template>
            </PageHeader>

            <div class="rounded-lg border p-4">
                <DetailList
                    :items="[
                        { label: $t('leave.fields.from_date'), value: formatDate(a.from_date) },
                        { label: $t('leave.fields.to_date'), value: formatDate(a.to_date) },
                        { label: $t('leave.allocations.source'), value: a.source_label },
                        { label: $t('leave.allocations.entitlement'), value: days(a.entitlement_days) },
                        { label: $t('leave.allocations.initial_days'), value: days(a.initial_days) },
                        { label: $t('leave.allocations.carry_processed'), value: formatDateTime(a.carry_processed_at) },
                        { label: $t('common.fields.remarks'), value: a.remarks },
                    ]"
                />
            </div>

            <section>
                <h2 class="mb-2 text-sm font-medium">{{ $t('leave.allocations.lots') }}</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-3 py-2">{{ $t('leave.ledger.kind') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('leave.fields.days') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('leave.allocations.remaining') }}</th>
                                <th class="px-3 py-2">{{ $t('leave.allocations.valid_until') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in a.lots" :key="l.id" class="border-t">
                                <td class="px-3 py-2">{{ l.kind_label }}</td>
                                <td class="px-3 py-2 text-right">{{ days(l.days) }}</td>
                                <td class="px-3 py-2 text-right font-medium">{{ days(l.remaining) }}</td>
                                <td class="px-3 py-2">{{ formatDate(l.date_to) }}</td>
                            </tr>
                            <tr v-if="!a.lots.length"><td colspan="4" class="px-3 py-4 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="a.credit_schedules.length">
                <h2 class="mb-2 text-sm font-medium">{{ $t('leave.pages.credit_schedules') }}</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="s in a.credit_schedules" :key="s.id" class="border-t first:border-t-0">
                                <td class="px-3 py-2">{{ formatDate(s.due_date) }}</td>
                                <td class="px-3 py-2">{{ $t(`leave.enums.credit_schedule_kind.${s.kind}`) }}</td>
                                <td class="px-3 py-2 text-right">{{ days(s.days) }}</td>
                                <td class="px-3 py-2"><StatusBadge :status="s.status === 'failed' ? 'rejected' : s.status === 'posted' ? 'approved' : 'pending'" :label="s.status_label" /></td>
                                <td class="px-3 py-2 text-xs text-muted-foreground">{{ s.failure_reason }}</td>
                                <td class="px-3 py-2 text-right"><Button v-if="s.status === 'failed'" size="sm" variant="outline" @click="retry(s)">{{ $t('leave.credits.retry') }}</Button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="mb-2 text-sm font-medium">{{ $t('leave.allocations.ledger') }}</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-3 py-2">{{ $t('leave.ledger.posted_on') }}</th>
                                <th class="px-3 py-2">{{ $t('leave.ledger.kind') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('leave.fields.days') }}</th>
                                <th class="px-3 py-2">{{ $t('leave.ledger.reverses') }}</th>
                                <th class="px-3 py-2">{{ $t('common.fields.note') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in a.ledger" :key="m.id" class="border-t">
                                <td class="px-3 py-2">{{ formatDate(m.posted_on) }}</td>
                                <td class="px-3 py-2">{{ m.kind_label }}</td>
                                <td class="px-3 py-2 text-right" :class="Number(m.days) < 0 ? 'text-red-600' : ''">{{ days(m.days) }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ m.reverses_movement_id ? `#${m.reverses_movement_id}` : '' }}</td>
                                <td class="px-3 py-2 text-xs text-muted-foreground">{{ m.note }}</td>
                            </tr>
                            <tr v-if="!a.ledger.length"><td colspan="5" class="px-3 py-4 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <ReasonDialog :open="cancelOpen" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => (cancelOpen = v)" @confirm="confirmCancel" />
    </AppLayout>
</template>
