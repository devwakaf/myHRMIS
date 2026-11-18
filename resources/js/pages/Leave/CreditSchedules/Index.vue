<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';
import type { Paginated } from '@/types';

type Schedule = {
    id: number;
    leave_allocation_id: number;
    employee: string;
    leave_type: string;
    due_date: string;
    kind: string;
    kind_label: string;
    days: string;
    status: string;
    status_label: string;
    failure_reason: string | null;
    posted_via: string | null;
};

const props = defineProps<{ schedules: Paginated<Schedule>; filters: { status?: string } }>();

const status = ref(props.filters.status ?? '');
const statuses = ['pending', 'posted', 'skipped', 'failed'].map((s) => ({ value: s, label: `leave.enums.credit_schedule_status.${s}` }));

function apply() {
    router.get('/leave/credit-schedules', { status: status.value }, { preserveState: true, replace: true });
}

function tone(s: string): string {
    return s === 'failed' ? 'rejected' : s === 'posted' ? 'approved' : s === 'skipped' ? 'skipped' : 'pending';
}
</script>

<template>
    <Head :title="$t('leave.pages.credit_schedules')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.credit_schedules', href: '/leave/credit-schedules' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.credit_schedules')" :description="$t('leave.credits.description')" />

            <form class="mb-4 flex max-w-md gap-2" @submit.prevent="apply">
                <SelectInput v-model="status" :options="statuses.map((s) => ({ value: s.value, label: $t(s.label) }))" :placeholder="$t('leave.filters.all_statuses')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('leave.credits.due_date') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.leave_type') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.ledger.kind') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.fields.days') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.credits.failure') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in schedules.data" :key="s.id" class="border-t">
                            <td class="px-3 py-2">{{ formatDate(s.due_date) }}</td>
                            <td class="px-3 py-2"><Link :href="`/leave/allocations/${s.leave_allocation_id}`" class="text-primary hover:underline">{{ s.employee }}</Link></td>
                            <td class="px-3 py-2">{{ s.leave_type }}</td>
                            <td class="px-3 py-2">{{ s.kind_label }}</td>
                            <td class="px-3 py-2 text-right">{{ days(s.days) }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="tone(s.status)" :label="s.status_label" /></td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">{{ s.failure_reason }}</td>
                            <td class="px-3 py-2 text-right"><Button v-if="s.status === 'failed'" size="sm" @click="router.post(`/leave/credit-schedules/${s.id}/retry`, {}, { preserveScroll: true })">{{ $t('leave.credits.retry') }}</Button></td>
                        </tr>
                        <tr v-if="!schedules.data.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="schedules.links" :from="schedules.from" :to="schedules.to" :total="schedules.total" />
        </div>
    </AppLayout>
</template>
