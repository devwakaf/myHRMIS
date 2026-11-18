<script setup lang="ts">
import { formatDate } from '@/composables/useFormat';
import { days } from '@/lib/leave';
import type { DayRow } from '@/lib/leave';

defineProps<{ rows: DayRow[] }>();

function tone(kind: string): string {
    return kind === 'working' ? '' : kind === 'holiday' ? 'text-sky-700 dark:text-sky-300' : 'text-muted-foreground';
}
</script>

<template>
    <div class="max-h-72 overflow-auto rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-xs">
                <tr>
                    <th class="px-2 py-1">{{ $t('leave.fields.date') }}</th>
                    <th class="px-2 py-1">{{ $t('leave.fields.day_kind') }}</th>
                    <th class="px-2 py-1">{{ $t('leave.fields.session') }}</th>
                    <th class="px-2 py-1 text-right">{{ $t('leave.fields.deducted') }}</th>
                    <th class="px-2 py-1 text-right">{{ $t('leave.fields.unpaid') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.date" class="border-t" :class="tone(row.day_kind)">
                    <td class="px-2 py-1">{{ formatDate(row.date) }}</td>
                    <td class="px-2 py-1">{{ row.day_kind_label }}<span v-if="row.holiday_name"> · {{ row.holiday_name }}</span></td>
                    <td class="px-2 py-1">{{ $t(`leave.enums.day_session.${row.session}`) }}</td>
                    <td class="px-2 py-1 text-right">{{ days(row.portion) }}</td>
                    <td class="px-2 py-1 text-right">{{ days(row.unpaid_portion) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
