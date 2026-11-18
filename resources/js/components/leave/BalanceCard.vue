<script setup lang="ts">
import { computed } from 'vue';
import { formatDate } from '@/composables/useFormat';
import { days } from '@/lib/leave';
import type { Balance } from '@/lib/leave';

const props = defineProps<{ balance: Balance }>();
const soonest = computed(() => props.balance.expiring[0] ?? null);
const expiringTotal = computed(() => props.balance.expiring.reduce((sum, lot) => sum + Number(lot.remaining), 0));
</script>

<template>
    <div class="rounded-lg border p-4" data-testid="balance-card">
        <div class="mb-2 flex items-center gap-2">
            <span class="size-3 rounded-full" :style="{ backgroundColor: balance.type.colour ?? '#94a3b8' }" />
            <h3 class="font-medium">{{ balance.type.display_name }}</h3>
            <span class="text-xs text-muted-foreground">{{ balance.type.code }}</span>
        </div>
        <template v-if="balance.has_balance">
            <p class="text-3xl font-semibold tracking-tight">
                {{ days(balance.available) }}<span class="ml-1 text-sm font-normal text-muted-foreground">{{ $t('leave.me.days_available') }}</span>
            </p>
            <dl class="mt-3 grid grid-cols-3 gap-2 text-xs">
                <div><dt class="text-muted-foreground">{{ $t('leave.balance.credited') }}</dt><dd class="text-sm">{{ days(balance.credited) }}</dd></div>
                <div><dt class="text-muted-foreground">{{ $t('leave.balance.reserved') }}</dt><dd class="text-sm">{{ days(balance.reserved) }}</dd></div>
                <div><dt class="text-muted-foreground">{{ $t('leave.balance.taken') }}</dt><dd class="text-sm">{{ days(balance.taken) }}</dd></div>
            </dl>
            <p v-if="soonest" class="mt-3 rounded bg-amber-50 px-2 py-1 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                {{ $t('leave.me.expiring', { days: days(String(expiringTotal)), date: formatDate(soonest.date_to) }) }}
            </p>
            <p v-if="balance.cap_limited" class="mt-2 text-xs text-muted-foreground">{{ $t('leave.me.cap_limited') }}</p>
            <ul v-if="balance.caps.length" class="mt-2 space-y-0.5 text-xs text-muted-foreground">
                <li v-for="cap in balance.caps" :key="cap.cap_id">{{ $t('leave.me.cap_line', { name: cap.name, used: days(cap.used), cap: days(cap.cap_days) }) }}</li>
            </ul>
        </template>
        <p v-else class="text-sm text-muted-foreground">{{ $t('leave.me.no_balance') }} · {{ $t('leave.balance.taken') }}: {{ days(balance.taken) }}</p>
    </div>
</template>
