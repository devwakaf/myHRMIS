<script setup lang="ts">
import { TriangleAlert } from 'lucide-vue-next';

/** Amber warning shown wherever unverified statutory rates or file layouts are used. */
defineProps<{ title?: string; message?: string }>();
</script>

<template>
    <div class="mb-4 flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100" role="alert" data-testid="unverified-banner">
        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
        <div class="flex-1">
            <p v-if="title" class="font-medium">{{ title }}</p>
            <p>{{ message ?? $t('statutory.messages.unverified_banner') }}</p>
            <slot />
        </div>
    </div>
</template>
