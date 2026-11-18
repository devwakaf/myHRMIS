<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/appearance';
import type { BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'auth.settings.appearance_title',
        href: edit(),
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="$t('auth.settings.appearance_title')" />

        <h1 class="sr-only">{{ $t('auth.settings.appearance_title') }}</h1>

        <SettingsLayout>
            <div class="space-y-6">
                <Heading
                    variant="small"
                    :title="$t('auth.settings.appearance_title')"
                    :description="$t('auth.settings.appearance_description')"
                />
                <AppearanceTabs />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
