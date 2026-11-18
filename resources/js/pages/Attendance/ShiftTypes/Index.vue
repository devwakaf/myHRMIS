<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { hoursMinutes, jsonRequest } from '@/lib/attendance';
import type { Option } from '@/types';

type ShiftType = {
    id: number;
    code: string;
    name: string;
    start_time: string;
    end_time: string;
    break_minutes: number;
    break_start_time: string | null;
    open_before_minutes: number;
    close_after_minutes: number;
    late_grace_minutes: number;
    early_grace_minutes: number;
    track_late: boolean;
    track_early: boolean;
    half_day_threshold_minutes: number;
    absent_threshold_minutes: number;
    hours_method: string;
    single_punch_policy: string;
    auto_attendance: boolean;
    process_from: string | null;
    absent_buffer_days: number;
    holiday_list_id: number | null;
    overtime_enabled: boolean;
    overtime_type_id: number | null;
    count_pre_shift_minutes: boolean;
    colour: string | null;
    normal_minutes: number | null;
    is_active: boolean;
    is_overnight: boolean;
    time_label: string;
};

const props = defineProps<{
    shift_types: ShiftType[];
    options: { single_punch_policies: Option<string>[]; holiday_lists: { id: number; name: string }[]; overtime_types: { id: number; code: string; name: string }[] };
    can: { manage: boolean };
}>();

const open = ref(false);
const editing = ref<ShiftType | null>(null);
const check = ref<{ errors: string[] | Record<string, string>; warnings: string[]; normal_minutes: number } | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;
let seq = 0;

const today = new Date().toISOString().slice(0, 10);
const blank = () => ({
    code: '',
    name: '',
    start_time: '08:00',
    end_time: '17:00',
    break_minutes: 60,
    break_start_time: '',
    open_before_minutes: 60,
    close_after_minutes: 120,
    late_grace_minutes: 10,
    early_grace_minutes: 10,
    track_late: true,
    track_early: true,
    half_day_threshold_minutes: 240,
    absent_threshold_minutes: 120,
    hours_method: 'first_last',
    single_punch_policy: 'review',
    auto_attendance: true,
    process_from: today,
    absent_buffer_days: 1,
    holiday_list_id: null as number | null,
    overtime_enabled: false,
    overtime_type_id: null as number | null,
    count_pre_shift_minutes: false,
    colour: '#3b82f6',
    is_active: true,
});
const form = useForm(blank());

const holidayOptions = computed(() => props.options.holiday_lists.map((h) => ({ value: h.id, label: h.name })));
const otOptions = computed(() => props.options.overtime_types.map((t) => ({ value: t.id, label: `${t.code} · ${t.name}` })));
const errorList = computed(() => Object.values(check.value?.errors ?? {}));

function payload(): Record<string, unknown> {
    const d = form.data();

    return { ...d, break_start_time: d.break_start_time || null, process_from: d.process_from || null, holiday_list_id: d.holiday_list_id, overtime_type_id: d.overtime_enabled ? d.overtime_type_id : null };
}

async function refresh() {
    const mine = ++seq;
    try {
        const { status, data } = await jsonRequest<{ errors: string[]; warnings: string[]; normal_minutes: number }>('POST', '/attendance/shift-types/check', payload());
        if (mine === seq && status === 200) check.value = data;
        if (mine === seq && status !== 200) check.value = null;
    } catch {
        if (mine === seq) check.value = null;
    }
}

watch(() => JSON.stringify(form.data()), () => {
    if (!open.value) return;
    if (timer) clearTimeout(timer);
    timer = setTimeout(refresh, 400);
});
onBeforeUnmount(() => timer && clearTimeout(timer));

function create() {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    check.value = null;
    open.value = true;
    refresh();
}

function edit(row: ShiftType) {
    editing.value = row;
    form.defaults({
        ...blank(),
        ...row,
        start_time: row.start_time.slice(0, 5),
        end_time: row.end_time.slice(0, 5),
        break_start_time: row.break_start_time?.slice(0, 5) ?? '',
        process_from: row.process_from ?? '',
        colour: row.colour ?? '#3b82f6',
    } as ReturnType<typeof blank>);
    form.reset();
    form.clearErrors();
    check.value = null;
    open.value = true;
    refresh();
}

