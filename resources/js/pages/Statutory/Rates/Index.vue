<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TabNav from '@/components/app/TabNav.vue';
import RateSetBadge from '@/components/payroll/RateSetBadge.vue';
import UnverifiedBanner from '@/components/payroll/UnverifiedBanner.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { RateSetRow } from '@/lib/payroll';
import type { Option } from '@/types';

type Format = { id: number; scheme: string; code: string; name: string; version: string | null; effective_from: string | null; status: string; source_url: string | null };

const props = defineProps<{ sets: RateSetRow[]; file_formats: Format[]; options: { schemes: Option<string>[] }; can: { manage: boolean } }>();

const tab = ref(String(props.options.schemes[0]?.value ?? 'epf'));
const schemeTabs = computed(() => props.options.schemes.map((s) => ({ key: String(s.value), label: s.label })));
const shown = computed(() => props.sets.filter((s) => s.scheme === tab.value));
const unverified = computed(() => props.sets.filter((s) => s.status === 'unverified'));

const copyOf = ref<RateSetRow | null>(null);
const copyForm = useForm({ code: '', effective_from: '', source_url: '' });

function startCopy(s: RateSetRow) {
    copyOf.value = s;
    copyForm.defaults({ code: `${s.code}-B`, effective_from: '', source_url: s.source_url ?? '' }).reset();
    copyForm.clearErrors();
}

function copy() {
    if (!copyOf.value) return;
    copyForm.transform((d) => ({ ...d, source_url: d.source_url || null })).post(`/statutory/rates/${copyOf.value.id}/copy`, { onSuccess: () => (copyOf.value = null) });
}
</script>

<template>
    <Head :title="$t('statutory.pages.rates')" />
    <AppLayout :breadcrumbs="[{ title: 'statutory.pages.rates', href: '/statutory/rates' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('statutory.pages.rates')" :description="$t('statutory.rates.description')" />

            <UnverifiedBanner v-if="unverified.length" :title="$t('statutory.rates.unverified_title')" :message="$t('statutory.rates.unverified_message', { codes: unverified.map((s) => s.code).join(', ') })" />

            <TabNav v-model="tab" :tabs="[...schemeTabs, { key: 'formats', label: $t('statutory.rates.formats') }]" />

            <div v-if="tab !== 'formats'" class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="rate-sets">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('payroll.fields.code') }}</th>
                            <th class="px-3 py-2">{{ $t('statutory.rates.effective') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2">{{ $t('statutory.rates.source') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in shown" :key="s.id" class="border-t">
                            <td class="px-3 py-2 font-mono text-xs">{{ s.code }}<span v-if="s.name" class="block font-sans text-sm">{{ s.name }}</span></td>
                            <td class="px-3 py-2">{{ formatDate(s.effective_from) }} – {{ formatDate(s.effective_to) || $t('statutory.rates.open_ended') }}</td>
                            <td class="px-3 py-2"><RateSetBadge :status="s.status" :label="s.status_label" :placeholder="s.is_placeholder" /></td>
                            <td class="px-3 py-2 text-xs"><a v-if="s.source_url" :href="s.source_url" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">{{ s.source_ref ?? s.source_url }}</a><span v-else class="text-muted-foreground">—</span></td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <Button size="sm" variant="outline" as-child><Link :href="`/statutory/rates/${s.id}`">{{ $t('common.actions.view') }}</Link></Button>
                                <Button v-if="can.manage" size="sm" variant="ghost" @click="startCopy(s)"><Copy class="size-4" />{{ $t('statutory.rates.copy') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!shown.length"><td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('payroll.fields.code') }}</th><th class="px-3 py-2">{{ $t('payroll.fields.name') }}</th><th class="px-3 py-2">{{ $t('statutory.rates.version') }}</th><th class="px-3 py-2">{{ $t('common.fields.status') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="f in file_formats" :key="f.id" class="border-t">
                            <td class="px-3 py-2 font-mono text-xs">{{ f.code }}</td>
                            <td class="px-3 py-2">{{ f.name }}<span class="block text-xs text-muted-foreground">{{ $t(`statutory.enums.file_scheme.${f.scheme}`) }}</span></td>
                            <td class="px-3 py-2">{{ f.version ?? '—' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="f.status" :label="$t(`statutory.enums.format_status.${f.status}`)" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog :open="copyOf !== null" @update:open="(v: boolean) => { if (!v) copyOf = null }">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('statutory.rates.copy') }} — {{ copyOf?.code }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="copy">
                    <FormField :label="$t('payroll.fields.code')" :error="copyForm.errors.code" required><Input v-model="copyForm.code" class="font-mono" /></FormField>
                    <FormField :label="$t('statutory.rates.effective_from')" :error="copyForm.errors.effective_from" required><Input v-model="copyForm.effective_from" type="date" /></FormField>
                    <FormField :label="$t('statutory.rates.source_url')" :error="copyForm.errors.source_url"><Input v-model="copyForm.source_url" type="url" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="copyOf = null">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="copyForm.processing">{{ $t('statutory.rates.copy') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
