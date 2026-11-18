<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed, defineComponent, h, ref } from 'vue';
import type { PropType, VNode } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Node = { id: number; name: string; designation: string | null; children: Node[] };

const props = defineProps<{
    report: string;
    reports: string[];
    filters: Record<string, string | string[]>;
    options: Record<string, Option[]>;
    sensitive: boolean;
    columns?: { key: string; label: string }[];
    rows?: Record<string, unknown>[];
    result?: { as_at: string; dimension: string; total: number; rows: { label: string; count: number; percent: string }[]; movement: Record<string, string | number> };
    tree?: Node[];
}>();

const f = ref<Record<string, any>>({ ...props.filters });
if (props.report === 'employees' && !Array.isArray(f.value.columns)) {
    f.value.columns = ['employee_no', 'full_name', 'department', 'designation', 'status', 'date_of_joining'];
}

function apply() {
    router.get(`/reports/core/${props.report}`, f.value as Record<string, string>, { preserveState: true, replace: true });
}

const exportUrl = computed(() => {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(f.value)) {
        if (Array.isArray(v)) v.forEach((x) => params.append(`${k}[]`, String(x)));
        else if (v !== null && v !== '') params.append(k, String(v));
    }

    return `/reports/core/${props.report}/export?${params.toString()}`;
});

const maxCount = computed(() => Math.max(1, ...(props.result?.rows ?? []).map((r) => r.count)));

function cell(row: Record<string, unknown>, key: string): string {
    const value = row[key];
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? '✓' : '✗';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value);

    return String(value);
}

const TreeNode: ReturnType<typeof defineComponent> = defineComponent({
    name: 'TreeNode',
    props: { node: { type: Object as PropType<Node>, required: true } },
    setup(p): () => VNode {
        return () =>
            h('li', { class: 'ml-4 border-l pl-3' }, [
                h(Link, { href: `/employees/${p.node.id}`, class: 'text-sm font-medium hover:underline' }, () => p.node.name),
                h('span', { class: 'ml-2 text-xs text-muted-foreground' }, p.node.designation ?? ''),
                p.node.children.length ? h('ul', { class: 'mt-1 space-y-1' }, p.node.children.map((c) => h(TreeNode, { node: c, key: c.id }))) : null,
            ]);
    },
});
</script>

