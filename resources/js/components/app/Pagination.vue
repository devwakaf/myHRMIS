<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
    from?: number | null;
    to?: number | null;
    total?: number;
}>();

function label(raw: string): string {
    if (raw.includes('&laquo;') || raw.toLowerCase().includes('previous') || raw.includes('Sebelumnya')) {
        return '‹';
    }
    if (raw.includes('&raquo;') || raw.toLowerCase().includes('next') || raw.includes('Seterusnya')) {
        return '›';
    }

    return raw;
}
</script>

<template>
    <div v-if="links.length > 3 || total" class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm">
        <p v-if="total !== undefined" class="text-muted-foreground">
            {{ $t('common.pagination.showing', { from: String(from ?? 0), to: String(to ?? 0), total: String(total) }) }}
        </p>
        <nav v-if="links.length > 3" class="flex flex-wrap gap-1">
            <template v-for="(link, i) in links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-2.5 py-1"
                    :class="link.active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent'"
                >{{ label(link.label) }}</Link>
                <span v-else class="rounded-md border px-2.5 py-1 text-muted-foreground">{{ label(link.label) }}</span>
            </template>
        </nav>
    </div>
</template>
