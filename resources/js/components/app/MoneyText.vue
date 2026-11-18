<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/composables/useFormat';

/** "RM 1,234.56" (spec-00 A3). Amounts arrive as decimal strings; nothing is computed here. */
const props = defineProps<{ value: string | number | null | undefined; strong?: boolean; muted?: boolean }>();
const text = computed(() => formatMoney(props.value));
const negative = computed(() => String(props.value ?? '').trim().startsWith('-'));
</script>

<template>
    <span class="tabular-nums whitespace-nowrap" :class="[negative ? 'text-red-600 dark:text-red-400' : '', strong ? 'font-semibold' : '', muted ? 'text-muted-foreground' : '']">{{ text }}</span>
</template>
