<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ChevronDown, ChevronRight, GripVertical, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import CheckField from '@/components/app/CheckField.vue';
import FormField from '@/components/app/FormField.vue';
import MoneyText from '@/components/app/MoneyText.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import FormulaEditor from '@/components/payroll/FormulaEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { postJson } from '@/lib/payroll';
import type { Option } from '@/types';

type ComponentOption = {
    id: number;
    code: string;
    name_ms: string;
    kind: string;
    calculation: string;
    default_amount: string | null;
    default_formula: string | null;
};
type StructureRow = {
    salary_component_id: number;
    code: string;
    name: string;
    kind: string;
    calculation_override: string | null;
    calculation: string;
    amount: string | null;
    formula: string | null;
    condition: string | null;
    prorate_override: boolean | null;
};
type DryRun = {
    lines: { code: string; name: string; kind: string; amount: string }[];
    gross: string;
    deductions: string;
    net: string;
    employer_total: string;
    warnings: string[];
};

const props = defineProps<{
    structure: { id: number; code: string; name_ms: string; name_en: string | null; description: string | null; is_active: boolean; revision: number; rows: StructureRow[] } | null;
    components: ComponentOption[];
    employees?: { id: number; employee_no: string; full_name: string }[];
    options: { functions: string[]; variables: string[]; kinds: Option<string>[] };
    can: { manage: boolean };
}>();

const KINDS = ['earning', 'deduction', 'employer_contribution', 'statistical'] as const;
const kindLabel = (k: string) => props.options.kinds.find((o) => o.value === k)?.label ?? k;

const form = useForm({
    code: props.structure?.code ?? '',
    name_ms: props.structure?.name_ms ?? '',
    name_en: props.structure?.name_en ?? '',
    description: props.structure?.description ?? '',
    is_active: props.structure?.is_active ?? true,
    test_employee_id: null as number | null,
    rows: [] as StructureRow[],
});
form.rows = (props.structure?.rows ?? []).map((r) => reactive({ ...r }));

const open = reactive<Record<string, boolean>>({ earning: true, deduction: true, employer_contribution: true, statistical: false });
const adding = reactive<Record<string, number | null>>({ earning: null, deduction: null, employer_contribution: null, statistical: null });
const dragFrom = ref<number | null>(null);

const codes = computed(() => props.components.map((c) => c.code));
const rowsOf = (kind: string) => form.rows.map((r, i) => ({ r, i })).filter((x) => x.r.kind === kind);
const available = (kind: string) => props.components.filter((c) => c.kind === kind && !form.rows.some((r) => r.salary_component_id === c.id));

function addRow(kind: string) {
    const id = adding[kind];
    const c = props.components.find((x) => x.id === id);
    if (!c) return;
    // keep rows grouped by kind: insert after the last row of the same kind
    const last = form.rows.map((r) => r.kind).lastIndexOf(kind);
    const row: StructureRow = reactive({
        salary_component_id: c.id,
        code: c.code,
        name: c.name_ms,
        kind: c.kind,
        calculation_override: null,
        calculation: c.calculation,
        amount: null,
        formula: null,
        condition: null,
        prorate_override: null,
    });
    form.rows.splice(last + 1, 0, row);
    adding[kind] = null;
}

function removeRow(i: number) {
    form.rows.splice(i, 1);
}

function swap(i: number, j: number) {
    if (j < 0 || j >= form.rows.length || form.rows[i].kind !== form.rows[j].kind) return;
    [form.rows[i], form.rows[j]] = [form.rows[j], form.rows[i]];
}

function drop(target: number) {
    const from = dragFrom.value;
    dragFrom.value = null;
    if (from === null || from === target || form.rows[from].kind !== form.rows[target].kind) return;
    const [moved] = form.rows.splice(from, 1);
    form.rows.splice(target, 0, moved);
}

function effective(r: StructureRow): string {
    return r.calculation_override ?? r.calculation;
}

function payloadRows() {
    return form.rows.map((r, i) => ({
        salary_component_id: r.salary_component_id,
        sort_order: (i + 1) * 10,
        calculation_override: r.calculation_override || null,
        amount: r.amount === '' ? null : r.amount,
        formula: effective(r) === 'formula' ? r.formula || null : null,
        condition: r.condition || null,
        prorate_override: r.prorate_override,
    }));
}

function save() {
    form.transform((d) => ({ ...d, rows: payloadRows() }))[props.structure ? 'put' : 'post'](props.structure ? `/payroll/structures/${props.structure.id}` : '/payroll/structures', { preserveScroll: true });
}

// ---- live validation + dry run (server side; nothing is calculated here)
const dry = ref<DryRun | null>(null);
const dryErrors = ref<string[]>([]);
const dryBusy = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let ticket = 0;

