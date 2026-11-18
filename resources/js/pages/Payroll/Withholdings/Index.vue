<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Plus } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
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
import { formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel, monthStart } from '@/lib/payroll';
import type { Option, Paginated } from '@/types';

type Cycle = { id: number; period: string; salary_slip_id: number | null; released_at: string | null; release_reason: string | null };
type Row = { id: number; number: string | null; employee: string | null; from_period: string; cycles: number | null; reason: string; status: string; cycle_rows: Cycle[] };

const props = defineProps<{
    rows: Paginated<Row>;
    options: { employees: { id: number; employee_no: string; full_name: string }[] };
    can: { manage: boolean; release: boolean };
}>();

const employeeOptions = computed<Option<number>[]>(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.employee_no} — ${e.full_name}` })));
const expanded = reactive<Record<number, boolean>>({});
const open = ref(false);
const indefinite = ref(false);
const form = useForm({ employee_id: null as number | null, from_period: '', cycles: 1 as string | number, reason: '' });

function startNew() {
    form.defaults({ employee_id: null, from_period: '', cycles: 1, reason: '' }).reset();
    form.clearErrors();
    indefinite.value = false;
    open.value = true;
}

function save() {
    form.transform((d) => ({ employee_id: d.employee_id, from_period: monthStart(d.from_period), cycles: indefinite.value ? null : Number(d.cycles), reason: d.reason })).post('/payroll/withholdings', {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

const cancelTarget = ref<Row | null>(null);
const releaseTarget = ref<Cycle | null>(null);
function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/payroll/withholdings/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}
function confirmRelease(reason: string) {
    if (releaseTarget.value) router.post(`/payroll/withholding-cycles/${releaseTarget.value.id}/release`, { reason }, { preserveScroll: true });
    releaseTarget.value = null;
}
</script>

<template>
    <Head :title="$t('payroll.pages.withholdings')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.withholdings', href: '/payroll/withholdings' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.withholdings')" :description="$t('payroll.withholdings.description')">
                <template #actions>
                    <Button v-if="can.manage" data-testid="withholding-new" @click="startNew"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="w-8 px-2 py-2"></th>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.withholdings.from') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.withholdings.cycles') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.reason') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="r in rows.data" :key="r.id">
                            <tr class="border-t">
                                <td class="px-2 py-2"><button type="button" class="text-muted-foreground" :aria-label="$t('payroll.withholdings.cycles')" @click="expanded[r.id] = !expanded[r.id]"><component :is="expanded[r.id] ? ChevronDown : ChevronRight" class="size-4" /></button></td>
                                <td class="px-3 py-2 font-mono text-xs">{{ r.number ?? '—' }}</td>
                                <td class="px-3 py-2">{{ r.employee }}</td>
                                <td class="px-3 py-2">{{ monthLabel(r.from_period) }}</td>
                                <td class="px-3 py-2">{{ r.cycles ?? $t('payroll.withholdings.until_released') }}</td>
                                <td class="max-w-xs truncate px-3 py-2" :title="r.reason">{{ r.reason }}</td>
                                <td class="px-3 py-2"><StatusBadge :status="r.status" :label="$t(`common.document_status.${r.status}`)" /></td>
                                <td class="px-3 py-2 text-right"><Button v-if="can.manage && r.status !== 'cancelled'" size="sm" variant="ghost" @click="cancelTarget = r">{{ $t('common.actions.cancel_doc') }}</Button></td>
                            </tr>
                            <tr v-if="expanded[r.id]" class="border-t bg-muted/20">
                                <td></td>
                                <td colspan="7" class="px-3 py-2">
                                    <table class="w-full text-xs">
                                        <thead class="text-left text-muted-foreground"><tr><th class="py-1">{{ $t('payroll.arrears.month') }}</th><th>{{ $t('common.fields.status') }}</th><th></th></tr></thead>
                                        <tbody>
                                            <tr v-for="c in r.cycle_rows" :key="c.id" class="border-t">
                                                <td class="py-1">{{ monthLabel(c.period) }}</td>
                                                <td>
                                                    <span v-if="c.released_at">{{ $t('payroll.withholdings.released', { at: formatDateTime(c.released_at) }) }}<span v-if="c.release_reason"> — {{ c.release_reason }}</span></span>
                                                    <span v-else-if="c.salary_slip_id">{{ $t('payroll.withholdings.held') }}</span>
                                                    <span v-else class="text-muted-foreground">{{ $t('payroll.withholdings.pending_slip') }}</span>
                                                </td>
                                                <td class="text-right"><Button v-if="can.release && c.salary_slip_id && !c.released_at" size="sm" variant="outline" data-testid="release-cycle" @click="releaseTarget = c">{{ $t('payroll.withholdings.release') }}</Button></td>
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
        <ReasonDialog :open="releaseTarget !== null" :title="$t('payroll.withholdings.release')" :description="$t('payroll.withholdings.release_hint')" @update:open="(v: boolean) => { if (!v) releaseTarget = null }" @confirm="confirmRelease" />

        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>{{ $t('payroll.withholdings.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="save">
                    <FormField :label="$t('payroll.fields.employee')" :error="form.errors.employee_id" required><SelectInput v-model="form.employee_id" :options="employeeOptions" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.withholdings.from')" :error="form.errors.from_period" required><Input v-model="form.from_period" type="month" /></FormField>
                        <FormField :label="$t('payroll.withholdings.cycles')" :error="form.errors.cycles"><Input v-model="form.cycles" type="number" min="1" max="24" step="1" :disabled="indefinite" /></FormField>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="indefinite" type="checkbox" class="size-4 accent-primary" />{{ $t('payroll.withholdings.until_released') }}</label>
                    <FormField :label="$t('common.fields.reason')" :error="form.errors.reason" required><TextArea v-model="form.reason" :rows="2" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing" data-testid="withholding-save">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
