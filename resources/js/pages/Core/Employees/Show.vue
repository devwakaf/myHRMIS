<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DetailList from '@/components/app/DetailList.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TabNav from '@/components/app/TabNav.vue';
import TextArea from '@/components/app/TextArea.vue';
import EmployeeAttendancePanel from '@/components/attendance/EmployeeAttendancePanel.vue';
import BalancesPanel from '@/components/leave/BalancesPanel.vue';
import EmployeePayrollPanel from '@/components/payroll/EmployeePayrollPanel.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Emp = Record<string, unknown> & {
    id: number;
    full_name: string;
    display_name: string;
    employee_no: string;
    status: string;
    labels: Record<string, string | null>;
    tax: Record<string, unknown> | null;
    sensitive_visible: boolean;
    ic_no_masked?: string | null;
};

const props = defineProps<{
    employee: Emp;
    emergencyContacts: { id: number; name: string; relationship: string; relationship_label: string; phone: string; alt_phone: string | null; address: string | null; is_primary: boolean }[];
    documents: { id: number; title: string; type: string | null; original_name: string; size_bytes: number; issued_on: string | null; expires_on: string | null; uploaded_at: string | null }[];
    workHistory: { id: number; from_date: string; to_date: string | null; branch: string | null; department: string | null; designation: string | null; grade: string | null; employment_type: string | null; source: string }[];
    movements: { id: number; number: string; type: string; effective_date: string; status: string; status_label: string; applied: boolean }[];
    qualifications: { id: number; level: string; institution: string; field: string | null; year_completed: number | null; grade_result: string | null }[];
    previousEmployments: { id: number; employer_name: string; position: string | null; from_date: string | null; to_date: string | null; reason_left: string | null; last_salary: string | null }[];
    approvers: Record<string, { value: number; label: string | null }>;
    lindung24: { id: number; status: string; effective_from: string; effective_to: string | null; note: string | null }[];
    audit: { id: number; event: string | null; description: string; causer: string | null; properties: Record<string, unknown>; created_at: string | null }[];
    holidayList: { id: number; name: string; fallback: boolean } | null;
    directReports: number;
    options: Record<string, Option[]> | null;
    can: Record<string, boolean>;
}>();

const e = props.employee;
const v = (key: string) => (e[key] === null || e[key] === undefined ? null : String(e[key]));
const d = (key: string) => formatDate(v(key));
const yn = (key: string) => (e[key] === null || e[key] === undefined ? null : e[key] ? 'common.yes' : 'common.no');

const tab = ref('summary');
const tabs = computed(() => [
    { key: 'summary', label: 'core.employee.tabs.summary' },
    { key: 'personal', label: 'core.employee.tabs.personal' },
    { key: 'contact', label: 'core.employee.tabs.contact' },
    { key: 'employment', label: 'core.employee.tabs.employment' },
    { key: 'statutory', label: 'core.employee.tabs.statutory', hidden: !props.can.sensitive },
    { key: 'bank', label: 'core.employee.tabs.bank', hidden: !props.can.sensitive },
    { key: 'emergency', label: 'core.employee.tabs.emergency' },
    { key: 'documents', label: 'core.employee.tabs.documents', hidden: !props.can.viewDocuments },
    { key: 'history', label: 'core.employee.tabs.history', hidden: !props.can.hr },
    { key: 'qualifications', label: 'core.employee.tabs.qualifications' },
    { key: 'approvers', label: 'core.employee.tabs.approvers', hidden: !props.can.hr },
    { key: 'leave', label: 'leave.title', hidden: !props.can.leave },
    { key: 'attendance', label: 'attendance.title', hidden: !props.can.attendance },
    { key: 'payroll', label: 'payroll.title', hidden: !props.can.payroll },
    { key: 'audit', label: 'core.employee.tabs.audit', hidden: !props.can.audit },
]);

const dialog = ref<null | 'correct' | 'separate' | 'user' | 'document' | 'qualification' | 'previous' | 'approvers' | 'contacts' | 'lindung' | 'rehire' | 'reports'>(null);
const close = () => (dialog.value = null);
const opts = { preserveScroll: true, onSuccess: close };

