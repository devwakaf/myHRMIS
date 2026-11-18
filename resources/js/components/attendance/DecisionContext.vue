<script setup lang="ts">
import { computed } from 'vue';
import { formatDate } from '@/composables/useFormat';
import { hoursMinutes } from '@/lib/attendance';
import { days as dec } from '@/lib/leave';

/**
 * Kehadiran section of the Kelulusan Saya drawer. Renders the `decisionContext` of the three
 * attendance approval hooks: attendance_request, shift_request and overtime_claim.
 */
type Ctx = Record<string, any>;

const props = defineProps<{ type: string; context: Record<string, unknown> }>();
const c = computed(() => props.context as Ctx);
const conflictLines = computed(() => {
    const conflicts = (c.value.conflicts ?? {}) as { locked_dates?: string[]; processed_dates?: string[]; other_requests?: string[] };

    return [
        { key: 'locked_dates', items: conflicts.locked_dates ?? [] },
        { key: 'processed_dates', items: conflicts.processed_dates ?? [] },
        { key: 'other_requests', items: conflicts.other_requests ?? [] },
    ].filter((x) => x.items.length > 0);
});
const dayTypes = computed(() => Object.entries((c.value.by_day_type ?? {}) as Record<string, { minutes: number; days: number }>));
const capPercent = computed(() => (c.value.cap?.cap_minutes ? Math.min(100, Math.round((c.value.cap.counted_minutes / c.value.cap.cap_minutes) * 100)) : null));
</script>

