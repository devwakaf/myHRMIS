<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Crosshair, Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Link = { type: string; id: number };
type Location = {
    id: number;
    name: string;
    branch_id: number | null;
    latitude: string;
    longitude: string;
    radius_m: number;
    accuracy_threshold_m: number | null;
    address_note: string | null;
    is_active: boolean;
    links: Link[];
};

const props = defineProps<{
    locations: Location[];
    options: { branches: { id: number; name: string }[]; employees: { id: number; full_name: string; employee_no: string }[]; link_types: Option<string>[] };
    can: { manage: boolean };
}>();

const open = ref(false);
const editing = ref<Location | null>(null);
const locating = ref(false);
const geoError = ref<string | null>(null);
const newLink = ref<{ type: string; id: number | string | null }>({ type: 'branch', id: null });

const form = useForm({
    name: '',
    branch_id: null as number | null,
    latitude: '' as string | number,
    longitude: '' as string | number,
    radius_m: 100 as string | number,
    accuracy_threshold_m: '' as string | number,
    address_note: '',
    is_active: true,
    links: [] as Link[],
});

const branchOptions = computed(() => props.options.branches.map((b) => ({ value: b.id, label: b.name })));
const employeeOptions = computed(() => props.options.employees.map((e) => ({ value: e.id, label: `${e.full_name} · ${e.employee_no}` })));
const typeLabel = (t: string) => props.options.link_types.find((o) => o.value === t)?.label ?? t;

function linkLabel(l: Link): string {
    if (l.type === 'branch') return props.options.branches.find((b) => b.id === l.id)?.name ?? `#${l.id}`;
    if (l.type === 'employee') return props.options.employees.find((e) => e.id === l.id)?.full_name ?? `#${l.id}`;

    return `#${l.id}`;
}

function create() {
    editing.value = null;
    form.defaults({ name: '', branch_id: null, latitude: '', longitude: '', radius_m: 100, accuracy_threshold_m: '', address_note: '', is_active: true, links: [] });
    form.reset();
    form.clearErrors();
    geoError.value = null;
    open.value = true;
}

function edit(l: Location) {
    editing.value = l;
    form.defaults({ name: l.name, branch_id: l.branch_id, latitude: l.latitude, longitude: l.longitude, radius_m: l.radius_m, accuracy_threshold_m: l.accuracy_threshold_m ?? '', address_note: l.address_note ?? '', is_active: l.is_active, links: l.links.map((x) => ({ ...x })) });
    form.reset();
    form.clearErrors();
    geoError.value = null;
    open.value = true;
}

function useMyLocation() {
    geoError.value = null;
    if (!('geolocation' in navigator) || !window.isSecureContext) {
        geoError.value = 'attendance.location.geo_unsupported';

        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (p) => {
            form.latitude = p.coords.latitude.toFixed(7);
            form.longitude = p.coords.longitude.toFixed(7);
            locating.value = false;
        },
        (e) => {
            geoError.value = e.code === 1 ? 'attendance.location.geo_denied' : 'attendance.location.geo_failed';
            locating.value = false;
        },
        { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 },
    );
}

function addLink() {
    if (newLink.value.id === null || newLink.value.id === '') return;
    const id = Number(newLink.value.id);
    if (!form.links.some((l) => l.type === newLink.value.type && l.id === id)) form.links.push({ type: newLink.value.type, id });
    newLink.value.id = null;
}

function save() {
    form.transform((d) => ({ ...d, branch_id: d.branch_id, accuracy_threshold_m: d.accuracy_threshold_m === '' ? null : d.accuracy_threshold_m, address_note: d.address_note || null }));
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`/attendance/locations/${editing.value.id}`, opts);
    else form.post('/attendance/locations', opts);
}
</script>

