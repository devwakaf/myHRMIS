<script setup lang="ts">
const model = defineModel<string>({ required: true });

defineProps<{ tabs: { key: string; label: string; hidden?: boolean }[] }>();
</script>

<template>
    <div class="mb-4 flex flex-wrap gap-1 border-b" role="tablist">
        <template v-for="tab in tabs" :key="tab.key">
            <button
                v-if="!tab.hidden"
                type="button"
                role="tab"
                :aria-selected="model === tab.key"
                class="-mb-px rounded-t-md border-b-2 px-3 py-2 text-sm"
                :class="model === tab.key ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
                @click="model = tab.key"
            >
                {{ tab.label }}
            </button>
        </template>
    </div>
</template>
