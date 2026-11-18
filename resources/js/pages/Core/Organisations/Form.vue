<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TabNav from '@/components/app/TabNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Org = Record<string, string | number | boolean | null> & { id?: number; has_employees?: boolean };

const props = defineProps<{
    organisation: Org | null;
    options: { registration_types: Option<string>[]; states: Option<string>[]; daily_rate_bases: Option<string>[] };
    canManage: boolean;
}>();

const tab = ref('info');
const o = props.organisation ?? {};

const form = useForm({
    name: (o.name as string) ?? '',
    short_code: (o.short_code as string) ?? '',
    registration_type: (o.registration_type as string) ?? 'company',
    ssm_no: (o.ssm_no as string) ?? '',
    ssm_old_no: (o.ssm_old_no as string) ?? '',
    kwsp_employer_no: (o.kwsp_employer_no as string) ?? '',
    perkeso_employer_code: (o.perkeso_employer_code as string) ?? '',
    lhdn_employer_no: (o.lhdn_employer_no as string) ?? '',
    lhdn_hq_employer_no: (o.lhdn_hq_employer_no as string) ?? '',
    tax_identification_no: (o.tax_identification_no as string) ?? '',
    hrd_corp_registered: Boolean(o.hrd_corp_registered),
    hrd_corp_levy_rate: (o.hrd_corp_levy_rate as string) ?? '',
    address_line1: (o.address_line1 as string) ?? '',
    address_line2: (o.address_line2 as string) ?? '',
    address_line3: (o.address_line3 as string) ?? '',
    postcode: (o.postcode as string) ?? '',
    city: (o.city as string) ?? '',
    state: (o.state as string) ?? 'PHG',
    phone: (o.phone as string) ?? '',
    email: (o.email as string) ?? '',
    website: (o.website as string) ?? '',
    daily_rate_basis: (o.daily_rate_basis as string) ?? 'fixed_divisor',
    daily_rate_divisor: (o.daily_rate_divisor as string) ?? '26.00',
    is_active: o.is_active === undefined ? true : Boolean(o.is_active),
});

function submit() {
    form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])) as typeof data);
    if (props.organisation?.id) {
        form.put(`/organisations/${props.organisation.id}`, { preserveScroll: true });
    } else {
        form.post('/organisations');
    }
}

const tabs = [
    { key: 'info', label: 'core.organisation.tabs.info' },
    { key: 'employer', label: 'core.organisation.tabs.employer' },
    { key: 'address', label: 'core.organisation.tabs.address' },
    { key: 'rate', label: 'core.organisation.tabs.rate' },
];
</script>

