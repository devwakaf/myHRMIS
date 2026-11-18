<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

const props = defineProps<{
    settings: Record<string, string | number | boolean | string[] | null>;
    options: { number_modes: Option<string>[]; holiday_notices: Option<string>[]; self_service_fields: Option<string>[] };
    canManage: boolean;
}>();

const s = props.settings;
const form = useForm({
    employee_no_format: String(s.employee_no_format ?? ''),
    employee_no_mode: String(s.employee_no_mode ?? 'auto'),
    retirement_age: Number(s.retirement_age),
    default_probation_months: Number(s.default_probation_months),
    standard_daily_hours: String(s.standard_daily_hours),
    standard_weekly_hours: String(s.standard_weekly_hours),
    min_employment_age: Number(s.min_employment_age),
    birthday_digest_to_hr: Boolean(s.birthday_digest_to_hr),
    birthday_digest_to_colleagues: Boolean(s.birthday_digest_to_colleagues),
    anniversary_digest: Boolean(s.anniversary_digest),
    remind_probation_days: Number(s.remind_probation_days),
    remind_contract_days: Number(s.remind_contract_days),
    remind_work_pass_days: Number(s.remind_work_pass_days),
    remind_document_days: Number(s.remind_document_days),
    remind_retirement_days: Number(s.remind_retirement_days),
    holiday_notice: String(s.holiday_notice ?? 'off'),
    self_service_editable: [...((s.self_service_editable as string[]) ?? [])],
});

const reminders = ['remind_probation_days', 'remind_contract_days', 'remind_work_pass_days', 'remind_document_days', 'remind_retirement_days'] as const;
</script>

<template>
    <Head :title="$t('common.nav.hr_settings')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.hr_settings', href: '/settings/hr' }]">
        <div class="max-w-4xl space-y-6 p-4 md:p-6">
            <PageHeader :title="$t('common.nav.hr_settings')" :description="$t('core.settings.description')">
                <template #actions>
                    <Button v-if="canManage" :disabled="form.processing" @click="form.put('/settings/hr', { preserveScroll: true })">{{ $t('common.actions.save') }}</Button>
                </template>
            </PageHeader>

            <fieldset :disabled="!canManage" class="space-y-6">
                <section class="rounded-lg border p-4">
                    <h2 class="mb-3 font-medium">{{ $t('core.settings.sections.numbering') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2">
                        <FormField :label="$t('core.settings.fields.employee_no_format')" :error="form.errors.employee_no_format" :hint="$t('core.settings.format_hint')"><Input v-model="form.employee_no_format" /></FormField>
                        <FormField :label="$t('core.settings.fields.employee_no_mode')"><SelectInput v-model="form.employee_no_mode" :options="options.number_modes" :nullable="false" /></FormField>
                    </div>
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="mb-3 font-medium">{{ $t('core.settings.sections.probation') }}</h2>
                    <div class="grid gap-4 md:grid-cols-3">
                        <FormField :label="$t('core.settings.fields.default_probation_months')" :error="form.errors.default_probation_months"><Input v-model.number="form.default_probation_months" type="number" /></FormField>
                        <FormField :label="$t('core.settings.fields.retirement_age')" :error="form.errors.retirement_age" :hint="$t('core.settings.retirement_hint')"><Input v-model.number="form.retirement_age" type="number" /></FormField>
                        <FormField :label="$t('core.settings.fields.min_employment_age')" :error="form.errors.min_employment_age"><Input v-model.number="form.min_employment_age" type="number" /></FormField>
                    </div>
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="mb-3 font-medium">{{ $t('core.settings.sections.hours') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2">
                        <FormField :label="$t('core.settings.fields.standard_daily_hours')" :error="form.errors.standard_daily_hours"><Input v-model="form.standard_daily_hours" inputmode="decimal" /></FormField>
                        <FormField :label="$t('core.settings.fields.standard_weekly_hours')" :error="form.errors.standard_weekly_hours"><Input v-model="form.standard_weekly_hours" inputmode="decimal" /></FormField>
                    </div>
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="mb-3 font-medium">{{ $t('core.settings.sections.reminders') }}</h2>
                    <div class="grid gap-4 md:grid-cols-3">
                        <FormField v-for="r in reminders" :key="r" :label="$t(`core.settings.fields.${r}`)" :error="form.errors[r]"><Input v-model.number="form[r]" type="number" min="0" /></FormField>
                        <FormField :label="$t('core.settings.fields.holiday_notice')"><SelectInput v-model="form.holiday_notice" :options="options.holiday_notices" :nullable="false" /></FormField>
                    </div>
                    <div class="mt-3 grid gap-2 text-sm">
                        <label class="flex items-center gap-2"><input v-model="form.birthday_digest_to_hr" type="checkbox" /> {{ $t('core.settings.fields.birthday_digest_to_hr') }}</label>
                        <label class="flex items-center gap-2"><input v-model="form.birthday_digest_to_colleagues" type="checkbox" /> {{ $t('core.settings.fields.birthday_digest_to_colleagues') }}</label>
                        <label class="flex items-center gap-2"><input v-model="form.anniversary_digest" type="checkbox" /> {{ $t('core.settings.fields.anniversary_digest') }}</label>
                    </div>
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="mb-1 font-medium">{{ $t('core.settings.sections.self_service') }}</h2>
                    <p class="mb-3 text-xs text-muted-foreground">{{ $t('core.settings.self_service_hint') }}</p>
                    <p v-if="form.errors.self_service_editable" class="text-sm text-red-600">{{ form.errors.self_service_editable }}</p>
                    <div class="grid gap-2 text-sm sm:grid-cols-2 md:grid-cols-3">
                        <label v-for="f in options.self_service_fields" :key="f.value" class="flex items-center gap-2"><input v-model="form.self_service_editable" type="checkbox" :value="f.value" /> {{ f.label }}</label>
                    </div>
                </section>
            </fieldset>
        </div>
    </AppLayout>
</template>
