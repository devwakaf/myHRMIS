<script setup lang="ts">
const model = defineModel<boolean>({ default: false });

defineProps<{ label: string; hint?: string; disabled?: boolean; id?: string }>();
</script>

<template>
    <label class="flex items-start gap-2 text-sm" :class="disabled ? 'opacity-60' : 'cursor-pointer'">
        <input :id="id" v-model="model" type="checkbox" :disabled="disabled" class="mt-0.5 size-4 rounded border-input accent-primary" />
        <span>
            {{ label }}
            <span v-if="hint" class="block text-xs text-muted-foreground">{{ hint }}</span>
        </span>
    </label>
</template>
