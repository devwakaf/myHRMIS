<script setup lang="ts">
import { formatDateTime } from '@/composables/useFormat';
import type { CheckinRow } from '@/lib/attendance';

defineProps<{ checkins: CheckinRow[]; showEmployee?: boolean; showCoordinates?: boolean }>();
</script>

<template>
    <div class="overflow-x-auto rounded-lg border">
        <table class="w-full text-sm" data-testid="checkin-table">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2">{{ $t('attendance.log.time') }}</th>
                    <th v-if="showEmployee" class="px-3 py-2">{{ $t('attendance.common.employee') }}</th>
                    <th class="px-3 py-2">{{ $t('attendance.log.type') }}</th>
                    <th class="px-3 py-2">{{ $t('attendance.log.source') }}</th>
                    <th class="px-3 py-2">{{ $t('attendance.log.geofence') }}</th>
                    <th class="px-3 py-2">{{ $t('attendance.log.location') }}</th>
                    <th class="px-3 py-2 text-right">{{ $t('attendance.log.distance') }}</th>
                    <th v-if="showCoordinates" class="px-3 py-2">{{ $t('attendance.log.coordinates') }}</th>
                    <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                    <th v-if="$slots.actions" class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in checkins" :key="c.id" class="border-t" :class="c.voided_at ? 'text-muted-foreground line-through' : ''">
                    <td class="whitespace-nowrap px-3 py-1.5">{{ formatDateTime(c.punched_at) }}<span v-if="c.skew_warning" class="ml-1 text-amber-600" :title="$t('attendance.log.skew_warning')">⚠</span></td>
                    <td v-if="showEmployee" class="px-3 py-1.5">{{ c.employee?.full_name }}</td>
                    <td class="px-3 py-1.5">{{ c.log_type ? $t(`attendance.enums.log_type.${c.log_type}`) : '—' }}</td>
                    <td class="px-3 py-1.5">{{ c.source_label }}</td>
                    <td class="px-3 py-1.5">{{ c.geofence_result_label }}</td>
                    <td class="px-3 py-1.5">{{ c.location ?? '—' }}</td>
                    <td class="px-3 py-1.5 text-right tabular-nums">{{ c.distance_m !== null ? `${Math.round(Number(c.distance_m))} m` : '—' }}</td>
                    <td v-if="showCoordinates" class="whitespace-nowrap px-3 py-1.5 font-mono text-xs">{{ c.latitude !== null ? `${c.latitude}, ${c.longitude}` : '—' }}</td>
                    <td class="px-3 py-1.5">{{ c.processing_state_label }}<span v-if="c.review_reason_label" class="text-xs text-muted-foreground"> · {{ c.review_reason_label }}</span></td>
                    <td v-if="$slots.actions" class="px-3 py-1.5 text-right"><slot name="actions" :checkin="c" /></td>
                </tr>
                <tr v-if="!checkins.length"><td :colspan="10" class="px-3 py-4 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
            </tbody>
        </table>
    </div>
</template>
