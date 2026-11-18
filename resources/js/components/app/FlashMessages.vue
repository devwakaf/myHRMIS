<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, TriangleAlert, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage();
const flash = computed(() => page.props.flash);
const dismissed = ref(false);

watch(
    () => page.props.flash,
    () => {
        dismissed.value = false;
    },
    { deep: true },
);

const errorBag = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors ? Object.values(errors) : [];
});
</script>

<template>
    <div v-if="!dismissed" class="space-y-2">
        <div
            v-if="flash?.success"
            class="flex items-start gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
            role="status"
        >
            <CircleCheck class="mt-0.5 size-4 shrink-0" />
            <span class="flex-1">{{ flash.success }}</span>
            <button type="button" class="opacity-60 hover:opacity-100" :aria-label="$t('common.actions.close')" @click="dismissed = true">
                <X class="size-4" />
            </button>
        </div>
        <div
            v-if="flash?.error"
            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100"
            role="alert"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0" />
            <span class="flex-1">{{ flash.error }}</span>
        </div>
        <div
            v-if="errorBag.length && !flash?.error"
            class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100"
            role="alert"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0" />
            <div class="flex-1">
                <p class="font-medium">{{ $t('common.messages.fix_errors') }}</p>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="(message, i) in errorBag.slice(0, 6)" :key="i">{{ message }}</li>
                </ul>
            </div>
        </div>
        <div
            v-if="flash?.warnings?.length"
            class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <ul class="flex-1 space-y-1">
                <li v-for="(warning, i) in flash.warnings" :key="i">{{ warning }}</li>
            </ul>
        </div>
    </div>
</template>
