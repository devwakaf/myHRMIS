<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { Languages } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const locale = computed(() => page.props.locale ?? 'ms');

function toggle() {
    const next = locale.value === 'ms' ? 'en' : 'ms';
    router.put('/locale', { locale: next }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => loadLanguageAsync(next),
    });
}
</script>

<template>
    <button
        type="button"
        class="inline-flex h-8 items-center gap-1 rounded-md border border-input px-2 text-xs font-medium hover:bg-accent"
        :title="$t('common.nav.language')"
        data-test="locale-toggle"
        @click="toggle"
    >
        <Languages class="size-4" />
        <span :class="locale === 'ms' ? 'font-bold' : 'text-muted-foreground'">BM</span>
        <span class="text-muted-foreground">/</span>
        <span :class="locale === 'en' ? 'font-bold' : 'text-muted-foreground'">EN</span>
    </button>
</template>
