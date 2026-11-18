<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';
import type { Option } from '@/types';

type Cap = { id: number; name: string; cap_days: string; is_active: boolean; leave_type_ids: number[] };

const props = defineProps<{ caps: Cap[]; options: { types: Option<number>[] }; can: { manage: boolean } }>();

const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ name: '', cap_days: '', is_active: true, leave_type_ids: [] as number[] });

const names = (ids: number[]) => ids.map((id) => props.options.types.find((t) => t.value === id)?.label ?? id).join(', ');

function load(c?: Cap) {
    form.defaults({ name: c?.name ?? '', cap_days: c?.cap_days ?? '', is_active: c?.is_active ?? true, leave_type_ids: c ? [...c.leave_type_ids] : [] });
    form.reset();
    form.clearErrors();
}

function create() {
    editingId.value = null;
    load();
    open.value = true;
}

function edit(c: Cap) {
    editingId.value = c.id;
    load(c);
    open.value = true;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editingId.value) form.put(`/leave/aggregate-caps/${editingId.value}`, opts);
    else form.post('/leave/aggregate-caps', opts);
}
</script>

<template>
    <Head :title="$t('leave.pages.aggregate_caps')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.aggregate_caps', href: '/leave/aggregate-caps' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.aggregate_caps')" :description="$t('leave.caps.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('leave.fields.name') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.caps.cap_days') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.caps.types') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in caps" :key="c.id" class="border-t">
                            <td class="px-3 py-2">{{ c.name }}</td>
                            <td class="px-3 py-2 text-right">{{ days(c.cap_days) }}</td>
                            <td class="px-3 py-2">{{ names(c.leave_type_ids) }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="c.is_active ? 'active' : 'left'" :label="c.is_active ? $t('common.active') : $t('common.inactive')" /></td>
                            <td class="px-3 py-2 text-right"><Button v-if="can.manage" size="sm" variant="outline" @click="edit(c)">{{ $t('common.actions.edit') }}</Button></td>
                        </tr>
                        <tr v-if="!caps.length"><td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>{{ editingId ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('leave.pages.aggregate_caps') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save">
                    <FormField :label="$t('leave.fields.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                    <FormField :label="$t('leave.caps.cap_days')" :error="form.errors.cap_days" required><Input v-model="form.cap_days" type="number" step="0.5" min="0" /></FormField>
                    <FormField :label="$t('leave.caps.types')" :error="form.errors.leave_type_ids" :hint="$t('leave.caps.types_hint')" required>
                        <div class="max-h-48 space-y-1 overflow-y-auto rounded border p-2">
                            <label v-for="t in options.types" :key="t.value" class="flex items-center gap-2 text-sm">
                                <input v-model="form.leave_type_ids" type="checkbox" :value="t.value" /> {{ t.label }}
                            </label>
                        </div>
                    </FormField>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> {{ $t('common.active') }}</label>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
