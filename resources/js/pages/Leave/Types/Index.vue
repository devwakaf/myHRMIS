<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { LeaveTypeRow } from '@/lib/leave';
import type { Option } from '@/types';

type Kind = 'text' | 'number' | 'int' | 'bool' | 'select' | 'colour';
type Field = { key: string; kind: Kind; options?: string; required?: boolean; when?: (f: Record<string, unknown>) => boolean };

const props = defineProps<{
    types: LeaveTypeRow[];
    options: Record<string, Option<string>[]>;
    can: { manage: boolean };
}>();

const BALANCE = (f: Record<string, unknown>) => ['per_period', 'earned', 'compensatory'].includes(String(f.entitlement_basis));
const SECTIONS: { key: string; fields: Field[] }[] = [
    {
        key: 'general',
        fields: [
            { key: 'code', kind: 'text', required: true },
            { key: 'name', kind: 'text', required: true },
            { key: 'name_en', kind: 'text' },
            { key: 'colour', kind: 'colour' },
            { key: 'sort_order', kind: 'int' },
            { key: 'entitlement_basis', kind: 'select', options: 'entitlement_bases', required: true },
            { key: 'pay_mode', kind: 'select', options: 'pay_modes', required: true },
            { key: 'paid_fraction', kind: 'number', when: (f) => f.pay_mode === 'partially_paid' },
            { key: 'is_active', kind: 'bool' },
        ],
    },
    {
        key: 'rules',
        fields: [
            { key: 'counts_holidays', kind: 'bool' },
            { key: 'allow_half_day', kind: 'bool' },
            { key: 'requires_reason', kind: 'bool' },
            { key: 'requires_attachment', kind: 'select', options: 'attachment_rules' },
            { key: 'attachment_over_days', kind: 'number', when: (f) => f.requires_attachment === 'over_days' },
            { key: 'min_service_days_to_apply', kind: 'int' },
            { key: 'min_notice_days', kind: 'int' },
            { key: 'notice_enforcement', kind: 'select', options: 'notice_enforcements', when: (f) => f.min_notice_days !== null && f.min_notice_days !== '' },
            { key: 'max_backdate_days', kind: 'int' },
            { key: 'max_days_per_application', kind: 'number' },
            { key: 'max_consecutive_days', kind: 'number' },
            { key: 'max_days_per_period', kind: 'number' },
            { key: 'eligible_gender', kind: 'select', options: 'genders' },
        ],
    },
    {
        key: 'balance',
        fields: [
            { key: 'proration_method', kind: 'select', options: 'proration_methods', when: (f) => f.entitlement_basis === 'per_period' },
            { key: 'rounding_step', kind: 'select', options: 'rounding_steps', when: BALANCE },
            { key: 'prorate_on_separation', kind: 'bool', when: BALANCE },
            { key: 'allow_negative', kind: 'bool', when: BALANCE },
            { key: 'max_negative_days', kind: 'number', when: (f) => Boolean(f.allow_negative) },
            { key: 'allow_carry_forward', kind: 'bool', when: BALANCE },
            { key: 'max_carry_forward_days', kind: 'number', when: (f) => Boolean(f.allow_carry_forward) },
            { key: 'carry_forward_expiry_days', kind: 'int', when: (f) => Boolean(f.allow_carry_forward) },
            { key: 'carry_includes_carried', kind: 'bool', when: (f) => Boolean(f.allow_carry_forward) },
            { key: 'allow_encashment', kind: 'bool', when: BALANCE },
            { key: 'max_encashable_days', kind: 'number', when: (f) => Boolean(f.allow_encashment) },
            { key: 'encashment_reserve_days', kind: 'number', when: (f) => Boolean(f.allow_encashment) },
            { key: 'accrual_frequency', kind: 'select', options: 'accrual_frequencies', when: (f) => f.entitlement_basis === 'earned' },
            { key: 'accrual_timing', kind: 'select', options: 'accrual_timings', when: (f) => f.entitlement_basis === 'earned' },
            { key: 'compensatory_expiry_days', kind: 'int', when: (f) => f.entitlement_basis === 'compensatory' },
        ],
    },
    {
        key: 'event',
        fields: [
            { key: 'per_event_max_days', kind: 'number', when: (f) => f.entitlement_basis === 'per_event' },
            { key: 'max_events_per_service', kind: 'int', when: (f) => f.entitlement_basis === 'per_event' },
            { key: 'max_events_per_period', kind: 'int', when: (f) => f.entitlement_basis === 'per_event' },
            { key: 'requires_event_date', kind: 'bool', when: (f) => f.entitlement_basis === 'per_event' },
        ],
    },
];

const ALL = SECTIONS.flatMap((s) => s.fields);
const choice = (label: (k: string) => string, values: string[]): Option<string>[] => values.map((v) => ({ value: v, label: label(v) }));
const selectOptions = computed<Record<string, Option<string>[]>>(() => ({
    ...props.options,
    genders: [{ value: 'male', label: 'common.gender.male' }, { value: 'female', label: 'common.gender.female' }],
    rounding_steps: choice((v) => v, ['0.25', '0.5', '1']),
}));
const MARITAL = ['single', 'married', 'divorced', 'widowed'];

