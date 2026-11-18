<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FormField from '@/components/app/FormField.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TextArea from '@/components/app/TextArea.vue';
import PlanTable from '@/components/attendance/PlanTable.vue';
import RoutePreview from '@/components/attendance/RoutePreview.vue';
import type { RouteData } from '@/components/attendance/RoutePreview.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import { firstError, jsonRequest } from '@/lib/attendance';
import type { AttendanceRequestRow, PlanDay } from '@/lib/attendance';
import type { Option } from '@/types';

type Preview = { days: PlanDay[]; route: RouteData | null };

const props = defineProps<{
    options: { kinds: Option<string>[]; sessions: Option<string>[]; employees: { id: number; full_name: string; employee_no: string }[] };
    canForOthers: boolean;
    request?: AttendanceRequestRow | null;
    prefillFrom?: string | null;
}>();
const emit = defineEmits<{ done: []; cancel: [] }>();

const form = useForm({
    employee_id: null as number | null,
    kind: props.request?.kind ?? 'wfh',
    from_date: props.request?.from_date ?? props.prefillFrom ?? '',
    to_date: props.request?.to_date ?? props.prefillFrom ?? '',
    is_half_day: props.request?.is_half_day ?? false,
    half_day_date: props.request?.half_day_date ?? '',
    half_day_session: props.request?.half_day_session ?? 'am',
    include_rest_days: props.request?.include_rest_days ?? false,
    duty_location: props.request?.duty_location ?? '',
    explanation: props.request?.explanation ?? '',
    choices: {} as Record<string, number | null>,
});

const preview = ref<Preview | null>(null);
const previewError = ref<string | null>(null);
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let seq = 0;

