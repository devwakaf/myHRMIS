<script setup lang="ts">
import type { Option } from '@/types';

/** Small multi-select as a scrollable checkbox list (used for run filters). */
const model = defineModel<number[]>({ default: () => [] });

defineProps<{ options: Option<number>[]; disabled?: boolean }>();

function toggle(value: number, on: boolean) {
    model.value = on ? [...model.value, value] : model.value.filter((v) => v !== value);
}
</script>

<template>
    <div class="max-h-32 overflow-y-auto rounded-md border p-2">
        <label v-for="o in options" :key="o.value" class="flex items-center gap-2 py-0.5 text-sm" :class="disabled ? 'opacity-60' : 'cursor-pointer'">
            <input type="checkbox" class="size-4 accent-primary" :disabled="disabled" :checked="model.includes(o.value)" @change="toggle(o.value, ($event.target as HTMLInputElement).checked)" />
            {{ o.label }}
        </label>
        <p v-if="!options.length" class="text-xs text-muted-foreground">{{ $t('common.empty') }}</p>
    </div>
</template>
