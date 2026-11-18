<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApprovalTimeline from '@/components/app/ApprovalTimeline.vue';
import type { TimelineData } from '@/components/app/ApprovalTimeline.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import AttendanceDecisionContext from '@/components/attendance/DecisionContext.vue';
import LeaveDecisionContext from '@/components/leave/LeaveDecisionContext.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    applicant: string | null;
    applicant_no: string | null;
    summary: string;
    step: { sequence: number; total: number; name: string } | null;
    submitted_at: string | null;
    waiting_days: number | null;
};

type Detail = Row & {
    workflow: { name: string; version: number };
    applicant_card: { name: string | null; employee_no: string | null; department: string | null; designation: string | null };
    decision_context: Record<string, unknown>;
    cancel_reason: string | null;
    timeline: TimelineData;
    can: { decide: boolean; override: boolean; withdraw: boolean };
};

const ATTENDANCE_TYPES = ['attendance_request', 'shift_request', 'overtime_claim'];

const props = defineProps<{
    tab: string;
    tabs: string[];
    requests: Paginated<Row>;
    selected: Detail | null;
    filters: { type: string; applicant: string; from: string; to: string };
    typeOptions: Option<string>[];
    members: Option<number>[];
}>();

const filters = ref({ ...props.filters });
const checked = ref<number[]>([]);
const note = ref('');
const drawerOpen = ref(props.selected !== null);
const dialog = ref<null | 'reject' | 'override_approve' | 'override_reject' | 'reassign' | 'withdraw'>(null);
const reassignUsers = ref<number[]>([]);

watch(
    () => props.selected,
    (value) => {
        drawerOpen.value = value !== null;
        note.value = '';
    },
);

watch(drawerOpen, (open) => {
    if (!open && props.selected) {
        router.get('/approvals', { ...currentQuery(), request: undefined }, { preserveState: true, preserveScroll: true, only: ['selected'] });
    }
});

function currentQuery() {
    return { tab: props.tab, ...filters.value };
}

function go(tab: string) {
    checked.value = [];
    router.get('/approvals', { ...filters.value, tab }, { preserveState: true, replace: true });
}

function applyFilters() {
    router.get('/approvals', currentQuery(), { preserveState: true, replace: true });
}

function open(row: Row) {
    router.get('/approvals', { ...currentQuery(), request: row.id }, { preserveState: true, preserveScroll: true, only: ['selected'] });
}

const reload = { preserveScroll: true, onSuccess: () => router.reload({ only: ['requests', 'selected', 'pendingApprovals'] }) };

function approve() {
    if (!props.selected) return;
    router.post(`/approvals/${props.selected.id}/approve`, { note: note.value || null }, reload);
}

function confirmDialog(reason: string) {
    if (!props.selected) return;
    const id = props.selected.id;
    switch (dialog.value) {
        case 'reject':
            router.post(`/approvals/${id}/reject`, { note: reason }, reload);
            break;
        case 'override_approve':
            router.post(`/approvals/${id}/override`, { decision: 'approve', reason }, reload);
            break;
        case 'override_reject':
            router.post(`/approvals/${id}/override`, { decision: 'reject', reason }, reload);
            break;
        case 'reassign':
            router.post(`/approvals/${id}/reassign`, { user_ids: reassignUsers.value, reason }, reload);
            break;
        case 'withdraw':
            router.post(`/approvals/${id}/cancel`, { reason }, reload);
            break;
    }
    dialog.value = null;
}

function bulkApprove() {
    router.post('/approvals/bulk-approve', { ids: checked.value }, { preserveScroll: true, onSuccess: () => (checked.value = []) });
}

const allChecked = computed({
    get: () => props.requests.data.length > 0 && checked.value.length === props.requests.data.length,
    set: (value: boolean) => {
        checked.value = value ? props.requests.data.map((r) => r.id) : [];
    },
});