const employeeOptions = computed(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` })));
const halfDayChoices = computed(() => [form.from_date, form.to_date].filter((d, i, a) => d !== '' && a.indexOf(d) === i).map((d) => ({ value: d, label: formatDate(d) })));
const blocked = computed(() => preview.value?.route?.blocked === true || previewError.value !== null);

function body(): Record<string, unknown> {
    return {
        employee_id: form.employee_id,
        kind: form.kind,
        from_date: form.from_date,
        to_date: form.to_date || form.from_date,
        is_half_day: form.is_half_day,
        half_day_date: form.is_half_day ? form.half_day_date || form.from_date : null,
        half_day_session: form.is_half_day ? form.half_day_session : null,
        include_rest_days: form.include_rest_days,
        duty_location: form.duty_location || null,
        explanation: form.explanation || '-',
        choices: form.choices,
    };
}

async function refresh() {
    if (!form.kind || !form.from_date) {
        preview.value = null;
        previewError.value = null;

        return;
    }
    const mine = ++seq;
    loading.value = true;
    try {
        const { status, data } = await jsonRequest<Preview & { message?: string; errors?: Record<string, string[]> }>('POST', '/attendance-requests/preview', body());
        if (mine !== seq) return;
        if (status === 200) {
            preview.value = data;
            previewError.value = null;
            for (const step of data.route?.steps ?? []) {
                const key = String(step.workflow_step_id);
                if (step.candidates.length && form.choices[key] === undefined) form.choices[key] = step.default_choice;
            }
        } else {
            preview.value = null;
            previewError.value = firstError(data) ?? String(status);
        }
    } catch {
        if (mine === seq) previewError.value = 'network';
    } finally {
        if (mine === seq) loading.value = false;
    }
}

function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(refresh, 350);
}

watch(() => [form.employee_id, form.kind, form.from_date, form.to_date, form.is_half_day, form.half_day_date, form.half_day_session, form.include_rest_days, JSON.stringify(form.choices)], schedule, { immediate: Boolean(form.from_date) });
watch(() => form.from_date, (value) => {
    if (value && (!form.to_date || form.to_date < value)) form.to_date = value;
});
onBeforeUnmount(() => timer && clearTimeout(timer));

function send(submit: boolean) {
    form.transform((data) => ({
        ...data,
        submit,
        to_date: data.to_date || data.from_date,
        half_day_date: data.is_half_day ? data.half_day_date || data.from_date : null,
        half_day_session: data.is_half_day ? data.half_day_session : null,
        duty_location: data.duty_location || null,
    }));
    const opts = { preserveScroll: true, onSuccess: () => emit('done') };
    if (props.request) form.put(`/attendance-requests/${props.request.id}`, opts);
    else form.post('/attendance-requests', opts);
}
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-5">
        <form class="space-y-4 lg:col-span-3" @submit.prevent="send(true)">
            <FormField v-if="canForOthers && !request" :label="$t('attendance.common.employee')" :error="form.errors.employee_id" :hint="$t('attendance.request.on_behalf_hint')">
                <SelectInput v-model="form.employee_id" :options="employeeOptions" :placeholder="$t('attendance.request.myself')" />
            </FormField>

            <FormField :label="$t('attendance.request.kind')" :error="form.errors.kind" required>
                <SelectInput v-model="form.kind" :options="options.kinds" :nullable="false" />
            </FormField>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField :label="$t('attendance.common.from_date')" :error="form.errors.from_date" required><Input v-model="form.from_date" type="date" /></FormField>
                <FormField :label="$t('attendance.common.to_date')" :error="form.errors.to_date"><Input v-model="form.to_date" type="date" :min="form.from_date" /></FormField>
            </div>

            <div class="space-y-3 rounded-md border p-3">
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_half_day" type="checkbox" />{{ $t('attendance.request.half_day') }}</label>
                <div v-if="form.is_half_day" class="grid gap-4 sm:grid-cols-2">
                    <FormField :label="$t('attendance.request.half_day_date')" :error="form.errors.half_day_date">
                        <SelectInput v-model="form.half_day_date" :options="halfDayChoices" :placeholder="$t('attendance.request.half_day_first')" />
                    </FormField>
                    <FormField :label="$t('attendance.request.session')" :error="form.errors.half_day_session">
                        <SelectInput v-model="form.half_day_session" :options="options.sessions" :nullable="false" />
                    </FormField>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.include_rest_days" type="checkbox" />{{ $t('attendance.request.include_rest_days') }}</label>
            </div>

            <FormField v-if="form.kind === 'on_duty'" :label="$t('attendance.request.duty_location')" :error="form.errors.duty_location" required>
                <Input v-model="form.duty_location" maxlength="255" />
            </FormField>

            <FormField :label="$t('attendance.request.explanation')" :error="form.errors.explanation" required>
                <TextArea v-model="form.explanation" :rows="3" />
            </FormField>

            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="form.processing || !form.from_date || !form.explanation.trim() || blocked">{{ $t('attendance.common.submit_for_approval') }}</Button>
                <Button type="button" variant="outline" :disabled="form.processing || !form.from_date || !form.explanation.trim()" @click="send(false)">{{ $t('attendance.common.save_draft') }}</Button>
                <Button type="button" variant="ghost" @click="emit('cancel')">{{ $t('common.actions.cancel') }}</Button>
            </div>
        </form>

        <aside class="space-y-4 lg:col-span-2" aria-live="polite">
            <div class="rounded-lg border p-4">
                <h2 class="mb-2 text-sm font-medium">{{ $t('attendance.request.preview') }}</h2>
                <p v-if="!preview && !loading && !previewError" class="text-sm text-muted-foreground">{{ $t('attendance.request.pick_dates') }}</p>
                <p v-if="loading" class="text-xs text-muted-foreground">{{ $t('attendance.common.calculating') }}</p>
                <p v-if="previewError" class="rounded-md bg-red-50 p-2 text-sm text-red-900 dark:bg-red-950 dark:text-red-100" role="alert">{{ previewError }}</p>
                <PlanTable v-if="preview" :days="preview.days" />
            </div>
            <RoutePreview v-model:choices="form.choices" :route="preview?.route ?? null" />
        </aside>
    </div>
</template>
