<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { ref } from 'vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import UnverifiedBanner from '@/components/payroll/UnverifiedBanner.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { monthLabel } from '@/lib/payroll';

const props = defineProps<{
    kind: string;
    kinds: string[];
    period: string;
    unverified: boolean;
    columns: { key: string; label: string; money: boolean }[];
    rows: Record<string, string>[];
    totals: Record<string, string>;
    summary: Record<string, string>;
}>();

const month = ref(props.period);
function go() {
    router.get(`/statutory/listings/${props.kind}`, { period: month.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="$t(`statutory.listings.names.${kind}`)" />
    <AppLayout :breadcrumbs="[{ title: 'statutory.pages.files', href: '/statutory/files' }, { title: `statutory.listings.names.${kind}`, href: `/statutory/listings/${kind}` }]">
        <div class="space-y-4 p-4 md:p-6">
            <div class="flex flex-wrap gap-1 border-b">
                <Link v-for="k in kinds" :key="k" :href="`/statutory/listings/${k}?period=${period}`" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="k === kind ? 'border-primary font-medium' : 'border-transparent text-muted-foreground'">{{ $t(`statutory.listings.names.${k}`) }}</Link>
            </div>
            <PageHeader :title="$t(`statutory.listings.names.${kind}`)" :description="`${monthLabel(period)} · ${$t('statutory.listings.rows', { count: summary.count })}${summary.malaysian !== undefined ? ` · ${$t('statutory.listings.malaysian', { count: summary.malaysian })}` : ''}`">
                <template #actions>
                    <Button variant="outline" as-child><a :href="`/statutory/listings/${kind}/export?period=${period}&format=pdf`" data-testid="export-pdf"><Download class="size-4" />PDF</a></Button>
                    <Button variant="outline" as-child><a :href="`/statutory/listings/${kind}/export?period=${period}&format=xlsx`" data-testid="export-xlsx"><Download class="size-4" />XLSX</a></Button>
                    <Button variant="outline" as-child><a :href="`/statutory/listings/${kind}/export?period=${period}&format=csv`"><Download class="size-4" />CSV</a></Button>
                </template>
            </PageHeader>
            <UnverifiedBanner v-if="unverified" />

            <form class="flex items-end gap-2" @submit.prevent="go">
                <label class="grid gap-1 text-xs">{{ $t('payroll.runs.period') }}<Input v-model="month" type="month" class="w-44" /></label>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="listing">
                    <thead class="bg-muted/50 text-left"><tr><th v-for="c in columns" :key="c.key" class="whitespace-nowrap px-3 py-2" :class="c.money ? 'text-right' : ''">{{ c.label }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in rows" :key="i" class="border-t">
                            <td v-for="c in columns" :key="c.key" class="px-3 py-1.5" :class="c.money ? 'text-right' : ''"><MoneyText v-if="c.money" :value="r[c.key]" /><template v-else>{{ r[c.key] }}</template></td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="columns.length" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                    <tfoot v-if="rows.length">
                        <tr class="border-t-2 bg-muted/40 font-semibold">
                            <td v-for="(c, i) in columns" :key="c.key" class="px-3 py-2" :class="c.money ? 'text-right' : ''">
                                <template v-if="i === 0">{{ $t('payroll.pdf.total') }}</template>
                                <MoneyText v-else-if="totals[c.key] !== undefined" :value="totals[c.key]" />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
