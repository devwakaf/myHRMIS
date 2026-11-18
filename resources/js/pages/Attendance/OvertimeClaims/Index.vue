<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { hoursMinutes } from '@/lib/attendance';
import type { ClaimRow } from '@/lib/attendance';
import { days as dec } from '@/lib/leave';
import type { Paginated } from '@/types';

const props = defineProps<{
    claims: Paginated<ClaimRow>;
    filters: { month?: string; status?: string };
    can: { manage: boolean; submit: boolean; show_amounts: boolean };
}>();

const f = ref({ month: props.filters.month ?? '', status: props.filters.status ?? '' });
const generateMonth = ref(props.filters.month ?? new Date().toISOString().slice(0, 7));
const generating = ref(false);
const statuses = ['draft', 'pending', 'approved', 'rejected', 'cancelled'];

function apply() {
    router.get('/attendance/overtime-claims', { month: f.value.month || undefined, status: f.value.status || undefined }, { preserveState: true, replace: true });
}

function generate() {
    generating.value = true;
    router.post('/attendance/overtime-claims/generate', { month: generateMonth.value }, { preserveScroll: true, onFinish: () => (generating.value = false) });
}

function capPercent(c: ClaimRow): number | null {
    return c.cap_minutes ? Math.min(100, Math.round((c.cap_counted_minutes / c.cap_minutes) * 100)) : null;
}
</script>

<template>
    <Head :title="$t('attendance.pages.overtime_claims')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.overtime_claims', href: '/attendance/overtime-claims' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.overtime_claims')" :description="$t('attendance.claim.description')">
                <template v-if="can.manage" #actions>
                    <Input v-model="generateMonth" type="month" class="w-40" :aria-label="$t('attendance.claim.month')" />
                    <Button :disabled="generating || !generateMonth" data-testid="generate-claims" @click="generate"><Sparkles class="size-4" />{{ $t('attendance.claim.generate') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="apply">
                <label class="grid gap-1 text-xs">{{ $t('attendance.claim.month') }}<Input v-model="f.month" type="month" class="w-44" /></label>
                <SelectInput v-model="f.status" class="w-48" :options="statuses.map((s) => ({ value: s, label: $t(`common.approval_status.${s}`) }))" :placeholder="$t('attendance.common.all_statuses')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="claims-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.claim.month') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('attendance.claim.total_hours') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.claim.cap') }}</th>
                            <th v-if="can.show_amounts || claims.data.some((c) => c.total_amount !== null)" class="px-3 py-2 text-right">{{ $t('attendance.claim.total_amount') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in claims.data" :key="c.id" class="border-t">
                            <td class="px-3 py-2"><Link :href="`/attendance/overtime-claims/${c.id}`" class="font-mono text-primary hover:underline">{{ c.number ?? `#${c.id}` }}</Link></td>
                            <td class="px-3 py-2">{{ c.employee?.full_name }} <span class="text-xs text-muted-foreground">{{ c.employee?.employee_no }}</span></td>
                            <td class="px-3 py-2">{{ c.period_month.slice(0, 7) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ dec(c.total_hours) }}</td>
                            <td class="px-3 py-2">
                                <div v-if="capPercent(c) !== null" class="flex items-center gap-2">
                                    <div class="h-2 w-20 overflow-hidden rounded bg-muted"><div class="h-full" :class="c.cap_exceeded ? 'bg-red-500' : 'bg-emerald-500'" :style="{ width: `${capPercent(c)}%` }" /></div>
                                    <span class="text-xs tabular-nums" :class="c.cap_exceeded ? 'text-red-600' : 'text-muted-foreground'">{{ hoursMinutes(c.cap_counted_minutes) }}/{{ hoursMinutes(c.cap_minutes) }}</span>
                                </div>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td v-if="can.show_amounts || claims.data.some((x) => x.total_amount !== null)" class="px-3 py-2 text-right tabular-nums">{{ c.total_amount !== null ? formatMoney(c.total_amount) : '—' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="c.status" :label="c.status_label" /></td>
                        </tr>
                        <tr v-if="!claims.data.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="claims.links" :from="claims.from" :to="claims.to" :total="claims.total" />
        </div>
    </AppLayout>
</template>
