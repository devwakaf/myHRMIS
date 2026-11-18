<script setup lang="ts">
import { CircleAlert, CircleCheck, FlaskConical } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postJson } from '@/lib/payroll';

/**
 * Formula / condition editor for Komponen Gaji and Struktur Gaji rows: a plain text box with
 * insert chips (variables, functions, component codes) and "Uji Formula", which parses and
 * evaluates the text on the server against sample values and answers in Bahasa Melayu.
 * Nothing is evaluated in the browser.
 */
const model = defineModel<string | null>({ default: '' });

const props = defineProps<{
    id?: string;
    variables: string[];
    functions: string[];
    codes?: string[];
    rows?: number;
    error?: string;
    placeholder?: string;
}>();

type TestResult = { ok: boolean; result?: string | boolean; references?: string[]; message?: string };

const area = ref<HTMLElement | null>(null);
const showHelp = ref(false);
const testing = ref(false);
const result = ref<TestResult | null>(null);
const samples = ref<Record<string, string>>({});

const userRefs = computed(() => (result.value?.references ?? []).filter((r) => !props.variables.includes(r)));

function insert(token: string) {
    const el = area.value?.querySelector('textarea') ?? (area.value as HTMLTextAreaElement | null);
    const current = model.value ?? '';
    if (el && 'selectionStart' in el) {
        const start = (el as HTMLTextAreaElement).selectionStart ?? current.length;
        const end = (el as HTMLTextAreaElement).selectionEnd ?? start;
        model.value = current.slice(0, start) + token + current.slice(end);
    } else {
        model.value = current + token;
    }
}

async function test() {
    if (!model.value?.trim()) {
        return;
    }
    testing.value = true;
    try {
        const values: Record<string, string> = {};
        for (const [k, v] of Object.entries(samples.value)) {
            if (v !== '') values[k] = v;
        }
        result.value = await postJson<TestResult>('/payroll/formula/test', { formula: model.value, values });
    } catch {
        result.value = { ok: false, message: 'Error' };
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <div class="grid gap-2" data-testid="formula-editor">
        <div ref="area">
            <TextArea :id="id" v-model="model" :rows="rows ?? 3" :placeholder="placeholder ?? $t('payroll.formula_editor.placeholder')" class="font-mono" spellcheck="false" />
        </div>
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>

        <div class="flex flex-wrap items-center gap-2">
            <Button type="button" size="sm" variant="outline" :disabled="testing || !model" data-testid="formula-test" @click="test"><FlaskConical class="size-4" />{{ $t('payroll.formula_editor.test') }}</Button>
            <button type="button" class="text-xs text-primary hover:underline" @click="showHelp = !showHelp">{{ showHelp ? $t('payroll.formula_editor.hide_help') : $t('payroll.formula_editor.show_help') }}</button>
        </div>

        <div v-if="showHelp" class="space-y-2 rounded-md border bg-muted/30 p-2 text-xs">
            <p class="text-muted-foreground">{{ $t('payroll.formula_editor.help') }}</p>
            <div>
                <span class="font-medium">{{ $t('payroll.formula_editor.variables') }}</span>
                <div class="mt-1 flex flex-wrap gap-1">
                    <button v-for="v in variables" :key="v" type="button" class="rounded border bg-background px-1.5 py-0.5 font-mono hover:bg-accent" @click="insert(v)">{{ v }}</button>
                </div>
            </div>
            <div>
                <span class="font-medium">{{ $t('payroll.formula_editor.functions') }}</span>
                <div class="mt-1 flex flex-wrap gap-1">
                    <button v-for="f in functions" :key="f" type="button" class="rounded border bg-background px-1.5 py-0.5 font-mono hover:bg-accent" @click="insert(`${f}(`)">{{ f }}()</button>
                </div>
            </div>
            <div v-if="codes?.length">
                <span class="font-medium">{{ $t('payroll.formula_editor.codes') }}</span>
                <div class="mt-1 flex flex-wrap gap-1">
                    <button v-for="c in codes" :key="c" type="button" class="rounded border bg-background px-1.5 py-0.5 font-mono hover:bg-accent" @click="insert(c)">{{ c }}</button>
                </div>
            </div>
        </div>

        <div v-if="result" class="rounded-md border px-3 py-2 text-sm" :class="result.ok ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950' : 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950'" data-testid="formula-result">
            <p v-if="result.ok" class="flex items-center gap-2"><CircleCheck class="size-4 text-emerald-600" />{{ $t('payroll.formula_editor.result') }}: <b class="font-mono">{{ String(result.result) }}</b></p>
            <p v-else class="flex items-start gap-2"><CircleAlert class="mt-0.5 size-4 shrink-0 text-red-600" /><span>{{ result.message }}</span></p>
            <div v-if="result.ok && userRefs.length" class="mt-2 grid gap-1">
                <p class="text-xs text-muted-foreground">{{ $t('payroll.formula_editor.sample_values') }}</p>
                <div class="flex flex-wrap gap-2">
                    <label v-for="r in userRefs" :key="r" class="grid gap-0.5 text-xs">
                        <span class="font-mono">{{ r }}</span>
                        <Input v-model="samples[r]" type="number" step="0.01" class="h-8 w-28" />
                    </label>
                    <Button type="button" size="sm" variant="outline" class="self-end" @click="test">{{ $t('payroll.formula_editor.retest') }}</Button>
                </div>
            </div>
        </div>
    </div>
</template>
