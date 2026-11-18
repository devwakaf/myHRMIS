<script setup lang="ts">
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { firstError, jsonRequest } from '@/lib/attendance';

type Emp = { id: number; full_name: string; employee_no: string; branch_id: number | null; department_id: number | null };
type Result = { employee_id: number; employee: string; result: 'created' | 'replaced' | 'conflict'; reason: string | null };

const props = defineProps<{
    employees: Emp[];
    shiftTypes: { id: number; code: string; name: string }[];
    branches: { id: number; name: string }[];
    departments: { id: number; name: string }[];
    locations: { id: number; name: string }[];
}>();
const emit = defineEmits<{ done: []; cancel: [] }>();

const step = ref(1);
const search = ref('');
const branchId = ref<number | null>(null);
const departmentId = ref<number | null>(null);
const selected = ref<number[]>([]);
const details = ref({ shift_type_id: null as number | null, start_date: '', end_date: '', mode: 'add', location_ids: [] as number[], notes: '' });
const results = ref<Result[]>([]);
const committed = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

const filtered = computed(() => props.employees.filter((e) => (!branchId.value || e.branch_id === branchId.value) && (!departmentId.value || e.department_id === departmentId.value)
    && (search.value === '' || `${e.full_name} ${e.employee_no}`.toLowerCase().includes(search.value.toLowerCase()))));
const allVisible = computed({
    get: () => filtered.value.length > 0 && filtered.value.every((e) => selected.value.includes(e.id)),
    set: (value: boolean) => {
        const ids = filtered.value.map((e) => e.id);
        selected.value = value ? Array.from(new Set([...selected.value, ...ids])) : selected.value.filter((id) => !ids.includes(id));
    },
});
const counts = computed(() => ({
    created: results.value.filter((r) => r.result === 'created').length,
    replaced: results.value.filter((r) => r.result === 'replaced').length,
    conflict: results.value.filter((r) => r.result === 'conflict').length,
}));
const detailsOk = computed(() => details.value.shift_type_id !== null && details.value.start_date !== '');

function body(preview: boolean): Record<string, unknown> {
    return {
        employee_ids: selected.value,
        shift_type_id: details.value.shift_type_id,
        start_date: details.value.start_date,
        end_date: details.value.end_date || null,
        mode: details.value.mode,
        location_ids: details.value.location_ids,
        notes: details.value.notes || null,
        preview,
    };
}

