<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type EmployeeCard = Option<number> & { current: Record<string, number | string | null> };

const props = defineProps<{
    movement: Record<string, string | number | null> | null;
    employeeId: number | null;
    employees: EmployeeCard[];
    options: Record<string, Option[]>;
}>();

const m = props.movement ?? {};
const form = useForm({
    employee_id: (m.employee_id as number | null) ?? props.employeeId,
    type: (m.type as string) ?? 'transfer',
    effective_date: (m.effective_date as string) ?? new Date().toISOString().slice(0, 10),
    new_branch_id: (m.new_branch_id as number | null) ?? null,
    new_department_id: (m.new_department_id as number | null) ?? null,
    new_designation_id: (m.new_designation_id as number | null) ?? null,
    new_grade_id: (m.new_grade_id as number | null) ?? null,
    new_employment_type_id: (m.new_employment_type_id as number | null) ?? null,
    new_reports_to_id: (m.new_reports_to_id as number | null) ?? null,
    new_contract_end_date: (m.new_contract_end_date as string) ?? '',
    target_organisation_id: (m.target_organisation_id as number | null) ?? null,
    reason: (m.reason as string) ?? '',
    salary_note: (m.salary_note as string) ?? '',
});

const current = computed(() => props.employees.find((e) => e.value === form.employee_id)?.current ?? null);

const rows = computed(() => [
    { field: 'new_branch_id', cur: 'branch_id', options: props.options.branches },
    { field: 'new_department_id', cur: 'department_id', options: props.options.departments },
    { field: 'new_designation_id', cur: 'designation_id', options: props.options.designations },
    { field: 'new_grade_id', cur: 'grade_id', options: props.options.grades },
    { field: 'new_employment_type_id', cur: 'employment_type_id', options: props.options.employment_types },
    { field: 'new_reports_to_id', cur: 'reports_to_id', options: props.options.managers },
] as const);

function label(options: Option[] | undefined, value: unknown) {
    return options?.find((o) => o.value === value)?.label ?? '—';
}

function submit() {
    form.transform((data) => ({ ...data, new_contract_end_date: data.new_contract_end_date || null, reason: data.reason || null, salary_note: data.salary_note || null }));
    if (props.movement?.id) form.put(`/employee-movements/${props.movement.id}`);
    else form.post('/employee-movements');
}
</script>

<template>
    <Head :title="$t('core.movement.create')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.movements', href: '/employee-movements' }, { title: 'core.movement.create', href: '#' }]">
        <div class="max-w-4xl p-4 md:p-6">
            <PageHeader :title="movement ? String(movement.number ?? '') || $t('core.movement.create') : $t('core.movement.create')" :description="$t('core.movement.form_hint')">
                <template #actions>
                    <Button :disabled="form.processing" @click="submit">{{ $t('core.movement.save_draft') }}</Button>
                </template>
            </PageHeader>

            <div class="grid gap-4 md:grid-cols-3">
                <FormField :label="$t('core.movement.employee')" :error="form.errors.employee_id" required class="md:col-span-2">
                    <SelectInput v-model="form.employee_id" :options="employees" :disabled="Boolean(movement)" />
                </FormField>
                <FormField :label="$t('core.movement.type')" :error="form.errors.type" required>
                    <SelectInput v-model="form.type" :options="options.types" :nullable="false" />
                </FormField>
                <FormField :label="$t('validation.attributes.effective_date')" :error="form.errors.effective_date" required>
                    <Input v-model="form.effective_date" type="date" />
                </FormField>
                <FormField v-if="form.type === 'inter_org_transfer'" :label="$t('core.movement.target_organisation')" :error="form.errors.target_organisation_id" required class="md:col-span-2">
                    <SelectInput v-model="form.target_organisation_id" :options="options.organisations" />
                </FormField>
            </div>

            <p v-if="(form.errors as Record<string, string>).movement" class="mt-3 text-sm text-red-600">{{ (form.errors as Record<string, string>).movement }}</p>

            <div v-if="form.type !== 'confirmation' && form.type !== 'inter_org_transfer'" class="mt-6 overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2">{{ $t('core.movement.current') }}</th>
                            <th class="px-3 py-2">{{ $t('core.movement.new') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.field" class="border-t">
                            <td class="px-3 py-2 font-medium">{{ $t(`validation.attributes.${r.cur}`) }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ current ? label(r.options, current[r.cur]) : '—' }}</td>
                            <td class="px-3 py-2">
                                <SelectInput v-model="form[r.field]" :options="r.options ?? []" :placeholder="$t('core.movement.unchanged')" />
                                <p v-if="form.errors[r.field]" class="text-xs text-red-600">{{ form.errors[r.field] }}</p>
                            </td>
                        </tr>
                        <tr class="border-t">
                            <td class="px-3 py-2 font-medium">{{ $t('validation.attributes.contract_end_date') }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ formatDate(String(current?.contract_end_date ?? '')) || '—' }}</td>
                            <td class="px-3 py-2"><Input v-model="form.new_contract_end_date" type="date" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                <FormField :label="$t('common.fields.reason')" :error="form.errors.reason"><TextArea v-model="form.reason" /></FormField>
                <FormField :label="$t('core.movement.salary_note')" :hint="$t('core.movement.salary_note_hint')"><TextArea v-model="form.salary_note" /></FormField>
            </div>
        </div>
    </AppLayout>
</template>
