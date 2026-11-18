<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Holiday = { id: number; date: string; name: string; name_en: string | null; display_name: string; kind: string; kind_label: string; is_half_day: boolean; is_mandatory: boolean };

const props = defineProps<{
    list: { id: number; display_name: string; name: string; name_en: string | null; from_date: string; to_date: string; state: string | null; state_label: string | null; weekly_off_days: number[]; half_day_weekly_off_days: number[]; notes: string | null; is_active: boolean };
    holidays: Holiday[];
    totals: Record<string, string>;
    suggestions: { holiday_id: number; holiday_date: string; holiday_name: string; suggested_date: string }[];
    kinds: Option<string>[];
    states: Option<string>[];
    catalogueYears: number[];
    canManage: boolean;
}>();

const base = `/holiday-lists/${props.list.id}`;
const showWeeklyOff = ref(false);
const month = ref<number | null>(null);
const visible = computed(() =>
    props.holidays.filter((h) => (showWeeklyOff.value || h.kind !== 'weekly_off') && (month.value === null || Number(h.date.slice(5, 7)) === month.value)),
);

const dialog = ref<null | 'holiday' | 'import' | 'copy'>(null);
const editingId = ref<number | null>(null);
const holidayForm = useForm({ date: '', name: '', name_en: '', kind: 'organisation', is_half_day: false, is_mandatory: false });
const importForm = useForm({ year: Number(props.list.from_date.slice(0, 4)), scopes: ['federal', ...(props.list.state ? [props.list.state] : [])] });
const copyForm = useForm({ name: `${props.list.name} (${Number(props.list.from_date.slice(0, 4)) + 1})` });

function addHoliday(date = '', kind = 'organisation', name = '') {
    editingId.value = null;
    holidayForm.defaults({ date, name, name_en: '', kind, is_half_day: false, is_mandatory: false });
    holidayForm.reset();
    holidayForm.clearErrors();
    dialog.value = 'holiday';
}

function editHoliday(h: Holiday) {
    editingId.value = h.id;
    holidayForm.defaults({ date: h.date, name: h.name, name_en: h.name_en ?? '', kind: h.kind, is_half_day: h.is_half_day, is_mandatory: h.is_mandatory });
    holidayForm.reset();
    dialog.value = 'holiday';
}

const opts = { preserveScroll: true, onSuccess: () => (dialog.value = null) };

function saveHoliday() {
    if (editingId.value) holidayForm.put(`${base}/holidays/${editingId.value}`, opts);
    else holidayForm.post(`${base}/holidays`, opts);
}
</script>

