<script setup lang="ts">
import { computed } from 'vue';
import DayBreakdown from '@/components/leave/DayBreakdown.vue';
import { formatDate } from '@/composables/useFormat';
import { days } from '@/lib/leave';
import type { CapRoom, DayRow, ExpiringLot } from '@/lib/leave';

/** Leave section of the Kelulusan Saya drawer; renders LeaveApplicationApproval::decisionContext. */
type Context = {
    type: { code: string; name: string; pay_mode: string; has_balance: boolean };
    from_date: string;
    to_date: string;
    total_days: string;
    unpaid_days: string;
    reason: string | null;
    days: DayRow[];
    balance_before: string;
    balance_after: string | null;
    reserved_by_others: string;
    caps: CapRoom[];
    expiring: ExpiringLot[];
    block_list_hits: { list: string; date: string; reason: string | null }[];
    is_backdated: boolean;
    is_on_behalf: boolean;
    attachments: { id: number; kind_label: string; original_name: string; download_url: string | null }[];
};

const props = defineProps<{ context: Record<string, unknown> }>();
const c = computed(() => props.context as unknown as Context);
const negative = computed(() => c.value.balance_after !== null && Number(c.value.balance_after) < 0);
</script>

<template>
    <div class="space-y-3 text-sm" data-testid="leave-decision-context">
        <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
            <dt class="text-muted-foreground">{{ $t('leave.fields.leave_type') }}</dt>
            <dd>{{ c.type.name }} ({{ c.type.code }})</dd>
            <dt class="text-muted-foreground">{{ $t('leave.decision.dates') }}</dt>
            <dd>{{ formatDate(c.from_date) }} – {{ formatDate(c.to_date) }}</dd>
            <dt class="text-muted-foreground">{{ $t('leave.apply.deducted_days') }}</dt>
            <dd class="font-medium">{{ days(c.total_days) }}</dd>
            <dt class="text-muted-foreground">{{ $t('leave.apply.unpaid_days') }}</dt>
            <dd>{{ days(c.unpaid_days) }}</dd>
            <template v-if="c.type.has_balance">
                <dt class="text-muted-foreground">{{ $t('leave.apply.balance_before') }}</dt>
                <dd>{{ days(c.balance_before) }}</dd>
                <dt class="text-muted-foreground">{{ $t('leave.apply.balance_after') }}</dt>
                <dd :class="negative ? 'font-medium text-red-600' : ''">{{ days(c.balance_after) }}</dd>
                <dt class="text-muted-foreground">{{ $t('leave.decision.reserved_by_others') }}</dt>
                <dd>{{ days(c.reserved_by_others) }}</dd>
            </template>
        </dl>

        <p v-if="c.reason"><span class="text-xs text-muted-foreground">{{ $t('leave.fields.reason') }}</span><br />{{ c.reason }}</p>

        <ul v-if="c.caps.length" class="space-y-0.5 text-xs text-muted-foreground">
            <li v-for="cap in c.caps" :key="cap.cap_id">{{ $t('leave.me.cap_line', { name: cap.name, used: days(cap.used), cap: days(cap.cap_days) }) }} · {{ $t('leave.decision.cap_room', { room: days(cap.room) }) }}</li>
        </ul>
        <p v-for="(lot, i) in c.expiring" :key="i" class="text-xs text-amber-700 dark:text-amber-300">{{ $t('leave.me.expiring', { days: days(lot.remaining), date: formatDate(lot.date_to) }) }}</p>

        <ul v-if="c.block_list_hits.length" class="list-disc space-y-0.5 rounded bg-amber-50 p-2 pl-5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">
            <li v-for="(h, i) in c.block_list_hits" :key="i">{{ $t('leave.apply.block_hit', { date: formatDate(h.date), list: h.list }) }}<span v-if="h.reason"> — {{ h.reason }}</span></li>
        </ul>

        <p v-if="c.is_backdated || c.is_on_behalf" class="text-xs text-muted-foreground">
            {{ [c.is_backdated ? $t('leave.flags.backdated') : '', c.is_on_behalf ? $t('leave.flags.on_behalf') : ''].filter(Boolean).join(' · ') }}
        </p>

        <div v-if="c.attachments.length">
            <p class="text-xs text-muted-foreground">{{ $t('leave.show.attachments') }}</p>
            <ul class="space-y-0.5">
                <li v-for="a in c.attachments" :key="a.id">
                    {{ a.kind_label }} ·
                    <a v-if="a.download_url" :href="a.download_url" class="text-primary hover:underline">{{ a.original_name }}</a>
                    <span v-else class="text-muted-foreground">{{ $t('leave.show.restricted') }}</span>
                </li>
            </ul>
        </div>

        <details v-if="c.days.length">
            <summary class="cursor-pointer text-xs text-muted-foreground">{{ $t('leave.show.day_breakdown') }}</summary>
            <div class="mt-2"><DayBreakdown :rows="c.days" /></div>
        </details>
    </div>
</template>
