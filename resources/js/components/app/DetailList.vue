<script setup lang="ts">
defineProps<{ items: { label: string; value: string | number | null | undefined; hidden?: boolean }[] }>();
</script>

<template>
    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
        <template v-for="(item, i) in items" :key="i">
            <div v-if="!item.hidden">
                <dt class="text-xs text-muted-foreground">{{ item.label }}</dt>
                <dd class="text-sm">{{ item.value === null || item.value === undefined || item.value === '' ? '—' : item.value }}</dd>
            </div>
        </template>
    </dl>
</template>