<template>
    <Head :title="list.display_name" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.holiday_lists', href: '/holiday-lists' }, { title: list.display_name, href: base }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="list.display_name" :description="`${formatDate(list.from_date)} – ${formatDate(list.to_date)}${list.state_label ? ' · ' + list.state_label : ''}`">
                <template #actions>
                    <template v-if="canManage">
                        <Button variant="outline" @click="router.post(`${base}/weekly-offs`, {}, { preserveScroll: true })">{{ $t('core.holiday.generate_weekly_offs') }}</Button>
                        <Button variant="outline" @click="dialog = 'import'">{{ $t('core.holiday.import') }}</Button>
                        <Button variant="outline" @click="dialog = 'copy'">{{ $t('core.holiday.copy') }}</Button>
                        <Button @click="addHoliday()">{{ $t('core.holiday.add') }}</Button>
                    </template>
                </template>
            </PageHeader>

            <div class="mb-4 grid gap-2 sm:grid-cols-4 lg:grid-cols-7">
                <div v-for="key in ['public', 'state', 'replacement', 'organisation', 'ad_hoc', 'weekly_off', 'total']" :key="key" class="rounded-lg border p-3">
                    <p class="text-lg font-semibold">{{ totals[key] }}</p>
                    <p class="text-xs text-muted-foreground">{{ $t(`core.holiday.totals.${key}`) }}</p>
                </div>
            </div>

            <div v-if="suggestions.length" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950">
                <p class="mb-2 font-medium">{{ $t('core.holiday.replacement_suggestions') }}</p>
                <ul class="space-y-1">
                    <li v-for="s in suggestions" :key="s.holiday_id" class="flex flex-wrap items-center gap-2">
                        {{ $t('core.holiday.suggestion_line', { name: s.holiday_name, date: formatDate(s.holiday_date), suggested: formatDate(s.suggested_date) }) }}
                        <Button v-if="canManage" size="sm" variant="outline" @click="addHoliday(s.suggested_date, 'replacement', `${$t('core.enums.holiday_kind.replacement')} ${s.holiday_name}`)">{{ $t('core.holiday.add_replacement') }}</Button>
                    </li>
                </ul>
            </div>

            <div class="mb-3 flex flex-wrap items-center gap-3 text-sm">
                <SelectInput v-model="month" class="max-w-40" :options="Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: $t(`common.months.${i + 1}`) }))" :placeholder="$t('core.holiday.all_months')" />
                <label class="flex items-center gap-2"><input v-model="showWeeklyOff" type="checkbox" /> {{ $t('core.holiday.show_weekly_off') }}</label>
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('core.holiday.date') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday.day') }}</th>
                            <th class="px-3 py-2">{{ $t('core.masters.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday.kind') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="h in visible" :key="h.id" class="border-t">
                            <td class="px-3 py-1.5">{{ formatDate(h.date) }}</td>
                            <td class="px-3 py-1.5">{{ $t(`common.weekdays.${((new Date(h.date + 'T00:00:00').getDay() + 6) % 7) + 1}`) }}</td>
                            <td class="px-3 py-1.5">
                                {{ h.display_name }}
                                <span v-if="h.is_half_day" class="ml-1 rounded bg-muted px-1 text-xs">{{ $t('core.holiday.half_day') }}</span>
                                <span v-if="h.is_mandatory" class="ml-1 rounded bg-primary/10 px-1 text-xs">{{ $t('core.holiday.mandatory') }}</span>
                            </td>
                            <td class="px-3 py-1.5">{{ h.kind_label }}</td>
                            <td class="px-3 py-1.5 text-right">
                                <template v-if="canManage">
                                    <Button size="sm" variant="ghost" @click="editHoliday(h)">{{ $t('common.actions.edit') }}</Button>
                                    <Button size="icon" variant="ghost" :aria-label="$t('common.actions.delete')" @click="router.delete(`${base}/holidays/${h.id}`, { preserveScroll: true })"><Trash2 class="size-4" /></Button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!visible.length"><td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog :open="dialog !== null" @update:open="(o: boolean) => { if (!o) dialog = null }">
            <DialogContent class="max-w-md">
                <template v-if="dialog === 'holiday'">
                    <DialogHeader><DialogTitle>{{ $t('core.holiday.add') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('core.holiday.date')" :error="holidayForm.errors.date" required><Input v-model="holidayForm.date" type="date" /></FormField>
                        <FormField :label="$t('core.masters.fields.name')" :error="holidayForm.errors.name" required><Input v-model="holidayForm.name" /></FormField>
                        <FormField :label="$t('core.masters.fields.name_en')"><Input v-model="holidayForm.name_en" /></FormField>
                        <FormField :label="$t('core.holiday.kind')" :error="holidayForm.errors.kind" required><SelectInput v-model="holidayForm.kind" :options="kinds" :nullable="false" /></FormField>
                        <label class="flex items-center gap-2 text-sm"><input v-model="holidayForm.is_half_day" type="checkbox" /> {{ $t('core.holiday.half_day') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="holidayForm.is_mandatory" type="checkbox" /> {{ $t('core.holiday.mandatory') }}</label>
                    </div>
                    <DialogFooter><Button :disabled="holidayForm.processing" @click="saveHoliday">{{ $t('common.actions.save') }}</Button></DialogFooter>
                </template>
                <template v-if="dialog === 'import'">
                    <DialogHeader><DialogTitle>{{ $t('core.holiday.import') }}</DialogTitle></DialogHeader>
                    <div class="grid gap-3">
                        <FormField :label="$t('core.holiday.year')" :error="importForm.errors.year"><Input v-model.number="importForm.year" type="number" /></FormField>
                        <FormField :label="$t('core.holiday.scopes')" :error="importForm.errors.scopes">
                            <div class="grid grid-cols-2 gap-1 text-sm">
                                <label class="flex items-center gap-2"><input v-model="importForm.scopes" type="checkbox" value="federal" /> {{ $t('core.holiday.federal') }}</label>
                                <label v-for="s in states" :key="s.value" class="flex items-center gap-2"><input v-model="importForm.scopes" type="checkbox" :value="s.value" /> {{ s.label }}</label>
                            </div>
                        </FormField>
                        <p class="text-xs text-muted-foreground">{{ $t('core.holiday.catalogue_years', { years: catalogueYears.join(', ') || '—' }) }}</p>
                    </div>
                    <DialogFooter><Button :disabled="importForm.processing" @click="importForm.post(`${base}/import`, opts)">{{ $t('core.holiday.import') }}</Button></DialogFooter>
                </template>
                <template v-if="dialog === 'copy'">
                    <DialogHeader><DialogTitle>{{ $t('core.holiday.copy') }}</DialogTitle></DialogHeader>
                    <p class="text-xs text-muted-foreground">{{ $t('core.holiday.copy_hint') }}</p>
                    <FormField :label="$t('core.masters.fields.name')" :error="copyForm.errors.name" required><Input v-model="copyForm.name" /></FormField>
                    <DialogFooter><Button :disabled="copyForm.processing" @click="copyForm.post(`${base}/copy`)">{{ $t('core.holiday.copy') }}</Button></DialogFooter>
                </template>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
