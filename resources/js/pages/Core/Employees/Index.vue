<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    employee_no: string;
    full_name: string;
    ic_no: string | null;
    passport_no: string | null;
    status: string;
    status_label: string;
    branch: string | null;
    department: string | null;
    designation: string | null;
    grade: string | null;
    employment_type: string | null;
    citizenship: string;
    date_of_joining: string;
    mobile_phone: string;
    work_email: string | null;
};

const props = defineProps<{
    employees: Paginated<Row>;
    filters: Record<string, string | number | null>;
    options: Record<string, Option[]>;
    can: { create: boolean; sensitive: boolean };
}>();

const ALL_COLUMNS = ['employee_no', 'full_name', 'ic_no', 'department', 'designation', 'branch', 'grade', 'employment_type', 'citizenship', 'date_of_joining', 'mobile_phone', 'status'] as const;
type Column = (typeof ALL_COLUMNS)[number];

const storageKey = 'myhrmis.employee-columns';
const initialColumns = (() => {
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) ?? 'null');
        if (Array.isArray(saved)) return saved as Column[];
    } catch {
        // ignore
    }

    return ['employee_no', 'full_name', 'ic_no', 'department', 'designation', 'status'] as Column[];
})();
const columns = ref<Column[]>(initialColumns);
const chooser = ref(false);

function toggleColumn(c: Column) {
    columns.value = columns.value.includes(c) ? columns.value.filter((x) => x !== c) : ALL_COLUMNS.filter((x) => x === c || columns.value.includes(x));
    try {
        localStorage.setItem(storageKey, JSON.stringify(columns.value));
    } catch {
        // ignore
    }
}

const filters = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? null,
    branch_id: props.filters.branch_id ?? null,
    department_id: props.filters.department_id ?? null,
    designation_id: props.filters.designation_id ?? null,
    grade_id: props.filters.grade_id ?? null,
    employment_type_id: props.filters.employment_type_id ?? null,
    citizenship_status: props.filters.citizenship_status ?? null,
});

function clean() {
    return Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== null && v !== ''));
}

function apply() {
    router.get('/employees', clean(), { preserveState: true, replace: true });
}

const exportUrl = computed(() => `/employees/export?${new URLSearchParams(clean() as Record<string, string>).toString()}`);
</script>

<template>
    <Head :title="$t('common.nav.employees')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.employees', href: '/employees' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.employees')" :description="$t('core.employee.list_description')">
                <template #actions>
                    <Button variant="outline" as-child>
                        <a :href="exportUrl"><Download class="size-4" />{{ $t('common.actions.export_xlsx') }}</a>
                    </Button>
                    <Button v-if="can.create" as-child>
                        <Link href="/employees/create"><Plus class="size-4" />{{ $t('core.employee.create') }}</Link>
                    </Button>
                </template>
            </PageHeader>

            <form class="mb-4 grid gap-2 md:grid-cols-4 xl:grid-cols-8" @submit.prevent="apply">
                <Input v-model="filters.search" :placeholder="$t('core.employee.search')" class="md:col-span-2" />
                <SelectInput v-model="filters.status" :options="options.statuses" :placeholder="$t('core.employee.filters.status')" />
                <SelectInput v-model="filters.branch_id" :options="options.branches" :placeholder="$t('core.employee.filters.branch')" />
                <SelectInput v-model="filters.department_id" :options="options.departments" :placeholder="$t('core.employee.filters.department')" />
                <SelectInput v-model="filters.designation_id" :options="options.designations" :placeholder="$t('core.employee.filters.designation')" />
                <SelectInput v-model="filters.grade_id" :options="options.grades" :placeholder="$t('core.employee.filters.grade')" />
                <SelectInput v-model="filters.employment_type_id" :options="options.employment_types" :placeholder="$t('core.employee.filters.employment_type')" />
                <SelectInput v-model="filters.citizenship_status" :options="options.citizenships" :placeholder="$t('core.employee.filters.citizenship')" />
                <div class="flex gap-2">
                    <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
                    <Button type="button" variant="ghost" @click="chooser = !chooser">{{ $t('core.employee.columns_chooser') }}</Button>
                </div>
            </form>

            <div v-if="chooser" class="mb-4 flex flex-wrap gap-3 rounded-lg border p-3 text-sm">
                <label v-for="c in ALL_COLUMNS" :key="c" class="flex items-center gap-1">
                    <input type="checkbox" :checked="columns.includes(c)" @change="toggleColumn(c)" /> {{ $t(`core.employee.columns.${c === 'status' ? 'status_label' : c}`) }}
                </label>
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="c in columns" :key="c" class="px-3 py-2 whitespace-nowrap">{{ $t(`core.employee.columns.${c === 'status' ? 'status_label' : c}`) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in employees.data" :key="e.id" class="border-t hover:bg-accent/40">
                            <td v-for="c in columns" :key="c" class="px-3 py-2">
                                <Link v-if="c === 'full_name'" :href="`/employees/${e.id}`" class="font-medium hover:underline">{{ e.full_name }}</Link>
                                <StatusBadge v-else-if="c === 'status'" :status="e.status" :label="e.status_label" />
                                <span v-else-if="c === 'date_of_joining'">{{ formatDate(e.date_of_joining) }}</span>
                                <span v-else-if="c === 'ic_no'" class="font-mono">{{ e.ic_no ?? e.passport_no ?? '—' }}</span>
                                <span v-else>{{ e[c] ?? '—' }}</span>
                            </td>
                        </tr>
                        <tr v-if="!employees.data.length">
                            <td :colspan="columns.length" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="employees.links" :from="employees.from" :to="employees.to" :total="employees.total" />
        </div>
    </AppLayout>
</template>
