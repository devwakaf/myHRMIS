<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Option } from '@/types';

/** "Kehadiran" tab of Butiran Kakitangan: the attendance profile (JSON) plus shortcuts. */
type Profile = {
    checkin_required: boolean;
    geofence_exempt: boolean;
    is_manual_labour: boolean;
    ot_eligibility: string;
    ot_eligibility_note: string | null;
    device_user_code: string | null;
    default_shift_type_id: number | null;
};

const props = defineProps<{ employeeId: number; canManage: boolean }>();

const profile = ref<Profile | null>(null);
const otOptions = ref<Option<string>[]>([]);
const shiftTypes = ref<{ id: number; code: string; name: string }[]>([]);
const loading = ref(true);
const failed = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

async function load() {
    loading.value = true;
    try {
        const response = await fetch(`/attendance/employees/${props.employeeId}/profile`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(String(response.status));
        const body = (await response.json()) as { profile: Profile; options: { ot_eligibility: Option<string>[]; shift_types: { id: number; code: string; name: string }[] } };
        profile.value = body.profile;
        otOptions.value = body.options.ot_eligibility;
        shiftTypes.value = body.options.shift_types;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function save() {
    if (!profile.value) return;
    saving.value = true;
    errors.value = {};
    router.put(`/attendance/employees/${props.employeeId}/profile`, { ...profile.value, ot_eligibility_note: profile.value.ot_eligibility_note || null, device_user_code: profile.value.device_user_code || null }, {
        preserveScroll: true,
        onError: (e) => (errors.value = e as Record<string, string>),
        onSuccess: () => load(),
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <div class="max-w-2xl space-y-4" data-testid="attendance-tab">
        <p v-if="loading" class="text-sm text-muted-foreground">…</p>
        <p v-else-if="failed" class="text-sm text-red-600">{{ $t('attendance.employee_tab.load_failed') }}</p>
        <form v-else-if="profile" class="space-y-4" @submit.prevent="save">
            <fieldset :disabled="!canManage" class="space-y-3">
                <label class="flex items-center gap-2 text-sm"><input v-model="profile.checkin_required" type="checkbox" />{{ $t('attendance.employee_tab.checkin_required') }}</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="profile.geofence_exempt" type="checkbox" />{{ $t('attendance.employee_tab.geofence_exempt') }}</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="profile.is_manual_labour" type="checkbox" />{{ $t('attendance.employee_tab.manual_labour') }}</label>
                <FormField :label="$t('attendance.employee_tab.ot_eligibility')" :error="errors.ot_eligibility"><SelectInput v-model="profile.ot_eligibility" :options="otOptions" :nullable="false" /></FormField>
                <FormField v-if="profile.ot_eligibility !== 'auto'" :label="$t('attendance.employee_tab.ot_note')" :error="errors.ot_eligibility_note" required><TextArea v-model="profile.ot_eligibility_note" :rows="2" /></FormField>
                <FormField :label="$t('attendance.employee_tab.device_code')" :error="errors.device_user_code"><Input v-model="profile.device_user_code" maxlength="30" /></FormField>
                <FormField v-if="shiftTypes.length" :label="$t('attendance.employee_tab.default_shift')" :error="errors.default_shift_type_id" :hint="$t('attendance.employee_tab.default_shift_hint')">
                    <SelectInput v-model="profile.default_shift_type_id" :options="shiftTypes.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))" :placeholder="$t('attendance.employee_tab.no_default_shift')" />
                </FormField>
            </fieldset>
            <Button v-if="canManage" type="submit" :disabled="saving">{{ $t('common.actions.save') }}</Button>
        </form>
        <div class="flex flex-wrap gap-4 text-sm">
            <Link :href="`/attendance/records?employee_id=${employeeId}`" class="text-primary hover:underline">{{ $t('attendance.employee_tab.records_link') }}</Link>
            <Link :href="`/attendance/shift-assignments?employee_id=${employeeId}`" class="text-primary hover:underline">{{ $t('attendance.employee_tab.assignments_link') }}</Link>
            <Link :href="`/attendance/checkins?employee_id=${employeeId}`" class="text-primary hover:underline">{{ $t('attendance.employee_tab.checkins_link') }}</Link>
        </div>
    </div>
</template>
