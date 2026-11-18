<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, List } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import CheckinTable from '@/components/attendance/CheckinTable.vue';
import StatusChip from '@/components/attendance/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { hhmm, hoursMinutes } from '@/lib/attendance';
import type { AttendanceRequestRow, AttendanceRow, CheckinRow, EmployeeRef, ShiftRef } from '@/lib/attendance';
import { days as dec } from '@/lib/leave';

type Day = { date: string; day_type: string; is_half_day_off: boolean; holiday_label: string | null; shift: ShiftRef };
type Summary = {
    absentDays: string;
    unmarkedDays: number;
    needsReviewDates: string[];
    presentDays: string;
    wfhDays: string;
    onDutyDays: string;
    onLeaveDays: string;
    lateCount: number;
    lateMinutes: number;
    earlyExitCount: number;
    earlyExitMinutes: number;
};

const props = defineProps<{
    employee: EmployeeRef;
    month: string;
    days: Day[];
    attendances: AttendanceRow[];
    checkins: CheckinRow[];
    summary: Summary;
    requests: AttendanceRequestRow[];
    can: { request: boolean; shift_request: boolean };
}>();

const [year, monthNo] = props.month.split('-').map(Number);
const view = ref<'calendar' | 'list'>('calendar');
const selected = ref<string | null>(null);
const drawerOpen = ref(false);
const showCoordinates = ref(false);

const byDate = computed(() => new Map(props.attendances.map((a) => [a.attendance_date, a])));
const dayByDate = computed(() => new Map(props.days.map((d) => [d.date, d])));
const lead = computed(() => {
    const first = new Date(Date.UTC(year, monthNo - 1, 1));

    return (first.getUTCDay() + 6) % 7; // Monday first
});
const todayIso = new Date().toISOString().slice(0, 10);

const selectedDay = computed(() => (selected.value ? dayByDate.value.get(selected.value) ?? null : null));
const selectedRecord = computed(() => (selected.value ? byDate.value.get(selected.value) ?? null : null));
const selectedCheckins = computed(() => props.checkins.filter((c) => selected.value !== null && (c.shift_date ?? c.punched_at.slice(0, 10)) === selected.value));
const selectedRequests = computed(() => props.requests.filter((r) => selected.value !== null && r.from_date <= selected.value && r.to_date >= selected.value));
const reviewDates = computed(() => new Set(props.summary.needsReviewDates));

function shiftMonth(delta: number) {
    const d = new Date(Date.UTC(year, monthNo - 1 + delta, 1));
    router.get('/me/attendance', { month: `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-01` }, { preserveState: true, preserveScroll: true });
}

/** A shift is only worth showing on working days or days with a record (the default shift also resolves on rest days). */
function shownShift(d: Day): ShiftRef {
    return d.day_type === 'normal' || byDate.value.has(d.date) ? d.shift : null;
}

function open(date: string) {
    selected.value = date;
    showCoordinates.value = false;
    drawerOpen.value = true;
}

function cellTone(date: string): string {
    const a = byDate.value.get(date);
    if (a) {
        return {
            present: 'bg-emerald-50 dark:bg-emerald-950/40',
            absent: 'bg-red-50 dark:bg-red-950/40',
            half_day: 'bg-amber-50 dark:bg-amber-950/40',
            on_leave: 'bg-sky-50 dark:bg-sky-950/40',
            wfh: 'bg-violet-50 dark:bg-violet-950/40',
            on_duty: 'bg-indigo-50 dark:bg-indigo-950/40',
        }[a.attendance_status] ?? '';
    }
    const d = dayByDate.value.get(date);

    return d && d.day_type !== 'normal' ? 'bg-muted/40' : '';
}

const requestLink = computed(() => (selectedDay.value ? `/attendance-requests?from=${selectedDay.value.date}` : '/attendance-requests'));
const cards = computed(() => [
    { key: 'present', value: dec(props.summary.presentDays) },
    { key: 'wfh', value: dec(props.summary.wfhDays) },
    { key: 'on_duty', value: dec(props.summary.onDutyDays) },
    { key: 'on_leave', value: dec(props.summary.onLeaveDays) },
    { key: 'absent', value: dec(props.summary.absentDays) },
    { key: 'unmarked', value: String(props.summary.unmarkedDays) },
]);
</script>

