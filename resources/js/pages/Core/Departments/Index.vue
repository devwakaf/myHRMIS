<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ChevronDown, ChevronRight, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Dept = {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    name_en: string | null;
    display_name: string;
    depth: number;
    path: string;
    cost_centre_code: string | null;
    head_employee_id: number | null;
    head_name: string | null;
    is_active: boolean;
    employee_count: number;
    approvers: Record<string, { value: number; label: string | null }[]>;
};

const props = defineProps<{
    departments: Dept[];
    employees: Option<number>[];
    users: Option<number>[];
    approvalTypes: Option<string>[];
    canManage: boolean;
}>();

const collapsed = ref<number[]>([]);
const selectedId = ref<number | null>(null);
const selected = computed(() => props.departments.find((d) => d.id === selectedId.value) ?? null);

const visible = computed(() =>
    props.departments.filter((d) => {
        const ancestors = d.path.split('/').filter(Boolean).map(Number).slice(0, -1);

        return !ancestors.some((a) => collapsed.value.includes(a));
    }),
);

function hasChildren(d: Dept) {
    return props.departments.some((x) => x.parent_id === d.id);
}

function toggle(d: Dept) {
    collapsed.value = collapsed.value.includes(d.id) ? collapsed.value.filter((x) => x !== d.id) : [...collapsed.value, d.id];
}

const form = useForm({
    parent_id: null as number | null,
    code: '',
    name: '',
    name_en: '',
    cost_centre_code: '',
    head_employee_id: null as number | null,
    is_active: true,
});

const approverForm = useForm({ approvers: {} as Record<string, number[]> });
const newApprover = ref<Record<string, number | null>>({});

function select(d: Dept) {
    selectedId.value = d.id;
    form.defaults({
        parent_id: d.parent_id,
        code: d.code,
        name: d.name,
        name_en: d.name_en ?? '',
        cost_centre_code: d.cost_centre_code ?? '',
        head_employee_id: d.head_employee_id,
        is_active: d.is_active,
    });
    form.reset();
    form.clearErrors();
    const approvers: Record<string, number[]> = {};
    for (const t of props.approvalTypes) {
        approvers[t.value] = (d.approvers[t.value] ?? []).map((a) => a.value);
    }
    approverForm.approvers = approvers;
}

function createChild(parent: Dept | null) {
    selectedId.value = null;
    form.defaults({ parent_id: parent?.id ?? props.departments[0]?.id ?? null, code: '', name: '', name_en: '', cost_centre_code: '', head_employee_id: null, is_active: true });
    form.reset();
    form.clearErrors();
}

function save() {
    const confirmMove = selected.value && form.parent_id !== selected.value.parent_id;
    if (confirmMove && !window.confirm(trans('core.department.confirm_move'))) {
        return;
    }
    form.transform((data) => ({ ...data, name_en: data.name_en || null, cost_centre_code: data.cost_centre_code || null }));
    if (selected.value) {
        form.put(`/departments/${selected.value.id}`, { preserveScroll: true });
    } else {
        form.post('/departments', { preserveScroll: true });
    }
}

function addApprover(type: string) {
    const id = newApprover.value[type];
    if (!id) return;
    const list = approverForm.approvers[type] ?? [];
    if (!list.includes(id)) approverForm.approvers[type] = [...list, id];
    newApprover.value[type] = null;
}

function moveApprover(type: string, index: number, delta: number) {
    const list = [...(approverForm.approvers[type] ?? [])];
    const target = index + delta;
    if (target < 0 || target >= list.length) return;
    [list[index], list[target]] = [list[target], list[index]];
    approverForm.approvers[type] = list;
}

function saveApprovers() {
    if (!selected.value) return;
    approverForm.put(`/departments/${selected.value.id}/approvers`, { preserveScroll: true, onSuccess: () => router.reload({ only: ['departments'] }) });
}

function userName(id: number) {
    return props.users.find((u) => u.value === id)?.label ?? `#${id}`;
}

const parentOptions = computed(() =>
    props.departments
        .filter((d) => d.is_active && (!selected.value || !d.path.startsWith(selected.value.path)))
        .map((d) => ({ value: d.id, label: `${'— '.repeat(d.depth)}${d.display_name}` })),
);
</script>

