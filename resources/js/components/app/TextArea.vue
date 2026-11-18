<script setup lang="ts">
const model = defineModel<string | null | undefined>();

defineProps<{ id?: string; rows?: number; placeholder?: string; disabled?: boolean }>();
</script>

<template>
    <textarea
        :id="id"
        v-model="model"
        :rows="rows ?? 3"
        :placeholder="placeholder"
        :disabled="disabled"
        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30"
    />
</template>
