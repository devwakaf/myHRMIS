<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TabNav from '@/components/app/TabNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Employee = Record<string, unknown> & { id: number; full_name: string; tax: Record<string, unknown> | null; sensitive_visible: boolean };
type Contact = { name: string; relationship: string; phone: string; alt_phone: string | null; address: string | null; is_primary: boolean };

const props = defineProps<{
    employee: Employee | null;
    emergencyContacts?: Contact[];
    approvers?: Record<string, number>;
    options: Record<string, Option[]>;
    settings: { employee_no_mode: string; min_employment_age: number; default_probation_months: number };
}>();

const isEdit = computed(() => props.employee !== null);
const e = (props.employee ?? {}) as Record<string, unknown>;
const tax = (props.employee?.tax ?? {}) as Record<string, unknown>;
const str = (v: unknown, d = ''): string => (v === null || v === undefined ? d : String(v));
const num = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : Number(v));

const form = useForm({
    employee_no: str(e.employee_no),
    full_name: str(e.full_name),
    preferred_name: str(e.preferred_name),
    identity_type: str(e.identity_type, 'mykad'),
    ic_no: str(e.ic_no),
    old_ic_no: str(e.old_ic_no),
    passport_no: str(e.passport_no),
    passport_country: str(e.passport_country),
    passport_expiry: str(e.passport_expiry),
    other_id_no: str(e.other_id_no),
    date_of_birth: str(e.date_of_birth),
    gender: str(e.gender),
    citizenship_status: str(e.citizenship_status, 'citizen'),
    nationality: str(e.nationality, 'MY'),
    race: str(e.race),
    religion: str(e.religion),
    marital_status: str(e.marital_status, 'single'),
    blood_type: str(e.blood_type),
    is_disabled: Boolean(e.is_disabled),
    mobile_phone: str(e.mobile_phone),
    personal_email: str(e.personal_email),
    work_email: str(e.work_email),
    address_line1: str(e.address_line1),
    address_line2: str(e.address_line2),
    address_line3: str(e.address_line3),
    postcode: str(e.postcode),
    city: str(e.city),
    state: str(e.state),
    country: str(e.country, 'MY'),
    permanent_same_as_current: e.permanent_same_as_current === undefined ? true : Boolean(e.permanent_same_as_current),
    permanent_address_line1: str(e.permanent_address_line1),
    permanent_address_line2: str(e.permanent_address_line2),
    permanent_address_line3: str(e.permanent_address_line3),
    permanent_postcode: str(e.permanent_postcode),
    permanent_city: str(e.permanent_city),
    permanent_state: str(e.permanent_state),
    permanent_country: str(e.permanent_country),
    date_of_joining: str(e.date_of_joining),
    continuous_service_from: str(e.continuous_service_from),
    branch_id: num(e.branch_id),
    department_id: num(e.department_id),
    designation_id: num(e.designation_id),
    grade_id: num(e.grade_id),
    employment_type_id: num(e.employment_type_id),
    reports_to_id: num(e.reports_to_id),
    probation_end_date: str(e.probation_end_date),
    confirmed_on: str(e.confirmed_on),
    contract_end_date: str(e.contract_end_date),
    notice_period_days: num(e.notice_period_days),
    kwsp_no: str(e.kwsp_no),
    kwsp_exempt: Boolean(e.kwsp_exempt),
    kwsp_exempt_reason: str(e.kwsp_exempt_reason),
    kwsp_employee_rate_override: str(e.kwsp_employee_rate_override),
    perkeso_no: str(e.perkeso_no),
    perkeso_first_insured_on: str(e.perkeso_first_insured_on),
    eis_exempt: Boolean(e.eis_exempt),
    work_pass_type: str(e.work_pass_type),
    work_pass_no: str(e.work_pass_no),
    work_pass_expiry: str(e.work_pass_expiry),
    salary_payment_mode: str(e.salary_payment_mode, 'bank_transfer'),
    bank_id: num(e.bank_id),
    bank_account_no: str(e.bank_account_no),
    bank_account_name: str(e.bank_account_name),
    tax: {
        tin: str(tax.tin),
        tax_residency: str(tax.tax_residency, 'resident'),
        mtd_scheme: str(tax.mtd_scheme, 'normal'),
        pcb_category: num(tax.pcb_category) ?? 1,
        pcb_category_override_reason: str(tax.pcb_category_override_reason),
        spouse_working: tax.spouse_working === null || tax.spouse_working === undefined ? '' : String(Boolean(tax.spouse_working)),
        spouse_disabled: Boolean(tax.spouse_disabled),
        children_basic: num(tax.children_basic) ?? 0,
        children_higher_ed: num(tax.children_higher_ed) ?? 0,
        children_disabled: num(tax.children_disabled) ?? 0,
        children_disabled_higher_ed: num(tax.children_disabled_higher_ed) ?? 0,
        claims_child_relief: tax.claims_child_relief === undefined ? true : Boolean(tax.claims_child_relief),
        zakat_via_payroll: Boolean(tax.zakat_via_payroll),
        zakat_monthly_amount: str(tax.zakat_monthly_amount),
        zakat_authority: str(tax.zakat_authority),
        zakat_ref_no: str(tax.zakat_ref_no),
    },
    approvers: { ...(props.approvers ?? {}) } as Record<string, number | null>,
    emergency_contacts: (props.emergencyContacts ?? []).map((c) => ({ ...c })) as Contact[],
});

