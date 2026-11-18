<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Slot = { day_index: number; kind: string; shift_type_id: number | null };
type Schedule = { id: number; code: string; name: string; cycle_length_days: number; is_active: boolean; notes: string | null; days: Slot[] };
type ShiftOpt = { id: number; code: string; name: string; colour: string | null };

const props = defineProps<{
    schedules: Schedule[];
    options: { kinds: Option<string>[]; shift_types: ShiftOpt[] };
    can: { manage: boolean };
}>();

const open = ref(false);
const editing = ref<Schedule | null>(null);
const form = useForm({ code: '', name: '', cycle_length_days: 7 as number | string, is_active: true, notes: '', days: [] as Slot[] });

const shiftById = computed(() => new Map(props.options.shift_types.map((s) => [s.id, s])));
const shiftOptions = computed(() => props.options.shift_types.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` })));

function slotsFor(length: number, existing: Slot[] = []): Slot[] {
    return Array.from({ length }, (_, i) => existing.find((d) => d.day_index === i) ?? { day_index: i, kind: 'rest', shift_type_id: null });
}

function create() {
    editing.value = null;
    form.defaults({ code: '', name: '', cycle_length_days: 7, is_active: true, notes: '', days: slotsFor(7) });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(s: Schedule) {
    editing.value = s;
    form.defaults({ code: s.code, name: s.name, cycle_length_days: s.cycle_length_days, is_active: s.is_active, notes: s.notes ?? '', days: slotsFor(s.cycle_length_days, s.days.map((d) => ({ ...d }))) });
    form.reset();
    form.clearErrors();
    open.value = true;
}

watch(() => form.cycle_length_days, (value) => {
    const n = Math.min(28, Math.max(1, Number(value) || 1));
    if (n !== form.days.length) form.days = slotsFor(n, form.days);
});

function setKind(slot: Slot, kind: string | number | null | undefined) {
    slot.kind = String(kind ?? 'rest');
    if (slot.kind !== 'shift') slot.shift_type_id = null;
}

function tone(slot: Slot): string {
    if (slot.kind === 'shift') return '';
    return slot.kind === 'rest' ? 'bg-muted' : 'bg-muted/40 text-muted-foreground';
}

function cellLabel(slot: Slot): string {
    if (slot.kind === 'shift') return shiftById.value.get(slot.shift_type_id ?? 0)?.code ?? '?';

    return slot.kind === 'rest' ? 'R' : '—';
}

function save() {
    form.transform((d) => ({ ...d, cycle_length_days: Number(d.cycle_length_days), notes: d.notes || null }));
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`/attendance/shift-schedules/${editing.value.id}`, opts);
    else form.post('/attendance/shift-schedules', opts);
}
</script>

<template>
    <Head :title="$t('attendance.pages.schedules')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.schedules', href: '/attendance/shift-schedules' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.schedules')" :description="$t('attendance.schedule.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="space-y-3" data-testid="schedules-list">
                <div v-for="s in schedules" :key="s.id" class="rounded-lg border p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-medium"><span class="font-mono">{{ s.code }}</span> · {{ s.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ $t('attendance.schedule.cycle_days', { days: String(s.cycle_length_days) }) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="s.is_active ? 'active' : 'left'" :label="s.is_active ? $t('common.active') : $t('common.inactive')" />
                            <Button v-if="can.manage" size="sm" variant="outline" @click="edit(s)">{{ $t('common.actions.edit') }}</Button>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-1">
                        <span v-for="d in s.days" :key="d.day_index" class="flex size-9 items-center justify-center rounded border text-xs font-medium" :class="tone(d)" :style="d.kind === 'shift' ? { backgroundColor: (shiftById.get(d.shift_type_id ?? 0)?.colour ?? '#94a3b8') + '40' } : undefined" :title="`${$t('attendance.schedule.day')} ${d.day_index + 1}`">{{ cellLabel(d) }}</span>
                    </div>
                    <p v-if="s.notes" class="mt-2 text-xs text-muted-foreground">{{ s.notes }}</p>
                </div>
                <p v-if="!schedules.length" class="rounded-lg border p-6 text-center text-sm text-muted-foreground">{{ $t('common.empty') }}</p>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[92vh] max-w-3xl overflow-y-auto sm:max-w-3xl">
                <DialogHeader><DialogTitle>{{ editing ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('attendance.pages.schedules') }}</DialogTitle></DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <FormField :label="$t('attendance.shift_type.code')" :error="form.errors.code" required><Input v-model="form.code" maxlength="20" /></FormField>
                        <FormField class="sm:col-span-2" :label="$t('attendance.shift_type.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                        <FormField :label="$t('attendance.schedule.cycle')" :error="form.errors.cycle_length_days" :hint="$t('attendance.schedule.cycle_hint')" required><Input v-model="form.cycle_length_days" type="number" min="1" max="28" /></FormField>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="mb-1 text-sm font-medium">{{ $t('attendance.schedule.grid') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4" data-testid="pattern-grid">
                            <div v-for="slot in form.days" :key="slot.day_index" class="space-y-1 rounded-md border p-2" :class="tone(slot)">
                                <p class="text-xs font-medium">{{ $t('attendance.schedule.day') }} {{ slot.day_index + 1 }}</p>
                                <SelectInput :model-value="slot.kind" :options="options.kinds" :nullable="false" @update:model-value="(v) => setKind(slot, v)" />
                                <SelectInput v-if="slot.kind === 'shift'" v-model="slot.shift_type_id" :options="shiftOptions" :placeholder="$t('attendance.schedule.pick_shift')" />
                            </div>
                        </div>
                        <p v-if="form.errors.days" class="text-xs text-red-600">{{ form.errors.days }}</p>
                    </fieldset>

                    <FormField :label="$t('common.fields.note')" :error="form.errors.notes"><TextArea v-model="form.notes" :rows="2" /></FormField>
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
