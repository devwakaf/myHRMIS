<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Repeat, StopCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { EmployeeRef } from '@/lib/attendance';

type Cell = {
    date: string;
    shift: { id: number; code: string; colour: string | null; source: string; assignment_id: number | null } | null;
    day_type: string;
    is_half_day_off: boolean;
    holiday_label: string | null;
    leave: string | null;
};
type Row = { employee: EmployeeRef; cells: Cell[] };
type RosterAssignment = { id: number; employee_id: number; schedule: string; anchor_date: string; end_date: string | null; generated_through: string | null };

const props = defineProps<{
    from: string;
    to: string;
    rows: Row[];
    roster_assignments: RosterAssignment[];
    filters: Record<string, string | undefined>;
    options: {
        shift_types: { id: number; code: string; name: string; colour: string | null }[];
        schedules: { id: number; code: string; name: string; cycle_length_days: number }[];
        employees: { id: number; full_name: string; employee_no: string }[];
        branches: { id: number; name: string }[];
        departments: { id: number; name: string }[];
    };
    can: { manage: boolean };
}>();

const page = usePage();
const f = ref({ from: props.from, to: props.to, branch_id: props.filters.branch_id ?? '', department_id: props.filters.department_id ?? '', shift_schedule_id: props.filters.shift_schedule_id ?? '' });
const selected = ref<Set<string>>(new Set());
const shiftChoice = ref<number | null>(null);
const dialog = ref<null | 'pattern' | 'stop'>(null);
const pattern = ref({ employee_ids: [] as number[], shift_schedule_id: null as number | null, anchor_date: props.from, end_date: '' });
const stopTarget = ref<RosterAssignment | null>(null);
const stopDate = ref(new Date().toISOString().slice(0, 10));
const busy = ref(false);

const dates = computed(() => props.rows[0]?.cells.map((c) => c.date) ?? []);
const employeesById = computed(() => new Map(props.rows.map((r) => [r.employee?.id ?? 0, r.employee])));
const spanDays = computed(() => Math.round((Date.parse(props.to) - Date.parse(props.from)) / 86400000) + 1);

function key(empId: number, date: string): string {
    return `${empId}|${date}`;
}

function toggle(empId: number, date: string) {
    if (!props.can.manage) return;
    const next = new Set(selected.value);
    if (next.has(key(empId, date))) next.delete(key(empId, date));
    else next.add(key(empId, date));
    selected.value = next;
}

function apply(extra: Record<string, string | number> = {}) {
    const q = { ...f.value, ...extra };
    router.get('/attendance/roster', Object.fromEntries(Object.entries(q).filter(([, v]) => v !== '' && v !== null)), { preserveState: true, replace: true });
}

function shiftRange(direction: number) {
    const days = spanDays.value * direction;
    const move = (iso: string) => new Date(Date.parse(iso) + days * 86400000).toISOString().slice(0, 10);
    f.value.from = move(props.from);
    f.value.to = move(props.to);
    selected.value = new Set();
    apply();
}

function preset(length: number) {
    f.value.to = new Date(Date.parse(f.value.from) + (length - 1) * 86400000).toISOString().slice(0, 10);
    apply();
}

/** The employee's default shift is not a roster entry: on a rest day or holiday the cell shows R / CU instead. */
function shiftOf(c: Cell): Cell['shift'] {
    return c.shift && !(c.shift.source === 'default' && c.day_type !== 'normal') ? c.shift : null;
}

function cellStyle(c: Cell): Record<string, string> {
    const shift = shiftOf(c);
    if (shift) return { backgroundColor: `${shift.colour ?? '#94a3b8'}40` };
    if (c.day_type === 'public_holiday' || c.leave) return { backgroundImage: 'repeating-linear-gradient(45deg, transparent 0 4px, rgba(100,116,139,.28) 4px 8px)' };

    return {};
}

function cellText(c: Cell): string {
    const shift = shiftOf(c);
    if (shift) return shift.code;
    if (c.day_type === 'rest_day') return 'R';
    if (c.day_type === 'public_holiday') return 'CU';

    return '·';
}

