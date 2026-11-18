<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDate } from '@/composables/useFormat';
import { days } from '@/lib/leave';
import type { ApplicationRow } from '@/lib/leave';

defineProps<{ rows: ApplicationRow[]; showEmployee?: boolean }>();
</script>

<template>
    <div class="overflow-x-auto rounded-lg border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                    <th v-if="showEmployee" class="px-3 py-2">{{ $t('leave.fields.employee') }}</th>
                    <th class="px-3 py-2">{{ $t('leave.fields.leave_type') }}</th>
                    <th class="px-3 py-2">{{ $t('leave.fields.from_date') }}</th>
                    <th class="px-3 py-2">{{ $t('leave.fields.to_date') }}</th>
                    <th class="px-3 py-2 text-right">{{ $t('leave.fields.days') }}</th>
                    <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in rows" :key="a.id" class="border-t">
                    <td class="px-3 py-2"><Link :href="`/leave/applications/${a.id}`" class="font-mono text-primary hover:underline">{{ a.number ?? `#${a.id}` }}</Link></td>
                    <td v-if="showEmployee" class="px-3 py-2">{{ a.employee }} <span class="text-xs text-muted-foreground">{{ a.employee_no }}</span></td>
                    <td class="px-3 py-2"><span class="mr-1 inline-block size-2 rounded-full" :style="{ backgroundColor: a.colour ?? '#94a3b8' }" />{{ a.leave_type }}</td>
                    <td class="px-3 py-2">{{ formatDate(a.from_date) }}</td>
                    <td class="px-3 py-2">{{ formatDate(a.to_date) }}</td>
                    <td class="px-3 py-2 text-right">{{ days(a.total_days) }}</td>
                    <td class="px-3 py-2"><StatusBadge :status="a.status" :label="a.status_label" /></td>
                </tr>
                <tr v-if="!rows.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
            </tbody>
        </table>
    </div>
</template>
