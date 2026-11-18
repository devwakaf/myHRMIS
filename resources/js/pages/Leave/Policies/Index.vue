<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';
import type { Option } from '@/types';

type Tier = { min_service_months: number | string; days: string };
type Row = {
    id?: number;
    leave_type_id: number | null;
    annual_days: string;
    uses_service_tiers: boolean;
    tier_basis: string;
    override_max_carry_forward_days: string | null;
    tiers: Tier[];
};
type Policy = { id: number; name: string; description: string | null; is_active: boolean; rows: Row[] };

const props = defineProps<{
    policies: Policy[];
    options: { types: Option<number>[]; tier_bases: Option<string>[] };
    can: { manage: boolean };
}>();

const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ name: '', description: '', is_active: true, rows: [] as Row[] });

const typeLabel = (id: number | null) => props.options.types.find((t) => t.value === id)?.label ?? '—';
const blankRow = (): Row => ({ leave_type_id: null, annual_days: '0', uses_service_tiers: false, tier_basis: 'prorata_by_anniversary', override_max_carry_forward_days: null, tiers: [] });

function load(p?: Policy) {
    form.defaults({
        name: p?.name ?? '',
        description: p?.description ?? '',
        is_active: p?.is_active ?? true,
        rows: p ? p.rows.map((r) => ({ ...r, tiers: r.tiers.map((t) => ({ ...t })) })) : [blankRow()],
    });
    form.reset();
    form.clearErrors();
}

function create() {
    editingId.value = null;
    load();
    open.value = true;
}

function edit(p: Policy) {
    editingId.value = p.id;
    load(p);
    open.value = true;
}

function addTier(row: Row) {
    row.tiers.push({ min_service_months: row.tiers.length === 0 ? 0 : '', days: '0' });
}

function save() {
    form.transform((d) => ({
        ...d,
        description: d.description || null,
        rows: d.rows.map((r) => ({
            leave_type_id: r.leave_type_id,
            annual_days: r.uses_service_tiers ? null : r.annual_days,
            uses_service_tiers: r.uses_service_tiers,
            tier_basis: r.tier_basis,
            override_max_carry_forward_days: r.override_max_carry_forward_days === '' ? null : r.override_max_carry_forward_days,
            tiers: r.uses_service_tiers ? r.tiers : [],
        })),
    }));
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editingId.value) form.put(`/leave/policies/${editingId.value}`, opts);
    else form.post('/leave/policies', opts);
}

function rowError(i: number, key: string): string | undefined {
    return (form.errors as Record<string, string>)[`rows.${i}.${key}`];
}
</script>

<template>
    <Head :title="$t('leave.pages.policies')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.policies', href: '/leave/policies' }]">
        <div class="space-y-4 p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.policies')" :description="$t('leave.policies.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div v-for="p in policies" :key="p.id" class="rounded-lg border p-4">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="font-medium">{{ p.name }}</h2>
                        <p v-if="p.description" class="text-xs text-muted-foreground">{{ p.description }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge :status="p.is_active ? 'active' : 'left'" :label="p.is_active ? $t('common.active') : $t('common.inactive')" />
                        <Button v-if="can.manage" size="sm" variant="outline" @click="edit(p)">{{ $t('common.actions.edit') }}</Button>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr><th class="py-1">{{ $t('leave.fields.leave_type') }}</th><th class="py-1">{{ $t('leave.policies.annual_days') }}</th><th class="py-1">{{ $t('leave.policies.tiers') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in p.rows" :key="i" class="border-t">
                            <td class="py-1">{{ typeLabel(r.leave_type_id) }}</td>
                            <td class="py-1">{{ r.uses_service_tiers ? '—' : days(r.annual_days) }}</td>
                            <td class="py-1 text-muted-foreground">{{ r.uses_service_tiers ? r.tiers.map((t) => `${t.min_service_months} ${$t('leave.policies.months_short')} → ${days(t.days)}`).join(' · ') : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!policies.length" class="rounded-lg border px-3 py-6 text-center text-sm text-muted-foreground">{{ $t('common.empty') }}</p>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-4xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ editingId ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('leave.pages.policies') }}</DialogTitle></DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('leave.fields.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                        <label class="flex items-center gap-2 self-end text-sm"><input v-model="form.is_active" type="checkbox" /> {{ $t('common.active') }}</label>
                    </div>
                    <FormField :label="$t('common.fields.description')" :error="form.errors.description"><TextArea v-model="form.description" :rows="2" /></FormField>

                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold">{{ $t('leave.policies.rows') }}</h3>
                        <p v-if="form.errors.rows" class="text-xs text-red-600">{{ form.errors.rows }}</p>
                        <div v-for="(row, i) in form.rows" :key="i" class="space-y-3 rounded-md border p-3">
                            <div class="grid gap-3 sm:grid-cols-4">
                                <FormField :label="$t('leave.fields.leave_type')" :error="rowError(i, 'leave_type_id')" required>
                                    <SelectInput v-model="row.leave_type_id" :options="options.types" />
                                </FormField>
                                <FormField v-if="!row.uses_service_tiers" :label="$t('leave.policies.annual_days')" :error="rowError(i, 'annual_days')">
                                    <Input v-model="row.annual_days" type="number" step="0.5" min="0" />
                                </FormField>
                                <FormField :label="$t('leave.policies.override_carry')" :error="rowError(i, 'override_max_carry_forward_days')">
                                    <Input v-model="row.override_max_carry_forward_days" type="number" step="0.5" min="0" />
                                </FormField>
                                <div class="flex items-end justify-end"><Button type="button" size="sm" variant="ghost" :aria-label="$t('common.actions.delete')" @click="form.rows.splice(i, 1)"><Trash2 class="size-4" /></Button></div>
                            </div>
                            <label class="flex items-center gap-2 text-sm"><input v-model="row.uses_service_tiers" type="checkbox" /> {{ $t('leave.policies.uses_tiers') }}</label>
                            <div v-if="row.uses_service_tiers" class="space-y-2 rounded bg-muted/30 p-2">
                                <FormField :label="$t('leave.policies.tier_basis')" :error="rowError(i, 'tier_basis')">
                                    <SelectInput v-model="row.tier_basis" :options="options.tier_bases" :nullable="false" />
                                </FormField>
                                <div v-for="(tier, j) in row.tiers" :key="j" class="flex flex-wrap items-end gap-2">
                                    <FormField :label="$t('leave.policies.min_months')" :error="rowError(i, `tiers.${j}.min_service_months`)"><Input v-model="tier.min_service_months" type="number" min="0" class="w-32" /></FormField>
                                    <FormField :label="$t('leave.fields.days')" :error="rowError(i, `tiers.${j}.days`)"><Input v-model="tier.days" type="number" step="0.5" min="0" class="w-28" /></FormField>
                                    <Button type="button" size="sm" variant="ghost" :aria-label="$t('common.actions.delete')" @click="row.tiers.splice(j, 1)"><Trash2 class="size-4" /></Button>
                                </div>
                                <Button type="button" size="sm" variant="outline" @click="addTier(row)"><Plus class="size-4" />{{ $t('leave.policies.add_tier') }}</Button>
                            </div>
                        </div>
                        <Button type="button" size="sm" variant="outline" @click="form.rows.push(blankRow())"><Plus class="size-4" />{{ $t('leave.policies.add_row') }}</Button>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
