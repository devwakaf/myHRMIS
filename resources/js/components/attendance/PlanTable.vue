<script setup lang="ts">
import { formatDate } from '@/composables/useFormat';
import type { PlanDay } from '@/lib/attendance';

defineProps<{ days: PlanDay[] }>();

function skipped(outcome: string): boolean {
    return outcome.startsWith('skipped');
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border">
        <table class="w-full text-sm" data-testid="plan-table">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2">{{ $t('attendance.common.date') }}</th>
                    <th class="px-3 py-2">{{ $t('attendance.request.outcome') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="d in days" :key="d.date" class="border-t">
                    <td class="px-3 py-1.5">{{ formatDate(d.date) }}</td>
                    <td class="px-3 py-1.5" :class="skipped(d.outcome) ? 'text-muted-foreground' : ''">{{ d.outcome_label ?? d.outcome }}</td>
                </tr>
                <tr v-if="!days.length"><td colspan="2" class="px-3 py-4 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
            </tbody>
        </table>
    </div>
</template>