function cellTitle(c: Cell): string {
    return [c.holiday_label, c.leave ? 'C' : null, c.is_half_day_off ? '½' : null].filter(Boolean).join(' · ');
}

/** Contiguous runs of selected dates per employee, grouped so employees with the same run share one request. */
function plan(): { employee_ids: number[]; from_date: string; to_date: string }[] {
    const perEmployee = new Map<number, string[]>();
    for (const k of selected.value) {
        const [id, date] = k.split('|');
        perEmployee.set(Number(id), [...(perEmployee.get(Number(id)) ?? []), date]);
    }
    const groups = new Map<string, number[]>();
    for (const [id, list] of perEmployee) {
        const sorted = [...list].sort();
        let start = sorted[0];
        let prev = sorted[0];
        const flush = () => groups.set(`${start}|${prev}`, [...(groups.get(`${start}|${prev}`) ?? []), id]);
        for (const d of sorted.slice(1)) {
            if (Date.parse(d) - Date.parse(prev) !== 86400000) {
                flush();
                start = d;
            }
            prev = d;
        }
        flush();
    }

    return [...groups].map(([range, ids]) => ({ employee_ids: ids, from_date: range.split('|')[0], to_date: range.split('|')[1] }));
}

function runQueue(requests: Record<string, unknown>[]) {
    const [first, ...rest] = requests;
    if (!first) {
        busy.value = false;
        selected.value = new Set();

        return;
    }
    busy.value = true;
    router.post('/attendance/roster', first as Record<string, string | number | number[]>, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (page.props.flash?.error ? ((busy.value = false)) : runQueue(rest)),
        onError: () => (busy.value = false),
    });
}

function edit(action: 'set_shift' | 'set_rest' | 'clear') {
    if (action === 'set_shift' && !shiftChoice.value) return;
    runQueue(plan().map((p) => ({ action, ...p, shift_type_id: action === 'set_shift' ? shiftChoice.value : undefined })));
}

const swapPair = computed(() => {
    const ids = new Set([...selected.value].map((k) => Number(k.split('|')[0])));

    return ids.size === 2 ? [...ids] : null;
});

function swap() {
    if (!swapPair.value) return;
    const sel = [...selected.value].map((k) => k.split('|')[1]).sort();
    runQueue([{ action: 'swap', employee_ids: [swapPair.value[0]], other_employee_id: swapPair.value[1], from_date: sel[0], to_date: sel[sel.length - 1] }]);
}

function assignPattern() {
    runQueue([{ action: 'assign_pattern', employee_ids: pattern.value.employee_ids, shift_schedule_id: pattern.value.shift_schedule_id, anchor_date: pattern.value.anchor_date, end_date: pattern.value.end_date || undefined }]);
    dialog.value = null;
}

function stop() {
    if (!stopTarget.value) return;
    runQueue([{ action: 'stop', shift_schedule_assignment_id: stopTarget.value.id, from_date: stopDate.value }]);
    dialog.value = null;
}

function employeeName(id: number): string {
    return employeesById.value.get(id)?.full_name ?? props.options.employees.find((e) => e.id === id)?.full_name ?? `#${id}`;
}
</script>

