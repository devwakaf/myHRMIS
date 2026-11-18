<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ApprovalTimeline from '@/components/app/ApprovalTimeline.vue';
import type { TimelineData } from '@/components/app/ApprovalTimeline.vue';
import DetailList from '@/components/app/DetailList.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import RoutePreview from '@/components/attendance/RoutePreview.vue';
import type { RouteData } from '@/components/attendance/RoutePreview.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatMoney } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { hoursMinutes } from '@/lib/attendance';
import type { ClaimRow } from '@/lib/attendance';
import { days as dec } from '@/lib/leave';
import type { Option } from '@/types';

const props = defineProps<{
    claim: ClaimRow;
    needs_compliance_review: boolean;
    route: RouteData | null;
    timeline: TimelineData | null;
    approval_request_id: number | null;
    options: { day_types: Option<string>[] };
    can: { manage: boolean; submit: boolean; cancel: boolean };
}>();

const lines = computed(() => props.claim.lines ?? []);
const isDraft = computed(() => props.claim.status === 'draft');
const showAmounts = computed(() => props.claim.total_amount !== null);
const capPercent = computed(() => (props.claim.cap_minutes ? Math.min(100, Math.round((props.claim.cap_counted_minutes / props.claim.cap_minutes) * 100)) : null));

const wage = ref(props.claim.monthly_wage_snapshot ?? '');
const capReason = ref(props.claim.cap_override_reason ?? '');
const choices = ref<Record<string, number | null>>({});
const lineOpen = ref(false);
const cancelOpen = ref(false);
const lineForm = useForm({ line: { work_date: '', day_type: 'normal', worked_minutes: 0, beyond_normal_minutes: 0, amount: '0', note: '' } });

function saveWage() {
    router.put(`/attendance/overtime-claims/${props.claim.id}`, { monthly_wage_snapshot: wage.value === '' ? null : wage.value }, { preserveScroll: true });
}

function saveCapReason() {
    router.put(`/attendance/overtime-claims/${props.claim.id}`, { cap_override_reason: capReason.value || null }, { preserveScroll: true });
}

function addLine() {
    lineForm.put(`/attendance/overtime-claims/${props.claim.id}`, { preserveScroll: true, onSuccess: () => ((lineOpen.value = false), lineForm.reset()) });
}

function removeLine(id: number) {
    router.delete(`/attendance/overtime-claims/${props.claim.id}/lines/${id}`, { preserveScroll: true });
}

function submit() {
    router.post(`/attendance/overtime-claims/${props.claim.id}/submit`, { cap_override_reason: capReason.value || null, choices: choices.value }, { preserveScroll: true });
}

function destroy() {
    router.delete(`/attendance/overtime-claims/${props.claim.id}`);
}

