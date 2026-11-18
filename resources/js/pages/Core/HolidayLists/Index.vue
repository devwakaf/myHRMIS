<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

defineProps<{
    lists: { id: number; display_name: string; from_date: string; to_date: string; state_label: string | null; is_active: boolean; totals: Record<string, string> }[];
    states: Option<string>[];
    canManage: boolean;
}>();

const open = ref(false);
const year = new Date().getFullYear();
const form = useForm({
    name: '',
    name_en: '',
    from_date: `${year}-01-01`,
    to_date: `${year}-12-31`,
    state: null as string | null,
    weekly_off_days: [6, 7] as number[],
    half_day_weekly_off_days: [] as number[],
    notes: '',
    is_active: true,
});
const weekdays = [1, 2, 3, 4, 5, 6, 7];

function toggle(list: 'weekly_off_days' | 'half_day_weekly_off_days', day: number) {
    form[list] = form[list].includes(day) ? form[list].filter((d) => d !== day) : [...form[list], day];
}
</script>

<template>
    <Head :title="$t('common.nav.holiday_lists')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.holiday_lists', href: '/holiday-lists' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.holiday_lists')" :description="$t('core.holiday.description')">
                <template #actions>
                    <Button v-if="canManage" @click="open = true"><Plus class="size-4" />{{ $t('core.holiday.create_list') }}</Button>
                </template>
            </PageHeader>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('core.masters.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday.period') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.state') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday.totals.public_state') }}</th>
                            <th class="px-3 py-2">{{ $t('core.holiday.totals.weekly_off') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in lists" :key="l.id" class="border-t">
                            <td class="px-3 py-2"><Link :href="`/holiday-lists/${l.id}`" class="font-medium hover:underline">{{ l.display_name }}</Link></td>
                            <td class="px-3 py-2">{{ formatDate(l.from_date) }} – {{ formatDate(l.to_date) }}</td>
                            <td class="px-3 py-2">{{ l.state_label ?? '—' }}</td>
                            <td class="px-3 py-2">{{ Number(l.totals.public) + Number(l.totals.state) + Number(l.totals.replacement) }}</td>
                            <td class="px-3 py-2">{{ l.totals.weekly_off }}</td>
                        </tr>
                        <tr v-if="!lists.length"><td colspan="5" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>{{ $t('core.holiday.create_list') }}</DialogTitle></DialogHeader>
                <div class="grid gap-3">
                    <FormField :label="$t('core.masters.fields.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                    <FormField :label="$t('core.masters.fields.name_en')"><Input v-model="form.name_en" /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('core.holiday.from_date')" :error="form.errors.from_date" required><Input v-model="form.from_date" type="date" /></FormField>
                        <FormField :label="$t('core.holiday.to_date')" :error="form.errors.to_date" required><Input v-model="form.to_date" type="date" /></FormField>
                    </div>
                    <FormField :label="$t('common.fields.state')"><SelectInput v-model="form.state" :options="states" /></FormField>
                    <FormField :label="$t('core.holiday.weekly_off_days')" :error="form.errors.weekly_off_days">
                        <div class="flex flex-wrap gap-3 text-sm">
                            <label v-for="d in weekdays" :key="d" class="flex items-center gap-1"><input type="checkbox" :checked="form.weekly_off_days.includes(d)" @change="toggle('weekly_off_days', d)" />{{ $t(`common.weekdays.${d}`) }}</label>
                        </div>
                    </FormField>
                    <FormField :label="$t('core.holiday.half_day_weekly_off_days')">
                        <div class="flex flex-wrap gap-3 text-sm">
                            <label v-for="d in weekdays" :key="d" class="flex items-center gap-1"><input type="checkbox" :checked="form.half_day_weekly_off_days.includes(d)" @change="toggle('half_day_weekly_off_days', d)" />{{ $t(`common.weekdays.${d}`) }}</label>
                        </div>
                    </FormField>
                </div>
                <DialogFooter><Button :disabled="form.processing" @click="form.post('/holiday-lists')">{{ $t('common.actions.save') }}</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
