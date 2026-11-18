<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';
import type { ApplicationRow } from '@/lib/leave';
import type { Paginated } from '@/types';

type Task = {
    id: number;
    reason: string;
    reason_label: string;
    details: Record<string, unknown> | null;
    resolved_at: string | null;
    resolution_note: string | null;
    application: ApplicationRow;
};

defineProps<{ tasks: Paginated<Task> }>();
const showAll = ref(new URLSearchParams(window.location.search).get('all') === '1');
const target = ref<Task | null>(null);

function toggle() {
    showAll.value = !showAll.value;
    router.get('/leave/review-tasks', showAll.value ? { all: 1 } : {}, { preserveState: true, replace: true });
}

function resolve(note: string) {
    if (target.value) router.post(`/leave/review-tasks/${target.value.id}/resolve`, { note: note || null }, { preserveScroll: true });
    target.value = null;
}

</script>

<template>
    <Head :title="$t('leave.pages.review_tasks')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.review_tasks', href: '/leave/review-tasks' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.review_tasks')" :description="$t('leave.review.description')">
                <template #actions>
                    <Button variant="outline" @click="toggle">{{ showAll ? $t('leave.review.only_open') : $t('leave.review.show_all') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('leave.review.reason') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.pages.application') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.from_date') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.fields.days') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in tasks.data" :key="t.id" class="border-t">
                            <td class="px-3 py-2">{{ t.reason_label }}</td>
                            <td class="px-3 py-2"><Link :href="`/leave/applications/${t.application.id}`" class="font-mono text-primary hover:underline">{{ t.application.number ?? `#${t.application.id}` }}</Link></td>
                            <td class="px-3 py-2">{{ t.application.employee }}</td>
                            <td class="px-3 py-2">{{ formatDate(t.application.from_date) }}</td>
                            <td class="px-3 py-2 text-right">{{ days(t.application.total_days) }}</td>
                            <td class="px-3 py-2">
                                <StatusBadge v-if="t.resolved_at" status="approved" :label="`${$t('leave.review.resolved')} ${formatDateTime(t.resolved_at)}`" />
                                <StatusBadge v-else status="pending" :label="$t('leave.review.open')" />
                                <p v-if="t.resolution_note" class="mt-1 text-xs text-muted-foreground">{{ t.resolution_note }}</p>
                            </td>
                            <td class="px-3 py-2 text-right"><Button v-if="!t.resolved_at" size="sm" @click="target = t">{{ $t('leave.review.resolve') }}</Button></td>
                        </tr>
                        <tr v-if="!tasks.data.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="tasks.links" :from="tasks.from" :to="tasks.to" :total="tasks.total" />
        </div>

        <ReasonDialog :open="target !== null" :title="$t('leave.review.resolve')" :required="false" @update:open="(v: boolean) => { if (!v) target = null }" @confirm="resolve" />
    </AppLayout>
</template>
