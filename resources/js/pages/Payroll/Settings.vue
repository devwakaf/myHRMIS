<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CheckField from '@/components/app/CheckField.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import UnverifiedBanner from '@/components/payroll/UnverifiedBanner.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Settings = {
    days_source: string;
    unmarked_day_treatment: string;
    partial_month_basis: string;
    lwp_presentation: string;
    y2_basis: string;
    publish_slips_on: string;
    email_slips: boolean;
    pdf_password_mode: string;
    show_employer_contributions: boolean;
    show_ytd: boolean;
    show_leave_balance: boolean;
    ic_display: string;
    variance_threshold_percent: string;
    block_unverified_rates: boolean;
    include_withheld_in_statutory: boolean;
    allow_submitter_mark_paid: boolean;
    min_wage_check: boolean;
    default_bank_account_id: number | null;
    kwsp_file_format_id: number | null;
    net_pay_rounding: string;
};
type Account = {
    id: number;
    bank_id: number;
    bank: string;
    account_no: string;
    account_name: string;
    corporate_id: string | null;
    bank_file_format_id: number | null;
    is_default: boolean;
    is_active: boolean;
};
type FormatOption = { id: number; code: string; name: string; status: string };

const props = defineProps<{
    settings: Settings;
    daily_rate: { daily_rate_basis: string; daily_rate_divisor: string };
    bank_accounts: Account[];
    options: Record<string, unknown>;
    can: { manage: boolean };
}>();

const form = useForm({
    days_source: props.settings.days_source,
    unmarked_day_treatment: props.settings.unmarked_day_treatment,
    partial_month_basis: props.settings.partial_month_basis,
    lwp_presentation: props.settings.lwp_presentation,
    y2_basis: props.settings.y2_basis,
    publish_slips_on: props.settings.publish_slips_on,
    email_slips: props.settings.email_slips,
    pdf_password_mode: props.settings.pdf_password_mode,
    show_employer_contributions: props.settings.show_employer_contributions,
    show_ytd: props.settings.show_ytd,
    show_leave_balance: props.settings.show_leave_balance,
    ic_display: props.settings.ic_display,
    variance_threshold_percent: props.settings.variance_threshold_percent,
    block_unverified_rates: props.settings.block_unverified_rates,
    include_withheld_in_statutory: props.settings.include_withheld_in_statutory,
    allow_submitter_mark_paid: props.settings.allow_submitter_mark_paid,
    min_wage_check: props.settings.min_wage_check,
    default_bank_account_id: props.settings.default_bank_account_id as number | null,
    kwsp_file_format_id: props.settings.kwsp_file_format_id as number | null,
    net_pay_rounding: props.settings.net_pay_rounding,
});

const kwspFormatRows = computed(() => props.options.kwsp_file_formats as FormatOption[]);
const accountOptions = computed<Option<number>[]>(() => props.bank_accounts.map((a) => ({ value: a.id, label: `${a.bank} ${a.account_no}` })));
const kwspFormats = computed<Option<number>[]>(() => kwspFormatRows.value.map((f) => ({ value: f.id, label: `${f.name} (${f.status === 'verified' ? '✓' : '!'})` })));
const kwspUnverified = computed(() => kwspFormatRows.value.find((f) => f.id === form.kwsp_file_format_id)?.status === 'unverified');
const bankOptions = computed<Option<number>[]>(() => (props.options.banks as { id: number; name: string }[]).map((b) => ({ value: b.id, label: b.name })));
const formatOptions = computed<Option<number>[]>(() => (props.options.bank_file_formats as FormatOption[]).map((f) => ({ value: f.id, label: f.name })));

function save() {
    form.put('/payroll/settings', { preserveScroll: true });
}

// ---- Akaun Bank Organisasi
const open = ref(false);
const editing = ref<Account | null>(null);
const account = useForm({ bank_id: null as number | null, account_no: '', account_name: '', corporate_id: '', bank_file_format_id: null as number | null, is_default: false, is_active: true });

function startAccount(a: Account | null) {
    editing.value = a;
    account.defaults({
        bank_id: a?.bank_id ?? null,
        account_no: a?.account_no ?? '',
        account_name: a?.account_name ?? '',
        corporate_id: a?.corporate_id ?? '',
        bank_file_format_id: a?.bank_file_format_id ?? null,
        is_default: a?.is_default ?? false,
        is_active: a?.is_active ?? true,
    }).reset();
    account.clearErrors();
    open.value = true;
}

