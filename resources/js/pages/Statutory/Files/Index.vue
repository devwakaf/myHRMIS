<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, FileText } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import UnverifiedBanner from '@/components/payroll/UnverifiedBanner.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel } from '@/lib/payroll';
import type { FileRow } from '@/lib/payroll';

type Remittance = { id: number; scheme: string; amount: string; paid_on: string; receipt_no: string | null };

const props = defineProps<{ period: string; files: FileRow[]; remittances: Remittance[]; can: { export: boolean; remittances: boolean } }>();

const month = ref(props.period);
const KINDS = ['perkeso', 'cp39', 'kwsp'] as const;
const LISTINGS = ['kwsp', 'perkeso', 'sip', 'pcb', 'hrdc'] as const;

function go() {
    router.get('/statutory/files', { period: month.value }, { preserveState: true, replace: true });
}
function generate(kind: string) {
    router.post(`/statutory/files/${kind}`, { period: props.period }, { preserveScroll: true });
}

const submitTarget = ref<FileRow | null>(null);
const submitForm = useForm({ external_ref: '' });
function markSubmitted() {
    if (!submitTarget.value) return;
    submitForm.transform((d) => ({ external_ref: d.external_ref || null })).post(`/statutory/files/${submitTarget.value.id}/submitted`, { preserveScroll: true, onSuccess: () => (submitTarget.value = null) });
}
</script>

<template>
    <Head :title="$t('statutory.pages.files')" />
    <AppLayout :breadcrumbs="[{ title: 'statutory.pages.files', href: '/statutory/files' }]">
        <div class="space-y-4 p-4 md:p-6">
            <PageHeader :title="$t('statutory.pages.files')" :description="$t('statutory.files.description')" />

            <form class="flex flex-wrap items-end gap-2" @submit.prevent="go">
                <FormField :label="$t('payroll.runs.period')"><Input v-model="month" type="month" class="w-44" /></FormField>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
                <span class="pb-2 text-sm text-muted-foreground">{{ monthLabel(period) }}</span>
            </form>

            <UnverifiedBanner :title="$t('statutory.files.kwsp_notice_title')" :message="$t('statutory.files.kwsp_notice')" />

            <section v-if="can.export" class="grid gap-3 sm:grid-cols-3">
                <div v-for="k in KINDS" :key="k" class="rounded-lg border p-4">
                    <h2 class="font-semibold">{{ $t(`statutory.files.kinds.${k}`) }}</h2>
                    <p class="my-1 text-xs text-muted-foreground">{{ $t(`statutory.files.kind_hint.${k}`) }}</p>
                    <p v-if="k === 'kwsp'" class="mb-2 text-xs font-medium text-amber-800 dark:text-amber-300" data-testid="kwsp-unverified">{{ $t('statutory.messages.format_unverified') }}</p>
                    <Button size="sm" :data-testid="`generate-${k}`" @click="generate(k)"><FileText class="size-4" />{{ $t('statutory.files.generate') }}</Button>
                </div>
            </section>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="statutory-files">
                    <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('payroll.wizard.file') }}</th><th class="px-3 py-2">{{ $t('payroll.wizard.records') }}</th><th class="px-3 py-2 text-right">{{ $t('payroll.fields.amount') }}</th><th class="px-3 py-2">{{ $t('common.fields.status') }}</th><th class="px-3 py-2"></th></tr></thead>
                    <tbody>
                        <tr v-for="f in files" :key="f.id" class="border-t">
                            <td class="px-3 py-2">{{ f.kind_label }}<span class="block text-xs text-muted-foreground">{{ f.file_name }} · {{ formatDateTime(f.generated_at) }}</span></td>
                            <td class="px-3 py-2">{{ f.record_count ?? '—' }}</td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="f.total_amount" /></td>
                            <td class="space-x-1 px-3 py-2">
                                <StatusBadge :status="f.status === 'submitted' ? 'submitted' : 'draft'" :label="f.status === 'submitted' ? $t('statutory.files.submitted') : $t('statutory.files.generated')" />
                                <StatusBadge v-if="f.format_unverified" status="unverified" :label="$t('statutory.messages.format_unverified')" />
                            </td>
                            <td class="space-x-1 px-3 py-2 text-right">
                                <Button size="sm" variant="outline" as-child><a :href="f.download_url"><Download class="size-4" />{{ $t('payroll.actions.download') }}</a></Button>
                                <Button v-if="can.remittances && f.status !== 'submitted'" size="sm" variant="ghost" @click="submitTarget = f; submitForm.reset()">{{ $t('statutory.files.mark_submitted') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!files.length"><td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <section class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border p-4">
                    <h2 class="mb-2 text-sm font-semibold">{{ $t('statutory.files.listings') }}</h2>
                    <ul class="space-y-1 text-sm">
                        <li v-for="l in LISTINGS" :key="l"><Link :href="`/statutory/listings/${l}?period=${period}`" class="text-primary hover:underline">{{ $t(`statutory.listings.names.${l}`) }}</Link></li>
                    </ul>
                    <p class="mt-3 text-xs text-muted-foreground"><Link href="/statutory/ea" class="text-primary hover:underline">{{ $t('statutory.pages.ea') }}</Link> · <Link href="/statutory/remittances" class="text-primary hover:underline">{{ $t('statutory.pages.remittances') }}</Link></p>
                </div>
                <div class="rounded-lg border p-4">
                    <h2 class="mb-2 text-sm font-semibold">{{ $t('statutory.files.recorded_payments') }}</h2>
                    <ul v-if="remittances.length" class="space-y-1 text-sm">
                        <li v-for="r in remittances" :key="r.id" class="flex justify-between"><span>{{ $t(`payroll.enums.remittance_scheme.${r.scheme}`) }} · {{ formatDate(r.paid_on) }}</span><MoneyText :value="r.amount" /></li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">{{ $t('statutory.files.no_payments') }}</p>
                </div>
            </section>
        </div>

        <Dialog :open="submitTarget !== null" @update:open="(v: boolean) => { if (!v) submitTarget = null }">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('statutory.files.mark_submitted') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="markSubmitted">
                    <FormField :label="$t('statutory.files.external_ref')" :error="submitForm.errors.external_ref"><Input v-model="submitForm.external_ref" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="submitTarget = null">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="submitForm.processing">{{ $t('common.actions.confirm') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