const tab = ref('personal');
const tabs = computed(() => [
    { key: 'personal', label: 'core.employee.tabs.personal' },
    { key: 'contact', label: 'core.employee.tabs.contact' },
    { key: 'employment', label: 'core.employee.tabs.employment' },
    { key: 'statutory', label: 'core.employee.tabs.statutory', hidden: isEdit.value && !props.employee?.sensitive_visible },
    { key: 'bank', label: 'core.employee.tabs.bank', hidden: isEdit.value && !props.employee?.sensitive_visible },
    { key: 'emergency', label: 'core.employee.tabs.emergency', hidden: isEdit.value },
    { key: 'approvers', label: 'core.employee.tabs.approvers', hidden: isEdit.value },
]);

// MyKad → date of birth and gender (the server re-derives and validates).
const icLocked = computed(() => form.identity_type === 'mykad');
watch(
    () => form.ic_no,
    (value) => {
        const digits = value.replace(/[\s-]/g, '');
        if (form.identity_type !== 'mykad' || !/^\d{12}$/.test(digits)) return;
        const yy = Number(digits.slice(0, 2));
        const mm = digits.slice(2, 4);
        const dd = digits.slice(4, 6);
        const thisYear = new Date().getFullYear();
        let year = 2000 + yy;
        if (thisYear - year < props.settings.min_employment_age) year = 1900 + yy;
        form.date_of_birth = `${year}-${mm}-${dd}`;
        form.gender = Number(digits.slice(-1)) % 2 === 1 ? 'male' : 'female';
    },
);

const isForeign = computed(() => ['foreign', 'foreign_pre_1998'].includes(form.citizenship_status));
const requiresEndDate = computed(() => Boolean(props.options.employment_types?.find((t) => t.value === form.employment_type_id)?.requires_end_date));
const suggestedPcb = computed(() => {
    if (form.marital_status === 'single') return 1;
    if (form.marital_status === 'married') return form.tax.spouse_working === 'false' ? 2 : 3;

    return 3;
});

watch(suggestedPcb, (value) => {
    if (!form.tax.pcb_category_override_reason) form.tax.pcb_category = value;
});

function addContact() {
    form.emergency_contacts.push({ name: '', relationship: 'parent', phone: '', alt_phone: null, address: null, is_primary: form.emergency_contacts.length === 0 });
}

function setPrimary(index: number) {
    form.emergency_contacts.forEach((c, i) => (c.is_primary = i === index));
}

const dirty = computed(() => form.isDirty);
const beforeUnload = (event: BeforeUnloadEvent) => {
    if (dirty.value && !form.processing) {
        event.preventDefault();
    }
};
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

