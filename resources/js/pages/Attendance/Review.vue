<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import CheckinTable from '@/components/attendance/CheckinTable.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AttendanceRow, CheckinRow, EmployeeRef } from '@/lib/attendance';
import type { Option } from '@/types';

type Group = { reason: string | null; reason_label: string | null; employee: EmployeeRef; date: string; checkins: CheckinRow[] };

const props = defineProps<{
    groups: Group[];
    counts: Record<string, number>;
    flagged_records: AttendanceRow[];
    filters: { reason?: string };
    options: { reasons: Option<string>[] };
}>();

const selected = ref<number[]>([]);
const ignoreOpen = ref(false);
const busy = ref(false);

const total = computed(() => Object.values(props.counts).reduce((a, b) => a + Number(b), 0));
const idsOf = (g: Group) => g.checkins.map((c) => c.id);
const selectedIds = computed(() => selected.value.flatMap((i) => (props.groups[i] ? idsOf(props.groups[i]) : [])));
const allChecked = computed({
    get: () => props.groups.length > 0 && selected.value.length === props.groups.length,
    set: (value: boolean) => (selected.value = value ? props.groups.map((_, i) => i) : []),
});

function filter(reason: string | null) {
    selected.value = [];
    router.get('/attendance/review', reason ? { reason } : {}, { preserveState: true, replace: true });
}

function act(action: 'ignore' | 'reprocess' | 'replace_existing', ids: number[], note?: string) {
    busy.value = true;
    router.post('/attendance/review', { action, checkin_ids: ids, note: note || null }, {
        preserveScroll: true,
        onSuccess: () => (selected.value = []),
        onFinish: () => (busy.value = false),
    });
}

const pending = ref<number[]>([]);

function askIgnore(ids: number[]) {
    pending.value = ids;
    ignoreOpen.value = true;
}

function confirmIgnore(note: string) {
    ignoreOpen.value = false;
    act('ignore', pending.value, note);
}
</script>

<template>
    <Head :title="$t('attendance.pages.review')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.review', href: '/attendance/review' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.review')" :description="$t('attendance.review.description')">
                <template v-if="selected.length" #actions>
                    <Button variant="outline" :disabled="busy" @click="act('reprocess', selectedIds)">{{ $t('attendance.review.reprocess') }} ({{ selected.length }})</Button>
                    <Button variant="outline" :disabled="busy" @click="askIgnore(selectedIds)">{{ $t('attendance.review.ignore') }} ({{ selected.length }})</Button>
                </template>
            </PageHeader>

            <div class="mb-4 flex flex-wrap gap-1 border-b" data-testid="review-tabs">
                <button type="button" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="!filters.reason ? 'border-primary font-medium' : 'border-transparent text-muted-foreground'" @click="filter(null)">{{ $t('attendance.review.all') }} ({{ total }})</button>
                <button v-for="r in options.reasons" :key="r.value" type="button" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="filters.reason === r.value ? 'border-primary font-medium' : 'border-transparent text-muted-foreground'" @click="filter(r.value)">{{ r.label }} ({{ counts[r.value] ?? 0 }})</button>
            </div>

            <label v-if="groups.length" class="mb-2 flex items-center gap-2 text-sm"><input v-model="allChecked" type="checkbox" />{{ $t('common.actions.select_all') }}</label>
            <div class="space-y-3" data-testid="review-groups">
                <article v-for="(g, i) in groups" :key="`${g.reason}-${g.employee?.id}-${g.date}`" class="space-y-2 rounded-lg border p-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <input v-model="selected" type="checkbox" :value="i" :aria-label="g.employee?.full_name" />
                        <p class="text-sm font-medium">{{ g.employee?.full_name }} <span class="text-xs font-normal text-muted-foreground">{{ g.employee?.employee_no }}</span></p>
                        <span class="text-sm">{{ formatDate(g.date) }}</span>
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ g.reason_label }}</span>
                        <div class="ml-auto flex flex-wrap gap-2">
                            <Button size="sm" variant="outline" :disabled="busy" @click="act('reprocess', idsOf(g))">{{ $t('attendance.review.reprocess') }}</Button>
                            <Button v-if="g.reason === 'existing_attendance'" size="sm" variant="outline" :disabled="busy" @click="act('replace_existing', idsOf(g))">{{ $t('attendance.review.replace') }}</Button>
                            <Button size="sm" variant="ghost" :disabled="busy" @click="askIgnore(idsOf(g))">{{ $t('attendance.review.ignore') }}</Button>
                        </div>
                    </div>
                    <CheckinTable :checkins="g.checkins" />
                </article>
                <p v-if="!groups.length" class="rounded-lg border p-6 text-center text-sm text-muted-foreground">{{ $t('attendance.review.empty') }}</p>
            </div>

            <section v-if="flagged_records.length" class="mt-8 space-y-2">
                <h2 class="text-sm font-medium">{{ $t('attendance.review.flagged') }}</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm" data-testid="flagged-table">
                        <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('attendance.common.date') }}</th><th class="px-3 py-2">{{ $t('attendance.common.employee') }}</th><th class="px-3 py-2">{{ $t('common.fields.status') }}</th><th class="px-3 py-2">{{ $t('attendance.review.flag') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="a in flagged_records" :key="a.id" class="border-t">
                                <td class="px-3 py-1.5"><Link :href="`/attendance/records/${a.id}`" class="text-primary hover:underline">{{ formatDate(a.attendance_date) }}</Link></td>
                                <td class="px-3 py-1.5">{{ a.employee?.full_name }}</td>
                                <td class="px-3 py-1.5">{{ a.attendance_status_label }}</td>
                                <td class="px-3 py-1.5 text-xs">{{ $t(`attendance.enums.review_reason.${a.review_flag}`) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <ReasonDialog :open="ignoreOpen" :title="$t('attendance.review.ignore')" :required="false" @update:open="(v: boolean) => (ignoreOpen = v)" @confirm="confirmIgnore" />
    </AppLayout>
</template>
