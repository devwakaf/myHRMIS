<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type BlockList = {
    id: number;
    name: string;
    applies_to_all_departments: boolean;
    leave_type_id: number | null;
    is_active: boolean;
    dates: { date: string; reason: string | null }[];
    department_ids: number[];
    exempt_user_ids: number[];
};

const props = defineProps<{
    lists: BlockList[];
    options: { types: Option<number>[]; departments: Option<number>[]; users: Option<number>[] };
    can: { manage: boolean };
}>();

const open = ref(false);
const editingId = ref<number | null>(null);
const bulkFrom = ref('');
const bulkTo = ref('');
const form = useForm({
    name: '',
    applies_to_all_departments: true,
    leave_type_id: null as number | null,
    is_active: true,
    dates: [] as { date: string; reason: string }[],
    department_ids: [] as number[],
    exempt_user_ids: [] as number[],
});

const typeName = (id: number | null) => (id === null ? '' : (props.options.types.find((t) => t.value === id)?.label ?? ''));

function load(l?: BlockList) {
    form.defaults({
        name: l?.name ?? '',
        applies_to_all_departments: l?.applies_to_all_departments ?? true,
        leave_type_id: l?.leave_type_id ?? null,
        is_active: l?.is_active ?? true,
        dates: l ? l.dates.map((d) => ({ date: d.date, reason: d.reason ?? '' })) : [],
        department_ids: l ? [...l.department_ids] : [],
        exempt_user_ids: l ? [...l.exempt_user_ids] : [],
    });
    form.reset();
    form.clearErrors();
}

function create() {
    editingId.value = null;
    load();
    open.value = true;
}

function edit(l: BlockList) {
    editingId.value = l.id;
    load(l);
    open.value = true;
}

function addRange() {
    if (!bulkFrom.value) return;
    const end = bulkTo.value && bulkTo.value >= bulkFrom.value ? bulkTo.value : bulkFrom.value;
    const existing = new Set(form.dates.map((d) => d.date));
    for (let cursor = new Date(`${bulkFrom.value}T00:00:00Z`); cursor.toISOString().slice(0, 10) <= end; cursor.setUTCDate(cursor.getUTCDate() + 1)) {
        const iso = cursor.toISOString().slice(0, 10);
        if (!existing.has(iso)) form.dates.push({ date: iso, reason: '' });
    }
    form.dates.sort((a, b) => a.date.localeCompare(b.date));
    bulkFrom.value = '';
    bulkTo.value = '';
}

function save() {
    form.transform((d) => ({
        ...d,
        dates: d.dates.map((x) => ({ date: x.date, reason: x.reason || null })),
        department_ids: d.applies_to_all_departments ? [] : d.department_ids,
    }));
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editingId.value) form.put(`/leave/block-lists/${editingId.value}`, opts);
    else form.post('/leave/block-lists', opts);
}
</script>

<template>
    <Head :title="$t('leave.pages.block_lists')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.block_lists', href: '/leave/block-lists' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.block_lists')" :description="$t('leave.blocks.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('leave.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.leave_type') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.blocks.dates') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.blocks.scope') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in lists" :key="l.id" class="border-t align-top">
                            <td class="px-3 py-2">{{ l.name }}</td>
                            <td class="px-3 py-2">{{ typeName(l.leave_type_id) || $t('leave.blocks.all_types') }}</td>
                            <td class="px-3 py-2 text-xs">{{ l.dates.slice(0, 6).map((d) => formatDate(d.date)).join(', ') }}<span v-if="l.dates.length > 6"> +{{ l.dates.length - 6 }}</span></td>
                            <td class="px-3 py-2">{{ l.applies_to_all_departments ? $t('leave.blocks.all_departments') : $t('leave.blocks.n_departments', { count: String(l.department_ids.length) }) }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="l.is_active ? 'active' : 'left'" :label="l.is_active ? $t('common.active') : $t('common.inactive')" /></td>
                            <td class="px-3 py-2 text-right"><Button v-if="can.manage" size="sm" variant="outline" @click="edit(l)">{{ $t('common.actions.edit') }}</Button></td>
                        </tr>
                        <tr v-if="!lists.length"><td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
                <DialogHeader><DialogTitle>{{ editingId ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('leave.pages.block_lists') }}</DialogTitle></DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('leave.fields.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                        <FormField :label="$t('leave.fields.leave_type')" :error="form.errors.leave_type_id" :hint="$t('leave.blocks.type_hint')"><SelectInput v-model="form.leave_type_id" :options="options.types" :placeholder="$t('leave.blocks.all_types')" /></FormField>
                    </div>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2"><input v-model="form.applies_to_all_departments" type="checkbox" /> {{ $t('leave.blocks.all_departments') }}</label>
                        <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" /> {{ $t('common.active') }}</label>
                    </div>
                    <FormField v-if="!form.applies_to_all_departments" :label="$t('leave.blocks.departments')" :error="form.errors.department_ids">
                        <div class="max-h-36 space-y-1 overflow-y-auto rounded border p-2">
                            <label v-for="d in options.departments" :key="d.value" class="flex items-center gap-2 text-sm"><input v-model="form.department_ids" type="checkbox" :value="d.value" /> {{ d.label }}</label>
                        </div>
                    </FormField>

                    <div class="space-y-2">
                        <h3 class="text-sm font-semibold">{{ $t('leave.blocks.dates') }}</h3>
                        <p v-if="form.errors.dates" class="text-xs text-red-600">{{ form.errors.dates }}</p>
                        <div class="flex flex-wrap items-end gap-2">
                            <FormField :label="$t('leave.fields.from_date')"><Input v-model="bulkFrom" type="date" class="w-40" /></FormField>
                            <FormField :label="$t('leave.fields.to_date')"><Input v-model="bulkTo" type="date" class="w-40" /></FormField>
                            <Button type="button" variant="outline" @click="addRange"><Plus class="size-4" />{{ $t('leave.blocks.add_range') }}</Button>
                        </div>
                        <ul class="max-h-48 space-y-1 overflow-y-auto">
                            <li v-for="(d, i) in form.dates" :key="d.date" class="flex items-center gap-2">
                                <span class="w-28 text-sm">{{ formatDate(d.date) }}</span>
                                <Input v-model="d.reason" :placeholder="$t('leave.blocks.reason')" class="h-8" />
                                <Button type="button" size="sm" variant="ghost" :aria-label="$t('common.actions.delete')" @click="form.dates.splice(i, 1)"><Trash2 class="size-4" /></Button>
                            </li>
                        </ul>
                    </div>

                    <FormField :label="$t('leave.blocks.exempt')" :error="form.errors.exempt_user_ids" :hint="$t('leave.blocks.exempt_hint')">
                        <div class="max-h-36 space-y-1 overflow-y-auto rounded border p-2">
                            <label v-for="u in options.users" :key="u.value" class="flex items-center gap-2 text-sm"><input v-model="form.exempt_user_ids" type="checkbox" :value="u.value" /> {{ u.label }}</label>
                        </div>
                    </FormField>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
