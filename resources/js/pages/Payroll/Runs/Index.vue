<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import MultiCheck from '@/components/app/MultiCheck.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel, monthStart } from '@/lib/payroll';
import type { Option, Paginated } from '@/types';

type Run = {
    id: number;
    number: string;
    period: string;
    status: string;
    status_label: string;
    processing_state: string;
    processing_label: string;
    employee_count: number;
    gross_total: string;
    net_total: string;
    payment_date: string;
};
type Named = { id: number; code: string; name: string };

const props = defineProps<{
    runs: Paginated<Run>;
    filters: Record<string, string>;
    next_period: string;
    options: { branches: Named[]; departments: Named[]; grades: Named[]; employment_types: Named[] };
    can: { prepare: boolean };
}>();

const status = ref<string | null>(props.filters.status ?? null);
const statusOptions = computed<Option<string>[]>(() => ['draft', 'slips_generated', 'submitted', 'paid', 'cancelled'].map((s) => ({ value: s, label: trans(`payroll.enums.run_status.${s}`) })));

function applyFilter() {
    router.get('/payroll/runs', { status: status.value || undefined }, { preserveState: true, replace: true });
}

const open = ref(false);
const form = useForm({
    period: props.next_period,
    payment_date: '',
    notes: '',
    branch_id: [] as number[],
    department_id: [] as number[],
    grade_id: [] as number[],
    employment_type_id: [] as number[],
});
const opts = (rows: Named[]): Option<number>[] => rows.map((r) => ({ value: r.id, label: r.name }));

function create() {
    form.transform((d) => ({
        period: monthStart(d.period),
        payment_date: d.payment_date || null,
        notes: d.notes || null,
        filters: Object.fromEntries((['branch_id', 'department_id', 'grade_id', 'employment_type_id'] as const).filter((k) => d[k].length > 0).map((k) => [k, d[k]])),
    })).post('/payroll/runs', { onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Head :title="$t('payroll.pages.runs')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.runs', href: '/payroll/runs' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.runs')" :description="$t('payroll.runs.description')">
                <template #actions>
                    <Button v-if="can.prepare" data-testid="run-new" @click="open = true"><Plus class="size-4" />{{ $t('payroll.runs.new') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="applyFilter">
                <div class="w-52"><SelectInput v-model="status" :options="statusOptions" :placeholder="$t('payroll.filters.all_statuses')" /></div>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('payroll.runs.period') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.runs.employees') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.slip.gross') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.slip.net') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.slip.payment_date') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in runs.data" :key="r.id" class="border-t">
                            <td class="px-3 py-2 font-medium">{{ monthLabel(r.period) }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ r.number }}</td>
                            <td class="px-3 py-2">
                                <StatusBadge :status="r.status" :label="r.status_label" />
                                <span v-if="r.processing_state !== 'idle'" class="ml-1 text-xs text-muted-foreground">{{ r.processing_label }}</span>
                            </td>
                            <td class="px-3 py-2 text-right">{{ r.employee_count }}</td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="r.gross_total" /></td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="r.net_total" /></td>
                            <td class="px-3 py-2">{{ formatDate(r.payment_date) }}</td>
                            <td class="px-3 py-2 text-right"><Button size="sm" variant="outline" as-child><Link :href="`/payroll/runs/${r.id}`">{{ $t('common.actions.view') }}</Link></Button></td>
                        </tr>
                        <tr v-if="!runs.data.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="runs.links" :from="runs.from" :to="runs.to" :total="runs.total" />
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ $t('payroll.runs.new') }} — {{ $t('payroll.wizard.steps.setup') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="create">
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.runs.period')" :error="form.errors.period" required><Input v-model="form.period" type="month" /></FormField>
                        <FormField :label="$t('payroll.slip.payment_date')" :error="form.errors.payment_date" :hint="$t('payroll.runs.payment_date_hint')"><Input v-model="form.payment_date" type="date" /></FormField>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ $t('payroll.runs.filters_hint') }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.runs.branches')"><MultiCheck v-model="form.branch_id" :options="opts(options.branches)" /></FormField>
                        <FormField :label="$t('payroll.runs.departments')"><MultiCheck v-model="form.department_id" :options="opts(options.departments)" /></FormField>
                        <FormField :label="$t('payroll.runs.grades')"><MultiCheck v-model="form.grade_id" :options="opts(options.grades)" /></FormField>
                        <FormField :label="$t('payroll.runs.employment_types')"><MultiCheck v-model="form.employment_type_id" :options="opts(options.employment_types)" /></FormField>
                    </div>
                    <FormField :label="$t('common.fields.remarks')" :error="form.errors.notes"><TextArea v-model="form.notes" :rows="2" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing" data-testid="run-create">{{ $t('payroll.runs.create') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
