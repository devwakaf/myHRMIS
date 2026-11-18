<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Paperclip } from 'lucide-vue-next';
import { ref } from 'vue';
import ApprovalTimeline from '@/components/app/ApprovalTimeline.vue';
import type { TimelineData } from '@/components/app/ApprovalTimeline.vue';
import DetailList from '@/components/app/DetailList.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ReasonDialog from '@/components/app/ReasonDialog.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextArea from '@/components/app/TextArea.vue';
import DayBreakdown from '@/components/leave/DayBreakdown.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';
import type { ApplicationRow, DayRow } from '@/lib/leave';

type Attachment = { id: number; kind: string; kind_label: string; original_name: string; size_bytes: number; download_url: string | null };
type Detail = ApplicationRow & {
    reason: string | null;
    contact_during_leave: string | null;
    handover_note: string | null;
    balance_at_submit: string | null;
    block_list_acknowledged: boolean;
    event_date: string | null;
    cancel_reason: string | null;
    amended_from_id: number | null;
    days: DayRow[];
    attachments: Attachment[];
};

const props = defineProps<{
    application: Detail;
    timeline: TimelineData | null;
    approval_request_id: number | null;
    can: { update: boolean; submit: boolean; withdraw: boolean; cancel: boolean; add_attachment: boolean };
}>();

const a = props.application;
const dialog = ref<null | 'withdraw' | 'cancel' | 'cancel_copy'>(null);
const editOpen = ref(false);
const fileOpen = ref(false);
const kinds = ['mc', 'hospital_letter', 'other'].map((k) => ({ value: k, label: `leave.enums.attachment_kind.${k}` }));

const edit = useForm({
    leave_type_id: a.leave_type_id,
    from_date: a.from_date,
    to_date: a.to_date,
    half_day_date: a.half_day_date ?? '',
    half_day_session: a.half_day_session ?? 'am',
    event_date: a.event_date ?? '',
    reason: a.reason ?? '',
    contact_during_leave: a.contact_during_leave ?? '',
    handover_note: a.handover_note ?? '',
});
const upload = useForm({ file: null as File | null, kind: 'mc', mc_serial_no: '', issuer_name: '' });

function saveEdit(submit: boolean) {
    edit.transform((d) => ({ ...d, submit, half_day_date: d.half_day_date || null, event_date: d.event_date || null })).put(`/leave/applications/${a.id}`, {
        preserveScroll: true,
        onSuccess: () => (editOpen.value = false),
    });
}

function sendAttachment() {
    upload.post(`/leave/applications/${a.id}/attachments`, { forceFormData: true, preserveScroll: true, onSuccess: () => { fileOpen.value = false; upload.reset(); } });
}

function confirmReason(reason: string) {
    const url = { withdraw: 'withdraw', cancel: 'cancel', cancel_copy: 'cancel-copy' }[dialog.value ?? 'cancel'];
    router.post(`/leave/applications/${a.id}/${url}`, { reason }, { preserveScroll: true });
    dialog.value = null;
}

function submitDraft() {
    router.post(`/leave/applications/${a.id}/submit`, {}, { preserveScroll: true });
}

function destroyDraft() {
    router.delete(`/leave/applications/${a.id}`);
}

function kb(size: number): string {
    return `${Math.max(1, Math.round(size / 1024))} KB`;
}
</script>

