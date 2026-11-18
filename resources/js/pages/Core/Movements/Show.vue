<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';

type Line = { field: string; label: string; value: string | null };

const props = defineProps<{
    movement: {
        id: number; number: string; employee_id: number; employee: string | null; employee_no: string | null; type: string; type_label: string;
        effective_date: string; status: string; status_label: string; applied_at: string | null; amended_from_id: number | null;
        before_values: Line[]; after_values: Line[]; proposed: Line[]; reason: string | null; salary_note: string | null;
        cancel_reason: string | null; target_organisation: string | null; target_employee_id: number | null;
    };
    can: { update: boolean; submit: boolean; cancel: boolean };
}>();

const cancelMode = ref<null | 'cancel' | 'duplicate'>(null);
const base = `/employee-movements/${props.movement.id}`;

function confirmCancel(reason: string) {
    router.post(`${base}/${cancelMode.value === 'duplicate' ? 'cancel-duplicate' : 'cancel'}`, { reason });
    cancelMode.value = null;
}
</script>

<template>
    <Head :title="movement.number" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.movements', href: '/employee-movements' }, { title: movement.number, href: base }]">
        <div class="max-w-4xl p-4 md:p-6">
            <PageHeader :title="`${movement.number} · ${movement.type_label}`">
                <template #meta>
                    <p class="text-sm">
                        <Link :href="`/employees/${movement.employee_id}`" class="hover:underline">{{ movement.employee }}</Link>
                        <span class="text-muted-foreground"> {{ movement.employee_no }} · {{ $t('validation.attributes.effective_date') }} {{ formatDate(movement.effective_date) }}</span>
                    </p>
                    <StatusBadge :status="movement.status" :label="movement.status_label" />
                    <span v-if="movement.applied_at" class="ml-2 text-xs text-muted-foreground">{{ $t('core.movement.applied_at', { at: formatDateTime(movement.applied_at) }) }}</span>
                </template>
                <template #actions>
                    <Button v-if="can.update" variant="outline" as-child><Link :href="`${base}/edit`">{{ $t('common.actions.edit') }}</Link></Button>
                    <Button v-if="can.update" variant="ghost" @click="router.delete(base)">{{ $t('common.actions.delete') }}</Button>
                    <Button v-if="can.submit" @click="router.post(`${base}/submit`)">{{ $t('common.actions.submit') }}</Button>
                    <Button v-if="can.cancel" variant="outline" @click="cancelMode = 'cancel'">{{ $t('common.actions.cancel_doc') }}</Button>
                    <Button v-if="can.cancel" variant="outline" @click="cancelMode = 'duplicate'">{{ $t('common.actions.cancel_duplicate') }}</Button>
                </template>
            </PageHeader>

            <p v-if="movement.target_organisation" class="mb-4 text-sm">{{ $t('core.movement.target_organisation') }}: {{ movement.target_organisation }}</p>
            <p v-if="movement.cancel_reason" class="mb-4 text-sm text-red-700">{{ $t('common.fields.reason') }}: {{ movement.cancel_reason }}</p>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2">{{ $t('core.movement.before') }}</th>
                            <th class="px-3 py-2">{{ movement.applied_at ? $t('core.movement.after') : $t('core.movement.new') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in movement.applied_at ? movement.after_values : movement.proposed" :key="line.field" class="border-t">
                            <td class="px-3 py-2 font-medium">{{ line.label }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ movement.before_values.find((b) => b.field === line.field)?.value ?? '—' }}</td>
                            <td class="px-3 py-2">{{ line.value ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                <div><p class="text-muted-foreground">{{ $t('common.fields.reason') }}</p><p>{{ movement.reason ?? '—' }}</p></div>
                <div><p class="text-muted-foreground">{{ $t('core.movement.salary_note') }}</p><p>{{ movement.salary_note ?? '—' }}</p></div>
            </div>
        </div>

        <ReasonDialog
            :open="cancelMode !== null"
            :title="cancelMode === 'duplicate' ? $t('common.actions.cancel_duplicate') : $t('common.actions.cancel_doc')"
            :description="$t('core.movement.cancel_hint')"
            destructive
            @update:open="(o: boolean) => { if (!o) cancelMode = null }"
            @confirm="confirmCancel"
        />
    </AppLayout>
</template>