function saveAccount() {
    account.transform((d) => ({ ...d, corporate_id: d.corporate_id || null }))[editing.value ? 'put' : 'post'](editing.value ? `/payroll/bank-accounts/${editing.value.id}` : '/payroll/bank-accounts', {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

const sel = (field: string) => props.options[field] as Option<string>[];
</script>

<template>
    <Head :title="$t('payroll.pages.settings')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.settings', href: '/payroll/settings' }]">
        <div class="max-w-4xl space-y-6 p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.settings')" :description="$t('payroll.settings.description')">
                <template #actions>
                    <Button v-if="can.manage" :disabled="form.processing" data-testid="settings-save" @click="save">{{ $t('common.actions.save') }}</Button>
                </template>
            </PageHeader>

            <form class="space-y-6" @submit.prevent="save">
                <section class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2">
                    <h2 class="text-sm font-semibold sm:col-span-2">{{ $t('payroll.settings.sections.days') }}</h2>
                    <FormField :label="$t('payroll.settings.days_source')" :error="form.errors.days_source"><SelectInput v-model="form.days_source" :options="sel('days_source')" :nullable="false" :disabled="!can.manage" /></FormField>
                    <FormField :label="$t('payroll.settings.partial_month_basis')" :error="form.errors.partial_month_basis"><SelectInput v-model="form.partial_month_basis" :options="sel('partial_month_basis')" :nullable="false" :disabled="!can.manage" /></FormField>
                    <FormField :label="$t('payroll.settings.lwp_presentation')" :error="form.errors.lwp_presentation"><SelectInput v-model="form.lwp_presentation" :options="sel('lwp_presentation')" :nullable="false" :disabled="!can.manage" /></FormField>
                    <FormField :label="$t('payroll.settings.y2_basis')" :error="form.errors.y2_basis"><SelectInput v-model="form.y2_basis" :options="sel('y2_basis')" :nullable="false" :disabled="!can.manage" /></FormField>
                    <p class="text-xs text-muted-foreground sm:col-span-2">
                        {{ $t('payroll.settings.daily_rate_note', { basis: daily_rate.daily_rate_basis, divisor: daily_rate.daily_rate_divisor }) }}
                    </p>
                </section>

                <section class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2">
                    <h2 class="text-sm font-semibold sm:col-span-2">{{ $t('payroll.settings.sections.attendance') }}</h2>
                    <FormField :label="$t('payroll.settings.unmarked_day_treatment')" :error="form.errors.unmarked_day_treatment" :hint="$t('payroll.settings.unmarked_hint')"><SelectInput v-model="form.unmarked_day_treatment" :options="sel('unmarked_day_treatment')" :nullable="false" :disabled="!can.manage" /></FormField>
                </section>

                <section class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2">
                    <h2 class="text-sm font-semibold sm:col-span-2">{{ $t('payroll.settings.sections.slip') }}</h2>
                    <FormField :label="$t('payroll.settings.publish_slips_on')" :error="form.errors.publish_slips_on"><SelectInput v-model="form.publish_slips_on" :options="sel('publish_slips_on')" :nullable="false" :disabled="!can.manage" /></FormField>
                    <FormField :label="$t('payroll.settings.pdf_password_mode')" :error="form.errors.pdf_password_mode" :hint="$t('payroll.settings.pdf_password_hint')"><SelectInput v-model="form.pdf_password_mode" :options="sel('pdf_password_mode')" :nullable="false" :disabled="!can.manage" /></FormField>
                    <FormField :label="$t('payroll.settings.ic_display')" :error="form.errors.ic_display"><SelectInput v-model="form.ic_display" :options="sel('ic_display')" :nullable="false" :disabled="!can.manage" /></FormField>
                    <div class="grid content-start gap-2">
                        <CheckField v-model="form.email_slips" :label="$t('payroll.settings.email_slips')" :disabled="!can.manage" />
                        <CheckField v-model="form.show_employer_contributions" :label="$t('payroll.settings.show_employer_contributions')" :disabled="!can.manage" />
                        <CheckField v-model="form.show_ytd" :label="$t('payroll.settings.show_ytd')" :disabled="!can.manage" />
                        <CheckField v-model="form.show_leave_balance" :label="$t('payroll.settings.show_leave_balance')" :disabled="!can.manage" />
                    </div>
                </section>

                <section class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2">
                    <h2 class="text-sm font-semibold sm:col-span-2">{{ $t('payroll.settings.sections.files') }}</h2>
                    <FormField :label="$t('payroll.settings.default_bank_account')" :error="form.errors.default_bank_account_id"><SelectInput v-model="form.default_bank_account_id" :options="accountOptions" :disabled="!can.manage" /></FormField>
                    <FormField :label="$t('payroll.settings.kwsp_file_format')" :error="form.errors.kwsp_file_format_id"><SelectInput v-model="form.kwsp_file_format_id" :options="kwspFormats" :disabled="!can.manage" /></FormField>
                    <UnverifiedBanner v-if="kwspUnverified" class="sm:col-span-2" :message="$t('statutory.messages.format_unverified')" />
                </section>

                <section class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2">
                    <h2 class="text-sm font-semibold sm:col-span-2">{{ $t('payroll.settings.sections.controls') }}</h2>
                    <FormField :label="$t('payroll.settings.variance_threshold')" :error="form.errors.variance_threshold_percent" :hint="$t('payroll.settings.variance_hint')"><Input v-model="form.variance_threshold_percent" type="number" step="0.01" min="0" max="999" :disabled="!can.manage" /></FormField>
                    <FormField :label="$t('payroll.settings.net_pay_rounding')" :error="form.errors.net_pay_rounding"><SelectInput v-model="form.net_pay_rounding" :options="sel('net_pay_rounding')" :nullable="false" :disabled="!can.manage" /></FormField>
                    <div class="grid gap-2 sm:col-span-2">
                        <CheckField v-model="form.block_unverified_rates" :label="$t('payroll.settings.block_unverified_rates')" :hint="$t('payroll.settings.block_unverified_hint')" :disabled="!can.manage" />
                        <CheckField v-model="form.include_withheld_in_statutory" :label="$t('payroll.settings.include_withheld_in_statutory')" :disabled="!can.manage" />
                        <CheckField v-model="form.allow_submitter_mark_paid" :label="$t('payroll.settings.allow_submitter_mark_paid')" :hint="$t('payroll.settings.sod_hint')" :disabled="!can.manage" />
                        <CheckField v-model="form.min_wage_check" :label="$t('payroll.settings.min_wage_check')" :disabled="!can.manage" />
                    </div>
                </section>
            </form>

            <section id="bank" class="rounded-lg border p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold">{{ $t('payroll.settings.sections.bank') }}</h2>
                    <Button v-if="can.manage" size="sm" variant="outline" data-testid="account-new" @click="startAccount(null)"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </div>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left"><tr>
                            <th class="px-3 py-2">{{ $t('payroll.settings.bank') }}</th><th class="px-3 py-2">{{ $t('payroll.settings.account_no') }}</th><th class="px-3 py-2">{{ $t('payroll.settings.account_name') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.settings.corporate_id') }}</th><th class="px-3 py-2">{{ $t('common.fields.status') }}</th><th class="px-3 py-2"></th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="a in bank_accounts" :key="a.id" class="border-t">
                                <td class="px-3 py-2">{{ a.bank }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ a.account_no }}</td>
                                <td class="px-3 py-2">{{ a.account_name }}</td>
                                <td class="px-3 py-2">{{ a.corporate_id ?? '—' }}</td>
                                <td class="space-x-1 px-3 py-2">
                                    <StatusBadge v-if="a.is_default" status="active" :label="$t('payroll.settings.default')" />
                                    <StatusBadge :status="a.is_active ? 'active' : 'removed'" :label="a.is_active ? $t('common.active') : $t('common.inactive')" />
                                </td>
                                <td class="px-3 py-2 text-right"><Button v-if="can.manage" size="sm" variant="outline" @click="startAccount(a)">{{ $t('common.actions.edit') }}</Button></td>
                            </tr>
                            <tr v-if="!bank_accounts.length"><td colspan="6" class="px-3 py-4 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>{{ $t('payroll.settings.sections.bank') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="saveAccount">
                    <FormField :label="$t('payroll.settings.bank')" :error="account.errors.bank_id" required><SelectInput v-model="account.bank_id" :options="bankOptions" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('payroll.settings.account_no')" :error="account.errors.account_no" required><Input v-model="account.account_no" /></FormField>
                        <FormField :label="$t('payroll.settings.corporate_id')" :error="account.errors.corporate_id"><Input v-model="account.corporate_id" /></FormField>
                    </div>
                    <FormField :label="$t('payroll.settings.account_name')" :error="account.errors.account_name" required><Input v-model="account.account_name" /></FormField>
                    <FormField :label="$t('payroll.settings.bank_file_format')" :error="account.errors.bank_file_format_id"><SelectInput v-model="account.bank_file_format_id" :options="formatOptions" :placeholder="$t('payroll.settings.generic_format')" /></FormField>
                    <div class="flex gap-6">
                        <CheckField v-model="account.is_default" :label="$t('payroll.settings.default')" />
                        <CheckField v-model="account.is_active" :label="$t('common.active')" />
                    </div>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="account.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
