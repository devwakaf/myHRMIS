<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Lock, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CheckField from '@/components/app/CheckField.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TabNav from '@/components/app/TabNav.vue';
import TextArea from '@/components/app/TextArea.vue';
import FormulaEditor from '@/components/payroll/FormulaEditor.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option } from '@/types';

type Component = {
    id: number;
    code: string;
    name_ms: string;
    name_en: string;
    kind: string;
    kind_label: string;
    calculation: string;
    statutory_key: string | null;
    default_amount: string | null;
    default_formula: string | null;
    default_condition: string | null;
    prorate: boolean;
    include_in_gross: boolean;
    show_on_slip: boolean;
    show_if_zero: boolean;
    epf_wage: boolean;
    socso_wage: boolean;
    eis_wage: boolean;
    hrdc_wage: boolean;
    taxable: boolean;
    pcb_type: string;
    tax_exemption_code: string | null;
    ea_box: string;
    is_epf_bonus: boolean;
    ot_wage_base: boolean;
    arrear_eligible: boolean;
    is_wage_reduction: boolean;
    arrear_component_id: number | null;
    rounding_mode: string;
    sort_order: number;
    is_system: boolean;
    is_active: boolean;
    used: boolean;
    description_ms: string | null;
    description_en: string | null;
};

const props = defineProps<{
    components: Component[];
    options: {
        kinds: Option<string>[];
        methods: Option<string>[];
        statutory_keys: Option<string>[];
        pcb_types: Option<string>[];
        ea_boxes: Option<string>[];
        rounding_modes: Option<string>[];
        functions: string[];
        variables: string[];
    };
    can: { manage: boolean };
}>();

const kindTab = ref('earning');
const kindTabs = computed(() => props.options.kinds.map((k) => ({ key: String(k.value), label: k.label })));
const shown = computed(() => props.components.filter((c) => c.kind === kindTab.value));
const codes = computed(() => props.components.map((c) => c.code));

const open = ref(false);
const editing = ref<Component | null>(null);
const dialogTab = ref('info');

const blank = {
    code: '',
    name_ms: '',
    name_en: '',
    kind: 'earning',
    calculation: 'fixed',
    statutory_key: null as string | null,
    default_amount: '' as string | number | null,
    default_formula: '' as string | null,
    default_condition: '' as string | null,
    prorate: false,
    include_in_gross: true,
    show_on_slip: true,
    show_if_zero: false,
    epf_wage: false,
    socso_wage: false,
    eis_wage: false,
    hrdc_wage: false,
    taxable: false,
    pcb_type: 'normal',
    tax_exemption_code: '' as string | null,
    ea_box: 'none',
    is_epf_bonus: false,
    ot_wage_base: false,
    arrear_eligible: false,
    is_wage_reduction: false,
    arrear_component_id: null as number | null,
    rounding_mode: 'half_up_2dp',
    sort_order: 100,
    description_ms: '' as string | null,
    description_en: '' as string | null,
    is_active: true,
};
const form = useForm({ ...blank });

const dialogTabs = computed(() => [
    { key: 'info', label: 'payroll.components.tabs.info' },
    { key: 'statutory', label: 'payroll.components.tabs.statutory', hidden: form.kind !== 'earning' && form.kind !== 'deduction' },
    { key: 'display', label: 'payroll.components.tabs.display' },
]);

function startNew() {
    editing.value = null;
    form.defaults({ ...blank, kind: kindTab.value }).reset();
    form.clearErrors();
    dialogTab.value = 'info';
    open.value = true;
}

