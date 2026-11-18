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
    settings: {
        default_leave_policy_id: number | null;
        default_max_backdate_days: number;
        calendar_colleague_visibility: string;
        expiry_reminder_days: number;
        encashment_rate_basis: string;
        applicant_may_cancel_approved: string;
    };
    options: { policies: Option<number>[]; calendar_visibilities: Option<string>[]; encashment_rate_bases: Option<string>[]; applicant_cancel_rules: Option<string>[] };
    can: { manage: boolean };
}>();

const form = useForm({ ...props.settings });

function save() {
    form.put('/settings/leave', { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('leave.pages.settings')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.settings', href: '/settings/leave' }]">
        <div class="max-w-2xl p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.settings')" :description="$t('leave.settings.description')" />
            <form class="grid gap-4" @submit.prevent="save">
                <FormField :label="$t('leave.settings.default_policy')" :error="form.errors.default_leave_policy_id" :hint="$t('leave.settings.default_policy_hint')">
                    <SelectInput v-model="form.default_leave_policy_id" :options="options.policies" :disabled="!can.manage" :placeholder="$t('common.none')" />
                </FormField>
                <FormField :label="$t('leave.settings.max_backdate')" :error="form.errors.default_max_backdate_days">
                    <Input v-model="form.default_max_backdate_days" type="number" min="0" :disabled="!can.manage" />
                </FormField>
                <FormField :label="$t('leave.settings.calendar_visibility')" :error="form.errors.calendar_colleague_visibility">
                    <SelectInput v-model="form.calendar_colleague_visibility" :options="options.calendar_visibilities" :nullable="false" :disabled="!can.manage" />
                </FormField>
                <FormField :label="$t('leave.settings.expiry_reminder_days')" :error="form.errors.expiry_reminder_days">
                    <Input v-model="form.expiry_reminder_days" type="number" min="1" :disabled="!can.manage" />
                </FormField>
                <FormField :label="$t('leave.settings.encashment_rate_basis')" :error="form.errors.encashment_rate_basis">
                    <SelectInput v-model="form.encashment_rate_basis" :options="options.encashment_rate_bases" :nullable="false" :disabled="!can.manage" />
                </FormField>
                <FormField :label="$t('leave.settings.applicant_cancel')" :error="form.errors.applicant_may_cancel_approved">
                    <SelectInput v-model="form.applicant_may_cancel_approved" :options="options.applicant_cancel_rules" :nullable="false" :disabled="!can.manage" />
                </FormField>
                <div v-if="can.manage"><Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button></div>
            </form>
        </div>
    </AppLayout>
</template>
