<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{
    organisations: {
        id: number;
        name: string;
        short_code: string;
        state: string;
        kwsp_employer_no: string | null;
        perkeso_employer_code: string | null;
        lhdn_employer_no: string | null;
        is_active: boolean;
    }[];
    canManage: boolean;
}>();
</script>

<template>
    <Head :title="$t('common.nav.organisations')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.organisations', href: '/organisations' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.organisations')" :description="$t('core.organisation.description')">
                <template #actions>
                    <Button v-if="canManage" as-child>
                        <Link href="/organisations/create"><Plus class="size-4" />{{ $t('core.organisation.create') }}</Link>
                    </Button>
                </template>
            </PageHeader>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('core.organisation.fields.short_code') }}</th>
                            <th class="px-3 py-2">{{ $t('core.organisation.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t('core.organisation.fields.state') }}</th>
                            <th class="px-3 py-2">{{ $t('core.organisation.fields.kwsp_employer_no') }}</th>
                            <th class="px-3 py-2">{{ $t('core.organisation.fields.perkeso_employer_code') }}</th>
                            <th class="px-3 py-2">{{ $t('core.organisation.fields.lhdn_employer_no') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in organisations" :key="o.id" class="border-t">
                            <td class="px-3 py-2 font-mono">{{ o.short_code }}</td>
                            <td class="px-3 py-2">
                                <Link :href="`/organisations/${o.id}/edit`" class="font-medium hover:underline">{{ o.name }}</Link>
                            </td>
                            <td class="px-3 py-2">{{ o.state }}</td>
                            <td class="px-3 py-2">{{ o.kwsp_employer_no ?? '—' }}</td>
                            <td class="px-3 py-2">{{ o.perkeso_employer_code ?? '—' }}</td>
                            <td class="px-3 py-2">{{ o.lhdn_employer_no ?? '—' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="o.is_active ? 'active' : 'left'" :label="o.is_active ? $t('common.active') : $t('common.inactive')" /></td>
                        </tr>
                        <tr v-if="!organisations.length">
                            <td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
