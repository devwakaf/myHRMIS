<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import ApplicationTable from '@/components/leave/ApplicationTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ApplicationRow, LeaveTypeRow } from '@/lib/leave';
import type { Option, Paginated } from '@/types';

const props = defineProps<{
    applications: Paginated<ApplicationRow>;
    filters: Record<string, string | undefined>;
    options: { types: LeaveTypeRow[]; statuses: Option<string>[] };
    can: { create_for_others: boolean };
}>();

const f = ref({ status: props.filters.status ?? '', leave_type_id: props.filters.leave_type_id ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const typeOptions = computed(() => props.options.types.map((t) => ({ value: t.id, label: t.display_name })));

function apply() {
    router.get('/leave/applications', { ...f.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="$t('leave.pages.applications')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.applications', href: '/leave/applications' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.applications')" :description="$t('leave.applications.description')">
                <template #actions>
                    <Button v-if="can.create_for_others" as-child><Link href="/leave/applications/create"><Plus class="size-4" />{{ $t('leave.pages.apply') }}</Link></Button>
                </template>
            </PageHeader>

            <form class="mb-4 grid gap-2 sm:grid-cols-5" @submit.prevent="apply">
                <SelectInput v-model="f.status" :options="options.statuses" :placeholder="$t('leave.filters.all_statuses')" />
                <SelectInput v-model="f.leave_type_id" :options="typeOptions" :placeholder="$t('leave.filters.all_types')" />
                <Input v-model="f.from" type="date" :aria-label="$t('leave.fields.from_date')" />
                <Input v-model="f.to" type="date" :aria-label="$t('leave.fields.to_date')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <ApplicationTable :rows="applications.data" show-employee />
            <Pagination :links="applications.links" :from="applications.from" :to="applications.to" :total="applications.total" />
        </div>
    </AppLayout>
</template>