<template>
    <Head :title="$t('attendance.pages.locations')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.locations', href: '/attendance/locations' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('attendance.pages.locations')" :description="$t('attendance.location.description')">
                <template #actions>
                    <Button v-if="can.manage" @click="create"><Plus class="size-4" />{{ $t('common.actions.add') }}</Button>
                </template>
            </PageHeader>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-testid="locations-table">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('attendance.location.name') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.location.branch') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.location.coordinates') }}</th>
                            <th class="px-3 py-2 text-right">{{ $t('attendance.location.radius') }}</th>
                            <th class="px-3 py-2">{{ $t('attendance.location.links') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in locations" :key="l.id" class="border-t">
                            <td class="px-3 py-2">{{ l.name }}<p v-if="l.address_note" class="text-xs text-muted-foreground">{{ l.address_note }}</p></td>
                            <td class="px-3 py-2">{{ options.branches.find((b) => b.id === l.branch_id)?.name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ l.latitude }}, {{ l.longitude }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ l.radius_m }} m</td>
                            <td class="px-3 py-2 text-xs">{{ l.links.length ? l.links.map((x) => `${typeLabel(x.type)}: ${linkLabel(x)}`).join('; ') : '—' }}</td>
                            <td class="px-3 py-2"><StatusBadge :status="l.is_active ? 'active' : 'left'" :label="l.is_active ? $t('common.active') : $t('common.inactive')" /></td>
                            <td class="px-3 py-2 text-right"><Button v-if="can.manage" size="sm" variant="outline" @click="edit(l)">{{ $t('common.actions.edit') }}</Button></td>
                        </tr>
                        <tr v-if="!locations.length"><td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-muted-foreground">{{ $t('attendance.location.no_map') }}</p>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[92vh] max-w-2xl overflow-y-auto sm:max-w-2xl">
                <DialogHeader><DialogTitle>{{ editing ? $t('common.actions.edit') : $t('common.actions.add') }} · {{ $t('attendance.pages.locations') }}</DialogTitle></DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.location.name')" :error="form.errors.name" required><Input v-model="form.name" /></FormField>
                        <FormField :label="$t('attendance.location.branch')" :error="form.errors.branch_id"><SelectInput v-model="form.branch_id" :options="branchOptions" /></FormField>
                        <FormField :label="$t('attendance.location.latitude')" :error="form.errors.latitude" required><Input v-model="form.latitude" type="number" step="0.0000001" min="-90" max="90" /></FormField>
                        <FormField :label="$t('attendance.location.longitude')" :error="form.errors.longitude" required><Input v-model="form.longitude" type="number" step="0.0000001" min="-180" max="180" /></FormField>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" size="sm" :disabled="locating" data-testid="use-my-location" @click="useMyLocation"><Crosshair class="size-4" />{{ locating ? $t('attendance.checkin_page.locating') : $t('attendance.location.use_my_location') }}</Button>
                        <span v-if="geoError" class="text-xs text-red-600">{{ $t(geoError) }}</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField :label="$t('attendance.location.radius')" :error="form.errors.radius_m" :hint="$t('attendance.location.radius_hint')" required><Input v-model="form.radius_m" type="number" min="20" max="5000" /></FormField>
                        <FormField :label="$t('attendance.location.accuracy')" :error="form.errors.accuracy_threshold_m" :hint="$t('attendance.location.accuracy_hint')"><Input v-model="form.accuracy_threshold_m" type="number" min="1" max="5000" /></FormField>
                    </div>
                    <FormField :label="$t('attendance.location.address_note')" :error="form.errors.address_note"><Input v-model="form.address_note" maxlength="255" /></FormField>

                    <fieldset class="space-y-2 rounded-md border p-3">
                        <legend class="px-1 text-sm font-medium">{{ $t('attendance.location.links') }}</legend>
                        <p class="text-xs text-muted-foreground">{{ $t('attendance.location.links_hint') }}</p>
                        <ul class="flex flex-wrap gap-1">
                            <li v-for="(l, i) in form.links" :key="`${l.type}-${l.id}`" class="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs">
                                {{ typeLabel(l.type) }}: {{ linkLabel(l) }}
                                <button type="button" :aria-label="$t('common.actions.delete')" @click="form.links.splice(i, 1)"><X class="size-3" /></button>
                            </li>
                        </ul>
                        <div class="flex flex-wrap items-end gap-2">
                            <SelectInput v-model="newLink.type" class="w-44" :options="options.link_types" :nullable="false" @update:model-value="newLink.id = null" />
                            <SelectInput v-if="newLink.type === 'branch'" v-model="newLink.id" class="w-56" :options="branchOptions" />
                            <SelectInput v-else-if="newLink.type === 'employee'" v-model="newLink.id" class="w-56" :options="employeeOptions" />
                            <Input v-else v-model="newLink.id" type="number" min="1" class="w-32" :placeholder="$t('attendance.location.link_id')" />
                            <Button type="button" size="sm" variant="outline" @click="addLink">{{ $t('common.actions.add') }}</Button>
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