function save() {
    form.transform(() => payload());
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`/attendance/shift-types/${editing.value.id}`, opts);
    else form.post('/attendance/shift-types', opts);
}
</script>

<template>
    <Head :title="$t('attendance.pages.shift_types')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.shift_types', href: '/attendance/shift-types' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.shift_types')" :description="$t('attendance.shift_type.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="shift-types-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('attendance.shift_type.code') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.shift_type.name') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.shift_type.time') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('attendance.shift_type.break') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('attendance.shift_type.normal_hours') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.shift_type.auto') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in shift_types" :key="s.id" class="border-t">
                            <td class="px-3 py-2 font-mono"><span class="mr-1 inline-block size-3 rounded-sm align-middle" :style="{ backgroundColor: s.colour ?? '#94a3b8' }" />{{ s.code }}</td>
                            <td class="px-3 py-2">{{ s.name }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ s.time_label }}<span v-if="s.is_overnight" class="ml-1 text-xs text-muted-foreground">({{ $t('attendance.shift_type.overnight') }})</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ s.break_minutes }} {{ $t('attendance.common.min') }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ hoursMinutes(s.normal_minutes) }}</td>
                            <td class="px-3 py-2">{{ s.auto_attendance ? $t('common.yes') : $t('common.no') }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="s.is_active ? 'active' : 'left'" :label="s.is_active ? $t('common.active') : $t('common.inactive')" /></td>
                            <td class="px-3 py-2 text-right"><Button v-if="can.manage" size="sm" variant="outline" @click="edit(s)">{{ $t('common.actions.edit') }}</Button></td>
                        </tr>
                        <tr v-if="!shift_types.length"><td colspan="8" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[92vh] max-w-3xl overflow-y-auto sm:max-w-3xl">
                <DialogHeader><DialogTitle>{{ editing ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('attendance.pages.shift_types') }}</DialogTitle></DialogHeader>
                <form class="space-y-5" @submit.prevent="save">
                    <fieldset class="space-y-3">
                        <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.shift_type.sections.time') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <FormField :label="$t('attendance.shift_type.code')" :error="form.errors.code" required><Input v-model="form.code" maxlength="20" /></FormField>
                            <FormField class="sm:col-span-2" :label="$t('attendance.shift_type.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                            <FormField :label="$t('attendance.shift_type.start')" :error="form.errors.start_time" required><Input v-model="form.start_time" type="time" /></FormField>
                            <FormField :label="$t('attendance.shift_type.end')" :error="form.errors.end_time" required><Input v-model="form.end_time" type="time" /></FormField>
                            <FormField :label="$t('attendance.shift_type.break_minutes')" :error="form.errors.break_minutes"><Input v-model="form.break_minutes" type="number" min="0" /></FormField>
                            <FormField :label="$t('attendance.shift_type.break_start')" :error="form.errors.break_start_time"><Input v-model="form.break_start_time" type="time" /></FormField>
                            <FormField :label="$t('attendance.shift_type.colour')" :error="form.errors.colour"><Input v-model="form.colour" type="color" class="h-9 w-20 p-1" /></FormField>
                        </div>
                    </fieldset>

                    <div class="rounded-md border p-3 text-sm" data-testid="shift-check" aria-live="polite">
                        <p><span class="text-muted-foreground">{{ $t('attendance.shift_type.computed_normal') }}:</span> <b class="tabular-nums">{{ check ? hoursMinutes(check.normal_minutes) : '—' }}</b></p>
                        <ul v-if="errorList.length" class="mt-1 list-disc space-y-0.5 pl-5 text-xs text-red-700 dark:text-red-300"><li v-for="(e, i) in errorList" :key="i">{{ e }}</li></ul>
                        <ul v-if="check?.warnings.length" class="mt-1 list-disc space-y-0.5 pl-5 text-xs text-amber-700 dark:text-amber-300" data-testid="shift-warnings"><li v-for="(w, i) in check.warnings" :key="i">{{ w }}</li></ul>
                    </div>

                    <fieldset class="space-y-3">
                        <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.shift_type.sections.window') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <FormField :label="$t('attendance.shift_type.open_before')" :error="form.errors.open_before_minutes" :hint="$t('attendance.shift_type.minutes_hint')"><Input v-model="form.open_before_minutes" type="number" min="0" /></FormField>
                            <FormField :label="$t('attendance.shift_type.close_after')" :error="form.errors.close_after_minutes" :hint="$t('attendance.shift_type.minutes_hint')"><Input v-model="form.close_after_minutes" type="number" min="0" /></FormField>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-3">
                        <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.shift_type.sections.grace') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <FormField :label="$t('attendance.shift_type.late_grace')" :error="form.errors.late_grace_minutes"><Input v-model="form.late_grace_minutes" type="number" min="0" /></FormField>
                            <FormField :label="$t('attendance.shift_type.early_grace')" :error="form.errors.early_grace_minutes"><Input v-model="form.early_grace_minutes" type="number" min="0" /></FormField>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.track_late" type="checkbox" />{{ $t('attendance.shift_type.track_late') }}</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.track_early" type="checkbox" />{{ $t('attendance.shift_type.track_early') }}</label>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-3">
                        <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.shift_type.sections.thresholds') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <FormField :label="$t('attendance.shift_type.half_day_threshold')" :error="form.errors.half_day_threshold_minutes" :hint="$t('attendance.shift_type.half_day_hint')"><Input v-model="form.half_day_threshold_minutes" type="number" min="0" /></FormField>
                            <FormField :label="$t('attendance.shift_type.absent_threshold')" :error="form.errors.absent_threshold_minutes" :hint="$t('attendance.shift_type.absent_hint')"><Input v-model="form.absent_threshold_minutes" type="number" min="0" /></FormField>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-3">
                        <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.shift_type.sections.auto') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex items-center gap-2 self-end text-sm"><input v-model="form.auto_attendance" type="checkbox" />{{ $t('attendance.shift_type.auto_attendance') }}</label>
                            <FormField :label="$t('attendance.shift_type.process_from')" :error="form.errors.process_from"><Input v-model="form.process_from" type="date" /></FormField>
                            <FormField :label="$t('attendance.shift_type.buffer_days')" :error="form.errors.absent_buffer_days"><Input v-model="form.absent_buffer_days" type="number" min="0" max="7" /></FormField>
                            <FormField :label="$t('attendance.shift_type.single_punch')" :error="form.errors.single_punch_policy"><SelectInput v-model="form.single_punch_policy" :options="options.single_punch_policies" :nullable="false" /></FormField>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-3">
                        <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.shift_type.sections.holiday') }}</legend>
                        <FormField :label="$t('attendance.shift_type.holiday_list')" :error="form.errors.holiday_list_id" :hint="$t('attendance.shift_type.holiday_hint')"><SelectInput v-model="form.holiday_list_id" :options="holidayOptions" :placeholder="$t('attendance.shift_type.holiday_default')" /></FormField>
                    </fieldset>

                    <fieldset class="space-y-3">
                        <legend class="mb-1 text-sm font-semibold">{{ $t('attendance.shift_type.sections.overtime') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.overtime_enabled" type="checkbox" />{{ $t('attendance.shift_type.overtime_enabled') }}</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.count_pre_shift_minutes" type="checkbox" :disabled="!form.overtime_enabled" />{{ $t('attendance.shift_type.count_pre_shift') }}</label>
                            <FormField v-if="form.overtime_enabled" :label="$t('attendance.shift_type.overtime_type')" :error="form.errors.overtime_type_id"><SelectInput v-model="form.overtime_type_id" :options="otOptions" :placeholder="$t('attendance.shift_type.overtime_default')" /></FormField>
                        </div>
                    </fieldset>

                    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" />{{ $t('common.active') }}</label>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