function submit() {
    form.transform((data) => {
        const blank = (v: unknown) => (v === '' ? null : v);
        const out: Record<string, unknown> = {};
        for (const [k, v] of Object.entries(data)) {
            out[k] = typeof v === 'object' && v !== null && !Array.isArray(v) && k !== 'approvers' ? v : blank(v);
        }
        out.tax = Object.fromEntries(Object.entries(data.tax).map(([k, v]) => [k, k === 'spouse_working' ? (v === '' ? null : v === 'true') : blank(v)]));
        if (isEdit.value) {
            delete out.approvers;
            delete out.emergency_contacts;
            if (!props.employee?.sensitive_visible) {
                for (const k of ['ic_no', 'old_ic_no', 'passport_no', 'passport_country', 'passport_expiry', 'other_id_no', 'race', 'religion', 'is_disabled', 'blood_type', 'kwsp_no', 'kwsp_exempt', 'kwsp_exempt_reason', 'kwsp_employee_rate_override', 'perkeso_no', 'perkeso_first_insured_on', 'eis_exempt', 'work_pass_type', 'work_pass_no', 'work_pass_expiry', 'salary_payment_mode', 'bank_id', 'bank_account_no', 'bank_account_name', 'tax']) {
                    delete out[k];
                }
            }
        }

        return out as typeof data;
    });

    const onError = (errors: Record<string, string>) => {
        const first = Object.keys(errors)[0] ?? '';
        const map: Record<string, string> = {
            mobile_phone: 'contact', personal_email: 'contact', work_email: 'contact', postcode: 'contact', address_line1: 'contact',
            date_of_joining: 'employment', department_id: 'employment', designation_id: 'employment', employment_type_id: 'employment', branch_id: 'employment', reports_to_id: 'employment', contract_end_date: 'employment', probation_end_date: 'employment', continuous_service_from: 'employment',
            kwsp_no: 'statutory', kwsp_exempt_reason: 'statutory', work_pass_type: 'statutory', work_pass_no: 'statutory', work_pass_expiry: 'statutory',
            bank_id: 'bank', bank_account_no: 'bank', bank_account_name: 'bank',
        };
        if (first.startsWith('tax.')) tab.value = 'statutory';
        else if (first.startsWith('emergency_contacts')) tab.value = 'emergency';
        else if (first.startsWith('approvers')) tab.value = 'approvers';
        else tab.value = map[first] ?? 'personal';
    };

    if (isEdit.value && props.employee) {
        form.put(`/employees/${props.employee.id}`, { preserveScroll: true, onError });
    } else {
        form.post('/employees', { preserveScroll: true, onError });
    }
}

const yesNo = computed<Option<string>[]>(() => [
    { value: 'true', label: trans('common.yes') },
    { value: 'false', label: trans('common.no') },
]);
</script>