function cancel(reason: string) {
    cancelOpen.value = false;
    router.post(`/attendance/overtime-claims/${props.claim.id}/cancel`, { reason }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('attendance.pages.overtime_claim_show')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.overtime_claims', href: '/attendance/overtime-claims' }, { title: 'attendance.pages.overtime_claim_show', href: `/attendance/overtime-claims/${claim.id}` }]">
        <div class="space-y-6 p-4 md:p-6">
            <PageHeader :title="`${claim.number ?? '#' + claim.id} · ${claim.period_month.slice(0, 7)}`" :description="claim.employee ? `${claim.employee.full_name} · ${claim.employee.employee_no}` : undefined">
                <template #meta><StatusBadge :status="claim.status" :label="claim.status_label" /></template>
                <template #actions>
                    <Button v-if="can.submit" data-testid="submit-claim" :disabled="claim.cap_exceeded && !capReason.trim()" @click="submit">{{ $t('attendance.common.submit_for_approval') }}</Button>
                    <Button v-if="can.manage && isDraft" variant="outline" @click="destroy"><Trash2 class="size-4" />{{ $t('common.actions.delete') }}</Button>
                    <Button v-if="can.cancel" variant="destructive" @click="cancelOpen = true">{{ $t('common.actions.cancel_doc') }}</Button>
                    <Button variant="ghost" as-child><Link href="/attendance/overtime-claims">{{ $t('common.actions.back') }}</Link></Button>
                </template>
            </PageHeader>

            <div class="grid gap-4 lg:grid-cols-3">
                <section class="space-y-3 rounded-lg border p-4 lg:col-span-2">
                    <DetailList
                        :items="[
                            { label: $t('attendance.claim.eligibility'), value: claim.eligibility_label },
                            { label: $t('attendance.claim.total_hours'), value: dec(claim.total_hours) },
                            { label: $t('attendance.claim.wage_source'), value: claim.wage_source ? $t(`attendance.enums.wage_source.${claim.wage_source}`) : null },
                            { label: $t('attendance.claim.wage'), value: claim.monthly_wage_snapshot ? formatMoney(claim.monthly_wage_snapshot) : null, hidden: !showAmounts },
                            { label: 'ORP', value: claim.orp ? formatMoney(claim.orp) : null, hidden: !showAmounts },
                            { label: 'HRP', value: claim.hrp ? formatMoney(claim.hrp) : null, hidden: !showAmounts },
                            { label: $t('attendance.claim.total_amount'), value: claim.total_amount ? formatMoney(claim.total_amount) : null, hidden: !showAmounts },
                        ]"
                    />
                    <p v-if="claim.wage_missing && isDraft" class="rounded-md bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100" data-testid="wage-missing">{{ $t('attendance.claim.wage_missing') }}</p>
                    <div v-if="can.manage && isDraft && showAmounts" class="flex flex-wrap items-end gap-2">
                        <FormField :label="$t('attendance.claim.manual_wage')" for="wage"><Input id="wage" v-model="wage" type="number" step="0.01" min="0" class="w-44" /></FormField>
                        <Button variant="outline" @click="saveWage">{{ $t('common.actions.save') }}</Button>
                    </div>
                </section>

                <section class="space-y-2 rounded-lg border p-4" data-testid="cap-gauge">
                    <h2 class="text-sm font-medium">{{ $t('attendance.claim.cap') }}</h2>
                    <p class="text-sm tabular-nums">{{ hoursMinutes(claim.cap_counted_minutes) }} / {{ claim.cap_minutes ? hoursMinutes(claim.cap_minutes) : '—' }}</p>
                    <div v-if="capPercent !== null" class="h-3 w-full overflow-hidden rounded bg-muted"><div class="h-full" :class="claim.cap_exceeded ? 'bg-red-500' : 'bg-emerald-500'" :style="{ width: `${capPercent}%` }" /></div>
                    <p v-if="claim.cap_exceeded" class="text-xs text-red-600">{{ $t('attendance.claim.cap_exceeded') }}</p>
                    <p v-if="needs_compliance_review" class="text-xs text-amber-700 dark:text-amber-300">{{ $t('attendance.claim.compliance_review') }}</p>
                    <div v-if="(can.manage || can.submit) && isDraft && claim.cap_exceeded" class="space-y-2">
                        <FormField :label="$t('attendance.claim.cap_reason')" required><TextArea v-model="capReason" :rows="2" /></FormField>
                        <Button size="sm" variant="outline" @click="saveCapReason">{{ $t('common.actions.save') }}</Button>
                    </div>
                    <p v-else-if="claim.cap_override_reason" class="text-xs text-muted-foreground">{{ claim.cap_override_reason }}</p>
                </section>
            </div>

            <section class="space-y-2">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-medium">{{ $t('attendance.claim.lines') }}</h2>
                    <Button v-if="can.manage && isDraft" size="sm" variant="outline" @click="lineOpen = true"><Plus class="size-4" />{{ $t('attendance.claim.add_line') }}</Button>
                </div>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm" data-testid="claim-lines">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-3 py-2">{{ $t('attendance.common.date') }}</th>
                                <th class="px-3 py-2">{{ $t('attendance.claim.day_type') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('attendance.claim.worked') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('attendance.claim.beyond_normal') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('attendance.claim.multiplier') }}</th>
                                <th v-if="showAmounts" class="px-3 py-2 text-right">{{ $t('attendance.claim.amount') }}</th>
                                <th class="px-3 py-2">{{ $t('attendance.claim.flags') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in lines" :key="l.id" class="border-t">
                                <td class="px-3 py-1.5">{{ formatDate(l.work_date) }}<span v-if="l.is_manual" class="ml-1 rounded bg-sky-100 px-1 text-[10px] text-sky-800 dark:bg-sky-900 dark:text-sky-100">{{ $t('attendance.claim.manual') }}</span></td>
                                <td class="px-3 py-1.5">{{ l.day_type_label }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ hoursMinutes(l.worked_minutes) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ hoursMinutes(l.beyond_normal_minutes) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ dec(l.multiplier) }}</td>
                                <td v-if="showAmounts" class="px-3 py-1.5 text-right tabular-nums">{{ l.amount !== null ? formatMoney(l.amount) : '—' }}</td>
                                <td class="px-3 py-1.5 text-xs">
                                    <span v-for="flag in l.flags" :key="flag" class="mr-1 rounded bg-amber-100 px-1 text-amber-800 dark:bg-amber-900 dark:text-amber-100">{{ $t(`attendance.reports.flags.${flag}`) }}</span>
                                    <span v-if="l.note" class="text-muted-foreground">{{ l.note }}</span>
                                </td>
                                <td class="px-3 py-1.5 text-right"><Button v-if="can.manage && isDraft && l.is_manual" size="icon-sm" variant="ghost" :aria-label="$t('common.actions.delete')" @click="removeLine(l.id)"><Trash2 class="size-4" /></Button></td>
                            </tr>
                            <tr v-if="!lines.length"><td :colspan="showAmounts ? 8 : 7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <RoutePreview v-if="route && can.submit" v-model:choices="choices" :route="route" class="max-w-xl" />

            <section v-if="timeline" class="space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.common.approval_progress') }}</h2>
                <ApprovalTimeline :timeline="timeline" />
            </section>
        </div>

        <ReasonDialog :open="cancelOpen" :title="$t('common.actions.cancel_doc')" :destructive="true" @update:open="(v: boolean) => (cancelOpen = v)" @confirm="cancel" />

        <Dialog v-model:open="lineOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ $t('attendance.claim.add_line') }}</DialogTitle></DialogHeader>
                <form class="space-y-3" @submit.prevent="addLine">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.common.date')" :error="lineForm.errors['line.work_date']" required><Input v-model="lineForm.line.work_date" type="date" /></FormField>
                        <FormField :label="$t('attendance.claim.day_type')" :error="lineForm.errors['line.day_type']" required><SelectInput v-model="lineForm.line.day_type" :options="options.day_types" :nullable="false" /></FormField>
                        <FormField :label="$t('attendance.claim.worked_minutes')" :error="lineForm.errors['line.worked_minutes']" required><Input v-model="lineForm.line.worked_minutes" type="number" min="0" /></FormField>
                        <FormField :label="$t('attendance.claim.beyond_minutes')" :error="lineForm.errors['line.beyond_normal_minutes']"><Input v-model="lineForm.line.beyond_normal_minutes" type="number" min="0" /></FormField>
                        <FormField :label="$t('attendance.claim.amount')" :error="lineForm.errors['line.amount']" required><Input v-model="lineForm.line.amount" type="number" step="0.01" min="0" /></FormField>
                    </div>
                    <FormField :label="$t('common.fields.note')" :error="lineForm.errors['line.note']" required><TextArea v-model="lineForm.line.note" :rows="2" /></FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="lineOpen = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="lineForm.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
