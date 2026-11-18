<script setup lang="ts">
import { computed } from 'vue';

/** Draf / Belum Disahkan / Disahkan badge of a statutory rate set (plus the dev-only placeholder flag). */
const props = defineProps<{ status: string; label: string; placeholder?: boolean }>();

const tone = computed(() => {
    switch (props.status) {
        case 'verified':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
        case 'unverified':
            return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200';
        default:
            return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
    }
});
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="tone" :data-status="status">{{ label }}</span>
        <span v-if="placeholder" class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-200">{{ $t('statutory.rates.placeholder_badge') }}</span>
    </span>
</template>