const open = ref(false);
const editing = ref<LeaveTypeRow | null>(null);
const form = useForm({} as Record<string, string | number | boolean | string[] | null>);

function load(row?: LeaveTypeRow) {
    const data: Record<string, string | number | boolean | string[] | null> = { eligible_marital_statuses: [] as string[] };
    for (const f of ALL) {
        const raw = row ? row[f.key] : undefined;
        if (f.kind === 'bool') data[f.key] = row ? Boolean(raw) : f.key === 'is_active';
        else data[f.key] = raw === undefined || raw === null ? (f.key === 'entitlement_basis' ? 'per_period' : f.key === 'pay_mode' ? 'paid' : f.key === 'rounding_step' ? '1' : '') : (raw as string | number);
    }
    if (row && Array.isArray(row.eligible_marital_statuses)) data.eligible_marital_statuses = [...(row.eligible_marital_statuses as string[])];
    form.defaults(data);
    form.reset();
    form.clearErrors();
}

function create() {
    editing.value = null;
    load();
    open.value = true;
}

function edit(row: LeaveTypeRow) {
    editing.value = row;
    load(row);
    open.value = true;
}

function visible(f: Field): boolean {
    return f.when ? f.when(form.data()) : true;
}

function save() {
    form.transform((data) => {
        const out: Record<string, unknown> = {};
        for (const f of ALL) {
            const v = data[f.key];
            out[f.key] = f.kind !== 'bool' && (v === '' || v === undefined) ? null : v;
            if (!visible(f) && f.kind !== 'bool' && f.key !== 'paid_fraction') out[f.key] = null;
        }
        out.eligible_marital_statuses = (data.eligible_marital_statuses as string[]).length ? data.eligible_marital_statuses : null;

        return out;
    });
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`/leave/types/${editing.value.id}`, opts);
    else form.post('/leave/types', opts);
}
</script>

<template>
    <Head :title="$t('leave.pages.types')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.types', href: '/leave/types' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.types')" :description="$t('leave.types.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('leave.fields.code') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.entitlement_basis') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.pay_mode') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in types" :key="t.id" class="border-t">
                            <td class="px-3 py-2 font-mono"><span class="mr-1 inline-block size-2 rounded-full" :style="{ backgroundColor: t.colour ?? '#94a3b8' }" />{{ t.code }}</td>
                            <td class="px-3 py-2">{{ t.display_name }}</td>
                            <td class="px-3 py-2">{{ t.entitlement_basis_label }}</td>
                            <td class="px-3 py-2">{{ t.pay_mode_label }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="t.is_active ? 'active' : 'left'" :label="t.is_active ? $t('common.active') : $t('common.inactive')" /></td>
                            <td class="px-3 py-2 text-right"><Button v-if="can.manage" size="sm" variant="outline" @click="edit(t)">{{ $t('common.actions.edit') }}</Button></td>
                        </tr>
                        <tr v-if="!types.length"><td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ editing ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('leave.pages.types') }}</DialogTitle></DialogHeader>
                <form class="space-y-5" @submit.prevent="save">
                    <fieldset v-for="section in SECTIONS" :key="section.key" class="space-y-3">
                        <legend v-if="section.fields.some(visible)" class="mb-1 text-sm font-semibold">{{ $t(`leave.types.sections.${section.key}`) }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <template v-for="f in section.fields.filter(visible)" :key="f.key">
                                <label v-if="f.kind === 'bool'" class="flex items-center gap-2 self-end text-sm">
                                    <input v-model="form[f.key]" type="checkbox" /> {{ $t(`leave.fields.${f.key}`) }}
                                </label>
                                <FormField v-else :label="$t(`leave.fields.${f.key}`)" :error="form.errors[f.key]" :required="f.required">
                                    <SelectInput
                                        v-if="f.kind === 'select'"
                                        v-model="(form[f.key] as string)"
                                        :options="(selectOptions[f.options ?? ''] ?? []).map((o) => ({ ...o, label: o.label.startsWith('common.') ? $t(o.label) : o.label }))"
                                        :nullable="!f.required"
                                    />
                                    <Input v-else-if="f.kind === 'colour'" v-model="(form[f.key] as string)" type="color" class="h-9 w-20 p-1" />
                                    <Input v-else v-model="(form[f.key] as string)" :type="f.kind === 'text' ? 'text' : 'number'" :step="f.kind === 'number' ? '0.001' : '1'" min="0" />
                                </FormField>
                            </template>
                        </div>
                        <div v-if="section.key === 'rules'" class="flex flex-wrap gap-3 text-sm">
                            <span class="text-xs text-muted-foreground">{{ $t('leave.fields.eligible_marital_statuses') }}:</span>
                            <label v-for="m in MARITAL" :key="m" class="flex items-center gap-1">
                                <input v-model="form.eligible_marital_statuses as string[]" type="checkbox" :value="m" /> {{ $t(`leave.marital.${m}`) }}
                            </label>
                        </div>
                    </fieldset>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