function startEdit(c: Component) {
    editing.value = c;
    form.defaults({
        code: c.code,
        name_ms: c.name_ms,
        name_en: c.name_en,
        kind: c.kind,
        calculation: c.calculation,
        statutory_key: c.statutory_key,
        default_amount: c.default_amount,
        default_formula: c.default_formula ?? '',
        default_condition: c.default_condition ?? '',
        prorate: c.prorate,
        include_in_gross: c.include_in_gross,
        show_on_slip: c.show_on_slip,
        show_if_zero: c.show_if_zero,
        epf_wage: c.epf_wage,
        socso_wage: c.socso_wage,
        eis_wage: c.eis_wage,
        hrdc_wage: c.hrdc_wage,
        taxable: c.taxable,
        pcb_type: c.pcb_type,
        tax_exemption_code: c.tax_exemption_code ?? '',
        ea_box: c.ea_box,
        is_epf_bonus: c.is_epf_bonus,
        ot_wage_base: c.ot_wage_base,
        arrear_eligible: c.arrear_eligible,
        is_wage_reduction: c.is_wage_reduction,
        arrear_component_id: c.arrear_component_id,
        rounding_mode: c.rounding_mode,
        sort_order: c.sort_order,
        description_ms: c.description_ms ?? '',
        description_en: c.description_en ?? '',
        is_active: c.is_active,
    }).reset();
    form.clearErrors();
    dialogTab.value = 'info';
    open.value = true;
}

const lockedKind = computed(() => editing.value?.is_system === true);
const lockedCode = computed(() => editing.value?.used === true);
const isFormula = computed(() => form.calculation === 'formula');
const isStatutory = computed(() => form.calculation === 'statutory');
const isEarning = computed(() => form.kind === 'earning');

function save() {
    const url = editing.value ? `/payroll/components/${editing.value.id}` : '/payroll/components';
    form.transform((d) => ({
        ...d,
        default_amount: d.default_amount === '' ? null : d.default_amount,
        default_formula: d.calculation === 'formula' ? d.default_formula || null : null,
        default_condition: d.default_condition || null,
        tax_exemption_code: d.tax_exemption_code || null,
        statutory_key: d.calculation === 'statutory' ? d.statutory_key : null,
        description_ms: d.description_ms || null,
        description_en: d.description_en || null,
    }))[editing.value ? 'put' : 'post'](url, { preserveScroll: true, onSuccess: () => (open.value = false) });
}

function flags(c: Component): string[] {
    return [c.epf_wage && 'KWSP', c.socso_wage && 'PERKESO', c.eis_wage && 'SIP', c.hrdc_wage && 'HRD', c.taxable && 'PCB'].filter(Boolean) as string[];
}

