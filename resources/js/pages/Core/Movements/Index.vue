<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option, Paginated } from '@/types';

type Row = { id: number; number: string; employee: string | null; employee_no: string | null; type_label: string; effective_date: string; status: string; status_label: string; applied_at: string | null };

const props = defineProps<{
    movements: Paginated<Row>;
    filters: { type: string; status: string };
    options: { types: Option<string>[]; statuses: Option<string>[] };
    canCreate: boolean;
}>();

const filters = ref({ ...props.filters });
function apply() {
    router.get('/employee-movements', filters.value, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="$t('common.nav.movements')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.movements', href: '/employee-movements' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.movements')" :description="$t('core.movement.description')">
                <template #actions>
                    <Button v-if="canCreate" as-child><Link href="/employee-movements/create"><Plus class="size-4" />{{ $t('core.movement.create') }}</Link></Button>
                </template>
            </PageHeader>
            <form class="mb-4 flex flex-wrap gap-2" @submit.prevent="apply">
                <SelectInput v-model="filters.type" class="max-w-56" :options="options.types" :placeholder="$t('core.movement.all_types')" />
                <SelectInput v-model="filters.status" class="max-w-48" :options="options.statuses" :placeholder="$t('core.holiday_assignment.all_statuses')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('core.movement.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('core.movement.type') }}</th>
                            <th class="px-3 py-2">{{ $t('validation.attributes.effective_date') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2">{{ $t('core.movement.applied') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in movements.data" :key="m.id" class="border-t">
                            <td class="px-3 py-2 font-mono"><Link :href="`/employee-movements/${m.id}`" class="hover:underline">{{ m.number }}</Link></td>
                            <td class="px-3 py-2">{{ m.employee }} <span class="text-muted-foreground">{{ m.employee_no }}</span></td>
                            <td class="px-3 py-2">{{ m.type_label }}</td>
                            <td class="px-3 py-2">{{ formatDate(m.effective_date) }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="m.status" :label="m.status_label" /></td>
                            <td class="px-3 py-2">{{ m.applied_at ? $t('common.yes') : '—' }}</td>
                        </tr>
                        <tr v-if="!movements.data.length"><td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="movements.links" :from="movements.from" :to="movements.to" :total="movements.total" />
        </div>
    </AppLayout>
</template>
