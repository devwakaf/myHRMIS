<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BadgeCheck, FlaskConical, Send, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TabNav from '@/components/app/TabNav.vue';
import RateSetBadge from '@/components/payroll/RateSetBadge.vue';
import UnverifiedBanner from '@/components/payroll/UnverifiedBanner.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { postJson } from '@/lib/payroll';
import type { RateSetRow } from '@/lib/payroll';

const props = defineProps<{
    set: RateSetRow;
    tables: Record<string, Record<string, unknown>[]>;
    csv_columns: Record<string, string[]>;
    can: { manage: boolean; verify: boolean };
}>();

const LIMIT = 200;
const tableNames = Object.keys(props.tables);
const tab = ref(tableNames[0] ?? '');
const tabs = computed(() => tableNames.map((t) => ({ key: t, label: `${t} (${props.tables[t].length})` })));
const rows = computed(() => (props.tables[tab.value] ?? []).slice(0, LIMIT));
const columns = computed(() => Object.keys(props.tables[tab.value]?.[0] ?? {}).filter((c) => c !== 'id' && c !== 'rate_set_id'));

const isDraft = computed(() => props.set.status === 'draft');
const isUnverified = computed(() => props.set.status === 'unverified');

const failures = ref<string[] | null>(null);
const busy = ref(false);

async function runFixtures() {
    busy.value = true;
    try {
        failures.value = (await postJson<{ failures: string[] }>(`/statutory/rates/${props.set.id}/fixtures`, {})).failures;
    } finally {
        busy.value = false;
    }
}

const importOpen = ref(false);
const importForm = useForm({ file: null as File | null, table: null as string | null });
function doImport() {
    importForm.post(`/statutory/rates/${props.set.id}/import`, { forceFormData: true, onSuccess: () => (importOpen.value = false) });
}

const verifyOpen = ref(false);
const deleteOpen = ref(false);
const post = (path: string) => router.post(`/statutory/rates/${props.set.id}/${path}`, {}, { preserveScroll: true, onSuccess: () => (verifyOpen.value = false) });
</script>