function toggleActive(c: Component) {
    router.put(`/payroll/components/${c.id}`, { ...c, is_active: !c.is_active }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('payroll.pages.components')" />
    <AppLayout :breadcrumbs="[{ title: 'payroll.pages.components', href: '/payroll/components' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('payroll.pages.components')" :description="$t('payroll.components.description')">
                <template #actions>
                    <Button v-if="can.manage" data-testid="component-new" @click="startNew"><Plus class="size-4" />{{ $t('payroll.components.new') }}</Button>
                </template>
            </PageHeader>

            <TabNav v-model="kindTab" :tabs="kindTabs" />

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('payroll.fields.code') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.components.method') }}</th>
                            <th class="px-3 py-2">{{ $t('payroll.components.basis') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in shown" :key="c.id" class="border-t" :class="c.is_active ? '' : 'opacity-60'">
                            <td class="px-3 py-2 font-mono text-xs">
                                <Lock v-if="c.is_system" class="mr-1 inline size-3 text-muted-foreground" :aria-label="$t('payroll.components.system')" />{{ c.code }}
                            </td>
                            <td class="px-3 py-2">
                                {{ c.name_ms }}<span class="block text-xs text-muted-foreground">{{ c.name_en }}</span>
                            </td>
                            <td class="px-3 py-2">
                                {{ options.methods.find((m) => m.value === c.calculation)?.label }}
                                <span v-if="c.default_formula" class="block max-w-xs truncate font-mono text-xs text-muted-foreground" :title="c.default_formula">{{ c.default_formula }}</span>
                                <span v-else-if="c.statutory_key" class="block text-xs text-muted-foreground">{{ options.statutory_keys.find((k) => k.value === c.statutory_key)?.label }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <span v-for="f in flags(c)" :key="f" class="mr-1 rounded bg-muted px-1.5 py-0.5 text-xs">{{ f }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <StatusBadge :status="c.is_active ? 'active' : 'removed'" :label="c.is_active ? $t('common.active') : $t('common.inactive')" />
                            </td>
                            <td class="space-x-2 px-3 py-2 text-right">
                                <Button v-if="can.manage" size="sm" variant="outline" @click="startEdit(c)">{{ $t('common.actions.edit') }}</Button>
                                <Button v-if="can.manage && !c.is_system" size="sm" variant="ghost" @click="toggleActive(c)">
                                    {{ c.is_active ? $t('payroll.components.deactivate') : $t('payroll.components.activate') }}
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="!shown.length">
                            <td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing ? $t('payroll.components.edit') : $t('payroll.components.new') }}</DialogTitle>
                </DialogHeader>
                <TabNav v-model="dialogTab" :tabs="dialogTabs.map((t) => ({ key: t.key, label: $t(t.label), hidden: t.hidden }))" />
                <form class="grid gap-3" @submit.prevent="save">
                    <div v-show="dialogTab === 'info'" class="grid gap-3">
                        <p v-if="lockedKind" class="flex items-center gap-1 text-xs text-muted-foreground"><Lock class="size-3" />{{ $t('payroll.components.system_locked') }}</p>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField :label="$t('payroll.fields.code')" :error="form.errors.code" :hint="lockedCode ? $t('payroll.components.code_locked') : $t('payroll.components.code_hint')" required>
                                <Input v-model="form.code" :disabled="lockedCode" class="font-mono uppercase" />
                            </FormField>
                            <FormField :label="$t('payroll.components.kind')" :error="form.errors.kind" required>
                                <SelectInput v-model="form.kind" :options="options.kinds" :nullable="false" :disabled="lockedKind" />
                            </FormField>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField :label="$t('payroll.fields.name_ms')" :error="form.errors.name_ms" required><Input v-model="form.name_ms" /></FormField>
                            <FormField :label="$t('payroll.fields.name_en')" :error="form.errors.name_en" required><Input v-model="form.name_en" /></FormField>
                        </div>
                        <FormField :label="$t('payroll.components.method')" :error="form.errors.calculation" required>
                            <SelectInput v-model="form.calculation" :options="options.methods" :nullable="false" :disabled="lockedKind" />
                        </FormField>
                        <FormField v-if="isStatutory" :label="$t('payroll.components.statutory_key')" :error="form.errors.statutory_key" required>
                            <SelectInput v-model="form.statutory_key" :options="options.statutory_keys" :disabled="lockedKind" />
                        </FormField>
                        <FormField v-if="form.calculation === 'fixed' || form.calculation === 'input'" :label="$t('payroll.components.default_amount')" :error="form.errors.default_amount">
                            <Input v-model="form.default_amount" type="number" step="0.01" min="0" />
                        </FormField>
                        <FormField v-if="isFormula" :label="$t('payroll.components.formula')" :error="form.errors.default_formula" :hint="$t('payroll.components.formula_hint')">
                            <FormulaEditor v-model="form.default_formula" :variables="options.variables" :functions="options.functions" :codes="codes.filter((c) => c !== form.code)" :error="form.errors.default_formula" />
                        </FormField>
                        <FormField :label="$t('payroll.components.condition')" :error="form.errors.default_condition" :hint="$t('payroll.components.condition_hint')">
                            <FormulaEditor v-model="form.default_condition" :variables="options.variables" :functions="options.functions" :codes="codes" :rows="2" :error="form.errors.default_condition" :placeholder="$t('payroll.components.condition_placeholder')" />
                        </FormField>
                        <CheckField v-model="form.is_active" :label="$t('common.active')" />
                    </div>

                    <div v-show="dialogTab === 'statutory'" class="grid gap-3">
                        <p class="text-xs text-muted-foreground">{{ $t('payroll.components.statutory_help') }}</p>
                        <div class="grid grid-cols-2 gap-2">
                            <CheckField v-model="form.epf_wage" :label="$t('payroll.components.epf_wage')" :disabled="!isEarning && !form.is_wage_reduction" />
                            <CheckField v-model="form.socso_wage" :label="$t('payroll.components.socso_wage')" :disabled="!isEarning && !form.is_wage_reduction" />
                            <CheckField v-model="form.eis_wage" :label="$t('payroll.components.eis_wage')" :disabled="!isEarning && !form.is_wage_reduction" />
                            <CheckField v-model="form.hrdc_wage" :label="$t('payroll.components.hrdc_wage')" :disabled="!isEarning && !form.is_wage_reduction" />
                            <CheckField v-model="form.taxable" :label="$t('payroll.components.taxable')" :disabled="!isEarning && !form.is_wage_reduction" />
                            <CheckField v-model="form.is_epf_bonus" :label="$t('payroll.components.is_epf_bonus')" :disabled="!isEarning" />
                            <CheckField v-model="form.ot_wage_base" :label="$t('payroll.components.ot_wage_base')" :disabled="!isEarning" />
                            <CheckField v-model="form.arrear_eligible" :label="$t('payroll.components.arrear_eligible')" :disabled="!isEarning" />
                            <CheckField v-model="form.is_wage_reduction" :label="$t('payroll.components.is_wage_reduction')" :disabled="form.kind !== 'deduction'" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField :label="$t('payroll.components.pcb_type')" :error="form.errors.pcb_type"><SelectInput v-model="form.pcb_type" :options="options.pcb_types" :nullable="false" /></FormField>
                            <FormField :label="$t('payroll.components.tax_exemption_code')" :error="form.errors.tax_exemption_code"><Input v-model="form.tax_exemption_code" class="font-mono" /></FormField>
                        </div>
                        <FormField :label="$t('payroll.components.ea_box')" :error="form.errors.ea_box"><SelectInput v-model="form.ea_box" :options="options.ea_boxes" :nullable="false" /></FormField>
                        <p v-if="form.errors.epf_wage || form.errors.taxable" class="text-xs text-red-600">{{ form.errors.epf_wage ?? form.errors.taxable }}</p>
                    </div>

                    <div v-show="dialogTab === 'display'" class="grid gap-3">
                        <div class="grid grid-cols-2 gap-2">
                            <CheckField v-model="form.show_on_slip" :label="$t('payroll.components.show_on_slip')" />
                            <CheckField v-model="form.show_if_zero" :label="$t('payroll.components.show_if_zero')" />
                            <CheckField v-model="form.prorate" :label="$t('payroll.components.prorate')" :hint="$t('payroll.components.prorate_hint')" />
                            <CheckField v-model="form.include_in_gross" :label="$t('payroll.components.include_in_gross')" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField :label="$t('payroll.components.rounding_mode')" :error="form.errors.rounding_mode"><SelectInput v-model="form.rounding_mode" :options="options.rounding_modes" :nullable="false" /></FormField>
                            <FormField :label="$t('payroll.fields.sort_order')" :error="form.errors.sort_order"><Input v-model="form.sort_order" type="number" step="1" /></FormField>
                        </div>
                        <FormField :label="$t('payroll.components.description_ms')" :error="form.errors.description_ms"><TextArea v-model="form.description_ms" :rows="2" /></FormField>
                        <FormField :label="$t('payroll.components.description_en')" :error="form.errors.description_en"><TextArea v-model="form.description_en" :rows="2" /></FormField>
                    </div>

                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing" data-testid="component-save">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