<template>
    <Head :title="$t('common.nav.departments')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.departments', href: '/departments' }]">
        <div class="grid gap-6 p-4 md:p-6 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <PageHeader :title="$t('common.nav.departments')" :description="$t('core.department.description')">
                    <template #actions>
                        <Button v-if="canManage" @click="createChild(selected)"><Plus class="size-4" />{{ $t('core.department.add') }}</Button>
                    </template>
                </PageHeader>
                <div class="rounded-lg border">
                    <div
                        v-for="d in visible"
                        :key="d.id"
                        class="flex cursor-pointer items-center gap-2 border-b px-3 py-2 text-sm last:border-b-0 hover:bg-accent/40"
                        :class="{ 'bg-accent/60': d.id === selectedId }"
                        :style="{ paddingLeft: `${12 + d.depth * 20}px` }"
                        @click="select(d)"
                    >
                        <button v-if="hasChildren(d)" type="button" class="text-muted-foreground" @click.stop="toggle(d)">
                            <ChevronRight v-if="collapsed.includes(d.id)" class="size-4" />
                            <ChevronDown v-else class="size-4" />
                        </button>
                        <span v-else class="w-4" />
                        <span class="font-mono text-xs text-muted-foreground">{{ d.code }}</span>
                        <span class="flex-1" :class="{ 'line-through opacity-60': !d.is_active }">{{ d.display_name }}</span>
                        <span v-if="d.head_name" class="hidden text-xs text-muted-foreground md:inline">{{ d.head_name }}</span>
                        <span class="rounded bg-muted px-1.5 text-xs">{{ d.employee_count }}</span>
                    </div>
                </div>
            </div>

            <div class="space-y-4 lg:col-span-2">
                <div class="rounded-lg border p-4">
                    <h2 class="mb-3 font-medium">{{ selected ? selected.display_name : $t('core.department.add') }}</h2>
                    <fieldset :disabled="!canManage" class="grid gap-3">
                        <FormField :label="$t('core.department.fields.parent_id')" :error="form.errors.parent_id" :required="!selected || selected.parent_id !== null">
                            <SelectInput v-model="form.parent_id" :options="parentOptions" :disabled="selected?.parent_id === null" />
                        </FormField>
                        <FormField :label="$t('core.masters.fields.code')" :error="form.errors.code" required>
                            <Input v-model="form.code" />
                        </FormField>
                        <FormField :label="$t('core.masters.fields.name')" :error="form.errors.name" required>
                            <Input v-model="form.name" />
                        </FormField>
                        <FormField :label="$t('core.masters.fields.name_en')" :error="form.errors.name_en">
                            <Input v-model="form.name_en" />
                        </FormField>
                        <FormField :label="$t('core.department.fields.cost_centre_code')" :error="form.errors.cost_centre_code">
                            <Input v-model="form.cost_centre_code" />
                        </FormField>
                        <FormField :label="$t('core.department.fields.head_employee_id')" :error="form.errors.head_employee_id" :hint="$t('core.department.head_hint')">
                            <SelectInput v-model="form.head_employee_id" :options="employees" />
                        </FormField>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> {{ $t('common.active') }}</label>
                        <p v-if="form.errors.is_active" class="text-xs text-red-600">{{ form.errors.is_active }}</p>
                        <Button v-if="canManage" :disabled="form.processing" @click="save">{{ $t('common.actions.save') }}</Button>
                    </fieldset>
                    <StatusBadge v-if="selected && !selected.is_active" class="mt-2" status="left" :label="$t('common.inactive')" />
                </div>

                <div v-if="selected" class="rounded-lg border p-4">
                    <h2 class="mb-1 font-medium">{{ $t('core.department.approvers') }}</h2>
                    <p class="mb-3 text-xs text-muted-foreground">{{ $t('core.department.approvers_hint') }}</p>
                    <div v-for="t in approvalTypes" :key="t.value" class="mb-3">
                        <p class="text-sm font-medium">{{ t.label }}</p>
                        <ol class="my-1 space-y-1 text-sm">
                            <li v-for="(id, i) in approverForm.approvers[t.value] ?? []" :key="id" class="flex items-center gap-2">
                                <span class="w-4 text-xs text-muted-foreground">{{ i + 1 }}.</span>
                                <span class="flex-1">{{ userName(id) }}</span>
                                <template v-if="canManage">
                                    <button type="button" class="text-xs" @click="moveApprover(t.value, i, -1)">↑</button>
                                    <button type="button" class="text-xs" @click="moveApprover(t.value, i, 1)">↓</button>
                                    <button type="button" class="text-xs text-red-600" @click="approverForm.approvers[t.value] = approverForm.approvers[t.value].filter((x) => x !== id)">✕</button>
                                </template>
                            </li>
                        </ol>
                        <div v-if="canManage" class="flex gap-2">
                            <SelectInput v-model="newApprover[t.value]" :options="users" class="h-8 text-xs" />
                            <Button size="sm" variant="outline" @click="addApprover(t.value)">{{ $t('common.actions.add') }}</Button>
                        </div>
                    </div>
                    <Button v-if="canManage" size="sm" :disabled="approverForm.processing" @click="saveApprovers">{{ $t('common.actions.save') }}</Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