<template>
    <Head :title="`${$t('statutory.pages.rate_set')} ${set.code}`" />
    <AppLayout :breadcrumbs="[{ title: 'statutory.pages.rates', href: '/statutory/rates' }, { title: 'statutory.pages.rate_set', href: `/statutory/rates/${set.id}` }]">
        <div class="space-y-4 p-4 md:p-6">
            <PageHeader :title="`${set.scheme_label} — ${set.code}`" :description="set.name ?? undefined">
                <template #meta><RateSetBadge :status="set.status" :label="set.status_label" :placeholder="set.is_placeholder" /></template>
                <template #actions>
                    <Button variant="outline" as-child><Link href="/statutory/rates">{{ $t('common.actions.back') }}</Link></Button>
                    <Button variant="outline" :disabled="busy" data-testid="run-fixtures" @click="runFixtures"><FlaskConical class="size-4" />{{ $t('statutory.rates.run_fixtures') }}</Button>
                    <Button v-if="can.manage && isDraft" variant="outline" @click="importOpen = true"><Upload class="size-4" />{{ $t('statutory.rates.import') }}</Button>
                    <Button v-if="can.manage && isDraft" data-testid="publish" @click="post('publish')"><Send class="size-4" />{{ $t('statutory.rates.publish') }}</Button>
                    <Button v-if="can.manage && isUnverified" :disabled="!can.verify || set.is_placeholder" data-testid="verify" @click="verifyOpen = true"><BadgeCheck class="size-4" />{{ $t('statutory.rates.verify') }}</Button>
                    <Button v-if="can.manage && isDraft" variant="destructive" @click="deleteOpen = true">{{ $t('common.actions.delete') }}</Button>
                </template>
            </PageHeader>

            <UnverifiedBanner v-if="set.status !== 'verified'" :title="$t(`statutory.rates.banner_${set.status}`)" :message="set.is_placeholder ? $t('statutory.rates.placeholder_message') : $t('statutory.messages.unverified_banner')">
                <p v-if="can.manage && isUnverified && !can.verify && !set.is_placeholder" class="mt-1 text-xs">{{ $t('statutory.rates.two_person') }}</p>
            </UnverifiedBanner>

            <dl class="grid gap-3 rounded-lg border p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs text-muted-foreground">{{ $t('statutory.rates.effective') }}</dt><dd>{{ formatDate(set.effective_from) }} – {{ formatDate(set.effective_to) || $t('statutory.rates.open_ended') }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('statutory.rates.source') }}</dt><dd><a v-if="set.source_url" :href="set.source_url" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">{{ set.source_ref ?? set.source_url }}</a><span v-else>—</span></dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('statutory.rates.fixture_passed') }}</dt><dd>{{ formatDateTime(set.fixture_passed_at) || '—' }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">{{ $t('statutory.rates.verified_at') }}</dt><dd>{{ formatDateTime(set.verified_at) || '—' }}</dd></div>
                <div v-if="set.notes" class="sm:col-span-2 lg:col-span-4"><dt class="text-xs text-muted-foreground">{{ $t('common.fields.note') }}</dt><dd class="whitespace-pre-line">{{ set.notes }}</dd></div>
            </dl>

            <div v-if="failures !== null" class="rounded-md border px-3 py-2 text-sm" :class="failures.length ? 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950' : 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950'" data-testid="fixture-result">
                <p v-if="!failures.length">{{ $t('statutory.rates.fixtures_ok') }}</p>
                <template v-else>
                    <p class="font-medium">{{ $t('statutory.rates.fixtures_failed') }}</p>
                    <ul class="list-disc pl-5"><li v-for="(f, i) in failures" :key="i">{{ f }}</li></ul>
                </template>
            </div>

            <div v-if="tableNames.length">
                <TabNav v-model="tab" :tabs="tabs" />
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-xs">
                        <thead class="bg-muted/50 text-left"><tr><th v-for="c in columns" :key="c" class="whitespace-nowrap px-2 py-1.5 font-mono">{{ c }}</th></tr></thead>
                        <tbody>
                            <tr v-for="(r, i) in rows" :key="i" class="border-t"><td v-for="c in columns" :key="c" class="whitespace-nowrap px-2 py-1">{{ r[c] ?? '' }}</td></tr>
                            <tr v-if="!rows.length"><td :colspan="columns.length || 1" class="px-3 py-4 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="(tables[tab] ?? []).length > LIMIT" class="mt-1 text-xs text-muted-foreground">{{ $t('statutory.rates.truncated', { limit: String(LIMIT), total: String(tables[tab].length) }) }}</p>
                <p v-if="csv_columns[tab]" class="mt-1 text-xs text-muted-foreground">{{ $t('statutory.rates.csv_columns') }}: <span class="font-mono">{{ csv_columns[tab].join(', ') }}</span></p>
            </div>
        </div>

        <Dialog v-model:open="importOpen">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('statutory.rates.import') }}</DialogTitle><DialogDescription>{{ $t('statutory.rates.import_hint') }}</DialogDescription></DialogHeader>
                <form class="grid gap-3" @submit.prevent="doImport">
                    <FormField :label="$t('statutory.rates.import_table')" :error="importForm.errors.table"><SelectInput v-model="importForm.table" :options="tableNames.map((t) => ({ value: t, label: t }))" :placeholder="$t('statutory.rates.import_table_default')" /></FormField>
                    <FormField :label="$t('statutory.rates.import_file')" :error="importForm.errors.file" required>
                        <input type="file" accept=".csv,text/csv,text/plain" class="text-sm" data-testid="import-file" @change="importForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    </FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="importOpen = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="importForm.processing || !importForm.file">{{ $t('statutory.rates.import') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="verifyOpen">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('statutory.rates.verify') }}</DialogTitle><DialogDescription>{{ $t('statutory.rates.verify_confirm', { code: set.code }) }}</DialogDescription></DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="verifyOpen = false">{{ $t('common.actions.cancel') }}</Button>
                    <Button data-testid="verify-confirm" @click="post('verify')">{{ $t('statutory.rates.verify') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteOpen">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('common.actions.delete') }}</DialogTitle><DialogDescription>{{ $t('statutory.rates.delete_confirm', { code: set.code }) }}</DialogDescription></DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="deleteOpen = false">{{ $t('common.actions.cancel') }}</Button>
                    <Button variant="destructive" @click="router.delete(`/statutory/rates/${set.id}`)">{{ $t('common.actions.delete') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