const correctForm = useForm({
    branch_id: e.branch_id as number | null, department_id: e.department_id as number | null, designation_id: e.designation_id as number | null,
    grade_id: e.grade_id as number | null, employment_type_id: e.employment_type_id as number | null, reports_to_id: e.reports_to_id as number | null, reason: '',
});
const separateForm = useForm({ resignation_date: '', relieving_date: '', separation_reason: 'resignation', separation_note: '', eligible_for_rehire: true });
const userForm = useForm({ email: v('work_email') ?? v('personal_email') ?? '', password: '', locale: 'ms' });
const documentForm = useForm({ file: null as File | null, document_type_id: null as number | null, title: '', issued_on: '', expires_on: '', remind_before_days: null as number | null });
const qualificationForm = useForm({ level: 'degree', institution: '', field: '', year_completed: null as number | null, grade_result: '' });
const previousForm = useForm({ employer_name: '', position: '', from_date: '', to_date: '', last_salary: '', reason_left: '' });
const approverForm = useForm({ approvers: Object.fromEntries(Object.entries(props.approvers).map(([k, a]) => [k, a.value])) as Record<string, number | null> });
const contactsForm = useForm({ emergency_contacts: props.emergencyContacts.map((c) => ({ name: c.name, relationship: c.relationship, phone: c.phone, alt_phone: c.alt_phone, address: c.address, is_primary: c.is_primary })) });
const lindungForm = useForm({ status: 'opted_in', month: '', note: '' });
const rehireForm = useForm({ date_of_joining: '', branch_id: null as number | null, department_id: e.department_id as number | null, designation_id: e.designation_id as number | null, grade_id: null as number | null, employment_type_id: e.employment_type_id as number | null, reports_to_id: null as number | null, contract_end_date: '', continuous_service_from: '', continuous_service_reason: '' });
const reportsForm = useForm({ reports_to_id: null as number | null });

const nullify = <T extends Record<string, unknown>>(data: T) => Object.fromEntries(Object.entries(data).map(([k, x]) => [k, x === '' ? null : x])) as T;
const base = `/employees/${e.id}`;