<template>
    <Head :title="$t('attendance.pages.roster')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.roster', href: '/attendance/roster' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.roster')" :description="$t('attendance.roster.description')">
                <template v-if="can.manage" #actions>
                    <Button data-testid="assign-pattern" @click="dialog = 'pattern'"><Repeat class="size-4" />{{ $t('attendance.roster.assign_pattern') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-3 flex flex-wrap items-end gap-2" @submit.prevent="apply()">
                <Button type="button" variant="outline" size="icon" :aria-label="$t('attendance.common.previous')" @click="shiftRange(-1)"><ChevronLeft class="size-4" /></Button>
                <label class="grid gap-1 text-xs">{{ $t('attendance.common.from_date') }}<Input v-model="f.from" type="date" class="w-40" /></label>
                <label class="grid gap-1 text-xs">{{ $t('attendance.common.to_date') }}<Input v-model="f.to" type="date" class="w-40" /></label>
                <Button type="button" variant="outline" size="icon" :aria-label="$t('attendance.common.next')" @click="shiftRange(1)"><ChevronRight class="size-4" /></Button>
                <Button type="button" size="sm" variant="ghost" @click="preset(7)">{{ $t('attendance.roster.week') }}</Button>
                <Button type="button" size="sm" variant="ghost" @click="preset(14)">{{ $t('attendance.roster.two_weeks') }}</Button>
                <Button type="button" size="sm" variant="ghost" @click="preset(31)">{{ $t('attendance.roster.month') }}</Button>
                <SelectInput v-model="f.branch_id" class="w-44" :options="options.branches.map((b) => ({ value: b.id, label: b.name }))" :placeholder="$t('attendance.common.all_branches')" />
                <SelectInput v-model="f.department_id" class="w-44" :options="options.departments.map((d) => ({ value: d.id, label: d.name }))" :placeholder="$t('attendance.common.all_departments')" />
                <SelectInput v-model="f.shift_schedule_id" class="w-44" :options="options.schedules.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))" :placeholder="$t('attendance.roster.all_patterns')" />
                <Button type="submit" variant="outline">{{ $t('common.actions.filter') }}</Button>
            </form>

            <div v-if="can.manage && selected.size" class="sticky top-0 z-10 mb-3 flex flex-wrap items-center gap-2 rounded-lg border bg-background p-3 shadow-sm" data-testid="roster-actions">
                <span class="text-sm font-medium">{{ $t('attendance.roster.selected', { count: String(selected.size) }) }}</span>
                <SelectInput v-model="shiftChoice" class="w-52" :options="options.shift_types.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))" :placeholder="$t('attendance.common.shift')" />
                <Button size="sm" :disabled="busy || !shiftChoice" @click="edit('set_shift')">{{ $t('attendance.roster.set_shift') }}</Button>
                <Button size="sm" variant="outline" :disabled="busy" @click="edit('set_rest')">{{ $t('attendance.roster.set_rest') }}</Button>
                <Button size="sm" variant="outline" :disabled="busy" @click="edit('clear')">{{ $t('attendance.roster.clear') }}</Button>
                <Button size="sm" variant="outline" :disabled="busy || !swapPair" :title="$t('attendance.roster.swap_hint')" @click="swap">{{ $t('attendance.roster.swap') }}</Button>
                <Button size="sm" variant="ghost" @click="selected = new Set()">{{ $t('attendance.roster.deselect') }}</Button>
            </div>

            <div class="mb-3 flex flex-wrap gap-3 text-xs text-muted-foreground">
                <span>{{ $t('attendance.roster.legend_rest') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block size-3 rounded border" style="background-image: repeating-linear-gradient(45deg, transparent 0 3px, rgba(100,116,139,.35) 3px 6px)" />{{ $t('attendance.roster.legend_leave') }}</span>
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="border-collapse text-xs" data-testid="roster-board">
                    <thead>
                        <tr class="bg-muted/50">
                            <th class="sticky left-0 z-[1] min-w-44 bg-muted px-3 py-2 text-left">{{ $t('attendance.common.employee') }}</th>
                            <th v-for="d in dates" :key="d" class="min-w-10 px-1 py-1 text-center font-medium" :class="[0, 6].includes(new Date(d).getUTCDay()) ? 'text-muted-foreground' : ''">
                                <div>{{ $t(`common.weekdays.${new Date(d).getUTCDay() || 7}`).slice(0, 3) }}</div>
                                <div>{{ Number(d.slice(8)) }}</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.employee?.id" class="border-t">
                            <th class="sticky left-0 z-[1] bg-background px-3 py-1 text-left font-normal">{{ row.employee?.full_name }} <span class="text-muted-foreground">{{ row.employee?.employee_no }}</span></th>
                            <td v-for="c in row.cells" :key="c.date" class="border-l p-0">
                                <button
                                    type="button"
                                    class="flex h-9 w-10 items-center justify-center font-medium"
                                    :class="[selected.has(key(row.employee?.id ?? 0, c.date)) ? 'ring-2 ring-inset ring-primary' : '', shiftOf(c) ? '' : 'text-muted-foreground', can.manage ? 'hover:bg-accent/60' : 'cursor-default']"
                                    :style="cellStyle(c)"
                                    :title="cellTitle(c)"
                                    :data-cell="`${row.employee?.id}|${c.date}`"
                                    @click="toggle(row.employee?.id ?? 0, c.date)"
                                >{{ cellText(c) }}<sup v-if="c.leave" class="ml-0.5 text-[9px]">C</sup></button>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td :colspan="dates.length + 1" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <section v-if="roster_assignments.length" class="mt-6 space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.roster.active_patterns') }}</h2>
                <ul class="divide-y rounded-lg border text-sm" data-testid="roster-assignments">
                    <li v-for="r in roster_assignments" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span>{{ employeeName(r.employee_id) }} · <span class="font-mono">{{ r.schedule }}</span> · {{ formatDate(r.anchor_date) }} – {{ r.end_date ? formatDate(r.end_date) : $t('attendance.assignment.open') }}</span>
                        <Button v-if="can.manage" size="sm" variant="outline" @click="((stopTarget = r), (dialog = 'stop'))"><StopCircle class="size-4" />{{ $t('attendance.roster.stop') }}</Button>
                    </li>
                </ul>
            </section>
        </div>

        <Dialog :open="dialog === 'pattern'" @update:open="(v: boolean) => { if (!v) dialog = null }">
            <DialogContent class="max-h-[92vh] overflow-y-auto">
                <DialogHeader><DialogTitle>{{ $t('attendance.roster.assign_pattern') }}</DialogTitle><DialogDescription>{{ $t('attendance.roster.pattern_description') }}</DialogDescription></DialogHeader>
                <form class="space-y-3" @submit.prevent="assignPattern">
                    <fieldset class="max-h-52 overflow-y-auto rounded-md border p-2">
                        <legend class="px-1 text-xs text-muted-foreground">{{ $t('attendance.roster.pattern_employees') }}</legend>
                        <label v-for="e in options.employees" :key="e.id" class="flex items-center gap-2 py-0.5 text-sm"><input v-model="pattern.employee_ids" type="checkbox" :value="e.id" />{{ e.full_name }} <span class="text-xs text-muted-foreground">{{ e.employee_no }}</span></label>
                    </fieldset>
                    <FormField :label="$t('attendance.roster.pattern')" required><SelectInput v-model="pattern.shift_schedule_id" :options="options.schedules.map((s) => ({ value: s.id, label: `${s.code} · ${s.name} (${s.cycle_length_days})` }))" /></FormField>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.roster.anchor')" :hint="$t('attendance.roster.anchor_hint')" required><Input v-model="pattern.anchor_date" type="date" /></FormField>
                        <FormField :label="$t('attendance.common.to_date')" :hint="$t('attendance.assignment.open_ended')"><Input v-model="pattern.end_date" type="date" :min="pattern.anchor_date" /></FormField>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialog = null">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="busy || !pattern.employee_ids.length || !pattern.shift_schedule_id || !pattern.anchor_date">{{ $t('attendance.roster.assign') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="dialog === 'stop'" @update:open="(v: boolean) => { if (!v) dialog = null }">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader><DialogTitle>{{ $t('attendance.roster.stop') }}</DialogTitle><DialogDescription>{{ $t('attendance.roster.stop_description') }}</DialogDescription></DialogHeader>
                <FormField :label="$t('attendance.roster.stop_from')" required><Input v-model="stopDate" type="date" /></FormField>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="dialog = null">{{ $t('common.actions.cancel') }}</Button>
                    <Button type="button" :disabled="!stopDate" @click="stop">{{ $t('common.actions.confirm') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
