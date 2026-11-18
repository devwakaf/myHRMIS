<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type S = {
    geofence_enabled: boolean;
    no_location_policy: string;
    outside_geofence_action: string;
    default_accuracy_threshold_m: number;
    min_punch_interval_seconds: number;
    location_notice_version: number;
    location_retention_months: number;
    attempt_log_retention_days: number;
    allow_multiple_assignments: boolean;
    sync_lag_minutes: number;
    absent_lookback_days: number;
    organisation_holiday_day_type: string;
    default_overtime_type_id: number | null;
    ot_monthly_cap_hours: string | null;
    ot_generate_drafts_monthly: boolean;
    roster_horizon_days: number;
    notify_roster_changes_days: number;
    remind_missing_checkin: boolean;
};

const props = defineProps<{
    settings: S;
    akta_kerja: { monthly_ot_cap_hours: string; ot_wage_threshold: string; verified: boolean };
    options: { no_location_policies: Option<string>[]; outside_geofence_actions: Option<string>[]; day_types: Option<string>[]; overtime_types: { id: number; code: string; name: string }[] };
    can: { manage: boolean };
}>();

const form = useForm({
    ...props.settings,
    ot_monthly_cap_hours: props.settings.ot_monthly_cap_hours ?? '',
    bump_notice_version: false,
});

const otOptions = computed(() => props.options.overtime_types.map((t) => ({ value: t.id, label: `${t.code} · ${t.name}` })));

function save() {
    form.transform((d) => ({ ...d, ot_monthly_cap_hours: d.ot_monthly_cap_hours === '' ? null : d.ot_monthly_cap_hours })).put('/settings/attendance', { preserveScroll: true, onSuccess: () => (form.bump_notice_version = false) });
}
</script>

<template>
    <Head :title="$t('attendance.pages.settings')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.settings', href: '/settings/attendance' }]">
        <div class="max-w-4xl p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.settings')" :description="$t('attendance.settings.description')" />

            <form class="space-y-8" @submit.prevent="save">
                <fieldset :disabled="!can.manage" class="space-y-3">
                    <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.settings.sections.geofence') }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex items-center gap-2 self-end text-sm"><input v-model="form.geofence_enabled" type="checkbox" />{{ $t('attendance.settings.geofence_enabled') }}</label>
                        <FormField :label="$t('attendance.settings.default_accuracy')" :error="form.errors.default_accuracy_threshold_m" :hint="$t('attendance.settings.default_accuracy_hint')"><Input v-model="form.default_accuracy_threshold_m" type="number" min="1" max="5000" /></FormField>
                        <FormField :label="$t('attendance.settings.no_location_policy')" :error="form.errors.no_location_policy"><SelectInput v-model="form.no_location_policy" :options="options.no_location_policies" :nullable="false" /></FormField>
                        <FormField :label="$t('attendance.settings.outside_action')" :error="form.errors.outside_geofence_action"><SelectInput v-model="form.outside_geofence_action" :options="options.outside_geofence_actions" :nullable="false" /></FormField>
                        <FormField :label="$t('attendance.settings.min_interval')" :error="form.errors.min_punch_interval_seconds"><Input v-model="form.min_punch_interval_seconds" type="number" min="0" max="3600" /></FormField>
                    </div>
                </fieldset>

                <fieldset :disabled="!can.manage" class="space-y-3">
                    <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.settings.sections.privacy') }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.settings.retention_months')" :error="form.errors.location_retention_months"><Input v-model="form.location_retention_months" type="number" min="1" max="120" /></FormField>
                        <FormField :label="$t('attendance.settings.attempt_retention')" :error="form.errors.attempt_log_retention_days"><Input v-model="form.attempt_log_retention_days" type="number" min="1" max="3650" /></FormField>
                        <p class="text-sm text-muted-foreground">{{ $t('attendance.settings.notice_version', { version: String(settings.location_notice_version) }) }}</p>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.bump_notice_version" type="checkbox" />{{ $t('attendance.settings.bump_notice') }}</label>
                    </div>
                </fieldset>

                <fieldset :disabled="!can.manage" class="space-y-3">
                    <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.settings.sections.processing') }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.settings.sync_lag')" :error="form.errors.sync_lag_minutes"><Input v-model="form.sync_lag_minutes" type="number" min="0" max="1440" /></FormField>
                        <FormField :label="$t('attendance.settings.lookback')" :error="form.errors.absent_lookback_days"><Input v-model="form.absent_lookback_days" type="number" min="1" max="120" /></FormField>
                        <FormField :label="$t('attendance.settings.org_holiday')" :error="form.errors.organisation_holiday_day_type" :hint="$t('attendance.settings.org_holiday_hint')"><SelectInput v-model="form.organisation_holiday_day_type" :options="options.day_types" :nullable="false" /></FormField>
                        <label class="flex items-center gap-2 self-end text-sm"><input v-model="form.allow_multiple_assignments" type="checkbox" />{{ $t('attendance.settings.multiple_assignments') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.remind_missing_checkin" type="checkbox" />{{ $t('attendance.settings.remind_missing') }}</label>
                    </div>
                </fieldset>

                <fieldset :disabled="!can.manage" class="space-y-3">
                    <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.settings.sections.roster') }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.settings.roster_horizon')" :error="form.errors.roster_horizon_days"><Input v-model="form.roster_horizon_days" type="number" min="7" max="366" /></FormField>
                        <FormField :label="$t('attendance.settings.notify_roster')" :error="form.errors.notify_roster_changes_days" :hint="$t('attendance.settings.notify_roster_hint')"><Input v-model="form.notify_roster_changes_days" type="number" min="0" max="60" /></FormField>
                    </div>
                </fieldset>

                <fieldset :disabled="!can.manage" class="space-y-3">
                    <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.settings.sections.overtime') }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.settings.default_ot_type')" :error="form.errors.default_overtime_type_id"><SelectInput v-model="form.default_overtime_type_id" :options="otOptions" /></FormField>
                        <FormField :label="$t('attendance.settings.ot_cap')" :error="form.errors.ot_monthly_cap_hours" :hint="$t('attendance.settings.ot_cap_hint', { hours: akta_kerja.monthly_ot_cap_hours })"><Input v-model="form.ot_monthly_cap_hours" type="number" step="0.5" min="0" max="744" /></FormField>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.ot_generate_drafts_monthly" type="checkbox" />{{ $t('attendance.settings.ot_auto_drafts') }}</label>
                    </div>
                    <div class="rounded-md border p-3 text-sm" data-testid="akta-box">
                        <p class="font-medium">{{ $t('attendance.settings.akta_title') }}</p>
                        <p class="text-muted-foreground">{{ $t('attendance.settings.akta_cap', { hours: akta_kerja.monthly_ot_cap_hours }) }} · {{ $t('attendance.settings.akta_threshold', { amount: akta_kerja.ot_wage_threshold }) }}</p>
                        <p v-if="!akta_kerja.verified" class="mt-1 text-xs text-amber-700 dark:text-amber-300" data-testid="akta-unverified">{{ $t('attendance.settings.akta_unverified') }}</p>
                    </div>
                </fieldset>

                <div v-if="can.manage"><Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button></div>
            </form>
        </div>
    </AppLayout>
</template>
