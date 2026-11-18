<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Plus } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
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
import { monthLabel, monthStart } from '@/lib/payroll';
import type { Option, Paginated } from '@/types';

type Line = { period: string; component: string; entitled_amount: string; paid_amount: string; difference: string; included: boolean; basis: string | null };
type Row = { id: number; number: string | null; employee: string | null; from_period: string; to_period: string; payout_period: string; total_amount: string; status: string; lines: Line[] };

const props = defineProps<{
    rows: Paginated<Row>;
    assignments: { id: number; number: string; employee: string; effective_from: string; base_salary: string }[];
    can: { manage: boolean };
}>();

const assignmentOptions = computed<Option<number>[]>(() => props.assignments.map((a) => ({ value: a.id, label: `${a.employee} — ${a.number} (${formatDate(a.effective_from)})` })));
const expanded = reactive<Record<number, boolean>>({});
const open = ref(false);
const form = useForm({ salary_assignment_id: null as number | null, from_period: '', to_period: '', payout_period: '', notes: '' });

function startNew(assignmentId: number | null = null) {
    const chosen = props.assignments.find((a) => a.id === assignmentId);
    form.defaults({
        salary_assignment_id: chosen?.id ?? null,
        from_period: chosen ? chosen.effective_from.slice(0, 7) : '',
        to_period: '',
        payout_period: '',
        notes: '',
    }).reset();
    form.clearErrors();
    open.value = true;
}

// "Jana Tunggakan" banner on Penugasan Struktur Gaji links here with ?assignment=<id>.
onMounted(() => {
    const id = Number(new URLSearchParams(window.location.search).get('assignment'));
    if (id && props.can.manage) startNew(id);
});

function compute() {
    form.transform((d) => ({ ...d, from_period: monthStart(d.from_period), to_period: monthStart(d.to_period), payout_period: monthStart(d.payout_period), notes: d.notes || null })).post('/payroll/arrears', {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

const cancelTarget = ref<Row | null>(null);
function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/payroll/arrears/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}
</script>

<template>
    <Head :title="$t('payroll.pages.arrears')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.arrears', href: '/payroll/arrears' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.arrears')" :description="$t('payroll.arrears.description')">
                <template #actions>
                    <Button v-if="can.manage" data-testid="arrear-new" @click="startNew()"><Plus class="size-4" />{{ $t('payroll.arrears.compute') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="w-8 px-2 py-2"></th>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.arrears.range') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.arrears.payout') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.arrears.total') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="r in rows.data" :key="r.id">
                            <tr class="border-t">
                                <td class="px-2 py-2"><button type="button" class="text-muted-foreground" :aria-label="$t('payroll.arrears.lines')" @click="expanded[r.id] = !expanded[r.id]"><component :is="expanded[r.id] ? ChevronDown : ChevronRight" class="size-4" /></button></td>
                                <td class="px-3 py-2 font-mono text-xs">{{ r.number ?? '—' }}</td>
                                <td class="px-3 py-2">{{ r.employee }}</td>
                                <td class="px-3 py-2">{{ monthLabel(r.from_period) }} – {{ monthLabel(r.to_period) }}</td>
                                <td class="px-3 py-2">{{ monthLabel(r.payout_period) }}</td>
                                <td class="px-3 py-2 text-right"><MoneyText :value="r.total_amount" strong /></td>
                                <td class="px-3 py-2"><StatusBadge :status="r.status" :label="$t(`common.document_status.${r.status}`)" /></td>
                                <td class="space-x-1 px-3 py-2 text-right">
                                    <Button v-if="can.manage && r.status === 'draft'" size="sm" @click="router.post(`/payroll/arrears/${r.id}/submit`, {}, { preserveScroll: true })">{{ $t('common.actions.submit') }}</Button>
                                    <Button v-if="can.manage && r.status !== 'cancelled'" size="sm" variant="ghost" @click="cancelTarget = r">{{ $t('common.actions.cancel_doc') }}</Button>
                                </td>
                            </tr>
                            <tr v-if="expanded[r.id]" class="border-t bg-muted/20">
                                <td></td>
                                <td colspan="7" class="px-3 py-2">
                                    <table class="w-full text-xs">
                                        <thead class="text-left text-muted-foreground"><tr><th class="py-1">{{ $t('payroll.arrears.month') }}</th><th>{{ $t('payroll.additional.component') }}</th><th class="text-right">{{ $t('payroll.arrears.entitled') }}</th><th class="text-right">{{ $t('payroll.arrears.paid') }}</th><th class="text-right">{{ $t('payroll.arrears.difference') }}</th><th class="pl-3">{{ $t('payroll.arrears.note') }}</th></tr></thead>
                                        <tbody>
                                            <tr v-for="(l, n) in r.lines" :key="n" class="border-t" :class="l.included ? '' : 'text-muted-foreground'">
                                                <td class="py-1">{{ monthLabel(l.period) }}</td><td class="font-mono">{{ l.component }}</td>
                                                <td class="text-right"><MoneyText :value="l.entitled_amount" /></td><td class="text-right"><MoneyText :value="l.paid_amount" /></td><td class="text-right"><MoneyText :value="l.difference" /></td>
                                                <td class="pl-3">{{ l.included ? '' : $t('payroll.arrears.not_included') }} {{ l.basis }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!rows.data.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rows.links" :from="rows.from" :to="rows.to" :total="rows.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="open">
            <DialogContent class="max-w-xl">
                <DialogHeader><DialogTitle>{{ $t('payroll.arrears.compute') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="compute">
                    <FormField :label="$t('payroll.arrears.assignment')" :error="form.errors.salary_assignment_id" :hint="$t('payroll.arrears.assignment_hint')" required><SelectInput v-model="form.salary_assignment_id" :options="assignmentOptions" /></FormField>
                    <div class="grid grid-cols-3 gap-3">
                        <FormField :label="$t('payroll.additional.from_month')" :error="form.errors.from_period" required><Input v-model="form.from_period" type="month" /></FormField>
                        <FormField :label="$t('payroll.additional.to_month')" :error="form.errors.to_period" required><Input v-model="form.to_period" type="month" /></FormField>
                        <FormField :label="$t('payroll.arrears.payout')" :error="form.errors.payout_period" required><Input v-model="form.payout_period" type="month" /></FormField>
                    </div>
                    <FormField :label="$t('common.fields.remarks')" :error="form.errors.notes"><TextArea v-model="form.notes" :rows="2" /></FormField>
                    <p class="text-xs text-muted-foreground">{{ $t('payroll.arrears.negative_note') }}</p>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing" data-testid="arrear-compute">{{ $t('payroll.arrears.compute') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
