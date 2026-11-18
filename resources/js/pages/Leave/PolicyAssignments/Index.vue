<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { postJson } from '@/lib/leave';
import type { Option, Paginated } from '@/types';

type Assignment = {
    id: number;
    number: string | null;
    employee: string;
    policy: string;
    effective_from: string;
    effective_to: string | null;
    status: string;
    status_label: string;
    remarks: string | null;
};
type BulkResult = { employee_id: number; employee: string; ok: boolean; message: string | null };

const props = defineProps<{
    assignments: Paginated<Assignment>;
    options: {
        employees: { id: number; full_name: string; employee_no: string }[];
        policies: { id: number; name: string }[];
        branches: Option<number>[];
        departments: Option<number>[];
        employment_types: Option<number>[];
    };
}>();

const employeeOptions = computed(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` })));
const policyOptions = computed(() => props.options.policies.map((p) => ({ value: p.id, label: p.name })));

// single assignment
const single = ref(false);
const form = useForm({ employee_id: null as number | null, leave_policy_id: null as number | null, effective_from: '', remarks: '' });

function saveSingle(submit: boolean) {
    form.transform((d) => ({ ...d, submit, remarks: d.remarks || null })).post('/leave/policy-assignments', { preserveScroll: true, onSuccess: () => { single.value = false; form.reset(); } });
}

// row actions
const cancelTarget = ref<Assignment | null>(null);

function submitRow(a: Assignment) {
    router.post(`/leave/policy-assignments/${a.id}/submit`, {}, { preserveScroll: true });
}

function destroyRow(a: Assignment) {
    router.delete(`/leave/policy-assignments/${a.id}`, { preserveScroll: true });
}

function confirmCancel(reason: string) {
    if (cancelTarget.value) router.post(`/leave/policy-assignments/${cancelTarget.value.id}/cancel`, { reason }, { preserveScroll: true });
    cancelTarget.value = null;
}

// Penetapan Pukal wizard: filters -> preview -> run (per-employee results)
const bulk = ref(false);
const step = ref<1 | 2 | 3>(1);
const bulkForm = ref({ leave_policy_id: null as number | null, effective_from: '', branch_id: null as number | null, department_id: null as number | null, employment_type_id: null as number | null });
const preview = ref<BulkResult[]>([]);
const results = ref<BulkResult[]>([]);
const working = ref(false);
const bulkError = ref<string | null>(null);
const failed = computed(() => results.value.filter((r) => !r.ok));

function openBulk() {
    step.value = 1;
    preview.value = [];
    results.value = [];
    bulkError.value = null;
    bulk.value = true;
}

function bulkPayload(previewOnly: boolean): Record<string, unknown> {
    return { ...bulkForm.value, bulk: true, preview: previewOnly };
}

async function runPreview() {
    working.value = true;
    bulkError.value = null;
    try {
        const answer = await postJson<{ results?: BulkResult[]; message?: string }>('/leave/policy-assignments', bulkPayload(true));
        if (!answer.results) throw new Error(answer.message ?? 'invalid');
        preview.value = answer.results;
        step.value = 2;
    } catch {
        bulkError.value = 'leave.bulk.failed';
    } finally {
        working.value = false;
    }
}

async function runBulk() {
    working.value = true;
    bulkError.value = null;
    try {
        const answer = await postJson<{ results?: BulkResult[] }>('/leave/policy-assignments', bulkPayload(false));
        results.value = answer.results ?? [];
        step.value = 3;
        router.reload({ only: ['assignments'] });
    } catch {
        bulkError.value = 'leave.bulk.failed';
    } finally {
        working.value = false;
    }
}
</script>

<template>
    <Head :title="$t('leave.pages.assignments')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.assignments', href: '/leave/policy-assignments' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.assignments')" :description="$t('leave.assignments.description')">
                <template #actions>
                    <Button variant="outline" @click="openBulk"><Users class="size-4" />{{ $t('leave.bulk.title') }}</Button>
                    <Button @click="single = true"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.fields.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.assignments.policy') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.assignments.effective_from') }}</th>
                            <th class="px-3 py-2">{{ $t('leave.assignments.effective_to') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in assignments.data" :key="a.id" class="border-t">
                            <td class="px-3 py-2 font-mono">{{ a.number ?? '—' }}</td>
                            <td class="px-3 py-2">{{ a.employee }}</td>
                            <td class="px-3 py-2">{{ a.policy }}</td>
                            <td class="px-3 py-2">{{ formatDate(a.effective_from) }}</td>
                            <td class="px-3 py-2">{{ a.effective_to ? formatDate(a.effective_to) : '—' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="a.status" :label="a.status_label" /></td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <template v-if="a.status === 'draft'">
                                    <Button size="sm" @click="submitRow(a)">{{ $t('common.actions.submit') }}</Button>
                                    <Button size="sm" variant="outline" @click="destroyRow(a)">{{ $t('common.actions.delete') }}</Button>
                                </template>
                                <Button v-else-if="a.status === 'submitted'" size="sm" variant="outline" @click="cancelTarget = a">{{ $t('common.actions.cancel_doc') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!assignments.data.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="assignments.links" :from="assignments.from" :to="assignments.to" :total="assignments.total" />
        </div>

        <ReasonDialog :open="cancelTarget !== null" :title="$t('common.actions.cancel_doc')" destructive @update:open="(v: boolean) => { if (!v) cancelTarget = null }" @confirm="confirmCancel" />

        <Dialog v-model:open="single">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('leave.assignments.new') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="saveSingle(true)">
                    <FormField :label="$t('leave.fields.employee')" :error="form.errors.employee_id" required><SelectInput v-model="form.employee_id" :options="employeeOptions" /></FormField>
                    <FormField :label="$t('leave.assignments.policy')" :error="form.errors.leave_policy_id" required><SelectInput v-model="form.leave_policy_id" :options="policyOptions" /></FormField>
                    <FormField :label="$t('leave.assignments.effective_from')" :error="form.errors.effective_from" required><Input v-model="form.effective_from" type="date" /></FormField>
                    <FormField :label="$t('common.fields.remarks')" :error="form.errors.remarks"><Input v-model="form.remarks" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="single = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="saveSingle(false)">{{ $t('leave.apply.save_draft') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.submit') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="bulk">
            <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ $t('leave.bulk.title') }} · {{ $t(`leave.bulk.step_${step}`) }}</DialogTitle>
                    <DialogDescription>{{ $t('leave.bulk.description') }}</DialogDescription>
                </DialogHeader>

                <div v-if="step === 1" class="grid gap-3">
                    <FormField :label="$t('leave.assignments.policy')" required><SelectInput v-model="bulkForm.leave_policy_id" :options="policyOptions" /></FormField>
                    <FormField :label="$t('leave.assignments.effective_from')" required><Input v-model="bulkForm.effective_from" type="date" /></FormField>
                    <FormField :label="$t('leave.bulk.branch')"><SelectInput v-model="bulkForm.branch_id" :options="options.branches" :placeholder="$t('leave.bulk.all')" /></FormField>
                    <FormField :label="$t('leave.bulk.department')"><SelectInput v-model="bulkForm.department_id" :options="options.departments" :placeholder="$t('leave.bulk.all')" /></FormField>
                    <FormField :label="$t('leave.bulk.employment_type')"><SelectInput v-model="bulkForm.employment_type_id" :options="options.employment_types" :placeholder="$t('leave.bulk.all')" /></FormField>
                </div>

                <div v-else-if="step === 2" class="space-y-2">
                    <p class="text-sm" data-testid="bulk-preview-count">{{ $t('leave.bulk.preview_count', { count: String(preview.length) }) }}</p>
                    <ul class="max-h-64 overflow-y-auto rounded border p-2 text-sm">
                        <li v-for="r in preview" :key="r.employee_id">{{ r.employee }}</li>
                    </ul>
                </div>

                <div v-else class="space-y-2" data-testid="bulk-results">
                    <p class="text-sm">{{ $t('leave.bulk.result_summary', { ok: String(results.length - failed.length), failed: String(failed.length) }) }}</p>
                    <ul class="max-h-64 divide-y overflow-y-auto rounded border text-sm">
                        <li v-for="r in results" :key="r.employee_id" class="flex justify-between gap-3 px-2 py-1">
                            <span>{{ r.employee }}</span>
                            <span :class="r.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-600'">{{ r.ok ? $t('leave.bulk.ok') : r.message }}</span>
                        </li>
                    </ul>
                </div>

                <p v-if="bulkError" class="text-sm text-red-600">{{ $t(bulkError) }}</p>

                <DialogFooter class="gap-2">
                    <Button v-if="step === 2" variant="outline" @click="step = 1">{{ $t('common.actions.back') }}</Button>
                    <Button v-if="step === 1" :disabled="working || !bulkForm.leave_policy_id || !bulkForm.effective_from" @click="runPreview">{{ $t('leave.bulk.preview') }}</Button>
                    <Button v-if="step === 2" :disabled="working || !preview.length" @click="runBulk">{{ $t('leave.bulk.run') }}</Button>
                    <Button v-if="step === 3" @click="bulk = false">{{ $t('common.actions.close') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
