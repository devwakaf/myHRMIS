<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';
import type { Option, Paginated } from '@/types';

type Adjustment = {
    id: number;
    number: string | null;
    employee: string;
    direction: string;
    direction_label: string;
    days: string;
    effective_date: string;
    expires_on: string | null;
    reason: string;
    status: string;
    status_label: string;
};

defineProps<{ adjustments: Paginated<Adjustment>; can: { submit: boolean }; options: { allocations: Option<number>[] } }>();

const open = ref(false);
const form = useForm({ leave_allocation_id: null as number | null, direction: 'add', days: '', effective_date: '', expires_on: '', reason: '' });
const cancelTarget = ref<Adjustment | null>(null);

function save(submit: boolean) {
    form.transform((d) => ({ ...d, submit, expires_on: d.expires_on || null }))
        .post('/leave/adjustments', { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });
}

function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/leave/adjustments/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}
</script>

<template>
    <Head :title="$t('leave.pages.adjustments')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.adjustments', href: '/leave/adjustments' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.adjustments')" :description="$t('leave.adjustments.description')">
                <template #actions>
                    <Button @click="open = true"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.adjustments.direction') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('leave.fields.days') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.adjustments.effective_date') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.reason') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in adjustments.data" :key="a.id" class="border-t">
                            <td class="px-3 py-2 font-mono">{{ a.number ?? '—' }}</td>
                            <td class="px-3 py-2">{{ a.employee }}</td>
                            <td class="px-3 py-2">{{ a.direction_label }}</td>
                            <td class="px-3 py-2 text-right">{{ days(a.days) }}</td>
                            <td class="px-3 py-2">{{ formatDate(a.effective_date) }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ a.reason }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="a.status" :label="a.status_label" /></td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <template v-if="can.submit">
                                    <Button v-if="a.status === 'draft'" size="sm" @click="router.post(`/leave/adjustments/${a.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                    <Button v-if="a.status === 'submitted'" size="sm" variant="outline" @click="cancelTarget = a">{{ $t('common.actions.cancel_doc') }}</Button>
                                </template>
                                <Button v-if="a.status === 'draft'" size="sm" variant="outline" @click="router.delete(`/leave/adjustments/${a.id}`, { preserveScroll: true })">{{ $t('common.actions.delete') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!adjustments.data.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="adjustments.links" :from="adjustments.from" :to="adjustments.to" :total="adjustments.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('leave.adjustments.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save(can.submit)">
                    <FormField :label="$t('leave.adjustments.allocation')" :error="form.errors.leave_allocation_id" required><SelectInput v-model="form.leave_allocation_id" :options="options.allocations" /></FormField>
                    <FormField :label="$t('leave.adjustments.direction')" :error="form.errors.direction" required>
                        <SelectInput v-model="form.direction" :nullable="false" :options="[{ value: 'add', label: $t('leave.enums.adjustment_direction.add') }, { value: 'reduce', label: $t('leave.enums.adjustment_direction.reduce') }]" />
                    </FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('leave.fields.days')" :error="form.errors.days" required><Input v-model="form.days" type="number" step="0.5" min="0" /></FormField>
                        <FormField :label="$t('leave.adjustments.effective_date')" :error="form.errors.effective_date" required><Input v-model="form.effective_date" type="date" /></FormField>
                    </div>
                    <FormField v-if="form.direction === 'add'" :label="$t('leave.adjustments.expires_on')" :error="form.errors.expires_on" :hint="$t('leave.adjustments.expires_hint')"><Input v-model="form.expires_on" type="date" /></FormField>
                    <FormField :label="$t('common.fields.reason')" :error="form.errors.reason" required><TextArea v-model="form.reason" :rows="2" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="save(false)">{{ $t('leave.apply.save_draft') }}</Button>
                        <Button v-if="can.submit" type="submit" :disabled="form.processing">{{ $t('common.actions.submit') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
