<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string; label?: string }>();

const tone = computed(() => {
    switch (props.status) {
        case 'active':
        case 'approved':
        case 'submitted':
        case 'opted_in':
        case 'paid':
        case 'verified':
        case 'success':
        case 'published':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
        case 'pending':
        case 'waiting':
        case 'draft':
        case 'suspended':
        case 'unverified':
        case 'warning':
        case 'withheld':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200';
        case 'rejected':
        case 'left':
        case 'cancelled':
        case 'failed':
            return 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200';
        case 'skipped':
        case 'not_required':
        case 'removed':
        case 'unpaid':
        case 'superseded':
            return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
        default:
            return 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200';
    }
});
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="tone">{{ label ?? status }}</span>
</template>