const dialogTitle = computed(() => {
    switch (dialog.value) {
        case 'reject':
            return 'approval.inbox.reject';
        case 'override_approve':
            return 'approval.inbox.override_approve';
        case 'override_reject':
            return 'approval.inbox.override_reject';
        case 'reassign':
            return 'approval.inbox.reassign';
        default:
            return 'approval.inbox.withdraw';
    }
});

function contextValue(value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
}
</script>

<template>
    <Head :title="$t('common.nav.approvals')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.approvals', href: '/approvals' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.approvals')" :description="$t('approval.inbox.description')">
                <template #actions>
                    <Button v-if="tab === 'mine' && checked.length" @click="bulkApprove">
                        {{ $t('approval.inbox.bulk_approve', { count: String(checked.length) }) }}
                    </Button>
                </template>
            </PageHeader>

            <div class="mb-4 flex flex-wrap gap-1 border-b">
                <button
                    v-for="t in tabs"
                    :key="t"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === t ? 'border-primary font-medium' : 'border-transparent text-muted-foreground'"
                    @click="go(t)"
                >
                    {{ $t(`approval.inbox.tabs.${t}`) }}
                </button>
            </div>

            <form class="mb-4 grid gap-2 sm:grid-cols-5" @submit.prevent="applyFilters">
                <SelectInput v-model="filters.type" :options="typeOptions" :placeholder="$t('approval.inbox.all_types')" />
                <Input v-model="filters.applicant" :placeholder="$t('approval.inbox.applicant')" />
                <Input v-model="filters.from" type="date" />
                <Input v-model="filters.to" type="date" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-if="tab === 'mine'" class="w-8 px-3 py-2"><input v-model="allChecked" type="checkbox" :aria-label="$t('common.actions.select_all')" /></th>
                            <th class="px-3 py-2">{{ $t('approval.inbox.columns.type') }}</th>
                            <th class="px-3 py-2">{{ $t('approval.inbox.columns.applicant') }}</th>
                            <th class="px-3 py-2">{{ $t('approval.inbox.columns.summary') }}</th>
                            <th class="px-3 py-2">{{ $t('approval.inbox.columns.step') }}</th>
                            <th class="px-3 py-2">{{ $t('approval.inbox.columns.submitted_at') }}</th>
                            <th class="px-3 py-2">{{ $t('approval.inbox.columns.waiting') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in requests.data" :key="row.id" class="cursor-pointer border-t hover:bg-accent/40" @click="open(row)">
                            <td v-if="tab === 'mine'" class="px-3 py-2" @click.stop>
                                <input v-model="checked" type="checkbox" :value="row.id" />
                            </td>
                            <td class="px-3 py-2">{{ row.type_label }}</td>
                            <td class="px-3 py-2">{{ row.applicant }} <span class="text-muted-foreground">{{ row.applicant_no }}</span></td>
                            <td class="px-3 py-2">{{ row.summary || '—' }}</td>
                            <td class="px-3 py-2">
                                <span v-if="row.step">{{ row.step.sequence }}/{{ row.step.total }} · {{ row.step.name }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-3 py-2">{{ formatDateTime(row.submitted_at) }}</td>
                            <td class="px-3 py-2">{{ row.waiting_days !== null ? $t('approval.inbox.days', { days: String(row.waiting_days) }) : '—' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="row.status" :label="row.status_label" /></td>
                        </tr>
                        <tr v-if="!requests.data.length">
                            <td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('approval.inbox.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="requests.links" :from="requests.from" :to="requests.to" :total="requests.total" />
        </div>

        <Sheet v-model:open="drawerOpen">
            <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
                <template v-if="selected">
                    <SheetHeader>
                        <SheetTitle>{{ selected.type_label }} · #{{ selected.id }}</SheetTitle>
                        <SheetDescription>{{ selected.workflow.name }} (v{{ selected.workflow.version }})</SheetDescription>
                    </SheetHeader>
                    <div class="space-y-5 px-4 pb-6">
                        <div class="rounded-lg border p-3 text-sm">
                            <p class="font-medium">{{ selected.applicant_card.name }} <span class="text-muted-foreground">{{ selected.applicant_card.employee_no }}</span></p>
                            <p class="text-muted-foreground">{{ selected.applicant_card.designation }} · {{ selected.applicant_card.department }}</p>
                            <p v-if="selected.summary" class="mt-2">{{ selected.summary }}</p>
                            <StatusBadge class="mt-2" :status="selected.status" :label="selected.status_label" />
                            <p v-if="selected.cancel_reason" class="mt-1 text-xs text-muted-foreground">{{ selected.cancel_reason }}</p>
                        </div>

                        <div v-if="selected.type === 'leave' && Object.keys(selected.decision_context).length" class="rounded-lg border p-3">
                            <h3 class="mb-2 text-sm font-medium">{{ $t('leave.decision.title') }}</h3>
                            <LeaveDecisionContext :context="selected.decision_context" />
                        </div>

                        <div v-else-if="ATTENDANCE_TYPES.includes(selected.type) && Object.keys(selected.decision_context).length" class="rounded-lg border p-3">
                            <h3 class="mb-2 text-sm font-medium">{{ $t('attendance.decision.title') }}</h3>
                            <AttendanceDecisionContext :type="selected.type" :context="selected.decision_context" />
                        </div>

                        <div v-else-if="Object.keys(selected.decision_context).length" class="rounded-lg border p-3">
                            <h3 class="mb-2 text-sm font-medium">{{ $t('approval.inbox.decision_context') }}</h3>
                            <dl class="grid grid-cols-2 gap-2 text-sm">
                                <template v-for="(value, key) in selected.decision_context" :key="key">
                                    <dt class="text-muted-foreground">{{ $t(`approval.context.${String(key)}`) }}</dt>
                                    <dd>{{ contextValue(value) }}</dd>
                                </template>
                            </dl>
                        </div>

                        <div>
                            <h3 class="mb-2 text-sm font-medium">{{ $t('approval.inbox.timeline') }}</h3>
                            <ApprovalTimeline :timeline="selected.timeline" />
                        </div>

                        <div v-if="selected.can.decide" class="space-y-2 rounded-lg border p-3">
                            <FormField :label="$t('approval.inbox.note')">
                                <TextArea v-model="note" :rows="2" />
                            </FormField>
                            <div class="flex gap-2">
                                <Button @click="approve">{{ $t('approval.inbox.approve') }}</Button>
                                <Button variant="destructive" @click="dialog = 'reject'">{{ $t('approval.inbox.reject') }}</Button>
                            </div>
                        </div>

                        <div v-if="selected.can.override" class="space-y-2 rounded-lg border border-dashed p-3">
                            <p class="text-xs text-muted-foreground">{{ $t('approval.inbox.override_hint') }}</p>
                            <div class="flex flex-wrap gap-2">
                                <Button size="sm" variant="outline" @click="dialog = 'override_approve'">{{ $t('approval.inbox.override_approve') }}</Button>
                                <Button size="sm" variant="outline" @click="dialog = 'override_reject'">{{ $t('approval.inbox.override_reject') }}</Button>
                                <Button size="sm" variant="outline" @click="dialog = 'reassign'">{{ $t('approval.inbox.reassign') }}</Button>
                            </div>
                        </div>

                        <Button v-if="selected.can.withdraw" variant="outline" @click="dialog = 'withdraw'">{{ $t('approval.inbox.withdraw') }}</Button>
                    </div>
                </template>
            </SheetContent>
        </Sheet>

        <ReasonDialog
            :open="dialog !== null"
            :title="$t(dialogTitle)"
            :destructive="dialog === 'reject' || dialog === 'override_reject' || dialog === 'withdraw'"
            @update:open="(v: boolean) => { if (!v) dialog = null }"
            @confirm="confirmDialog"
        >
            <div v-if="dialog === 'reassign'" class="mt-2 max-h-48 space-y-1 overflow-y-auto rounded border p-2">
                <label v-for="m in members" :key="m.value" class="flex items-center gap-2 text-sm">
                    <input v-model="reassignUsers" type="checkbox" :value="m.value" /> {{ m.label }}
                </label>
            </div>
        </ReasonDialog>
    </AppLayout>
</template>
