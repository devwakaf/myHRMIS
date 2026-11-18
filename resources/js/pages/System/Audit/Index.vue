<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Paginated } from '@/types';

type Entry = {
    id: number;
    log_name: string | null;
    description: string;
    event: string | null;
    subject: string;
    causer: string | null;
    properties: Record<string, unknown>;
    created_at: string | null;
};

const props = defineProps<{ entries: Paginated<Entry>; filters: { log: string } }>();
const log = ref(props.filters.log ?? '');

function apply() {
    router.get('/audit', { log: log.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="$t('common.nav.audit')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.audit', href: '/audit' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.audit')" :description="$t('common.audit.description')" />
            <form class="mb-4 flex gap-2" @submit.prevent="apply">
                <Input v-model="log" :placeholder="$t('common.audit.log_filter')" class="max-w-xs" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.audit.when') }}</th>
                            <th class="px-3 py-2">{{ $t('common.audit.who') }}</th>
                            <th class="px-3 py-2">{{ $t('common.audit.module') }}</th>
                            <th class="px-3 py-2">{{ $t('common.audit.event') }}</th>
                            <th class="px-3 py-2">{{ $t('common.audit.subject') }}</th>
                            <th class="px-3 py-2">{{ $t('common.audit.changes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in entries.data" :key="e.id" class="border-t align-top">
                            <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(e.created_at) }}</td>
                            <td class="px-3 py-2">{{ e.causer ?? $t('approval.inbox.system') }}</td>
                            <td class="px-3 py-2">{{ e.log_name }}</td>
                            <td class="px-3 py-2">{{ e.event ?? e.description }}</td>
                            <td class="px-3 py-2">{{ e.subject }}</td>
                            <td class="px-3 py-2">
                                <pre class="max-w-md overflow-x-auto text-xs whitespace-pre-wrap">{{ JSON.stringify(e.properties, null, 1) }}</pre>
                            </td>
                        </tr>
                        <tr v-if="!entries.data.length">
                            <td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="entries.links" :from="entries.from" :to="entries.to" :total="entries.total" />
        </div>
    </AppLayout>
</template>