async function runDry() {
    const mine = ++ticket;
    dryBusy.value = true;
    try {
        const body = await postJson<{ ok: boolean; result?: DryRun; errors?: Record<string, string[]> }>('/payroll/structures/dry-run', {
            code: form.code || 'UJI',
            name_ms: form.name_ms || 'Uji',
            rows: payloadRows(),
            test_employee_id: form.test_employee_id,
        });
        if (mine !== ticket) return;
        if (body.ok && body.result) {
            dry.value = body.result;
            dryErrors.value = [];
        } else {
            dry.value = null;
            dryErrors.value = Object.values(body.errors ?? {}).flat();
        }
    } catch {
        dryErrors.value = [];
    } finally {
        if (mine === ticket) dryBusy.value = false;
    }
}

watch(
    () => [form.rows.map((r) => [r.salary_component_id, r.calculation_override, r.amount, r.formula, r.condition, r.prorate_override]), form.test_employee_id],
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(runDry, 700);
    },
    { deep: true },
);
if (form.rows.length) runDry();

const employeeOptions = computed<Option<number>[]>(() => (props.employees ?? []).map((e) => ({ value: e.id, label: `${e.employee_no} — ${e.full_name}` })));
const prorateOptions = computed<Option<string>[]>(() => [
    { value: 'yes', label: 'payroll.structures.prorate_yes' },
    { value: 'no', label: 'payroll.structures.prorate_no' },
]);
</script>

