<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

type Entry = { application_id: number | null; employee: string; from: string; to: string; status: string; type_code: string | null; colour: string | null; own: boolean };
type Holiday = { date: string; name: string; kind: string; is_half_day: boolean };
type Block = { date: string; list: string; reason: string | null };

const props = defineProps<{ month: string; entries: Entry[]; holidays: Holiday[]; block_dates: Block[] }>();

const [year, monthNo] = props.month.split('-').map(Number);

function iso(y: number, m: number, d: number): string {
    return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

const cells = computed(() => {
    const first = new Date(Date.UTC(year, monthNo - 1, 1));
    const lead = (first.getUTCDay() + 6) % 7; // Monday first
    const total = new Date(Date.UTC(year, monthNo, 0)).getUTCDate();
    const out: ({ date: string; day: number } | null)[] = Array.from({ length: lead }, () => null);
    for (let d = 1; d <= total; d++) out.push({ date: iso(year, monthNo, d), day: d });
    while (out.length % 7 !== 0) out.push(null);

    return out;
});

const holidayByDate = computed(() => new Map(props.holidays.map((h) => [h.date, h])));
const blockByDate = computed(() => new Map(props.block_dates.map((b) => [b.date, b])));

function entriesOn(date: string): Entry[] {
    return props.entries.filter((e) => e.from <= date && e.to >= date);
}

function shift(delta: number) {
    const d = new Date(Date.UTC(year, monthNo - 1 + delta, 1));
    router.get('/leave/calendar', { month: iso(d.getUTCFullYear(), d.getUTCMonth() + 1, 1) }, { preserveState: true, preserveScroll: true });
}

function chipStyle(e: Entry): Record<string, string> {
    const colour = e.colour ?? '#64748b';
    if (e.status === 'pending') {
        return { color: colour, border: `1px dashed ${colour}`, backgroundImage: `repeating-linear-gradient(45deg, transparent 0 4px, ${colour}33 4px 8px)` };
    }

    return { backgroundColor: colour, color: '#fff' };
}
</script>

<template>
    <Head :title="$t('leave.pages.calendar')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.calendar', href: '/leave/calendar' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.calendar')" :description="$t('leave.calendar.description')">
                <template #actions>
                    <Button variant="outline" size="icon" :aria-label="$t('leave.calendar.previous')" @click="shift(-1)"><ChevronLeft class="size-4" /></Button>
                    <span class="min-w-36 text-center text-sm font-medium" data-testid="month-label">{{ $t(`common.months.${monthNo}`) }} {{ year }}</span>
                    <Button variant="outline" size="icon" :aria-label="$t('leave.calendar.next')" @click="shift(1)"><ChevronRight class="size-4" /></Button>
                    <Button variant="outline" as-child><Link href="/leave/calendar">{{ $t('leave.calendar.today') }}</Link></Button>
                </template>
            </PageHeader>

            <div class="mb-3 flex flex-wrap gap-4 text-xs text-muted-foreground">
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-6 rounded bg-slate-500" />{{ $t('leave.calendar.approved') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-6 rounded border border-dashed border-slate-500" style="background-image: repeating-linear-gradient(45deg, transparent 0 3px, #64748b44 3px 6px)" />{{ $t('leave.calendar.pending') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block size-3 rounded bg-sky-200 dark:bg-sky-900" />{{ $t('leave.calendar.holiday') }}</span>
                <span class="flex items-center gap-1"><span class="inline-block size-3 rounded bg-red-200 dark:bg-red-900" />{{ $t('leave.calendar.blocked') }}</span>
            </div>

            <div class="overflow-x-auto">
                <div class="grid min-w-[44rem] grid-cols-7 border-l border-t text-sm">
                    <div v-for="n in 7" :key="n" class="border-b border-r bg-muted/50 px-2 py-1 text-xs font-medium">{{ $t(`common.weekdays.${n}`) }}</div>
                    <div
                        v-for="(cell, i) in cells"
                        :key="i"
                        class="min-h-24 border-b border-r p-1"
                        :class="[
                            cell && holidayByDate.get(cell.date)?.kind === 'weekly_off' ? 'bg-muted/40' : '',
                            cell && holidayByDate.get(cell.date) && holidayByDate.get(cell.date)?.kind !== 'weekly_off' ? 'bg-sky-50 dark:bg-sky-950/40' : '',
                            cell && blockByDate.get(cell.date) ? 'ring-1 ring-inset ring-red-400' : '',
                        ]"
                    >
                        <template v-if="cell">
                            <div class="flex items-start justify-between">
                                <span class="text-xs font-medium">{{ cell.day }}</span>
                                <span v-if="blockByDate.get(cell.date)" class="rounded bg-red-100 px-1 text-[10px] text-red-800 dark:bg-red-900 dark:text-red-100" :title="blockByDate.get(cell.date)?.reason ?? blockByDate.get(cell.date)?.list">{{ $t('leave.calendar.blocked') }}</span>
                            </div>
                            <p v-if="holidayByDate.get(cell.date) && holidayByDate.get(cell.date)?.kind !== 'weekly_off'" class="truncate text-[10px] text-sky-800 dark:text-sky-200">{{ holidayByDate.get(cell.date)?.name }}</p>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="(e, j) in entriesOn(cell.date)" :key="j">
                                    <component
                                        :is="e.application_id ? Link : 'span'"
                                        :href="e.application_id ? `/leave/applications/${e.application_id}` : undefined"
                                        class="block truncate rounded px-1 text-[11px]"
                                        :class="e.own ? 'font-semibold' : ''"
                                        :style="chipStyle(e)"
                                        :title="`${e.employee}${e.type_code ? ' · ' + e.type_code : ''}`"
                                        :data-status="e.status"
                                    >{{ e.employee }}<template v-if="e.type_code"> · {{ e.type_code }}</template></component>
                                </li>
                            </ul>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
