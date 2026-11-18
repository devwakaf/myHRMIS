<script setup lang="ts">
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDateTime } from '@/composables/useFormat';

export type TimelineData = {
    steps: {
        id: number;
        sequence: number;
        name: string;
        mode_label: string;
        status: string;
        status_label: string;
        skip_reason_label: string | null;
        activated_at: string | null;
        completed_at: string | null;
        assignees: {
            user_id: number;
            name: string | null;
            decision: string;
            decision_label: string;
            decided_at: string | null;
            note: string | null;
        }[];
    }[];
    actions: {
        id: number;
        action: string;
        action_label: string;
        actor: string | null;
        note: string | null;
        created_at: string | null;
    }[];
};

defineProps<{ timeline: TimelineData }>();
</script>

<template>
    <div class="space-y-4">
        <ol class="relative space-y-4 border-l pl-5">
            <li v-for="step in timeline.steps" :key="step.id" class="relative">
                <span
                    class="absolute top-1 -left-[27px] size-3 rounded-full border-2 bg-background"
                    :class="{
                        'border-emerald-500 bg-emerald-500': step.status === 'approved',
                        'border-red-500 bg-red-500': step.status === 'rejected',
                        'border-amber-500': step.status === 'active',
                        'border-slate-400': ['waiting', 'skipped', 'cancelled'].includes(step.status),
                    }"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium">{{ step.sequence }}. {{ step.name }}</span>
                    <StatusBadge :status="step.status" :label="step.status_label" />
                    <span class="text-xs text-muted-foreground">{{ step.mode_label }}</span>
                </div>
                <p v-if="step.skip_reason_label" class="text-xs text-muted-foreground">{{ step.skip_reason_label }}</p>
                <ul v-if="step.assignees.length" class="mt-1 space-y-1">
                    <li v-for="a in step.assignees" :key="a.user_id" class="text-sm">
                        <span>{{ a.name }}</span>
                        <StatusBadge class="ml-2" :status="a.decision" :label="a.decision_label" />
                        <span v-if="a.decided_at" class="ml-2 text-xs text-muted-foreground">{{ formatDateTime(a.decided_at) }}</span>
                        <p v-if="a.note" class="text-xs text-muted-foreground italic">“{{ a.note }}”</p>
                    </li>
                </ul>
                <p v-else-if="step.status === 'active'" class="text-xs text-amber-700">{{ $t('approval.inbox.no_assignees') }}</p>
            </li>
        </ol>
        <details class="text-sm">
            <summary class="cursor-pointer text-muted-foreground">{{ $t('approval.inbox.history') }}</summary>
            <ul class="mt-2 space-y-1">
                <li v-for="a in timeline.actions" :key="a.id" class="text-xs">
                    <span class="text-muted-foreground">{{ formatDateTime(a.created_at) }}</span>
                    · {{ a.action_label }} · {{ a.actor ?? $t('approval.inbox.system') }}
                    <span v-if="a.note" class="italic">— {{ a.note }}</span>
                </li>
            </ul>
        </details>
    </div>
</template>
