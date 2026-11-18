<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import UnverifiedBanner from '@/components/payroll/UnverifiedBanner.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';

type Form = { id: number; employee_id: number; employee: string; tax_year: number; serial_no: string; status: string; status_label: string; data: { gross?: string; pcb?: string } };

const props = defineProps<{ year: number; forms: Form[]; can: { generate: boolean; publish: boolean } }>();

const year = ref(props.year);
function go() {
    router.get('/statutory/ea', { year: year.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="$t('statutory.pages.ea')" />
    <AppLayout :breadcrumbs="[{ title: 'statutory.pages.ea', href: '/statutory/ea' }]">
        <div class="space-y-4 p-4 md:p-6">
            <PageHeader :title="$t('statutory.pages.ea')" :description="$t('statutory.ea.description')">
                <template #actions>
                    <Button v-if="can.generate" data-testid="ea-generate" @click="router.post('/statutory/ea/generate', { year }, { preserveScroll: true })">{{ $t('statutory.ea.generate', { year: String(year) }) }}</Button>
                </template>
            </PageHeader>
            <UnverifiedBanner :message="$t('statutory.ea.layout_notice')" />

            <form class="flex items-end gap-2" @submit.prevent="go">
                <FormField :label="$t('statutory.ea.year')"><Input v-model="year" type="number" min="2000" max="2100" class="w-32" /></FormField>
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('payroll.fields.employee') }}</th><th class="px-3 py-2">{{ $t('statutory.ea.serial') }}</th><th class="px-3 py-2 text-right">{{ $t('payroll.pdf.ea.gross') }}</th><th class="px-3 py-2 text-right">PCB</th><th class="px-3 py-2">{{ $t('common.fields.status') }}</th><th class="px-3 py-2"></th></tr></thead>
                    <tbody>
                        <tr v-for="f in forms" :key="f.id" class="border-t">
                            <td class="px-3 py-2">{{ f.employee }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ f.serial_no }}</td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="f.data.gross" /></td>
                            <td class="px-3 py-2 text-right"><MoneyText :value="f.data.pcb" /></td>
                            <td class="px-3 py-2"><StatusBadge :status="f.status" :label="f.status_label" /></td>
                            <td class="space-x-1 px-3 py-2 text-right">
                                <Button size="sm" variant="outline" as-child><a :href="`/statutory/ea/${f.id}/pdf`"><Download class="size-4" />EA</a></Button>
                                <Button size="sm" variant="outline" as-child><a :href="`/statutory/pcb2ii/${f.employee_id}/pdf?year=${f.tax_year}`"><Download class="size-4" />PCB 2(II)</a></Button>
                                <Button v-if="can.publish && f.status === 'draft'" size="sm" @click="router.post(`/statutory/ea/${f.id}/publish`, {}, { preserveScroll: true })">{{ $t('statutory.ea.publish') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!forms.length"><td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('statutory.ea.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