<template>
    <Head :title="organisation ? String(organisation.name) : $t('core.organisation.create')" />
    <AppLayout
        :breadcrumbs="[
            { title: 'common.nav.organisations', href: '/organisations' },
            { title: organisation ? String(organisation.name) : 'core.organisation.create', href: '#' },
        ]"
    >
        <div class="max-w-4xl p-4 md:p-6">
            <PageHeader :title="organisation ? String(organisation.name) : $t('core.organisation.create')">
                <template #actions>
                    <Button v-if="canManage" :disabled="form.processing" @click="submit">{{ $t('common.actions.save') }}</Button>
                </template>
            </PageHeader>

            <TabNav v-model="tab" :tabs="tabs.map((t) => ({ key: t.key, label: $t(t.label) }))" />

            <fieldset :disabled="!canManage" class="grid gap-4 md:grid-cols-2">
                <template v-if="tab === 'info'">
                    <FormField :label="$t('core.organisation.fields.name')" :error="form.errors.name" required class="md:col-span-2">
                        <Input v-model="form.name" />
                    </FormField>
                    <FormField
                        :label="$t('core.organisation.fields.short_code')"
                        :error="form.errors.short_code"
                        :hint="$t('core.organisation.short_code_hint')"
                        required
                    >
                        <Input v-model="form.short_code" maxlength="10" :disabled="Boolean(organisation?.has_employees)" class="uppercase" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.registration_type')" :error="form.errors.registration_type" required>
                        <SelectInput v-model="form.registration_type" :options="options.registration_types" :nullable="false" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.ssm_no')" :error="form.errors.ssm_no">
                        <Input v-model="form.ssm_no" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.ssm_old_no')" :error="form.errors.ssm_old_no">
                        <Input v-model="form.ssm_old_no" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.phone')" :error="form.errors.phone">
                        <Input v-model="form.phone" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.email')" :error="form.errors.email">
                        <Input v-model="form.email" type="email" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.website')" :error="form.errors.website">
                        <Input v-model="form.website" />
                    </FormField>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> {{ $t('common.active') }}</label>
                </template>

                <template v-if="tab === 'employer'">
                    <FormField :label="$t('core.organisation.fields.kwsp_employer_no')" :error="form.errors.kwsp_employer_no">
                        <Input v-model="form.kwsp_employer_no" inputmode="numeric" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.perkeso_employer_code')" :error="form.errors.perkeso_employer_code">
                        <Input v-model="form.perkeso_employer_code" maxlength="12" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.lhdn_employer_no')" :error="form.errors.lhdn_employer_no">
                        <Input v-model="form.lhdn_employer_no" maxlength="10" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.lhdn_hq_employer_no')" :error="form.errors.lhdn_hq_employer_no">
                        <Input v-model="form.lhdn_hq_employer_no" maxlength="10" />
                    </FormField>
                    <FormField :label="$t('core.organisation.fields.tax_identification_no')" :error="form.errors.tax_identification_no">
                        <Input v-model="form.tax_identification_no" />
                    </FormField>
                    <div class="grid gap-2">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.hrd_corp_registered" type="checkbox" /> {{ $t('core.organisation.fields.hrd_corp_registered') }}</label>
                        <FormField v-if="form.hrd_corp_registered" :label="$t('core.organisation.fields.hrd_corp_levy_rate')" :error="form.errors.hrd_corp_levy_rate">
                            <Input v-model="form.hrd_corp_levy_rate" inputmode="decimal" />
                        </FormField>
                    </div>
                </template>

                <template v-if="tab === 'address'">
                    <FormField :label="$t('common.fields.address_line1')" :error="form.errors.address_line1" required class="md:col-span-2">
                        <Input v-model="form.address_line1" />
                    </FormField>
                    <FormField :label="$t('common.fields.address_line2')" :error="form.errors.address_line2" class="md:col-span-2">
                        <Input v-model="form.address_line2" />
                    </FormField>
                    <FormField :label="$t('common.fields.address_line3')" :error="form.errors.address_line3" class="md:col-span-2">
                        <Input v-model="form.address_line3" />
                    </FormField>
                    <FormField :label="$t('common.fields.postcode')" :error="form.errors.postcode" required>
                        <Input v-model="form.postcode" maxlength="5" inputmode="numeric" />
                    </FormField>
                    <FormField :label="$t('common.fields.city')" :error="form.errors.city" required>
                        <Input v-model="form.city" />
                    </FormField>
                    <FormField :label="$t('common.fields.state')" :error="form.errors.state" required>
                        <SelectInput v-model="form.state" :options="options.states" :nullable="false" />
                    </FormField>
                </template>

                <template v-if="tab === 'rate'">
                    <FormField :label="$t('core.organisation.fields.daily_rate_basis')" :error="form.errors.daily_rate_basis" required>
                        <SelectInput v-model="form.daily_rate_basis" :options="options.daily_rate_bases" :nullable="false" />
                    </FormField>
                    <FormField
                        v-if="form.daily_rate_basis === 'fixed_divisor'"
                        :label="$t('core.organisation.fields.daily_rate_divisor')"
                        :error="form.errors.daily_rate_divisor"
                        required
                    >
                        <Input v-model="form.daily_rate_divisor" inputmode="decimal" />
                    </FormField>
                    <p class="text-sm text-muted-foreground md:col-span-2">{{ $t('core.organisation.daily_rate_note') }}</p>
                </template>
            </fieldset>
        </div>
    </AppLayout>
</template>