<template>
    <div class="space-y-3 text-sm" :data-testid="`attendance-context-${type}`">
        <template v-if="type === 'attendance_request'">
            <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
                <dt class="text-muted-foreground">{{ $t('attendance.request.kind') }}</dt>
                <dd>{{ c.kind_label }}</dd>
                <dt class="text-muted-foreground">{{ $t('attendance.decision.dates') }}</dt>
                <dd>{{ formatDate(c.from_date) }} – {{ formatDate(c.to_date) }}</dd>
                <template v-if="c.is_half_day">
                    <dt class="text-muted-foreground">{{ $t('attendance.request.half_day') }}</dt>
                    <dd>{{ formatDate(c.half_day_date) }} · {{ c.half_day_session ? $t(`attendance.enums.half_day_session.${c.half_day_session}`) : '' }}</dd>
                </template>
                <dt class="text-muted-foreground">{{ $t('attendance.request.include_rest_days') }}</dt>
                <dd>{{ c.include_rest_days ? $t('common.yes') : $t('common.no') }}</dd>
                <template v-if="c.duty_location">
                    <dt class="text-muted-foreground">{{ $t('attendance.request.duty_location') }}</dt>
                    <dd>{{ c.duty_location }}</dd>
                </template>
            </dl>
            <p v-if="c.explanation"><span class="text-xs text-muted-foreground">{{ $t('attendance.request.explanation') }}</span><br />{{ c.explanation }}</p>
            <details v-if="c.preview?.length" open>
                <summary class="cursor-pointer text-xs text-muted-foreground">{{ $t('attendance.decision.day_plan') }}</summary>
                <ul class="mt-1 space-y-0.5 text-xs">
                    <li v-for="d in c.preview" :key="d.date" :class="String(d.outcome).startsWith('skipped') ? 'text-muted-foreground' : ''">{{ formatDate(d.date) }} · {{ d.outcome_label }}</li>
                </ul>
            </details>
        </template>

        <template v-else-if="type === 'shift_request'">
            <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
                <dt class="text-muted-foreground">{{ $t('attendance.common.shift') }}</dt>
                <dd>{{ c.shift ? `${c.shift.name} (${c.shift.code}) · ${c.shift.time}` : '—' }}</dd>
                <dt class="text-muted-foreground">{{ $t('attendance.decision.dates') }}</dt>
                <dd>{{ formatDate(c.from_date) }} – {{ formatDate(c.to_date) }}</dd>
            </dl>
            <p v-if="c.reason"><span class="text-xs text-muted-foreground">{{ $t('attendance.shift_request.reason') }}</span><br />{{ c.reason }}</p>
            <ul v-if="conflictLines.length" class="list-disc space-y-0.5 rounded bg-amber-50 p-2 pl-5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100" data-testid="shift-conflicts">
                <li v-for="x in conflictLines" :key="x.key">{{ $t(`attendance.decision.conflicts.${x.key}`) }}: {{ x.items.join(', ') }}</li>
            </ul>
            <div v-if="c.days?.length">
                <p class="mb-1 text-xs text-muted-foreground">{{ $t('attendance.decision.current_shifts') }}</p>
                <ul class="space-y-0.5 text-xs">
                    <li v-for="d in c.days" :key="d.date">{{ formatDate(d.date) }} · {{ d.current_shift ? `${d.current_shift} (${d.current_time})` : $t('attendance.decision.no_shift') }}</li>
                </ul>
            </div>
            <div v-if="c.change_set?.length">
                <p class="mb-1 text-xs text-muted-foreground">{{ $t('attendance.decision.change_set') }}</p>
                <ul class="space-y-0.5 text-xs">
                    <li v-for="x in c.change_set" :key="x.assignment_id">{{ x.shift }} · {{ formatDate(x.start_date) }} – {{ x.end_date ? formatDate(x.end_date) : '…' }} · {{ $t(`attendance.decision.effects.${x.effect}`) }}</li>
                </ul>
            </div>
        </template>

        <template v-else>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
                <dt class="text-muted-foreground">{{ $t('attendance.claim.month') }}</dt>
                <dd>{{ String(c.period_month).slice(0, 7) }}</dd>
                <dt class="text-muted-foreground">{{ $t('attendance.claim.eligibility') }}</dt>
                <dd>{{ c.eligibility_label }}</dd>
                <dt class="text-muted-foreground">{{ $t('attendance.claim.total_hours') }}</dt>
                <dd class="font-medium">{{ dec(c.total_hours) }}</dd>
                <template v-if="c.show_amounts && c.total_amount !== null">
                    <dt class="text-muted-foreground">{{ $t('attendance.claim.total_amount') }}</dt>
                    <dd class="font-medium">RM {{ c.total_amount }}</dd>
                </template>
            </dl>
            <ul v-if="dayTypes.length" class="space-y-0.5 text-xs">
                <li v-for="[type2, v] in dayTypes" :key="type2">{{ $t(`attendance.enums.day_type.${type2}`) }}: {{ hoursMinutes(v.minutes) }} ({{ v.days }} {{ $t('attendance.common.days') }})</li>
            </ul>
            <div v-if="c.cap" class="space-y-1">
                <p class="text-xs text-muted-foreground">{{ $t('attendance.claim.cap') }}: {{ hoursMinutes(c.cap.counted_minutes) }} / {{ c.cap.cap_minutes ? hoursMinutes(c.cap.cap_minutes) : '—' }}</p>
                <div v-if="capPercent !== null" class="h-2 w-full overflow-hidden rounded bg-muted"><div class="h-full" :class="c.cap.exceeded ? 'bg-red-500' : 'bg-emerald-500'" :style="{ width: `${capPercent}%` }" /></div>
                <p v-if="c.cap.exceeded" class="text-xs text-red-600">{{ $t('attendance.claim.cap_exceeded') }}<span v-if="c.cap.override_reason"> — {{ c.cap.override_reason }}</span></p>
            </div>
            <details v-if="c.lines?.length">
                <summary class="cursor-pointer text-xs text-muted-foreground">{{ $t('attendance.claim.lines') }}</summary>
                <ul class="mt-1 space-y-0.5 text-xs">
                    <li v-for="(l, i) in c.lines" :key="i">
                        {{ formatDate(l.work_date) }} · {{ $t(`attendance.enums.day_type.${l.day_type}`) }} · {{ hoursMinutes(l.day_type === 'normal' ? l.beyond_normal_minutes : l.worked_minutes) }}
                        <span v-if="c.show_amounts && l.amount !== null"> · RM {{ l.amount }}</span>
                        <span v-for="flag in l.flags" :key="flag" class="ml-1 rounded bg-amber-100 px-1 text-amber-800 dark:bg-amber-900 dark:text-amber-100">{{ $t(`attendance.reports.flags.${flag}`) }}</span>
                    </li>
                </ul>
            </details>
        </template>
    </div>
</template>
