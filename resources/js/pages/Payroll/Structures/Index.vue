<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Copy, Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';

type Structure = {
    id: number;
    code: string;
    name: string;
    name_ms: string;
    revision: number;
    is_active: boolean;
    last_validated_at: string | null;
    employee_count: number;
};

defineProps<{ structures: Structure[]; can: { manage: boolean } }>();

const copyOf = ref<Structure | null>(null);
const copyForm = useForm({ code: '', name_ms: '' });

function startCopy(s: Structure) {
    copyOf.value = s;
    copyForm.defaults({ code: `${s.code}_SALINAN`, name_ms: `${s.name_ms} (salinan)` }).reset();
    copyForm.clearErrors();
}

function copy() {
    if (!copyOf.value) return;
    copyForm.post(`/payroll/structures/${copyOf.value.id}/copy`, { onSuccess: () => (copyOf.value = null) });
}
</script>

<template>
    <Head :title="$t('payroll.pages.structures')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.structures', href: '/payroll/structures' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.structures')" :description="$t('payroll.structures.description')">
                <template #actions>
                    <Button v-if="can.manage" as-child data-testid="structure-new"><Link href="/payroll/structures/create"><Plus class="size-4" />{{ $t('payroll.structures.new') }}</Link></Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('payroll.fields.code') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.name') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.structures.revision') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('payroll.structures.employees') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.structures.validated') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in structures" :key="s.id" class="border-t">
                            <td class="px-3 py-2 font-mono text-xs">{{ s.code }}</td>
                            <td class="px-3 py-2">{{ s.name }}</td>
                            <td class="px-3 py-2 text-right">{{ s.revision }}</td>
                            <td class="px-3 py-2 text-right">{{ s.employee_count }}</td>
                            <td class="px-3 py-2">{{ formatDateTime(s.last_validated_at) || '—' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="s.is_active ? 'active' : 'removed'" :label="s.is_active ? $t('common.active') : $t('common.inactive')" /></td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <Button size="sm" variant="outline" as-child><Link :href="`/payroll/structures/${s.id}/edit`">{{ can.manage ? $t('common.actions.edit') : $t('common.actions.view') }}</Link></Button>
                                <Button v-if="can.manage" size="sm" variant="ghost" @click="startCopy(s)"><Copy class="size-4" />{{ $t('payroll.structures.copy') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!structures.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog :open="copyOf !== null" @update:open="(v: boolean) => { if (!v) copyOf = null }">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('payroll.structures.copy_title') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="copy">
                    <FormField :label="$t('payroll.fields.code')" :error="copyForm.errors.code" required><Input v-model="copyForm.code" class="font-mono uppercase" /></FormField>
                    <FormField :label="$t('payroll.fields.name_ms')" :error="copyForm.errors.name_ms" required><Input v-model="copyForm.name_ms" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="copyOf = null">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="copyForm.processing">{{ $t('payroll.structures.copy') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