<template>
    <Head :title="`${$t('leave.pages.application')} ${a.number ?? ''}`" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.me', href: '/leave/me' }, { title: 'leave.pages.application', href: `/leave/applications/${a.id}` }]">
        <div class="space-y-6 p-4 md:p-6">
            <PageHeader :title="`${a.leave_type} · ${a.number ?? '#' + a.id}`" :description="`${a.employee} (${a.employee_no})`">
                <template #meta><StatusBadge :status="a.status" :label="a.status_label" /></template>
                <template #actions>
                    <Button v-if="can.update" variant="outline" @click="editOpen = true">{{ $t('common.actions.edit') }}</Button>
                    <Button v-if="can.submit" @click="submitDraft">{{ $t('leave.apply.submit') }}</Button>
                    <Button v-if="can.update" variant="outline" @click="destroyDraft">{{ $t('common.actions.delete') }}</Button>
                    <Button v-if="can.withdraw" variant="outline" @click="dialog = 'withdraw'">{{ $t('leave.actions.withdraw') }}</Button>
                    <Button v-if="can.cancel" variant="outline" @click="dialog = 'cancel'">{{ $t('leave.actions.cancel_approved') }}</Button>
                    <Button v-if="can.withdraw || can.cancel" variant="outline" @click="dialog = 'cancel_copy'">{{ $t('common.actions.cancel_duplicate') }}</Button>
                </template>
            </PageHeader>

            <div class="rounded-lg border p-4">
                <DetailList
                    :items="[
                        { label: $t('leave.fields.from_date'), value: formatDate(a.from_date) },
                        { label: $t('leave.fields.to_date'), value: formatDate(a.to_date) },
                        { label: $t('leave.apply.deducted_days'), value: days(a.total_days) },
                        { label: $t('leave.apply.unpaid_days'), value: days(a.unpaid_days) },
                        { label: $t('leave.apply.calendar_days'), value: a.calendar_days },
                        { label: $t('leave.fields.balance_at_submit'), value: a.balance_at_submit === null ? null : days(a.balance_at_submit), hidden: a.balance_at_submit === null },
                        { label: $t('leave.fields.event_date'), value: formatDate(a.event_date), hidden: !a.event_date },
                        { label: $t('leave.fields.half_day'), value: a.half_day_date ? `${formatDate(a.half_day_date)} · ${$t('leave.enums.day_session.' + (a.half_day_session ?? 'am'))}` : null, hidden: !a.half_day_date },
                        { label: $t('leave.fields.contact_during_leave'), value: a.contact_during_leave },
                        { label: $t('leave.fields.handover_note'), value: a.handover_note },
                        { label: $t('leave.fields.submitted_at'), value: formatDateTime(a.submitted_at) },
                        { label: $t('leave.fields.flags'), value: [a.is_on_behalf ? $t('leave.flags.on_behalf') : '', a.is_backdated ? $t('leave.flags.backdated') : ''].filter(Boolean).join(', ') },
                    ]"
                />
                <p v-if="a.reason" class="mt-3 text-sm"><span class="text-xs text-muted-foreground">{{ $t('leave.fields.reason') }}</span><br />{{ a.reason }}</p>
                <p v-if="a.cancel_reason" class="mt-3 text-sm text-red-700 dark:text-red-300">{{ $t('leave.fields.cancel_reason') }}: {{ a.cancel_reason }}</p>
                <p v-if="a.amended_from_id" class="mt-2 text-xs text-muted-foreground">
                    {{ $t('leave.fields.amended_from') }}: <a :href="`/leave/applications/${a.amended_from_id}`" class="text-primary hover:underline">#{{ a.amended_from_id }}</a>
                </p>
            </div>

            <div v-if="a.days.length">
                <h2 class="mb-2 text-sm font-medium">{{ $t('leave.show.day_breakdown') }}</h2>
                <DayBreakdown :rows="a.days" />
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-sm font-medium">{{ $t('leave.show.attachments') }}</h2>
                    <Button v-if="can.add_attachment" size="sm" variant="outline" @click="fileOpen = true"><Paperclip class="size-4" />{{ $t('leave.show.add_attachment') }}</Button>
                </div>
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="f in a.attachments" :key="f.id" class="flex items-center justify-between gap-3 px-3 py-2">
                        <span>{{ f.kind_label }} · {{ f.original_name }} <span class="text-xs text-muted-foreground">{{ kb(f.size_bytes) }}</span></span>
                        <a v-if="f.download_url" :href="f.download_url" class="text-primary hover:underline">{{ $t('leave.show.download') }}</a>
                        <span v-else class="text-xs text-muted-foreground">{{ $t('leave.show.restricted') }}</span>
                    </li>
                    <li v-if="!a.attachments.length" class="px-3 py-4 text-center text-muted-foreground">{{ $t('common.empty') }}</li>
                </ul>
            </div>

            <div v-if="timeline">
                <h2 class="mb-2 text-sm font-medium">{{ $t('approval.inbox.timeline') }}</h2>
                <ApprovalTimeline :timeline="timeline" />
            </div>
        </div>

        <ReasonDialog
            :open="dialog !== null"
            :title="$t(dialog === 'withdraw' ? 'leave.actions.withdraw' : dialog === 'cancel' ? 'leave.actions.cancel_approved' : 'common.actions.cancel_duplicate')"
            :destructive="true"
            @update:open="(v: boolean) => { if (!v) dialog = null }"
            @confirm="confirmReason"
        />

        <Dialog v-model:open="editOpen">
            <DialogContent class="max-w-lg">
                <DialogHeader><DialogTitle>{{ $t('common.actions.edit') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="saveEdit(false)">
                    <div class="grid grid-cols-2 gap-3">
                        <FormField :label="$t('leave.fields.from_date')" :error="edit.errors.from_date"><Input v-model="edit.from_date" type="date" /></FormField>
                        <FormField :label="$t('leave.fields.to_date')" :error="edit.errors.to_date"><Input v-model="edit.to_date" type="date" /></FormField>
                    </div>
                    <FormField :label="$t('leave.fields.reason')" :error="edit.errors.reason"><TextArea v-model="edit.reason" /></FormField>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="editOpen = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" variant="outline" :disabled="edit.processing">{{ $t('leave.apply.save_draft') }}</Button>
                        <Button type="button" :disabled="edit.processing" @click="saveEdit(true)">{{ $t('leave.apply.submit') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="fileOpen">
            <DialogContent class="max-w-md">
                <DialogHeader><DialogTitle>{{ $t('leave.show.add_attachment') }}</DialogTitle></DialogHeader>
                <form class="grid gap-3" @submit.prevent="sendAttachment">
                    <FormField :label="$t('leave.show.file')" :error="upload.errors.file" required>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="text-sm" @change="(e: Event) => (upload.file = (e.target as HTMLInputElement).files?.[0] ?? null)" />
                    </FormField>
                    <FormField :label="$t('leave.show.kind')" :error="upload.errors.kind">
                        <SelectInput v-model="upload.kind" :options="kinds.map((k) => ({ value: k.value, label: $t(k.label) }))" :nullable="false" />
                    </FormField>
                    <template v-if="upload.kind === 'mc'">
                        <FormField :label="$t('leave.show.mc_serial_no')" :error="upload.errors.mc_serial_no"><Input v-model="upload.mc_serial_no" /></FormField>
                        <FormField :label="$t('leave.show.issuer_name')" :error="upload.errors.issuer_name"><Input v-model="upload.issuer_name" /></FormField>
                    </template>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="fileOpen = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="upload.processing || !upload.file">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