<template>
    <Head :title="structure ? $t('payroll.pages.structure_edit') : $t('payroll.structures.new')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.structures', href: '/payroll/structures' }, { title: 'payroll.pages.structure_edit', href: '#' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="structure ? `${structure.code} — ${structure.name_ms}` : $t('payroll.structures.new')" :description="$t('payroll.structures.editor_description')">
                <template #actions>
                    <Button variant="outline" as-child><Link href="/payroll/structures">{{ $t('common.actions.back') }}</Link></Button>
                    <Button v-if="can.manage" :disabled="form.processing" data-testid="structure-save" @click="save">{{ $t('common.actions.save') }}</Button>
                </template>
            </PageHeader>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="space-y-4">
                    <div class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2">
                        <FormField :label="$t('payroll.fields.code')" :error="form.errors.code" required><Input v-model="form.code" class="font-mono uppercase" :disabled="!can.manage" /></FormField>
                        <FormField :label="$t('payroll.fields.name_ms')" :error="form.errors.name_ms" required><Input v-model="form.name_ms" :disabled="!can.manage" /></FormField>
                        <FormField :label="$t('payroll.fields.name_en')" :error="form.errors.name_en"><Input v-model="form.name_en" :disabled="!can.manage" /></FormField>
                        <FormField :label="$t('payroll.structures.description_field')" :error="form.errors.description"><Input v-model="form.description" :disabled="!can.manage" /></FormField>
                        <CheckField v-model="form.is_active" :label="$t('common.active')" :disabled="!can.manage" />
                    </div>
                    <p v-if="form.errors.rows" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert" data-testid="structure-rows-error">{{ form.errors.rows }}</p>

                    <section v-for="kind in KINDS" :key="kind" class="rounded-lg border">
                        <button type="button" class="flex w-full items-center gap-2 border-b bg-muted/40 px-3 py-2 text-left text-sm font-semibold" @click="open[kind] = !open[kind]">
                            <component :is="open[kind] ? ChevronDown : ChevronRight" class="size-4" />{{ kindLabel(kind) }}
                            <span class="ml-1 rounded bg-background px-1.5 text-xs font-normal text-muted-foreground">{{ rowsOf(kind).length }}</span>
                        </button>
                        <div v-show="open[kind]" class="space-y-2 p-3">
                            <div
                                v-for="{ r, i } in rowsOf(kind)"
                                :key="r.salary_component_id"
                                class="rounded-md border bg-background p-3"
                                :draggable="can.manage"
                                @dragstart="dragFrom = i"
                                @dragover.prevent
                                @drop.prevent="drop(i)"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <GripVertical v-if="can.manage" class="size-4 cursor-grab text-muted-foreground" />
                                    <span class="font-mono text-xs">{{ r.code }}</span>
                                    <span class="text-sm font-medium">{{ r.name }}</span>
                                    <span class="ml-auto flex items-center gap-1">
                                        <Button v-if="can.manage" type="button" size="icon-sm" variant="ghost" :aria-label="$t('common.actions.move_up')" @click="swap(i, i - 1)"><ArrowUp class="size-4" /></Button>
                                        <Button v-if="can.manage" type="button" size="icon-sm" variant="ghost" :aria-label="$t('common.actions.move_down')" @click="swap(i, i + 1)"><ArrowDown class="size-4" /></Button>
                                        <Button v-if="can.manage" type="button" size="icon-sm" variant="ghost" :aria-label="$t('common.actions.delete')" @click="removeRow(i)"><Trash2 class="size-4" /></Button>
                                    </span>
                                </div>
                                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                    <FormField v-if="r.calculation !== 'statutory'" :label="$t('payroll.components.method')">
                                        <select v-model="r.calculation_override" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm" :disabled="!can.manage">
                                            <option :value="null">{{ $t('payroll.structures.method_default', { method: $t(`payroll.enums.calculation_method.${r.calculation}`) }) }}</option>
                                            <option value="fixed">{{ $t('payroll.enums.calculation_method.fixed') }}</option>
                                            <option value="formula">{{ $t('payroll.enums.calculation_method.formula') }}</option>
                                        </select>
                                    </FormField>
                                    <p v-else class="self-end text-xs text-muted-foreground">{{ $t('payroll.structures.statutory_row') }}</p>
                                    <FormField v-if="r.calculation !== 'statutory' && effective(r) !== 'formula'" :label="$t('payroll.fields.amount')" :error="form.errors[`rows.${i}.amount`]">
                                        <Input v-model="r.amount" type="number" step="0.01" min="0" :disabled="!can.manage" :placeholder="$t('payroll.structures.amount_placeholder')" />
                                    </FormField>
                                    <FormField v-if="r.calculation !== 'statutory'" :label="$t('payroll.structures.prorate')">
                                        <select
                                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                            :disabled="!can.manage"
                                            :value="r.prorate_override === null ? '' : r.prorate_override ? 'yes' : 'no'"
                                            @change="r.prorate_override = ($event.target as HTMLSelectElement).value === '' ? null : ($event.target as HTMLSelectElement).value === 'yes'"
                                        >
                                            <option value="">{{ $t('payroll.structures.prorate_default') }}</option>
                                            <option v-for="o in prorateOptions" :key="o.value" :value="o.value">{{ $t(o.label) }}</option>
                                        </select>
                                    </FormField>
                                </div>
                                <FormField v-if="r.calculation !== 'statutory' && effective(r) === 'formula'" :label="$t('payroll.components.formula')" :error="form.errors[`rows.${i}.formula`]" class="mt-2">
                                    <FormulaEditor v-model="r.formula" :variables="options.variables" :functions="options.functions" :codes="codes" :rows="2" :error="form.errors[`rows.${i}.formula`]" />
                                </FormField>
                                <FormField :label="$t('payroll.components.condition')" :error="form.errors[`rows.${i}.condition`]" class="mt-2">
                                    <Input v-model="r.condition" class="font-mono" :disabled="!can.manage" :placeholder="$t('payroll.components.condition_placeholder')" />
                                </FormField>
                            </div>
                            <p v-if="!rowsOf(kind).length" class="text-sm text-muted-foreground">{{ $t('payroll.structures.no_rows') }}</p>

                            <div v-if="can.manage && available(kind).length" class="flex items-end gap-2">
                                <div class="grid flex-1 gap-1">
                                    <SelectInput v-model="adding[kind]" :options="available(kind).map((c) => ({ value: c.id, label: `${c.code} — ${c.name_ms}` }))" :placeholder="$t('payroll.structures.add_component')" />
                                </div>
                                <Button type="button" variant="outline" :disabled="adding[kind] === null" @click="addRow(kind)">{{ $t('common.actions.add') }}</Button>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="space-y-3" data-testid="dry-run-panel">
                    <div class="sticky top-4 space-y-3 rounded-lg border p-4">
                        <h2 class="text-sm font-semibold">{{ $t('payroll.structures.sample_slip') }}</h2>
                        <p class="text-xs text-muted-foreground">{{ $t('payroll.structures.sample_hint') }}</p>
                        <FormField v-if="employeeOptions.length" :label="$t('payroll.structures.test_employee')">
                            <SelectInput v-model="form.test_employee_id" :options="employeeOptions" :placeholder="$t('payroll.structures.test_employee_none')" />
                        </FormField>
                        <p v-if="dryBusy" class="text-xs text-muted-foreground">{{ $t('payroll.structures.calculating') }}</p>
                        <ul v-if="dryErrors.length" class="list-disc space-y-1 pl-4 text-sm text-red-700 dark:text-red-300" data-testid="dry-run-errors">
                            <li v-for="(e, n) in dryErrors" :key="n">{{ e }}</li>
                        </ul>
                        <template v-if="dry">
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="l in dry.lines" :key="l.code" class="border-b last:border-0">
                                        <td class="py-1">{{ l.name }}</td>
                                        <td class="py-1 text-right"><MoneyText :value="l.amount" /></td>
                                    </tr>
                                </tbody>
                            </table>
                            <dl class="space-y-1 border-t pt-2 text-sm">
                                <div class="flex justify-between"><dt>{{ $t('payroll.slip.gross') }}</dt><dd><MoneyText :value="dry.gross" strong /></dd></div>
                                <div class="flex justify-between"><dt>{{ $t('payroll.slip.total_deductions') }}</dt><dd><MoneyText :value="dry.deductions" strong /></dd></div>
                                <div class="flex justify-between"><dt>{{ $t('payroll.slip.net') }}</dt><dd><MoneyText :value="dry.net" strong /></dd></div>
                                <div class="flex justify-between text-muted-foreground"><dt>{{ $t('payroll.slip.employer_box') }}</dt><dd><MoneyText :value="dry.employer_total" /></dd></div>
                            </dl>
                            <ul v-if="dry.warnings.length" class="list-disc space-y-1 pl-4 text-xs text-amber-800 dark:text-amber-300">
                                <li v-for="(w, n) in dry.warnings" :key="n">{{ w }}</li>
                            </ul>
                        </template>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