<template>
    <Head :title="$t('attendance.pages.me')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.me', href: '/me/attendance' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.me')" :description="employee ? `${employee.full_name} · ${employee.employee_no}` : undefined">
                <template #actions>
                    <Button v-if="can.request" variant="outline" as-child><Link :href="requestLink">{{ $t('attendance.me.request_wfh') }}</Link></Button>
                    <Button v-if="can.shift_request" variant="outline" as-child><Link href="/shift-requests">{{ $t('attendance.me.request_shift') }}</Link></Button>
                    <Button as-child><Link href="/check-in">{{ $t('attendance.pages.checkin') }}</Link></Button>
                </template>
            </PageHeader>

            <div class="mb-4 flex flex-wrap items-center gap-2">
                <Button variant="outline" size="icon" :aria-label="$t('attendance.common.previous')" @click="shiftMonth(-1)"><ChevronLeft class="size-4" /></Button>
                <span class="min-w-36 text-center text-sm font-medium" data-testid="month-label">{{ $t(`common.months.${monthNo}`) }} {{ year }}</span>
                <Button variant="outline" size="icon" :aria-label="$t('attendance.common.next')" @click="shiftMonth(1)"><ChevronRight class="size-4" /></Button>
                <div class="ml-auto flex gap-1">
                    <Button size="sm" :variant="view === 'calendar' ? 'default' : 'outline'" @click="view = 'calendar'"><CalendarDays class="size-4" />{{ $t('attendance.me.calendar') }}</Button>
                    <Button size="sm" :variant="view === 'list' ? 'default' : 'outline'" @click="view = 'list'"><List class="size-4" />{{ $t('attendance.me.list') }}</Button>
                </div>
            </div>

            <div class="mb-4 grid grid-cols-3 gap-2 sm:grid-cols-6" data-testid="month-totals">
                <div v-for="c in cards" :key="c.key" class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">{{ $t(`attendance.me.totals.${c.key}`) }}</p>
                    <p class="text-xl font-semibold tabular-nums">{{ c.value }}</p>
                </div>
            </div>
            <p class="mb-4 text-xs text-muted-foreground">
                {{ $t('attendance.me.late_line', { count: String(summary.lateCount), minutes: String(summary.lateMinutes) }) }} ·
                {{ $t('attendance.me.early_line', { count: String(summary.earlyExitCount), minutes: String(summary.earlyExitMinutes) }) }}
                <template v-if="summary.needsReviewDates.length"> · <span class="text-amber-700 dark:text-amber-300">{{ $t('attendance.me.review_line', { count: String(summary.needsReviewDates.length) }) }}</span></template>
            </p>

            <div v-if="view === 'calendar'" class="overflow-x-auto">
                <div class="grid min-w-[40rem] grid-cols-7 border-l border-t text-sm" data-testid="attendance-calendar">
                    <div v-for="n in 7" :key="n" class="border-b border-r bg-muted/50 px-2 py-1 text-xs font-medium">{{ $t(`common.weekdays.${n}`) }}</div>
                    <div v-for="n in lead" :key="`l${n}`" class="min-h-24 border-b border-r" />
                    <button
                        v-for="d in days"
                        :key="d.date"
                        type="button"
                        class="min-h-24 border-b border-r p-1 text-left align-top hover:bg-accent/40"
                        :class="[cellTone(d.date), d.date === todayIso ? 'ring-2 ring-inset ring-primary' : '']"
                        :data-date="d.date"
                        @click="open(d.date)"
                    >
                        <div class="flex items-start justify-between">
                            <span class="text-xs font-medium">{{ Number(d.date.slice(8)) }}</span>
                            <span v-if="shownShift(d)" class="rounded px-1 text-[10px]" :style="{ backgroundColor: (shownShift(d)!.colour ?? '#94a3b8') + '33' }">{{ shownShift(d)!.code }}</span>
                        </div>
                        <p v-if="d.holiday_label" class="truncate text-[10px] text-sky-800 dark:text-sky-200">{{ d.holiday_label }}</p>
                        <p v-else-if="d.day_type === 'rest_day'" class="text-[10px] text-muted-foreground">{{ $t('attendance.enums.day_type.rest_day') }}</p>
                        <template v-if="byDate.get(d.date)">
                            <p class="mt-1 text-[11px] font-medium">{{ byDate.get(d.date)?.attendance_status_label }}</p>
                            <p class="text-[10px] tabular-nums text-muted-foreground">{{ hhmm(byDate.get(d.date)?.in_at) }} – {{ hhmm(byDate.get(d.date)?.out_at) }}</p>
                            <p class="mt-0.5 flex gap-1 text-[10px]">
                                <span v-if="byDate.get(d.date)?.is_late" class="rounded bg-amber-100 px-1 text-amber-800 dark:bg-amber-900 dark:text-amber-100" :title="$t('attendance.me.late')">L</span>
                                <span v-if="byDate.get(d.date)?.is_early_exit" class="rounded bg-orange-100 px-1 text-orange-800 dark:bg-orange-900 dark:text-orange-100" :title="$t('attendance.me.early')">A</span>
                                <span v-if="byDate.get(d.date)?.review_flag" class="rounded bg-red-100 px-1 text-red-800 dark:bg-red-900 dark:text-red-100" :title="$t('attendance.me.flagged')">?</span>
                            </p>
                        </template>
                        <p v-else-if="reviewDates.has(d.date)" class="mt-1 text-[11px] text-amber-700 dark:text-amber-300">{{ $t('attendance.me.needs_review') }}</p>
                    </button>
                </div>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="attendance-list">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('attendance.common.date') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.common.shift') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.me.in') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.me.out') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('attendance.me.worked') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.me.flags') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in days" :key="d.date" class="cursor-pointer border-t hover:bg-accent/40" @click="open(d.date)">
                            <td class="px-3 py-1.5">{{ formatDate(d.date) }}</td>
                            <td class="px-3 py-1.5">{{ shownShift(d)?.code ?? '—' }}</td>
                            <td class="px-3 py-1.5">
                                <StatusChip v-if="byDate.get(d.date)" :status="byDate.get(d.date)!.attendance_status" :label="byDate.get(d.date)!.attendance_status_label" />
                                <span v-else class="text-muted-foreground">{{ d.holiday_label ?? (d.day_type !== 'normal' ? $t(`attendance.enums.day_type.${d.day_type}`) : '—') }}</span>
                            </td>
                            <td class="px-3 py-1.5 tabular-nums">{{ hhmm(byDate.get(d.date)?.in_at) }}</td>
                            <td class="px-3 py-1.5 tabular-nums">{{ hhmm(byDate.get(d.date)?.out_at) }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ hoursMinutes(byDate.get(d.date)?.worked_minutes) }}</td>
                            <td class="px-3 py-1.5 text-xs">
                                <span v-if="byDate.get(d.date)?.is_late">{{ $t('attendance.me.late') }} {{ byDate.get(d.date)?.late_minutes }}m </span>
                                <span v-if="byDate.get(d.date)?.is_early_exit">{{ $t('attendance.me.early') }} {{ byDate.get(d.date)?.early_exit_minutes }}m </span>
                                <span v-if="byDate.get(d.date)?.review_flag" class="text-amber-700">{{ $t('attendance.me.flagged') }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Sheet v-model:open="drawerOpen">
            <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
                <template v-if="selectedDay">
                    <SheetHeader>
                        <SheetTitle>{{ formatDate(selectedDay.date) }}</SheetTitle>
                        <SheetDescription>
                            {{ $t(`attendance.enums.day_type.${selectedDay.day_type}`) }}<template v-if="selectedDay.holiday_label"> · {{ selectedDay.holiday_label }}</template>
                        </SheetDescription>
                    </SheetHeader>
                    <div class="space-y-4 px-4 pb-6 text-sm" data-testid="day-drawer">
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
                            <dt class="text-muted-foreground">{{ $t('attendance.common.shift') }}</dt>
                            <dd>{{ selectedDay.shift ? `${selectedDay.shift.code} · ${selectedDay.shift.time}` : '—' }}</dd>
                            <template v-if="selectedRecord">
                                <dt class="text-muted-foreground">{{ $t('common.fields.status') }}</dt>
                                <dd><StatusChip :status="selectedRecord.attendance_status" :label="selectedRecord.attendance_status_label" /></dd>
                                <dt class="text-muted-foreground">{{ $t('attendance.me.scheduled') }}</dt>
                                <dd class="tabular-nums">{{ hhmm(selectedRecord.scheduled_start_at) }} – {{ hhmm(selectedRecord.scheduled_end_at) }}</dd>
                                <dt class="text-muted-foreground">{{ $t('attendance.me.actual') }}</dt>
                                <dd class="tabular-nums">{{ hhmm(selectedRecord.in_at) }} – {{ hhmm(selectedRecord.out_at) }}</dd>
                                <dt class="text-muted-foreground">{{ $t('attendance.me.worked') }}</dt>
                                <dd class="tabular-nums">{{ hoursMinutes(selectedRecord.worked_minutes) }}</dd>
                                <dt class="text-muted-foreground">{{ $t('attendance.common.source') }}</dt>
                                <dd>{{ selectedRecord.source_label }}</dd>
                                <template v-if="selectedRecord.is_late || selectedRecord.is_early_exit">
                                    <dt class="text-muted-foreground">{{ $t('attendance.me.flags') }}</dt>
                                    <dd>
                                        <span v-if="selectedRecord.is_late">{{ $t('attendance.me.late') }} {{ selectedRecord.late_minutes }} {{ $t('attendance.common.min') }} </span>
                                        <span v-if="selectedRecord.is_early_exit">{{ $t('attendance.me.early') }} {{ selectedRecord.early_exit_minutes }} {{ $t('attendance.common.min') }}</span>
                                    </dd>
                                </template>
                                <template v-if="selectedRecord.ot_detected_minutes > 0">
                                    <dt class="text-muted-foreground">{{ $t('attendance.me.ot_detected') }}</dt>
                                    <dd class="tabular-nums">{{ hoursMinutes(selectedRecord.ot_detected_minutes) }}</dd>
                                </template>
                            </template>
                        </dl>
                        <p v-if="selectedRecord?.review_flag" class="rounded-md bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ $t('attendance.me.flagged_hint') }}</p>
                        <p v-if="!selectedRecord" class="text-muted-foreground">{{ $t('attendance.me.no_record') }}</p>
                        <p v-if="selectedRecord?.leave_application_id">
                            <Link :href="`/leave/applications/${selectedRecord.leave_application_id}`" class="text-primary hover:underline">{{ $t('attendance.me.linked_leave') }}</Link>
                        </p>

                        <div v-if="selectedRequests.length">
                            <h3 class="mb-1 text-xs font-medium text-muted-foreground">{{ $t('attendance.pages.requests') }}</h3>
                            <ul class="space-y-1">
                                <li v-for="r in selectedRequests" :key="r.id">
                                    <Link :href="`/attendance-requests/${r.id}`" class="text-primary hover:underline">{{ r.number ?? `#${r.id}` }}</Link>
                                    · {{ r.kind_label }} <StatusBadge :status="r.status" :label="r.status_label" />
                                </li>
                            </ul>
                        </div>

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <h3 class="text-xs font-medium text-muted-foreground">{{ $t('attendance.me.punches') }}</h3>
                                <Button v-if="selectedCheckins.length" size="sm" variant="ghost" @click="showCoordinates = !showCoordinates">{{ showCoordinates ? $t('attendance.me.hide_coordinates') : $t('attendance.me.show_coordinates') }}</Button>
                            </div>
                            <CheckinTable :checkins="selectedCheckins" :show-coordinates="showCoordinates" />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button v-if="can.request" size="sm" variant="outline" as-child><Link :href="`/attendance-requests?from=${selectedDay.date}`">{{ $t('attendance.me.request_wfh') }}</Link></Button>
                            <Button v-if="can.shift_request" size="sm" variant="outline" as-child><Link :href="`/shift-requests?from=${selectedDay.date}`">{{ $t('attendance.me.request_shift') }}</Link></Button>
                        </div>
                    </div>
                </template>
            </SheetContent>
        </Sheet>
    </AppLayout>
</template>
