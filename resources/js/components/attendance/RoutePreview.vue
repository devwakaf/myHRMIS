<script setup lang="ts">
import { computed } from 'vue';
import FormField from '@/components/app/FormField.vue';
import SelectInput from '@/components/app/SelectInput.vue';

export type RouteStep = {
    workflow_step_id: number;
    sequence: number;
    name: string;
    outcome: string;
    approvers: { id: number; name: string }[];
    candidates: { id: number; name: string }[];
    default_choice: number | null;
};

export type RouteData = { name: string; blocked: boolean; steps: RouteStep[] };

const props = defineProps<{ route: RouteData | null }>();
const choices = defineModel<Record<string, number | null>>('choices', { default: () => ({}) });

const pickerSteps = computed(() => (props.route?.steps ?? []).filter((s) => s.candidates.length > 0));
</script>

<template>
    <div v-if="route" class="space-y-3 rounded-lg border p-4" data-testid="route-preview">
        <h2 class="text-sm font-medium">{{ $t('attendance.common.approval_route') }}<span v-if="route.name"> · {{ route.name }}</span></h2>
        <ol class="space-y-2 text-sm">
            <li v-for="step in route.steps" :key="step.workflow_step_id">
                <span class="font-medium">{{ step.sequence }}. {{ step.name }}</span>
                <span v-if="step.outcome === 'skipped'" class="ml-2 text-xs text-muted-foreground">{{ $t('attendance.common.route_skipped') }}</span>
                <span v-else-if="step.approvers.length" class="ml-2 text-muted-foreground">{{ step.approvers.map((a) => a.name).join(', ') }}</span>
                <span v-else class="ml-2 text-xs text-amber-700 dark:text-amber-300">{{ $t('attendance.common.route_no_approver') }}</span>
            </li>
        </ol>
        <p v-if="route.blocked" class="rounded-md bg-red-50 p-2 text-xs text-red-900 dark:bg-red-950 dark:text-red-100">{{ $t('attendance.common.route_blocked') }}</p>
        <div v-if="pickerSteps.length" class="space-y-2">
            <FormField v-for="step in pickerSteps" :key="step.workflow_step_id" :label="step.name">
                <SelectInput v-model="choices[String(step.workflow_step_id)]" :options="step.candidates.map((c) => ({ value: c.id, label: c.name }))" />
            </FormField>
        </div>
    </div>
</template>
