<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import DetailList from '@/components/app/DetailList.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

const props = defineProps<{
    employee: Record<string, unknown> & { full_name: string; employee_no: string; labels: Record<string, string | null> };
    emergencyContacts: { name: string; relationship: string; relationship_label: string; phone: string; alt_phone: string | null; address: string | null; is_primary: boolean }[];
    documents: { id: number; title: string; type: string | null; expires_on: string | null }[];
    holidayList: string | null;
    holidays: { date: string; name: string; kind: string; half_day: boolean }[];
    editable: string[];
    options: { states: Option<string>[]; relationships: Option<string>[] };
    canEdit: boolean;
}>();

const e = props.employee;
const s = (k: string) => (e[k] === null || e[k] === undefined ? '' : String(e[k]));
const editableFields = ['preferred_name', 'mobile_phone', 'personal_email', 'address_line1', 'address_line2', 'address_line3', 'postcode', 'city', 'state'].filter((f) => props.editable.includes(f));

const form = useForm<Record<string, any>>({
    ...Object.fromEntries(editableFields.map((f) => [f, s(f)])),
    ...(props.editable.includes('emergency_contacts')
        ? { emergency_contacts: props.emergencyContacts.map((c) => ({ name: c.name, relationship: c.relationship, phone: c.phone, alt_phone: c.alt_phone, address: c.address, is_primary: c.is_primary })) }
        : {}),
});

const contacts = form.emergency_contacts as { name: string; relationship: string; phone: string; is_primary: boolean }[] | undefined;
</script>

<template>
    <Head :title="$t('common.nav.me')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.me', href: '/me' }]">
        <div class="max-w-5xl space-y-6 p-4 md:p-6">
            <PageHeader :title="employee.full_name" :description="`${employee.employee_no} · ${employee.labels.designation ?? ''} · ${employee.labels.department ?? ''}`" />

            <DetailList
                :items="[
                    { label: $t('validation.attributes.ic_no'), value: s('ic_no') || s('passport_no') },
                    { label: $t('validation.attributes.date_of_birth'), value: formatDate(s('date_of_birth')) },
                    { label: $t('validation.attributes.date_of_joining'), value: formatDate(s('date_of_joining')) },
                    { label: $t('validation.attributes.reports_to_id'), value: employee.labels.reports_to },
                    { label: $t('validation.attributes.kwsp_no'), value: s('kwsp_no') },
                    { label: $t('validation.attributes.bank_account_no'), value: s('bank_account_no') },
                ]"
            />

            <section v-if="canEdit && (editableFields.length || contacts)" class="rounded-lg border p-4">
                <h2 class="mb-3 font-medium">{{ $t('core.me.edit') }}</h2>
                <div class="grid gap-3 md:grid-cols-3">
                    <FormField v-for="f in editableFields" :key="f" :label="$t(f.startsWith('address') || ['postcode', 'city', 'state'].includes(f) ? `common.fields.${f}` : `validation.attributes.${f}`)" :error="form.errors[f]">
                        <SelectInput v-if="f === 'state'" v-model="form[f]" :options="options.states" />
                        <Input v-else v-model="form[f]" />
                    </FormField>
                </div>
                <div v-if="contacts" class="mt-4 space-y-2">
                    <h3 class="text-sm font-medium">{{ $t('core.employee.tabs.emergency') }}</h3>
                    <p v-if="form.errors.emergency_contacts" class="text-sm text-red-600">{{ form.errors.emergency_contacts }}</p>
                    <div v-for="(c, i) in contacts" :key="i" class="grid gap-2 md:grid-cols-4">
                        <Input v-model="c.name" :placeholder="$t('validation.attributes.name')" />
                        <SelectInput v-model="c.relationship" :options="options.relationships" :nullable="false" />
                        <Input v-model="c.phone" :placeholder="$t('validation.attributes.phone')" />
                        <label class="flex items-center gap-2 text-sm"><input type="radio" :checked="c.is_primary" @change="contacts.forEach((x, j) => (x.is_primary = j === i))" /> {{ $t('core.employee.primary_contact') }}</label>
                    </div>
                    <Button size="sm" variant="outline" @click="contacts.push({ name: '', relationship: 'parent', phone: '', is_primary: contacts.length === 0 })">{{ $t('core.employee.add_contact') }}</Button>
                </div>
                <Button class="mt-4" :disabled="form.processing" @click="form.put('/me', { preserveScroll: true })">{{ $t('common.actions.save') }}</Button>
            </section>

            <div class="grid gap-6 md:grid-cols-2">
                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-medium">{{ $t('core.employee.tabs.documents') }}</h2>
                    <ul class="space-y-1 text-sm">
                        <li v-for="d in documents" :key="d.id" class="flex items-center justify-between">
                            <span>{{ d.title }} <span class="text-muted-foreground">{{ d.type }}</span></span>
                            <a :href="`/employee-documents/${d.id}/download`"><Download class="size-4" /></a>
                        </li>
                        <li v-if="!documents.length" class="text-muted-foreground">{{ $t('common.empty') }}</li>
                    </ul>
                </section>
                <section class="rounded-lg border p-4">
                    <h2 class="mb-2 font-medium">{{ holidayList ?? $t('core.employee.no_holiday_list') }}</h2>
                    <ul class="max-h-80 space-y-1 overflow-y-auto text-sm">
                        <li v-for="h in holidays" :key="h.date">{{ formatDate(h.date) }} · {{ h.name }}<span v-if="h.half_day" class="text-muted-foreground"> ({{ $t('core.holiday.half_day') }})</span></li>
                    </ul>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
