<script setup lang="ts">
defineProps<{
    label: string;
    for?: string;
    error?: string;
    required?: boolean;
    hint?: string;
}>();
</script>

<template>
    <div class="grid gap-1.5">
        <label :for="$props.for" class="text-sm font-medium leading-none">
            {{ label }}<span v-if="required" class="ml-0.5 text-red-600" aria-hidden="true">*</span>
        </label>
        <slot />
        <p v-if="hint && !error" class="text-xs text-muted-foreground">{{ hint }}</p>
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>
