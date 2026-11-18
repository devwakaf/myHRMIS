<script setup lang="ts">
import type { Option } from '@/types';

const model = defineModel<string | number | null | undefined>();

withDefaults(
    defineProps<{
        options: Option[];
        id?: string;
        placeholder?: string;
        disabled?: boolean;
        nullable?: boolean;
        showInactive?: boolean;
    }>(),
    { nullable: true, showInactive: false },
);

function onChange(event: Event) {
    const raw = (event.target as HTMLSelectElement).value;
    if (raw === '') {
        model.value = null;

        return;
    }
    model.value = /^\d+$/.test(raw) ? Number(raw) : raw;
}
</script>

<template>
    <select
        :id="id"
        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30"
        :value="model ?? ''"
        :disabled="disabled"
        @change="onChange"
    >
        <option v-if="nullable" value="">{{ placeholder ?? $t('common.select_placeholder') }}</option>
        <option
            v-for="option in options.filter((o) => showInactive || o.is_active !== false || o.value === model)"
            :key="String(option.value)"
            :value="option.value"
        >
            {{ option.label }}{{ option.is_active === false ? ` (${$t('common.inactive')})` : '' }}
        </option>
    </select>
</template>