<template>
    <Head :title="employee ? employee.full_name : $t('core.employee.create')" />
    <AppLayout
        :breadcrumbs="[
            { title: 'common.nav.employees', href: '/employees' },
            { title: employee ? employee.full_name : 'core.employee.create', href: employee ? `/employees/${employee.id}` : '/employees/create' },
        ]"
    >
        <div class="max-w-5xl p-4 md:p-6">
            <PageHeader :title="employee ? $t('core.employee.edit_title', { name: employee.full_name }) : $t('core.employee.create')">
                <template #actions>
                    <Button :disabled="form.processing" data-test="save-employee" @click="submit">{{ $t('common.actions.save') }}</Button>
                </template>
            </PageHeader>

            <TabNav v-model="tab" :tabs="tabs.map((t) => ({ key: t.key, label: $t(t.label), hidden: t.hidden }))" />

            <!-- Peribadi -->
            <div v-show="tab === 'personal'" class="grid gap-4 md:grid-cols-3">
                <FormField :label="$t('validation.attributes.full_name')" :error="form.errors.full_name" required class="md:col-span-2">
                    <Input v-model="form.full_name" />
                </FormField>
                <FormField :label="$t('validation.attributes.preferred_name')" :error="form.errors.preferred_name">
                    <Input v-model="form.preferred_name" />
                </FormField>
                <FormField
                    v-if="!isEdit || settings.employee_no_mode === 'manual'"
                    :label="$t('validation.attributes.employee_no')"
                    :error="form.errors.employee_no"
                    :hint="settings.employee_no_mode === 'auto' ? $t('core.employee.auto_number') : undefined"
                >
                    <Input v-model="form.employee_no" :disabled="settings.employee_no_mode === 'auto'" />
                </FormField>
                <FormField :label="$t('validation.attributes.citizenship_status')" :error="form.errors.citizenship_status" required>
                    <SelectInput v-model="form.citizenship_status" :options="options.citizenships" :nullable="false" />
                </FormField>
                <FormField :label="$t('validation.attributes.nationality')" :error="form.errors.nationality" required :hint="$t('core.employee.iso_country_hint')">
                    <Input v-model="form.nationality" maxlength="2" class="uppercase" />
                </FormField>
                <template v-if="!isEdit || employee?.sensitive_visible">
                    <FormField :label="$t('validation.attributes.identity_type')" :error="form.errors.identity_type" required>
                        <SelectInput v-model="form.identity_type" :options="options.identity_types" :nullable="false" />
                    </FormField>
                    <FormField v-if="form.identity_type === 'mykad'" :label="$t('validation.attributes.ic_no')" :error="form.errors.ic_no" required :hint="$t('core.employee.ic_hint')">
                        <Input v-model="form.ic_no" maxlength="14" class="font-mono" data-test="ic-input" />
                    </FormField>
                    <FormField v-if="form.identity_type === 'mykad'" :label="$t('validation.attributes.old_ic_no')" :error="form.errors.old_ic_no">
                        <Input v-model="form.old_ic_no" />
                    </FormField>
                    <FormField v-if="['military', 'police'].includes(form.identity_type)" :label="$t('validation.attributes.other_id_no')" :error="form.errors.other_id_no">
                        <Input v-model="form.other_id_no" />
                    </FormField>
                    <template v-if="isForeign || form.identity_type === 'passport'">
                        <FormField :label="$t('validation.attributes.passport_no')" :error="form.errors.passport_no" :required="isForeign">
                            <Input v-model="form.passport_no" />
                        </FormField>
                        <FormField :label="$t('validation.attributes.passport_country')" :error="form.errors.passport_country" :required="isForeign">
                            <Input v-model="form.passport_country" maxlength="2" class="uppercase" />
                        </FormField>
                        <FormField :label="$t('validation.attributes.passport_expiry')" :error="form.errors.passport_expiry">
                            <Input v-model="form.passport_expiry" type="date" />
                        </FormField>
                    </template>
                </template>
                <FormField :label="$t('validation.attributes.date_of_birth')" :error="form.errors.date_of_birth" required :hint="icLocked ? $t('core.employee.derived_from_ic') : undefined">
                    <Input v-model="form.date_of_birth" type="date" :disabled="icLocked" />
                </FormField>
                <FormField :label="$t('validation.attributes.gender')" :error="form.errors.gender" required>
                    <SelectInput v-model="form.gender" :options="options.genders" :disabled="icLocked" />
                </FormField>
                <FormField :label="$t('validation.attributes.marital_status')" :error="form.errors.marital_status" required>
                    <SelectInput v-model="form.marital_status" :options="options.marital_statuses" :nullable="false" />
                </FormField>
                <template v-if="!isEdit || employee?.sensitive_visible">
                    <FormField :label="$t('validation.attributes.race')" :error="form.errors.race" :hint="$t('core.employee.sensitive_hint')">
                        <SelectInput v-model="form.race" :options="options.races" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.religion')" :error="form.errors.religion" :hint="$t('core.employee.sensitive_hint')">
                        <SelectInput v-model="form.religion" :options="options.religions" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.blood_type')" :error="form.errors.blood_type">
                        <SelectInput v-model="form.blood_type" :options="options.blood_types" />
                    </FormField>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_disabled" type="checkbox" /> {{ $t('validation.attributes.is_disabled') }}</label>
                </template>
            </div>

            <!-- Hubungan -->
            <div v-show="tab === 'contact'" class="grid gap-4 md:grid-cols-3">
                <FormField :label="$t('validation.attributes.mobile_phone')" :error="form.errors.mobile_phone" required>
                    <Input v-model="form.mobile_phone" />
                </FormField>
                <FormField :label="$t('validation.attributes.personal_email')" :error="form.errors.personal_email">
                    <Input v-model="form.personal_email" type="email" />
                </FormField>
                <FormField :label="$t('validation.attributes.work_email')" :error="form.errors.work_email">
                    <Input v-model="form.work_email" type="email" />
                </FormField>
                <FormField :label="$t('common.fields.address_line1')" :error="form.errors.address_line1" class="md:col-span-3">
                    <Input v-model="form.address_line1" />
                </FormField>
                <FormField :label="$t('common.fields.address_line2')" :error="form.errors.address_line2" class="md:col-span-3">
                    <Input v-model="form.address_line2" />
                </FormField>
                <FormField :label="$t('common.fields.postcode')" :error="form.errors.postcode">
                    <Input v-model="form.postcode" maxlength="5" inputmode="numeric" />
                </FormField>
                <FormField :label="$t('common.fields.city')" :error="form.errors.city">
                    <Input v-model="form.city" />
                </FormField>
                <FormField :label="$t('common.fields.state')" :error="form.errors.state">
                    <SelectInput v-model="form.state" :options="options.states" />
                </FormField>
                <label class="flex items-center gap-2 text-sm md:col-span-3">
                    <input v-model="form.permanent_same_as_current" type="checkbox" /> {{ $t('validation.attributes.permanent_same_as_current') }}
                </label>
                <template v-if="!form.permanent_same_as_current">
                    <FormField :label="$t('validation.attributes.permanent_address_line1')" class="md:col-span-3">
                        <Input v-model="form.permanent_address_line1" />
                    </FormField>
                    <FormField :label="$t('common.fields.postcode')" :error="form.errors.permanent_postcode">
                        <Input v-model="form.permanent_postcode" maxlength="5" />
                    </FormField>
                    <FormField :label="$t('common.fields.city')">
                        <Input v-model="form.permanent_city" />
                    </FormField>
                    <FormField :label="$t('common.fields.state')">
                        <SelectInput v-model="form.permanent_state" :options="options.states" />
                    </FormField>
                </template>
            </div>

            <!-- Pekerjaan -->
            <div v-show="tab === 'employment'" class="grid gap-4 md:grid-cols-3">
                <p v-if="isEdit" class="text-sm text-muted-foreground md:col-span-3">{{ $t('core.employee.assignment_locked') }}</p>
                <FormField :label="$t('validation.attributes.date_of_joining')" :error="form.errors.date_of_joining" required>
                    <Input v-model="form.date_of_joining" type="date" />
                </FormField>
                <FormField :label="$t('validation.attributes.continuous_service_from')" :error="form.errors.continuous_service_from" :hint="$t('core.employee.continuous_service_hint')">
                    <Input v-model="form.continuous_service_from" type="date" />
                </FormField>
                <FormField :label="$t('validation.attributes.branch_id')" :error="form.errors.branch_id">
                    <SelectInput v-model="form.branch_id" :options="options.branches" :disabled="isEdit" />
                </FormField>
                <FormField :label="$t('validation.attributes.department_id')" :error="form.errors.department_id" required>
                    <SelectInput v-model="form.department_id" :options="options.departments" :disabled="isEdit" />
                </FormField>
                <FormField :label="$t('validation.attributes.designation_id')" :error="form.errors.designation_id" required>
                    <SelectInput v-model="form.designation_id" :options="options.designations" :disabled="isEdit" />
                </FormField>
                <FormField :label="$t('validation.attributes.grade_id')" :error="form.errors.grade_id">
                    <SelectInput v-model="form.grade_id" :options="options.grades" :disabled="isEdit" />
                </FormField>
                <FormField :label="$t('validation.attributes.employment_type_id')" :error="form.errors.employment_type_id" required>
                    <SelectInput v-model="form.employment_type_id" :options="options.employment_types" :disabled="isEdit" />
                </FormField>
                <FormField :label="$t('validation.attributes.reports_to_id')" :error="form.errors.reports_to_id" :hint="$t('core.employee.reports_to_hint')">
                    <SelectInput v-model="form.reports_to_id" :options="options.managers.filter((m) => m.value !== employee?.id)" :disabled="isEdit" />
                </FormField>
                <FormField :label="$t('validation.attributes.probation_end_date')" :error="form.errors.probation_end_date" :hint="!isEdit ? $t('core.employee.probation_hint', { months: String(settings.default_probation_months) }) : undefined">
                    <Input v-model="form.probation_end_date" type="date" />
                </FormField>
                <FormField :label="$t('validation.attributes.contract_end_date')" :error="form.errors.contract_end_date" :required="requiresEndDate">
                    <Input v-model="form.contract_end_date" type="date" />
                </FormField>
                <FormField :label="$t('validation.attributes.notice_period_days')" :error="form.errors.notice_period_days">
                    <Input v-model.number="form.notice_period_days" type="number" min="0" />
                </FormField>
            </div>

            <!-- Statutori & Cukai -->
            <div v-show="tab === 'statutory'" class="space-y-6">
                <div class="grid gap-4 md:grid-cols-3">
                    <FormField :label="$t('validation.attributes.kwsp_no')" :error="form.errors.kwsp_no">
                        <Input v-model="form.kwsp_no" inputmode="numeric" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.perkeso_no')" :error="form.errors.perkeso_no">
                        <Input v-model="form.perkeso_no" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.perkeso_first_insured_on')" :error="form.errors.perkeso_first_insured_on">
                        <Input v-model="form.perkeso_first_insured_on" type="date" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.kwsp_employee_rate_override')" :error="form.errors.kwsp_employee_rate_override" :hint="$t('core.employee.rate_hint')">
                        <Input v-model="form.kwsp_employee_rate_override" inputmode="decimal" />
                    </FormField>
                    <div class="grid gap-2">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.kwsp_exempt" type="checkbox" /> {{ $t('validation.attributes.kwsp_exempt') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.eis_exempt" type="checkbox" /> {{ $t('validation.attributes.eis_exempt') }}</label>
                    </div>
                    <FormField v-if="form.kwsp_exempt" :label="$t('validation.attributes.kwsp_exempt_reason')" :error="form.errors.kwsp_exempt_reason" required>
                        <Input v-model="form.kwsp_exempt_reason" />
                    </FormField>
                    <template v-if="isForeign">
                        <FormField :label="$t('validation.attributes.work_pass_type')" :error="form.errors.work_pass_type" required>
                            <SelectInput v-model="form.work_pass_type" :options="options.work_pass_types" />
                        </FormField>
                        <FormField :label="$t('validation.attributes.work_pass_no')" :error="form.errors.work_pass_no" required>
                            <Input v-model="form.work_pass_no" />
                        </FormField>
                        <FormField :label="$t('validation.attributes.work_pass_expiry')" :error="form.errors.work_pass_expiry" required>
                            <Input v-model="form.work_pass_expiry" type="date" />
                        </FormField>
                    </template>
                </div>

                <h3 class="font-medium">{{ $t('core.employee.tax_profile') }}</h3>
                <div class="grid gap-4 md:grid-cols-3">
                    <FormField :label="$t('validation.attributes.tin')" :error="form.errors['tax.tin']">
                        <Input v-model="form.tax.tin" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.tax_residency')" :error="form.errors['tax.tax_residency']">
                        <SelectInput v-model="form.tax.tax_residency" :options="options.tax_residencies" :nullable="false" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.mtd_scheme')" :error="form.errors['tax.mtd_scheme']">
                        <SelectInput v-model="form.tax.mtd_scheme" :options="options.mtd_schemes" :nullable="false" />
                    </FormField>
                    <FormField v-if="form.marital_status === 'married'" :label="$t('validation.attributes.spouse_working')" :error="form.errors['tax.spouse_working']" required>
                        <SelectInput v-model="form.tax.spouse_working" :options="yesNo" />
                    </FormField>
                    <FormField
                        :label="$t('validation.attributes.pcb_category')"
                        :error="form.errors['tax.pcb_category']"
                        :hint="$t('core.employee.pcb_suggestion', { category: String(suggestedPcb) })"
                    >
                        <SelectInput v-model="form.tax.pcb_category" :options="[1, 2, 3].map((n) => ({ value: n, label: String(n) }))" :nullable="false" />
                    </FormField>
                    <FormField
                        v-if="form.tax.pcb_category !== suggestedPcb"
                        :label="$t('validation.attributes.pcb_category_override_reason')"
                        :error="form.errors['tax.pcb_category_override_reason']"
                        required
                    >
                        <Input v-model="form.tax.pcb_category_override_reason" />
                    </FormField>
                    <fieldset class="grid grid-cols-2 gap-2 md:col-span-3 md:grid-cols-4" :disabled="form.tax.tax_residency === 'non_resident'">
                        <FormField :label="$t('validation.attributes.children_basic')" :error="form.errors['tax.children_basic']">
                            <Input v-model.number="form.tax.children_basic" type="number" min="0" max="30" />
                        </FormField>
                        <FormField :label="$t('validation.attributes.children_higher_ed')" :error="form.errors['tax.children_higher_ed']">
                            <Input v-model.number="form.tax.children_higher_ed" type="number" min="0" max="30" />
                        </FormField>
                        <FormField :label="$t('validation.attributes.children_disabled')" :error="form.errors['tax.children_disabled']">
                            <Input v-model.number="form.tax.children_disabled" type="number" min="0" max="30" />
                        </FormField>
                        <FormField :label="$t('validation.attributes.children_disabled_higher_ed')" :error="form.errors['tax.children_disabled_higher_ed']">
                            <Input v-model.number="form.tax.children_disabled_higher_ed" type="number" min="0" max="30" />
                        </FormField>
                    </fieldset>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.tax.spouse_disabled" type="checkbox" /> {{ $t('validation.attributes.spouse_disabled') }}</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.tax.claims_child_relief" type="checkbox" /> {{ $t('validation.attributes.claims_child_relief') }}</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.tax.zakat_via_payroll" type="checkbox" /> {{ $t('validation.attributes.zakat_via_payroll') }}</label>
                    <template v-if="form.tax.zakat_via_payroll">
                        <FormField :label="$t('validation.attributes.zakat_monthly_amount')" :error="form.errors['tax.zakat_monthly_amount']" required>
                            <div class="flex items-center gap-1"><span class="text-sm text-muted-foreground">RM</span><Input v-model="form.tax.zakat_monthly_amount" inputmode="decimal" class="text-right" /></div>
                        </FormField>
                        <FormField :label="$t('validation.attributes.zakat_authority')">
                            <Input v-model="form.tax.zakat_authority" />
                        </FormField>
                        <FormField :label="$t('validation.attributes.zakat_ref_no')">
                            <Input v-model="form.tax.zakat_ref_no" />
                        </FormField>
                    </template>
                </div>
            </div>

            <!-- Bank -->
            <div v-show="tab === 'bank'" class="grid gap-4 md:grid-cols-3">
                <FormField :label="$t('validation.attributes.salary_payment_mode')" :error="form.errors.salary_payment_mode" required>
                    <SelectInput v-model="form.salary_payment_mode" :options="options.payment_modes" :nullable="false" />
                </FormField>
                <template v-if="form.salary_payment_mode === 'bank_transfer'">
                    <FormField :label="$t('validation.attributes.bank_id')" :error="form.errors.bank_id" required>
                        <SelectInput v-model="form.bank_id" :options="options.banks" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.bank_account_no')" :error="form.errors.bank_account_no" required>
                        <Input v-model="form.bank_account_no" inputmode="numeric" />
                    </FormField>
                    <FormField :label="$t('validation.attributes.bank_account_name')" :error="form.errors.bank_account_name" required class="md:col-span-2">
                        <Input v-model="form.bank_account_name" />
                    </FormField>
                </template>
            </div>

            <!-- Kontak kecemasan (create) -->
            <div v-show="tab === 'emergency'" class="space-y-3">
                <p v-if="form.errors.emergency_contacts" class="text-sm text-red-600">{{ form.errors.emergency_contacts }}</p>
                <div v-for="(c, i) in form.emergency_contacts" :key="i" class="grid gap-2 rounded-lg border p-3 md:grid-cols-5">
                    <Input v-model="c.name" :placeholder="$t('validation.attributes.name')" />
                    <SelectInput v-model="c.relationship" :options="options.relationships" :nullable="false" />
                    <Input v-model="c.phone" :placeholder="$t('validation.attributes.phone')" />
                    <label class="flex items-center gap-2 text-sm"><input type="radio" :checked="c.is_primary" @change="setPrimary(i)" /> {{ $t('core.employee.primary_contact') }}</label>
                    <Button variant="ghost" size="icon" :aria-label="$t('common.actions.delete')" @click="form.emergency_contacts.splice(i, 1)"><Trash2 class="size-4" /></Button>
                </div>
                <Button variant="outline" size="sm" @click="addContact"><Plus class="size-4" />{{ $t('core.employee.add_contact') }}</Button>
            </div>

            <!-- Pelulus (create) -->
            <div v-show="tab === 'approvers'" class="grid gap-4 md:grid-cols-2">
                <p class="text-sm text-muted-foreground md:col-span-2">{{ $t('core.employee.approvers_hint') }}</p>
                <FormField v-for="t in options.approval_types" :key="String(t.value)" :label="t.label" :error="form.errors[`approvers.${t.value}`]">
                    <SelectInput v-model="form.approvers[String(t.value)]" :options="options.users" />
                </FormField>
            </div>
        </div>
    </AppLayout>
</template>
