<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';

type Period = { id: number; name: string; from_date: string; to_date: string; notes: string | null; allocations_generated_at: string | null };

defineProps<{ periods: Period[]; can: { manage: boolean; generate: boolean } }>();

const open = ref(false);
const editingId = ref<number | null>(null);
const confirmGenerate = ref<Period | null>(null);
const form = useForm({ name: '', from_date: '', to_date: '', notes: '' });

function create() {
    editingId.value = null;
    form.defaults({ name: '', from_date: '', to_date: '', notes: '' });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(p: Period) {
    editingId.value = p.id;
    form.defaults({ name: p.name, from_date: p.from_date, to_date: p.to_date, notes: p.notes ?? '' });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    form.transform((d) => ({ ...d, notes: d.notes || null }));
    if (editingId.value) form.put(`/leave/periods/${editingId.value}`, opts);
    else form.post('/leave/periods', opts);
}

function generate() {
    if (!confirmGenerate.value) return;
    router.post(`/leave/periods/${confirmGenerate.value.id}/generate`, {}, { preserveScroll: true });
    confirmGenerate.value = null;
}

function destroy(p: Period) {
    router.delete(`/leave/periods/${p.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('leave.pages.periods')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.periods', href: '/leave/periods' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.periods')" :description="$t('leave.periods.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('leave.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.from_date') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.to_date') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.periods.generated') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in periods" :key="p.id" class="border-t">
                            <td class="px-3 py-2">{{ p.name }}</td>
                            <td class="px-3 py-2">{{ formatDate(p.from_date) }}</td>
                            <td class="px-3 py-2">{{ formatDate(p.to_date) }}</td>
                            <td class="px-3 py-2">
                                <StatusBadge v-if="p.allocations_generated_at" status="active" :label="formatDateTime(p.allocations_generated_at)" />
                                <StatusBadge v-else status="pending" :label="$t('leave.periods.not_generated')" />
                            </td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <Button v-if="can.generate" size="sm" @click="confirmGenerate = p">{{ $t('leave.periods.generate') }}</Button>
                                <Button v-if="can.manage" size="sm" variant="outline" @click="edit(p)">{{ $t('common.actions.edit') }}</Button>
                                <Button v-if="can.manage && !p.allocations_generated_at" size="sm" variant="outline" @click="destroy(p)">{{ $t('common.actions.delete') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!periods.length"><td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ editingId ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('leave.pages.periods') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save">
                    <FormField :label="$t('leave.fields.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('leave.fields.from_date')" :error="form.errors.from_date" required><Input v-model="form.from_date" type="date" /></FormField>
                        <FormField :label="$t('leave.fields.to_date')" :error="form.errors.to_date" required><Input v-model="form.to_date" type="date" /></FormField>
                    </div>
                    <FormField :label="$t('common.fields.remarks')" :error="form.errors.notes"><TextArea v-model="form.notes" :rows="2" /></FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="confirmGenerate !== null" @update:open="(v: boolean) => { if (!v) confirmGenerate = null }">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ $t('leave.periods.generate') }}</DialogTitle>
                    <DialogDescription>{{ $t('leave.periods.generate_confirm', { name: confirmGenerate?.name ?? '' }) }}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="confirmGenerate = null">{{ $t('common.actions.cancel') }}</Button>
                    <Button @click="generate">{{ $t('common.actions.confirm') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
