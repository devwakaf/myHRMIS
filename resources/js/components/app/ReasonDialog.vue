<script setup lang="ts">
import { ref, watch } from 'vue';
import TextArea from '@/components/app/TextArea.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const open = defineModel<boolean>('open', { default: false });

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmLabel?: string;
        required?: boolean;
        destructive?: boolean;
        processing?: boolean;
    }>(),
    { required: true, destructive: false, processing: false },
);

const emit = defineEmits<{ confirm: [reason: string] }>();
const reason = ref('');

watch(open, (value) => {
    if (value) {
        reason.value = '';
    }
});

function confirm() {
    if (props.required && reason.value.trim() === '') {
        return;
    }
    emit('confirm', reason.value.trim());
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{ description }}</DialogDescription>
            </DialogHeader>
            <div class="grid gap-2">
                <label class="text-sm font-medium" for="reason-input">
                    {{ $t('common.fields.reason') }}<span v-if="required" class="text-red-600">*</span>
                </label>
                <TextArea id="reason-input" v-model="reason" :rows="3" />
                <slot />
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" type="button" @click="open = false">{{ $t('common.actions.back') }}</Button>
                <Button
                    type="button"
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="processing || (required && reason.trim() === '')"
                    @click="confirm"
                >
                    {{ confirmLabel ?? $t('common.actions.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