function deleteRow(url: string) {
    router.delete(url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="employee.full_name" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.employees', href: '/employees' }, { title: employee.full_name, href: base }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="employee.full_name" :description="`${employee.employee_no} · ${employee.labels.designation ?? ''} · ${employee.labels.department ?? ''}`">
                <template #meta>
                    <StatusBadge :status="employee.status" :label="employee.labels.status ?? employee.status" />
                </template>
                <template #actions>
                    <Button v-if="can.update" variant="outline" as-child><Link :href="`${base}/edit`">{{ $t('common.actions.edit') }}</Link></Button>
                    <Button v-if="can.correct" variant="outline" @click="dialog = 'correct'">{{ $t('core.employee.actions.correct') }}</Button>
                    <Button v-if="can.movement && employee.status !== 'left'" variant="outline" as-child>
                        <Link :href="`/employee-movements/create?employee_id=${employee.id}`">{{ $t('core.employee.actions.movement') }}</Link>
                    </Button>
                    <Button v-if="can.separate && employee.status !== 'left'" variant="outline" @click="dialog = directReports > 0 ? 'reports' : 'separate'">{{ $t('core.employee.actions.separate') }}</Button>
                    <Button v-if="can.createUser" variant="outline" @click="dialog = 'user'">{{ $t('core.employee.actions.create_user') }}</Button>
                    <Button v-if="can.rehire" @click="dialog = 'rehire'">{{ $t('core.employee.actions.rehire') }}</Button>
                </template>
            </PageHeader>

            <TabNav v-model="tab" :tabs="tabs.map((t) => ({ key: t.key, label: $t(t.label), hidden: t.hidden }))" />

            <div v-if="tab === 'summary'" class="space-y-4">
                <DetailList
                    :items="[
                        { label: $t('validation.attributes.employee_no'), value: employee.employee_no },
                        { label: $t('validation.attributes.department_id'), value: employee.labels.department },
                        { label: $t('validation.attributes.designation_id'), value: employee.labels.designation },
                        { label: $t('validation.attributes.branch_id'), value: employee.labels.branch },
                        { label: $t('validation.attributes.grade_id'), value: employee.labels.grade },
                        { label: $t('validation.attributes.employment_type_id'), value: employee.labels.employment_type },
                        { label: $t('validation.attributes.reports_to_id'), value: employee.labels.reports_to },
                        { label: $t('validation.attributes.date_of_joining'), value: d('date_of_joining') },
                        { label: $t('core.employee.service_start'), value: d('service_start') },
                        { label: $t('validation.attributes.relieving_date'), value: d('relieving_date'), hidden: !employee.relieving_date },
                        { label: $t('core.employee.holiday_list'), value: holidayList ? holidayList.name + (holidayList.fallback ? ` (${$t('core.employee.fallback')})` : '') : $t('core.employee.no_holiday_list') },
                        { label: $t('core.employee.user_account'), value: employee.labels.user ?? $t('core.employee.no_user') },
                    ]"
                />
            </div>

            <div v-if="tab === 'personal'">
                <DetailList
                    :items="[
                        { label: $t('validation.attributes.full_name'), value: employee.full_name },
                        { label: $t('validation.attributes.preferred_name'), value: v('preferred_name') },
                        { label: $t('validation.attributes.identity_type'), value: employee.labels.identity_type },
                        { label: $t('validation.attributes.ic_no'), value: employee.sensitive_visible ? v('ic_no') : employee.ic_no_masked },
                        { label: $t('validation.attributes.old_ic_no'), value: v('old_ic_no'), hidden: !employee.sensitive_visible },
                        { label: $t('validation.attributes.passport_no'), value: v('passport_no'), hidden: !employee.sensitive_visible },
                        { label: $t('validation.attributes.passport_expiry'), value: d('passport_expiry'), hidden: !employee.sensitive_visible },
                        { label: $t('validation.attributes.date_of_birth'), value: d('date_of_birth') },
                        { label: $t('validation.attributes.gender'), value: employee.labels.gender },
                        { label: $t('validation.attributes.citizenship_status'), value: employee.labels.citizenship_status },
                        { label: $t('validation.attributes.nationality'), value: v('nationality') },
                        { label: $t('validation.attributes.marital_status'), value: employee.labels.marital_status },
                        { label: $t('validation.attributes.race'), value: employee.labels.race, hidden: !employee.sensitive_visible },
                        { label: $t('validation.attributes.religion'), value: employee.labels.religion, hidden: !employee.sensitive_visible },
                        { label: $t('validation.attributes.retirement_date'), value: d('retirement_date') },
                    ]"
                />
            </div>

            <div v-if="tab === 'contact'">
                <DetailList
                    :items="[
                        { label: $t('validation.attributes.mobile_phone'), value: v('mobile_phone') },
                        { label: $t('validation.attributes.personal_email'), value: v('personal_email') },
                        { label: $t('validation.attributes.work_email'), value: v('work_email') },
                        { label: $t('common.fields.address'), value: [v('address_line1'), v('address_line2'), v('postcode'), v('city'), employee.labels.state].filter(Boolean).join(', ') },
                    ]"
                />
            </div>

            <div v-if="tab === 'employment'">
                <DetailList
                    :items="[
                        { label: $t('validation.attributes.date_of_joining'), value: d('date_of_joining') },
                        { label: $t('validation.attributes.continuous_service_from'), value: d('continuous_service_from') },
                        { label: $t('validation.attributes.probation_end_date'), value: d('probation_end_date') },
                        { label: $t('validation.attributes.confirmed_on'), value: d('confirmed_on') },
                        { label: $t('validation.attributes.contract_end_date'), value: d('contract_end_date') },
                        { label: $t('validation.attributes.notice_period_days'), value: v('notice_period_days') },
                        { label: $t('validation.attributes.resignation_date'), value: d('resignation_date') },
                        { label: $t('validation.attributes.relieving_date'), value: d('relieving_date') },
                        { label: $t('validation.attributes.separation_reason'), value: employee.labels.separation_reason },
                    ]"
                />
            </div>

            <div v-if="tab === 'statutory'" class="space-y-6">
                <DetailList
                    :items="[
                        { label: $t('validation.attributes.kwsp_no'), value: v('kwsp_no') },
                        { label: $t('validation.attributes.kwsp_exempt'), value: yn('kwsp_exempt') ? $t(yn('kwsp_exempt')!) : null },
                        { label: $t('validation.attributes.perkeso_no'), value: v('perkeso_no') },
                        { label: $t('validation.attributes.perkeso_first_insured_on'), value: d('perkeso_first_insured_on') },
                        { label: $t('validation.attributes.eis_exempt'), value: yn('eis_exempt') ? $t(yn('eis_exempt')!) : null },
                        { label: $t('validation.attributes.work_pass_type'), value: employee.labels.work_pass_type },
                        { label: $t('validation.attributes.work_pass_no'), value: v('work_pass_no') },
                        { label: $t('validation.attributes.work_pass_expiry'), value: d('work_pass_expiry') },
                    ]"
                />
                <div v-if="employee.tax">
                    <h3 class="mb-2 font-medium">{{ $t('core.employee.tax_profile') }}</h3>
                    <DetailList
                        :items="[
                            { label: $t('validation.attributes.tin'), value: employee.tax.tin as string },
                            { label: $t('validation.attributes.tax_residency'), value: $t(`core.enums.tax_residency.${employee.tax.tax_residency}`) },
                            { label: $t('validation.attributes.pcb_category'), value: String(employee.tax.pcb_category) },
                            { label: $t('validation.attributes.children_basic'), value: String(employee.tax.children_basic) },
                            { label: $t('validation.attributes.zakat_monthly_amount'), value: employee.tax.zakat_via_payroll ? `RM ${employee.tax.zakat_monthly_amount}` : null },
                        ]"
                    />
                </div>
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="font-medium">{{ $t('core.lindung24.title') }}</h3>
                        <Button v-if="can.update" size="sm" variant="outline" @click="dialog = 'lindung'">{{ $t('common.actions.add') }}</Button>
                    </div>
                    <p v-if="!lindung24.length" class="text-sm text-muted-foreground">{{ $t('core.lindung24.default') }}</p>
                    <ul class="text-sm">
                        <li v-for="l in lindung24" :key="l.id">{{ formatDate(l.effective_from) }} – {{ formatDate(l.effective_to) || '…' }}: {{ l.status }} <span class="text-muted-foreground">{{ l.note }}</span></li>
                    </ul>
                </div>
            </div>

            <div v-if="tab === 'bank'">
                <DetailList
                    :items="[
                        { label: $t('validation.attributes.salary_payment_mode'), value: employee.labels.salary_payment_mode },
                        { label: $t('validation.attributes.bank_id'), value: employee.labels.bank },
                        { label: $t('validation.attributes.bank_account_no'), value: v('bank_account_no') },
                        { label: $t('validation.attributes.bank_account_name'), value: v('bank_account_name') },
                    ]"
                />
            </div>

            <div v-if="tab === 'emergency'" class="space-y-2">
                <div v-for="c in emergencyContacts" :key="c.id" class="rounded-lg border p-3 text-sm">
                    <p class="font-medium">{{ c.name }} <span v-if="c.is_primary" class="ml-1 rounded bg-primary/10 px-1 text-xs">{{ $t('core.employee.primary_contact') }}</span></p>
                    <p class="text-muted-foreground">{{ c.relationship_label }} · {{ c.phone }} {{ c.alt_phone ? `/ ${c.alt_phone}` : '' }}</p>
                </div>
                <p v-if="!emergencyContacts.length" class="text-sm text-muted-foreground">{{ $t('common.empty') }}</p>
                <Button v-if="can.update" size="sm" variant="outline" @click="dialog = 'contacts'">{{ $t('common.actions.edit') }}</Button>
            </div>

            <div v-if="tab === 'documents'" class="space-y-3">
                <Button v-if="can.manageDocuments" size="sm" @click="dialog = 'document'">{{ $t('core.documents.upload') }}</Button>
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr>
                            <th class="py-1">{{ $t('core.documents.title') }}</th>
                            <th>{{ $t('core.documents.type') }}</th>
                            <th>{{ $t('core.documents.expires_on') }}</th>
                            <th>{{ $t('core.documents.uploaded_at') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="doc in documents" :key="doc.id" class="border-t">
                            <td class="py-1.5">{{ doc.title }}</td>
                            <td>{{ doc.type }}</td>
                            <td>{{ formatDate(doc.expires_on) || '—' }}</td>
                            <td>{{ formatDateTime(doc.uploaded_at) }}</td>
                            <td class="text-right">
                                <a :href="`/employee-documents/${doc.id}/download`" class="mr-2 inline-flex"><Download class="size-4" /></a>
                                <button v-if="can.manageDocuments" type="button" :aria-label="$t('common.actions.delete')" @click="deleteRow(`/employee-documents/${doc.id}`)"><Trash2 class="size-4" /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="tab === 'history'" class="space-y-6">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr>
                            <th class="py-1">{{ $t('core.history.period') }}</th>
                            <th>{{ $t('validation.attributes.department_id') }}</th>
                            <th>{{ $t('validation.attributes.designation_id') }}</th>
                            <th>{{ $t('validation.attributes.branch_id') }}</th>
                            <th>{{ $t('validation.attributes.grade_id') }}</th>
                            <th>{{ $t('core.history.source') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="h in workHistory" :key="h.id" class="border-t">
                            <td class="py-1.5">{{ formatDate(h.from_date) }} – {{ formatDate(h.to_date) || $t('core.history.current') }}</td>
                            <td>{{ h.department }}</td>
                            <td>{{ h.designation }}</td>
                            <td>{{ h.branch }}</td>
                            <td>{{ h.grade }}</td>
                            <td>{{ h.source }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="movements.length">
                    <h3 class="mb-2 font-medium">{{ $t('common.nav.movements') }}</h3>
                    <ul class="text-sm">
                        <li v-for="m in movements" :key="m.id">
                            <Link :href="`/employee-movements/${m.id}`" class="hover:underline">{{ m.number }}</Link> · {{ m.type }} · {{ formatDate(m.effective_date) }}
                            <StatusBadge :status="m.status" :label="m.status_label" />
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="tab === 'qualifications'" class="space-y-6">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="font-medium">{{ $t('core.qualifications.title') }}</h3>
                        <Button v-if="can.update" size="sm" variant="outline" @click="dialog = 'qualification'">{{ $t('common.actions.add') }}</Button>
                    </div>
                    <ul class="space-y-1 text-sm">
                        <li v-for="q in qualifications" :key="q.id" class="flex items-center justify-between border-b py-1">
                            <span>{{ q.level }} · {{ q.institution }} <span class="text-muted-foreground">{{ q.field }} {{ q.year_completed }} {{ q.grade_result }}</span></span>
                            <button v-if="can.update" type="button" @click="deleteRow(`${base}/qualifications/${q.id}`)"><Trash2 class="size-4" /></button>
                        </li>
                    </ul>
                </div>
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="font-medium">{{ $t('core.previous.title') }}</h3>
                        <Button v-if="can.update" size="sm" variant="outline" @click="dialog = 'previous'">{{ $t('common.actions.add') }}</Button>
                    </div>
                    <ul class="space-y-1 text-sm">
                        <li v-for="p in previousEmployments" :key="p.id" class="flex items-center justify-between border-b py-1">
                            <span>{{ p.employer_name }} · {{ p.position }} <span class="text-muted-foreground">{{ formatDate(p.from_date) }} – {{ formatDate(p.to_date) }} {{ p.last_salary ?? '' }}</span></span>
                            <button v-if="can.update" type="button" @click="deleteRow(`${base}/previous-employments/${p.id}`)"><Trash2 class="size-4" /></button>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="tab === 'leave'" class="space-y-3">
                <BalancesPanel :employee-id="e.id" />
                <Link v-if="can.hr" :href="`/leave/allocations?employee_id=${e.id}`" class="text-sm text-primary hover:underline">{{ $t('leave.employee_tab.allocations_link') }}</Link>
            </div>

            <div v-if="tab === 'attendance'" class="space-y-3">
                <EmployeeAttendancePanel :employee-id="e.id" :can-manage="can.attendanceManage" />
            </div>

            <div v-if="tab === 'payroll' && can.payroll" class="space-y-3">
                <EmployeePayrollPanel :employee-id="e.id" />
            </div>

            <div v-if="tab === 'approvers'" class="space-y-3">
                <p class="text-sm text-muted-foreground">{{ $t('core.employee.approvers_hint') }}</p>
                <DetailList :items="(options?.approval_types ?? []).map((t) => ({ label: t.label, value: approvers[String(t.value)]?.label ?? '—' }))" />
                <Button v-if="can.update" size="sm" variant="outline" @click="dialog = 'approvers'">{{ $t('common.actions.edit') }}</Button>
            </div>

            <div v-if="tab === 'audit'">
                <ul class="space-y-2 text-sm">
                    <li v-for="a in audit" :key="a.id" class="border-b pb-2">
                        <span class="text-muted-foreground">{{ formatDateTime(a.created_at) }}</span> · {{ a.causer ?? $t('approval.inbox.system') }} · {{ a.event ?? a.description }}
                        <pre class="mt-1 overflow-x-auto text-xs whitespace-pre-wrap">{{ JSON.stringify(a.properties) }}</pre>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Dialogs -->
        <Dialog :open="dialog !== null" @update:open="(o: boolean) => { if (!o) close() }">
            <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
                <template v-if="dialog === 'correct' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.employee.actions.correct') }}</DialogTitle></DialogHeader>
                    <p class="text-xs text-muted-foreground">{{ $t('core.employee.correct_hint') }}</p>
                    <div class="grid gap-3">
                        <FormField :label="$t('validation.attributes.branch_id')" :error="correctForm.errors.branch_id"><SelectInput v-model="correctForm.branch_id" :options="options.branches" /></FormField>
                        <FormField :label="$t('validation.attributes.department_id')" :error="correctForm.errors.department_id" required><SelectInput v-model="correctForm.department_id" :options="options.departments" /></FormField>
                        <FormField :label="$t('validation.attributes.designation_id')" :error="correctForm.errors.designation_id" required><SelectInput v-model="correctForm.designation_id" :options="options.designations" /></FormField>
                        <FormField :label="$t('validation.attributes.grade_id')" :error="correctForm.errors.grade_id"><SelectInput v-model="correctForm.grade_id" :options="options.grades" /></FormField>
                        <FormField :label="$t('validation.attributes.employment_type_id')" :error="correctForm.errors.employment_type_id" required><SelectInput v-model="correctForm.employment_type_id" :options="options.employment_types" /></FormField>
                        <FormField :label="$t('validation.attributes.reports_to_id')" :error="correctForm.errors.reports_to_id"><SelectInput v-model="correctForm.reports_to_id" :options="options.managers" /></FormField>
                        <FormField :label="$t('common.fields.reason')" :error="correctForm.errors.reason" required><TextArea v-model="correctForm.reason" /></FormField>
                    </div>
                    <DialogFooter><Button :disabled="correctForm.processing" @click="correctForm.post(`${base}/correction`, opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'reports' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.separation.reassign_title') }}</DialogTitle></DialogHeader>
                    <p class="text-sm">{{ $t('core.separation.has_reports', { count: String(directReports) }) }}</p>
                    <FormField :label="$t('validation.attributes.reports_to_id')"><SelectInput v-model="reportsForm.reports_to_id" :options="options.managers.filter((m) => m.value !== employee.id)" /></FormField>
                    <DialogFooter><Button :disabled="reportsForm.processing" @click="reportsForm.post(`${base}/reassign-reports`, opts)">{{ $t('core.separation.move_reports') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'separate' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.employee.actions.separate') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('validation.attributes.resignation_date')" :error="separateForm.errors.resignation_date"><Input v-model="separateForm.resignation_date" type="date" /></FormField>
                        <FormField :label="$t('validation.attributes.relieving_date')" :error="separateForm.errors.relieving_date" required :hint="$t('core.separation.relieving_hint')"><Input v-model="separateForm.relieving_date" type="date" /></FormField>
                        <FormField :label="$t('validation.attributes.separation_reason')" :error="separateForm.errors.separation_reason" required><SelectInput v-model="separateForm.separation_reason" :options="options.separation_reasons" :nullable="false" /></FormField>
                        <FormField :label="$t('validation.attributes.separation_note')"><TextArea v-model="separateForm.separation_note" /></FormField>
                        <label class="flex items-center gap-2 text-sm"><input v-model="separateForm.eligible_for_rehire" type="checkbox" /> {{ $t('validation.attributes.eligible_for_rehire') }}</label>
                        <p v-if="(separateForm.errors as Record<string, string>).reports" class="text-sm text-red-600">{{ (separateForm.errors as Record<string, string>).reports }}</p>
                        <p v-if="(separateForm.errors as Record<string, string>).approvals" class="text-sm text-red-600">{{ (separateForm.errors as Record<string, string>).approvals }}</p>
                    </div>
                    <DialogFooter><Button variant="destructive" :disabled="separateForm.processing" @click="separateForm.transform(nullify).post(`${base}/separation`, opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'user'">
                    <DialogHeader><DialogTitle>{{ $t('core.employee.actions.create_user') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('auth.fields.email')" :error="userForm.errors.email" required><Input v-model="userForm.email" type="email" /></FormField>
                        <FormField :label="$t('auth.fields.password')" :error="userForm.errors.password" :hint="$t('common.users.password_hint_new')"><Input v-model="userForm.password" type="password" autocomplete="new-password" /></FormField>
                    </div>
                    <DialogFooter><Button :disabled="userForm.processing" @click="userForm.post(`${base}/user-account`, opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'document' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.documents.upload') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('core.documents.file')" :error="documentForm.errors.file" required :hint="$t('core.documents.file_hint')">
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.docx" @change="(ev: Event) => (documentForm.file = (ev.target as HTMLInputElement).files?.[0] ?? null)" />
                        </FormField>
                        <FormField :label="$t('core.documents.type')" :error="documentForm.errors.document_type_id" required><SelectInput v-model="documentForm.document_type_id" :options="options.document_types" /></FormField>
                        <FormField :label="$t('core.documents.title')" :error="documentForm.errors.title"><Input v-model="documentForm.title" /></FormField>
                        <FormField :label="$t('core.documents.issued_on')"><Input v-model="documentForm.issued_on" type="date" /></FormField>
                        <FormField :label="$t('core.documents.expires_on')"><Input v-model="documentForm.expires_on" type="date" /></FormField>
                        <FormField :label="$t('core.documents.remind_before_days')"><Input v-model.number="documentForm.remind_before_days" type="number" min="1" /></FormField>
                    </div>
                    <DialogFooter><Button :disabled="documentForm.processing" @click="documentForm.transform(nullify).post(`${base}/documents`, { ...opts, forceFormData: true })">{{ $t('core.documents.upload') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'qualification' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.qualifications.title') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('core.qualifications.level')" required><SelectInput v-model="qualificationForm.level" :options="options.qualification_levels" :nullable="false" /></FormField>
                        <FormField :label="$t('core.qualifications.institution')" :error="qualificationForm.errors.institution" required><Input v-model="qualificationForm.institution" /></FormField>
                        <FormField :label="$t('core.qualifications.field')"><Input v-model="qualificationForm.field" /></FormField>
                        <FormField :label="$t('core.qualifications.year_completed')" :error="qualificationForm.errors.year_completed"><Input v-model.number="qualificationForm.year_completed" type="number" /></FormField>
                        <FormField :label="$t('core.qualifications.grade_result')"><Input v-model="qualificationForm.grade_result" /></FormField>
                    </div>
                    <DialogFooter><Button :disabled="qualificationForm.processing" @click="qualificationForm.transform(nullify).post(`${base}/qualifications`, opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'previous'">
                    <DialogHeader><DialogTitle>{{ $t('core.previous.title') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('core.previous.employer_name')" :error="previousForm.errors.employer_name" required><Input v-model="previousForm.employer_name" /></FormField>
                        <FormField :label="$t('core.previous.position')"><Input v-model="previousForm.position" /></FormField>
                        <FormField :label="$t('core.previous.from_date')"><Input v-model="previousForm.from_date" type="date" /></FormField>
                        <FormField :label="$t('core.previous.to_date')" :error="previousForm.errors.to_date"><Input v-model="previousForm.to_date" type="date" /></FormField>
                        <FormField :label="$t('core.previous.last_salary')"><Input v-model="previousForm.last_salary" inputmode="decimal" /></FormField>
                        <FormField :label="$t('core.previous.reason_left')"><Input v-model="previousForm.reason_left" /></FormField>
                    </div>
                    <DialogFooter><Button :disabled="previousForm.processing" @click="previousForm.transform(nullify).post(`${base}/previous-employments`, opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'approvers' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.employee.tabs.approvers') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField v-for="t in options.approval_types" :key="String(t.value)" :label="t.label" :error="approverForm.errors[`approvers.${t.value}`]">
                            <SelectInput v-model="approverForm.approvers[String(t.value)]" :options="options.users" />
                        </FormField>
                    </div>
                    <DialogFooter><Button :disabled="approverForm.processing" @click="approverForm.put(`${base}/approvers`, opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'contacts' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.employee.tabs.emergency') }}</DialogTitle></DialogHeader>
                    <p v-if="contactsForm.errors.emergency_contacts" class="text-sm text-red-600">{{ contactsForm.errors.emergency_contacts }}</p>
                    <div v-for="(c, i) in contactsForm.emergency_contacts" :key="i" class="grid gap-2 rounded border p-2">
                        <Input v-model="c.name" :placeholder="$t('validation.attributes.name')" />
                        <SelectInput v-model="c.relationship" :options="options.relationships" :nullable="false" />
                        <Input v-model="c.phone" :placeholder="$t('validation.attributes.phone')" />
                        <div class="flex items-center justify-between text-sm">
                            <label class="flex items-center gap-2"><input type="radio" :checked="c.is_primary" @change="contactsForm.emergency_contacts.forEach((x, j) => (x.is_primary = j === i))" /> {{ $t('core.employee.primary_contact') }}</label>
                            <button type="button" @click="contactsForm.emergency_contacts.splice(i, 1)"><Trash2 class="size-4" /></button>
                        </div>
                    </div>
                    <Button size="sm" variant="outline" @click="contactsForm.emergency_contacts.push({ name: '', relationship: 'parent', phone: '', alt_phone: null, address: null, is_primary: contactsForm.emergency_contacts.length === 0 })">{{ $t('core.employee.add_contact') }}</Button>
                    <DialogFooter><Button :disabled="contactsForm.processing" @click="contactsForm.put(`${base}/emergency-contacts`, opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'lindung' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.lindung24.title') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('common.fields.status')" :error="lindungForm.errors.status"><SelectInput v-model="lindungForm.status" :options="options.lindung24_statuses" :nullable="false" /></FormField>
                        <FormField :label="$t('core.lindung24.month')" :error="lindungForm.errors.month" required><Input v-model="lindungForm.month" type="date" /></FormField>
                        <FormField :label="$t('common.fields.note')"><Input v-model="lindungForm.note" /></FormField>
                    </div>
                    <DialogFooter><Button :disabled="lindungForm.processing" @click="lindungForm.post(`${base}/lindung24`, opts)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>

                <template v-if="dialog === 'rehire' && options">
                    <DialogHeader><DialogTitle>{{ $t('core.employee.actions.rehire') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('validation.attributes.date_of_joining')" :error="rehireForm.errors.date_of_joining" required><Input v-model="rehireForm.date_of_joining" type="date" /></FormField>
                        <FormField :label="$t('validation.attributes.branch_id')" :error="rehireForm.errors.branch_id"><SelectInput v-model="rehireForm.branch_id" :options="options.branches" /></FormField>
                        <FormField :label="$t('validation.attributes.department_id')" :error="rehireForm.errors.department_id" required><SelectInput v-model="rehireForm.department_id" :options="options.departments" /></FormField>
                        <FormField :label="$t('validation.attributes.designation_id')" :error="rehireForm.errors.designation_id" required><SelectInput v-model="rehireForm.designation_id" :options="options.designations" /></FormField>
                        <FormField :label="$t('validation.attributes.employment_type_id')" :error="rehireForm.errors.employment_type_id" required><SelectInput v-model="rehireForm.employment_type_id" :options="options.employment_types" /></FormField>
                        <FormField :label="$t('validation.attributes.contract_end_date')" :error="rehireForm.errors.contract_end_date"><Input v-model="rehireForm.contract_end_date" type="date" /></FormField>
                        <FormField :label="$t('validation.attributes.continuous_service_from')" :error="rehireForm.errors.continuous_service_from"><Input v-model="rehireForm.continuous_service_from" type="date" /></FormField>
                        <FormField v-if="rehireForm.continuous_service_from" :label="$t('common.fields.reason')" :error="rehireForm.errors.continuous_service_reason" required><Input v-model="rehireForm.continuous_service_reason" /></FormField>
                    </div>
                    <DialogFooter><Button :disabled="rehireForm.processing" @click="rehireForm.transform(nullify).post(`${base}/rehire`)">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
