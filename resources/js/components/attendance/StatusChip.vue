<script setup lang="ts">
import { statusTone } from '@/lib/attendance';

defineProps<{ status: string; label: string }>();
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="statusTone(status)">{{ label }}</span>
</template>
