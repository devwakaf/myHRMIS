<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2 } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const organisations = computed(() => page.props.organisations ?? []);
const current = computed(() => page.props.organisation);

function switchTo(event: Event) {
    const id = Number((event.target as HTMLSelectElement).value);
    if (!id || id === current.value?.id) {
        return;
    }
    router.put('/organisation/current', { organisation_id: id }, { preserveScroll: false });
}
</script>

<template>
    <label v-if="organisations.length" class="flex items-center gap-2 text-sm">
        <Building2 class="size-4 text-muted-foreground" />
        <span class="sr-only">{{ $t('common.nav.switch_organisation') }}</span>
        <select
            class="h-8 max-w-56 truncate rounded-md border border-input bg-background px-2 text-sm"
            :value="current?.id"
            :disabled="organisations.length < 2"
            :title="$t('common.nav.switch_organisation')"
            data-test="org-switcher"
            @change="switchTo"
        >
            <option v-for="org in organisations" :key="org.id" :value="org.id">{{ org.short_code }} · {{ org.name }}</option>
        </select>
    </label>
</template>
