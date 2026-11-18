<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Filter } from 'lucide-vue-next';
import PageHeader from '@/components/app/PageHeader.vue';
import { formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{
    cards: {
        type: string;
        label: string;
        bound: boolean;
        workflow: null | {
            id: number;
            name: string;
            version: number;
            published_at: string | null;
            steps: { sequence: number; name: string; rule_label: string; mode_label: string; has_condition: boolean }[];
        };
    }[];
}>();
</script>

<template>
    <Head :title="$t('common.nav.approval_workflows')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.approval_workflows', href: '/settings/approval-workflows' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.approval_workflows')" :description="$t('approval.workflows.description')" />
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="card in cards"
                    :key="card.type"
                    :href="`/settings/approval-workflows/${card.type}`"
                    class="rounded-xl border p-4 transition hover:bg-accent/40"
                >
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-medium">{{ card.label }}</h2>
                        <span v-if="card.workflow" class="rounded bg-muted px-1.5 text-xs">v{{ card.workflow.version }}</span>
                    </div>
                    <p v-if="!card.bound" class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ $t('approval.workflows.module_pending') }}</p>
                    <template v-if="card.workflow">
                        <p class="text-sm text-muted-foreground">{{ card.workflow.name }}</p>
                        <ol class="mt-3 space-y-1 text-sm">
                            <li v-for="s in card.workflow.steps" :key="s.sequence" class="flex items-center gap-1">
                                <span class="inline-flex size-5 items-center justify-center rounded-full bg-primary/10 text-xs">{{ s.sequence }}</span>
                                {{ s.name }} <span class="text-muted-foreground">· {{ s.rule_label }} · {{ s.mode_label }}</span>
                                <Filter v-if="s.has_condition" class="size-3 text-muted-foreground" :title="$t('approval.workflows.has_condition')" />
                            </li>
                        </ol>
                        <p class="mt-3 text-xs text-muted-foreground">{{ $t('approval.workflows.published_at', { at: formatDateTime(card.workflow.published_at) }) }}</p>
                    </template>
                    <p v-else class="mt-2 text-sm text-red-600">{{ $t('approval.workflows.none') }}</p>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
