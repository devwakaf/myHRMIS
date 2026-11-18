<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { days as dec } from '@/lib/leave';
import type { Option } from '@/types';

type Rule = { day_type: string; beyond_normal_multiplier: string | null; within_normal_mode: string | null; within_normal_orp_days: string | null; salary_component_id: number | null };
type OtType = {
    id: number;
    code: string;
    name: string;
    basis: string;
    rounding_unit_minutes: number;
    min_minutes_per_day: number;
    daily_max_minutes: number | null;
    salary_component_id: number | null;
    is_active: boolean;
    rules: Rule[];
};

const props = defineProps<{
    overtime_types: OtType[];
    options: { bases: Option<string>[]; day_types: Option<string>[]; within_normal_modes: Option<string>[] };
    can: { manage: boolean };
}>();

const open = ref(false);
const editing = ref<OtType | null>(null);
const form = useForm({
    code: '',
    name: '',
    basis: 'statutory',
    rounding_unit_minutes: 15 as number | string,
    min_minutes_per_day: 0 as number | string,
    daily_max_minutes: '' as number | string,
    salary_component_id: null as number | null,
    is_active: true,
    rules: [] as Rule[],
});

const DEFAULTS: Record<string, [string, string, string]> = { normal: ['1.5', 'none', ''], rest_day: ['2.0', 'orp_half_or_full', ''], public_holiday: ['3.0', 'orp_half_or_full', ''] };

function rulesFor(existing: Rule[] = []): Rule[] {
    return props.options.day_types.map((d) => existing.find((r) => r.day_type === d.value) ?? { day_type: d.value, beyond_normal_multiplier: DEFAULTS[d.value]?.[0] ?? '1.5', within_normal_mode: DEFAULTS[d.value]?.[1] ?? 'none', within_normal_orp_days: null, salary_component_id: null });
}

function create() {
    editing.value = null;
    form.defaults({ code: '', name: '', basis: 'statutory', rounding_unit_minutes: 15, min_minutes_per_day: 0, daily_max_minutes: '', salary_component_id: null, is_active: true, rules: rulesFor() });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(t: OtType) {
    editing.value = t;
    form.defaults({ code: t.code, name: t.name, basis: t.basis, rounding_unit_minutes: t.rounding_unit_minutes, min_minutes_per_day: t.min_minutes_per_day, daily_max_minutes: t.daily_max_minutes ?? '', salary_component_id: t.salary_component_id, is_active: t.is_active, rules: rulesFor(t.rules.map((r) => ({ ...r }))) });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save() {
    form.transform((d) => ({
        ...d,
        daily_max_minutes: d.daily_max_minutes === '' ? null : d.daily_max_minutes,
        rules: d.rules.map((r) => ({
            ...r,
            beyond_normal_multiplier: r.beyond_normal_multiplier === '' ? null : r.beyond_normal_multiplier,
            within_normal_orp_days: r.within_normal_mode === 'orp_days' && r.within_normal_orp_days !== '' ? r.within_normal_orp_days : null,
        })),
    }));
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`/attendance/overtime-types/${editing.value.id}`, opts);
    else form.post('/attendance/overtime-types', opts);
}

const dayLabel = (v: string) => props.options.day_types.find((d) => d.value === v)?.label ?? v;
const basisLabel = (v: string) => props.options.bases.find((d) => d.value === v)?.label ?? v;
</script>

<template>
    <Head :title="$t('attendance.pages.overtime_types')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.overtime_types', href: '/attendance/overtime-types' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.overtime_types')" :description="$t('attendance.ot_type.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="ot-types-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('attendance.shift_type.code') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.shift_type.name') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.ot_type.basis') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.ot_type.multipliers') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in overtime_types" :key="t.id" class="border-t">
                            <td class="px-3 py-2 font-mono">{{ t.code }}</td>
                            <td class="px-3 py-2">{{ t.name }}</td>
                            <td class="px-3 py-2">{{ basisLabel(t.basis) }}</td>
                            <td class="px-3 py-2 text-xs">{{ t.rules.map((r) => `${dayLabel(r.day_type)} ×${dec(r.beyond_normal_multiplier)}`).join(' · ') }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="t.is_active ? 'active' : 'left'" :label="t.is_active ? $t('common.active') : $t('common.inactive')" /></td>
                            <td class="px-3 py-2 text-right"><Button v-if="can.manage" size="sm" variant="outline" @click="edit(t)">{{ $t('common.actions.edit') }}</Button></td>
                        </tr>
                        <tr v-if="!overtime_types.length"><td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[92vh] max-w-3xl overflow-y-auto sm:max-w-3xl">
                <DialogHeader><DialogTitle>{{ editing ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('attendance.pages.overtime_types') }}</DialogTitle></DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <FormField :label="$t('attendance.shift_type.code')" :error="form.errors.code" required><Input v-model="form.code" maxlength="20" /></FormField>
                        <FormField class="sm:col-span-2" :label="$t('attendance.shift_type.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                        <FormField :label="$t('attendance.ot_type.basis')" :error="form.errors.basis" required><SelectInput v-model="form.basis" :options="options.bases" :nullable="false" /></FormField>
                        <FormField :label="$t('attendance.ot_type.rounding')" :error="form.errors.rounding_unit_minutes" required><Input v-model="form.rounding_unit_minutes" type="number" min="1" max="240" /></FormField>
                        <FormField :label="$t('attendance.ot_type.minimum')" :error="form.errors.min_minutes_per_day"><Input v-model="form.min_minutes_per_day" type="number" min="0" /></FormField>
                        <FormField :label="$t('attendance.ot_type.daily_max')" :error="form.errors.daily_max_minutes"><Input v-model="form.daily_max_minutes" type="number" min="1" /></FormField>
                    </div>
                    <p v-if="form.basis === 'statutory'" class="rounded-md bg-sky-50 p-2 text-xs text-sky-900 dark:bg-sky-950 dark:text-sky-100">{{ $t('attendance.ot_type.statutory_note') }}</p>

                    <fieldset class="space-y-2">
                        <legend class="mb-1 text-sm font-medium">{{ $t('attendance.ot_type.rules') }}</legend>
                        <div v-for="(rule, i) in form.rules" :key="rule.day_type" class="grid gap-3 rounded-md border p-3 sm:grid-cols-4" data-testid="ot-rule">
                            <p class="self-center text-sm font-medium">{{ dayLabel(rule.day_type) }}</p>
                            <FormField :label="$t('attendance.ot_type.multiplier')" :error="form.errors[`rules.${i}.beyond_normal_multiplier`]"><Input v-model="rule.beyond_normal_multiplier" type="number" step="0.01" min="0" max="10" /></FormField>
                            <FormField :label="$t('attendance.ot_type.within_normal')" :error="form.errors[`rules.${i}.within_normal_mode`]"><SelectInput v-model="rule.within_normal_mode" :options="options.within_normal_modes" :nullable="false" /></FormField>
                            <FormField v-if="rule.within_normal_mode === 'orp_days'" :label="$t('attendance.ot_type.orp_days')" :error="form.errors[`rules.${i}.within_normal_orp_days`]"><Input v-model="rule.within_normal_orp_days" type="number" step="0.01" min="0" /></FormField>
                        </div>
                        <p v-if="form.errors.rules" class="text-xs text-red-600">{{ form.errors.rules }}</p>
                    </fieldset>

                    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" />{{ $t('common.active') }}</label>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