async function run(preview: boolean) {
    busy.value = true;
    error.value = null;
    try {
        const { status, data } = await jsonRequest<{ results?: Result[]; message?: string; errors?: Record<string, string[]> }>('POST', '/attendance/shift-assignments', body(preview));
        if (status === 200 && data.results) {
            results.value = data.results;
            if (preview) step.value = 3;
            else {
                committed.value = true;
                step.value = 4;
            }
        } else {
            error.value = firstError(data) ?? String(status);
        }
    } catch {
        error.value = 'network';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-4" data-testid="bulk-wizard">
        <ol class="flex flex-wrap gap-2 text-xs">
            <li v-for="n in 4" :key="n" class="rounded-full px-3 py-1" :class="step === n ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'">{{ n }}. {{ $t(`attendance.assignment.steps.${n}`) }}</li>
        </ol>

        <div v-if="step === 1" class="space-y-3">
            <div class="grid gap-2 sm:grid-cols-3">
                <SelectInput v-model="branchId" :options="branches.map((b) => ({ value: b.id, label: b.name }))" :placeholder="$t('attendance.common.all_branches')" />
                <SelectInput v-model="departmentId" :options="departments.map((d) => ({ value: d.id, label: d.name }))" :placeholder="$t('attendance.common.all_departments')" />
                <Input v-model="search" :placeholder="$t('common.actions.search')" />
            </div>
            <div class="max-h-72 overflow-y-auto rounded-md border">
                <label class="flex items-center gap-2 border-b bg-muted/50 px-3 py-2 text-sm font-medium"><input v-model="allVisible" type="checkbox" />{{ $t('common.actions.select_all') }} ({{ filtered.length }})</label>
                <label v-for="e in filtered" :key="e.id" class="flex items-center gap-2 border-b px-3 py-1.5 text-sm last:border-b-0"><input v-model="selected" type="checkbox" :value="e.id" />{{ e.full_name }} <span class="text-xs text-muted-foreground">{{ e.employee_no }}</span></label>
                <p v-if="!filtered.length" class="px-3 py-4 text-center text-sm text-muted-foreground">{{ $t('common.empty') }}</p>
            </div>
            <div class="flex items-center justify-between">
                <p class="text-xs text-muted-foreground">{{ $t('attendance.assignment.selected_count', { count: String(selected.length) }) }}</p>
                <div class="flex gap-2">
                    <Button type="button" variant="ghost" @click="emit('cancel')">{{ $t('common.actions.cancel') }}</Button>
                    <Button type="button" :disabled="!selected.length" @click="step = 2">{{ $t('common.actions.continue') }}</Button>
                </div>
            </div>
        </div>

        <div v-else-if="step === 2" class="space-y-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField :label="$t('attendance.common.shift')" required><SelectInput v-model="details.shift_type_id" :options="shiftTypes.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))" /></FormField>
                <FormField :label="$t('attendance.assignment.mode')" :hint="$t(`attendance.assignment.mode_hint_${details.mode}`)">
                    <SelectInput v-model="details.mode" :options="[{ value: 'add', label: $t('attendance.assignment.mode_add') }, { value: 'replace', label: $t('attendance.assignment.mode_replace') }]" :nullable="false" />
                </FormField>
                <FormField :label="$t('attendance.common.from_date')" required><Input v-model="details.start_date" type="date" /></FormField>
                <FormField :label="$t('attendance.common.to_date')" :hint="$t('attendance.assignment.open_ended')"><Input v-model="details.end_date" type="date" :min="details.start_date" /></FormField>
            </div>
            <fieldset v-if="locations.length" class="rounded-md border p-3">
                <legend class="px-1 text-xs text-muted-foreground">{{ $t('attendance.assignment.locations') }}</legend>
                <label v-for="l in locations" :key="l.id" class="mr-4 inline-flex items-center gap-1 text-sm"><input v-model="details.location_ids" type="checkbox" :value="l.id" />{{ l.name }}</label>
            </fieldset>
            <FormField :label="$t('common.fields.note')"><Input v-model="details.notes" /></FormField>
            <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
            <div class="flex justify-between">
                <Button type="button" variant="ghost" @click="step = 1">{{ $t('common.actions.back') }}</Button>
                <Button type="button" :disabled="!detailsOk || busy" data-testid="wizard-preview" @click="run(true)">{{ $t('attendance.assignment.preview') }}</Button>
            </div>
        </div>

        <div v-else-if="step === 3 || step === 4" class="space-y-3">
            <p class="text-sm" data-testid="wizard-counts">{{ $t('attendance.assignment.result_counts', { created: String(counts.created), replaced: String(counts.replaced), conflict: String(counts.conflict) }) }}</p>
            <div class="max-h-72 overflow-y-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left"><tr><th class="px-3 py-2">{{ $t('attendance.common.employee') }}</th><th class="px-3 py-2">{{ $t('attendance.assignment.result') }}</th><th class="px-3 py-2">{{ $t('attendance.assignment.reason') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="r in results" :key="r.employee_id" class="border-t">
                            <td class="px-3 py-1.5">{{ r.employee }}</td>
                            <td class="px-3 py-1.5" :class="r.result === 'conflict' ? 'text-red-600' : r.result === 'replaced' ? 'text-amber-700' : 'text-emerald-700'">{{ $t(`attendance.assignment.results.${r.result}`) }}</td>
                            <td class="px-3 py-1.5 text-xs text-muted-foreground">{{ r.reason }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
            <div class="flex justify-between">
                <Button v-if="!committed" type="button" variant="ghost" @click="step = 2">{{ $t('common.actions.back') }}</Button>
                <span v-else />
                <Button v-if="!committed" type="button" :disabled="busy || counts.created + counts.replaced === 0" data-testid="wizard-commit" @click="run(false)">{{ $t('attendance.assignment.commit') }}</Button>
                <Button v-else type="button" @click="emit('done')">{{ $t('common.actions.close') }}</Button>
            </div>
        </div>
    </div>
</template>