<template>
    <Head :title="$t(`core.reports.names.${report}`)" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.reports', href: '/reports/core/employees' }, { title: `core.reports.names.${report}`, href: `/reports/core/${report}` }]">
        <div class="p-4 md:p-6">
            <div class="mb-4 flex flex-wrap gap-1 border-b">
                <Link
                    v-for="r in reports"
                    :key="r"
                    :href="`/reports/core/${r}`"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="r === report ? 'border-primary font-medium' : 'border-transparent text-muted-foreground'"
                >{{ $t(`core.reports.names.${r}`) }}</Link>
            </div>

            <PageHeader :title="$t(`core.reports.names.${report}`)" :description="$t(`core.reports.descriptions.${report}`)">
                <template #actions>
                    <template v-if="report !== 'org-chart'">
                        <Button variant="outline" as-child><a :href="exportUrl + '&format=xlsx'"><Download class="size-4" />XLSX</a></Button>
                        <Button variant="outline" as-child><a :href="exportUrl + '&format=csv'"><Download class="size-4" />CSV</a></Button>
                    </template>
                </template>
            </PageHeader>

            <form v-if="report !== 'org-chart'" class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="apply">
                <template v-if="report === 'employees'">
                    <label class="grid gap-1 text-xs">{{ $t('core.reports.as_at') }}<Input v-model="f.as_at" type="date" class="w-44" /></label>
                    <SelectInput v-model="f.department_id" class="w-48" :options="options.departments" :placeholder="$t('core.employee.filters.department')" />
                    <SelectInput v-model="f.branch_id" class="w-48" :options="options.branches" :placeholder="$t('core.employee.filters.branch')" />
                    <SelectInput v-model="f.status" class="w-40" :options="options.statuses" :placeholder="$t('core.employee.filters.status')" />
                </template>
                <template v-if="report === 'analytics'">
                    <label class="grid gap-1 text-xs">{{ $t('core.reports.as_at') }}<Input v-model="f.as_at" type="date" class="w-44" /></label>
                    <label class="grid gap-1 text-xs">{{ $t('core.reports.dimension') }}<SelectInput v-model="f.dimension" class="w-48" :options="options.dimensions" :nullable="false" /></label>
                    <label class="grid gap-1 text-xs">{{ $t('core.reports.from') }}<Input v-model="f.from" type="date" class="w-44" /></label>
                    <label class="grid gap-1 text-xs">{{ $t('core.reports.to') }}<Input v-model="f.to" type="date" class="w-44" /></label>
                </template>
                <template v-if="report === 'birthdays'">
                    <SelectInput v-model="f.month" class="w-44" :nullable="false" :options="Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: $t(`common.months.${i + 1}`) }))" />
                </template>
                <template v-if="report === 'leavers'">
                    <label class="grid gap-1 text-xs">{{ $t('core.reports.from') }}<Input v-model="f.from" type="date" class="w-44" /></label>
                    <label class="grid gap-1 text-xs">{{ $t('core.reports.to') }}<Input v-model="f.to" type="date" class="w-44" /></label>
                </template>
                <template v-if="report === 'upcoming'">
                    <label class="grid gap-1 text-xs">{{ $t('core.reports.days') }}<Input v-model="f.days" type="number" class="w-28" /></label>
                </template>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div v-if="report === 'employees'" class="mb-4 flex flex-wrap gap-3 rounded-lg border p-3 text-xs">
                <label v-for="c in options.columns" :key="String(c.value)" class="flex items-center gap-1">
                    <input v-model="f.columns" type="checkbox" :value="c.value" /> {{ c.label }}
                </label>
            </div>

            <div v-if="columns && rows" class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr><th v-for="c in columns" :key="c.key" class="px-3 py-2 whitespace-nowrap">{{ c.label }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in rows" :key="i" class="border-t">
                            <td v-for="c in columns" :key="c.key" class="px-3 py-1.5">{{ cell(row, c.key) }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="columns.length" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
                <p class="px-3 py-2 text-xs text-muted-foreground">{{ $t('core.reports.row_count', { count: String(rows.length) }) }}</p>
            </div>

            <div v-if="result" class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-lg border p-4 lg:col-span-2">
                    <p class="mb-3 text-sm text-muted-foreground">{{ $t('core.reports.headcount_as_at', { date: formatDate(result.as_at), total: String(result.total) }) }}</p>
                    <div v-for="r in result.rows" :key="r.label" class="mb-2">
                        <div class="flex justify-between text-sm"><span>{{ r.label }}</span><span>{{ r.count }} ({{ r.percent }}%)</span></div>
                        <div class="h-2 rounded bg-muted"><div class="h-2 rounded bg-primary" :style="{ width: `${(r.count / maxCount) * 100}%` }" /></div>
                    </div>
                </div>
                <div class="rounded-lg border p-4 text-sm">
                    <h3 class="mb-2 font-medium">{{ $t('core.reports.movement_title') }}</h3>
                    <p>{{ formatDate(String(result.movement.from)) }} – {{ formatDate(String(result.movement.to)) }}</p>
                    <p>{{ $t('core.reports.joiners') }}: <b>{{ result.movement.joiners }}</b></p>
                    <p>{{ $t('core.reports.leavers') }}: <b>{{ result.movement.leavers }}</b></p>
                    <p>{{ $t('core.reports.average_headcount') }}: {{ result.movement.average_headcount }}</p>
                    <p>{{ $t('core.reports.turnover') }}: <b>{{ result.movement.turnover_percent }}%</b></p>
                </div>
            </div>

            <ul v-if="tree" class="space-y-1">
                <TreeNode v-for="n in tree" :key="n.id" :node="n" />
            </ul>
        </div>
    </AppLayout>
</template>
