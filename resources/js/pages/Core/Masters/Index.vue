<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Row = Record<string, string | number | boolean | null> & { id: number; display_name: string; is_active: boolean };
type FieldType = 'text' | 'textarea' | 'number' | 'money' | 'bool' | 'state' | 'category';
type Field = { key: string; type: FieldType; required?: boolean };

const props = defineProps<{
    kind: 'branches' | 'designations' | 'grades' | 'employment-types' | 'document-types';
    rows: Row[];
    filters: { search: string };
    options: { states: Option<string>[]; categories: Option<string>[] };
    canManage: boolean;
}>();

const FIELDS: Record<string, Field[]> = {
    branches: [
        { key: 'code', type: 'text', required: true },
        { key: 'name', type: 'text', required: true },
        { key: 'name_en', type: 'text' },
        { key: 'address_line1', type: 'text' },
        { key: 'address_line2', type: 'text' },
        { key: 'postcode', type: 'text' },
        { key: 'city', type: 'text' },
        { key: 'state', type: 'state', required: true },
        { key: 'phone', type: 'text' },
    ],
    designations: [
        { key: 'code', type: 'text' },
        { key: 'name', type: 'text', required: true },
        { key: 'name_en', type: 'text' },
        { key: 'description', type: 'textarea' },
    ],
    grades: [
        { key: 'code', type: 'text', required: true },
        { key: 'name', type: 'text', required: true },
        { key: 'name_en', type: 'text' },
        { key: 'rank', type: 'number', required: true },
        { key: 'salary_min', type: 'money' },
        { key: 'salary_max', type: 'money' },
    ],
    'employment-types': [
        { key: 'code', type: 'text', required: true },
        { key: 'name', type: 'text', required: true },
        { key: 'name_en', type: 'text' },
        { key: 'category', type: 'category', required: true },
        { key: 'requires_end_date', type: 'bool' },
    ],
    'document-types': [
        { key: 'code', type: 'text', required: true },
        { key: 'name', type: 'text', required: true },
        { key: 'name_en', type: 'text' },
        { key: 'tracks_expiry', type: 'bool' },
    ],
};

const fields = computed(() => FIELDS[props.kind]);
const titleKey = computed(() => `common.nav.${props.kind.replace('-', '_')}`);
const search = ref(props.filters.search ?? '');
const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<Record<string, any>>({});

function reset(row?: Row) {
    const data: Record<string, string | number | boolean | null> = { is_active: row ? row.is_active : true };
    for (const f of fields.value) {
        data[f.key] = row ? (row[f.key] ?? (f.type === 'bool' ? false : '')) : f.type === 'bool' ? false : f.type === 'state' ? 'PHG' : f.type === 'category' ? 'permanent' : '';
    }
    form.defaults(data);
    form.reset();
    form.clearErrors();
}

function create() {
    editingId.value = null;
    reset();
    open.value = true;
}

function edit(row: Row) {
    editingId.value = row.id;
    reset(row);
    open.value = true;
}

function submit() {
    form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editingId.value) {
        form.put(`/${props.kind}/${editingId.value}`, options);
    } else {
        form.post(`/${props.kind}`, options);
    }
}

function applySearch() {
    router.get(`/${props.kind}`, { search: search.value }, { preserveState: true, replace: true });
}

function extra(row: Row): string {
    switch (props.kind) {
        case 'branches':
            return String(row.state_label ?? '');
        case 'grades':
            return `${row.rank} · ${row.salary_min ? formatMoney(String(row.salary_min)) : '—'} – ${row.salary_max ? formatMoney(String(row.salary_max)) : '—'}`;
        case 'employment-types':
            return String(row.category_label ?? '');
        case 'document-types':
            return row.tracks_expiry ? String(row.tracks_expiry_label ?? '✓') : '';
        default:
            return String(row.description ?? '');
    }
}
</script>

<template>
    <Head :title="$t(titleKey)" />
    <AppLayout :breadcrumbs="[{ title: titleKey, href: `/${kind}` }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t(titleKey)" :description="$t(`core.masters.descriptions.${kind.replace('-', '_')}`)">
                <template #actions>
                    <Button v-if="canManage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex gap-2" @submit.prevent="applySearch">
                <Input v-model="search" :placeholder="$t('common.actions.search')" class="max-w-sm" />
                <Button type="submit" variant="outline">{{ $t('common.actions.search') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('core.masters.fields.code') }}</th>
                            <th class="px-3 py-2">{{ $t('core.masters.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t(`core.masters.extra.${kind.replace('-', '_')}`) }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-t">
                            <td class="px-3 py-2 font-mono">{{ row.code ?? '—' }}</td>
                            <td class="px-3 py-2">{{ row.display_name }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ extra(row) }}</td>
                            <td class="px-3 py-2">
                                <StatusBadge :status="row.is_active ? 'active' : 'left'" :label="row.is_active ? $t('common.active') : $t('common.inactive')" />
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button v-if="canManage" size="sm" variant="outline" @click="edit(row)">{{ $t('common.actions.edit') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ editingId ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t(titleKey) }}</DialogTitle>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="submit">
                    <FormField
                        v-for="f in fields.filter((x) => x.type !== 'bool')"
                        :key="f.key"
                        :label="$t(`core.masters.fields.${f.key}`)"
                        :error="form.errors[f.key]"
                        :required="f.required"
                    >
                        <SelectInput v-if="f.type === 'state'" v-model="form[f.key]" :options="options.states" :nullable="false" />
                        <SelectInput v-else-if="f.type === 'category'" v-model="form[f.key]" :options="options.categories" :nullable="false" />
                        <TextArea v-else-if="f.type === 'textarea'" v-model="form[f.key]" :rows="2" />
                        <Input
                            v-else
                            v-model="form[f.key]"
                            :type="f.type === 'number' ? 'number' : 'text'"
                            :inputmode="f.type === 'money' ? 'decimal' : undefined"
                        />
                    </FormField>
                    <label v-for="f in fields.filter((x) => x.type === 'bool')" :key="f.key" class="flex items-center gap-2 text-sm">
                        <input v-model="form[f.key]" type="checkbox" /> {{ $t(`core.masters.fields.${f.key}`) }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_active" type="checkbox" /> {{ $t('common.active') }}
                    </label>
                    <p v-if="form.errors.is_active" class="text-xs text-red-600">{{ form.errors.is_active }}</p>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
