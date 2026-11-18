<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { LogIn, LogOut, MapPin } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatDate, formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { hhmm, hhmmss, isoOffsetMinutes, jsonRequest, localDateOf, localTimeOf } from '@/lib/attendance';
import type { CheckinRow } from '@/lib/attendance';

const props = defineProps<{
    server_now: string;
    employee: { id: number; employee_no: string; full_name: string } | null;
    shift: { code: string; name: string; time: string; date: string; window_start: string; window_end: string } | null;
    last_punch: CheckinRow | null;
    next_action: 'checkin' | 'checkout';
    geofence: { required: boolean; request_location: boolean };
    consent: { notice_version: number; retention_months: number; accepted: boolean; decided_at: string | null };
    checkin_required: boolean;
}>();

type Phase = 'idle' | 'locating' | 'sending';
type Result = { kind: 'success' | 'error'; title: string; lines: string[]; warning?: boolean; hint?: string };

const phase = ref<Phase>('idle');
const result = ref<Result | null>(null);
const resultOpen = ref(false);
const noticeDismissed = ref(false);
const consentBusy = ref(false);

// Display clock: the server time is authoritative, the browser only ticks the difference.
const offsetMinutes = isoOffsetMinutes(props.server_now);
const skew = Date.parse(props.server_now) - Date.now();
const nowMs = ref(Date.now() + skew);
let timer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    timer = setInterval(() => (nowMs.value = Date.now() + skew), 1000);
});
onBeforeUnmount(() => timer && clearInterval(timer));

const clock = computed(() => localTimeOf(nowMs.value, offsetMinutes));
const today = computed(() => localDateOf(nowMs.value, offsetMinutes));

const needsConsent = computed(() => props.geofence.required);
const noticeOpen = computed(() => needsConsent.value && !props.consent.accepted && !noticeDismissed.value);
const consentDeclined = computed(() => needsConsent.value && !props.consent.accepted && noticeDismissed.value);
const busy = computed(() => phase.value !== 'idle');
const buttonLabel = computed(() => (props.next_action === 'checkout' ? 'attendance.checkin_page.checkout' : 'attendance.checkin_page.checkin'));
const progress = computed(() => (phase.value === 'locating' ? 'attendance.checkin_page.locating' : 'attendance.checkin_page.sending'));

function acceptNotice() {
    consentBusy.value = true;
    router.post('/check-in/consent', { accepted: true }, { preserveScroll: true, onFinish: () => (consentBusy.value = false) });
}

function declineNotice() {
    consentBusy.value = true;
    router.post('/check-in/consent', { accepted: false }, {
        preserveScroll: true,
        onFinish: () => {
            consentBusy.value = false;
            noticeDismissed.value = true;
        },
    });
}

function withdraw() {
    router.post('/check-in/consent/withdraw', {}, { preserveScroll: true, onSuccess: () => (noticeDismissed.value = false) });
}

type Position = { latitude: number; longitude: number; accuracy: number };

function locate(): Promise<Position> {
    return new Promise((resolve, reject) => {
        if (!('geolocation' in navigator) || !window.isSecureContext) {
            reject(new Error('unsupported'));

            return;
        }
        navigator.geolocation.getCurrentPosition(
            (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }),
            (e) => reject(new Error(e.code === 1 ? 'denied' : e.code === 3 ? 'timeout' : 'unavailable')),
            { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 },
        );
    });
}

function show(r: Result) {
    result.value = r;
    resultOpen.value = true;
}

function uuid(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

type Answer = {
    message?: string;
    reason?: string;
    details?: { nearest_location?: string; distance_m?: number };
    duplicate?: boolean;
    server_time?: string;
    log_type?: string | null;
    location?: string | null;
    distance_m?: number | null;
    geofence_result?: string;
};

async function press() {
    if (busy.value) return;
    result.value = null;
    let position: Position | null = null;

    if (props.geofence.required) {
        phase.value = 'locating';
        try {
            position = await locate();
        } catch (e) {
            phase.value = 'idle';
            const code = e instanceof Error ? e.message : 'unavailable';
            show({ kind: 'error', title: `attendance.checkin_page.errors.${code}.title`, lines: [`attendance.checkin_page.errors.${code}.text`], hint: `attendance.checkin_page.errors.${code}.hint` });

            return;
        }
    }

    phase.value = 'sending';
    try {
        const { status, data } = await jsonRequest<Answer>('POST', '/check-in', {
            latitude: position?.latitude ?? null,
            longitude: position?.longitude ?? null,
            accuracy: position?.accuracy ?? null,
            client_time: new Date().toISOString(),
            client_request_id: uuid(),
            standalone: window.matchMedia?.('(display-mode: standalone)').matches ?? false,
        });
        handle(status, data);
    } catch {
        show({ kind: 'error', title: 'attendance.checkin_page.errors.network.title', lines: ['attendance.checkin_page.errors.network.text'], hint: 'attendance.checkin_page.errors.network.hint' });
    } finally {
        phase.value = 'idle';
    }
}

function handle(status: number, data: Answer) {
    if (status === 200 || status === 201) {
        const outside = data.geofence_result === 'outside_accepted';
        show({
            kind: 'success',
            title: data.duplicate ? 'attendance.checkin_page.duplicate' : data.log_type === 'out' ? 'attendance.checkin_page.recorded_out' : 'attendance.checkin_page.recorded_in',
            lines: [data.server_time ?? '', data.location ? `${data.location}${data.distance_m !== null && data.distance_m !== undefined ? ` · ${data.distance_m} m` : ''}` : ''],
            warning: outside,
            hint: outside ? 'attendance.checkin_page.outside_accepted' : undefined,
        });
        router.reload({ only: ['last_punch', 'next_action', 'shift'] });

        return;
    }
    if (status === 409) {
        noticeDismissed.value = false;
        router.reload({ only: ['consent'] });
        show({ kind: 'error', title: 'attendance.checkin_page.errors.consent_required.title', lines: [data.message ?? ''] });

        return;
    }
    const known = ['outside', 'low_accuracy', 'no_location', 'not_active', 'rate_limited'];
    const reason = known.includes(data.reason ?? '') ? (data.reason as string) : 'other';
    show({
        kind: 'error',
        title: `attendance.checkin_page.errors.${reason}.title`,
        lines: [data.message ?? ''],
        hint: `attendance.checkin_page.errors.${reason}.hint`,
    });
}
</script>

<template>
    <Head :title="$t('attendance.pages.checkin')" />
    <AppLayout :breadcrumbs="[{ title: 'attendance.pages.checkin', href: '/check-in' }]">
        <div class="mx-auto flex w-full max-w-md flex-col gap-5 p-4 md:p-6">
            <div class="rounded-2xl border bg-card p-6 text-center shadow-sm">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ $t('attendance.checkin_page.server_time') }}</p>
                <p class="mt-1 font-mono text-5xl font-semibold tabular-nums" data-testid="server-clock">{{ clock }}</p>
                <p class="mt-1 text-sm text-muted-foreground">{{ formatDate(today) }}</p>
                <p v-if="employee" class="mt-3 text-sm font-medium">{{ employee.full_name }} <span class="text-muted-foreground">{{ employee.employee_no }}</span></p>
            </div>

            <div class="rounded-xl border p-4 text-sm" data-testid="shift-card">
                <template v-if="shift">
                    <p class="text-xs text-muted-foreground">{{ $t('attendance.checkin_page.todays_shift') }}</p>
                    <p class="font-medium">{{ shift.name }} <span class="text-muted-foreground">({{ shift.code }})</span></p>
                    <p class="tabular-nums">{{ shift.time }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ $t('attendance.checkin_page.window', { from: hhmm(shift.window_start), to: hhmm(shift.window_end) }) }}</p>
                </template>
                <p v-else class="text-muted-foreground">{{ $t('attendance.checkin_page.no_shift') }}</p>
                <div class="mt-3 border-t pt-3">
                    <p class="text-xs text-muted-foreground">{{ $t('attendance.checkin_page.last_punch') }}</p>
                    <p v-if="last_punch" data-testid="last-punch">
                        {{ formatDateTime(last_punch.punched_at) }}
                        <span class="text-muted-foreground">· {{ last_punch.log_type ? $t(`attendance.enums.log_type.${last_punch.log_type}`) : '' }}<template v-if="last_punch.location"> · {{ last_punch.location }}</template></span>
                    </p>
                    <p v-else class="text-muted-foreground">{{ $t('attendance.checkin_page.no_punch') }}</p>
                </div>
            </div>

            <p v-if="!checkin_required" class="rounded-md bg-sky-50 p-3 text-xs text-sky-900 dark:bg-sky-950 dark:text-sky-100">{{ $t('attendance.checkin_page.not_required') }}</p>

            <div v-if="consentDeclined" class="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100" data-testid="consent-declined">
                <p>{{ $t('attendance.checkin_page.consent_declined') }}</p>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" @click="noticeDismissed = false">{{ $t('attendance.checkin_page.read_notice') }}</Button>
                    <Button size="sm" variant="outline" as-child><Link href="/attendance-requests">{{ $t('attendance.pages.requests') }}</Link></Button>
                </div>
            </div>

            <Button
                size="lg"
                class="h-28 w-full flex-col gap-1 rounded-2xl text-2xl"
                :variant="next_action === 'checkout' ? 'secondary' : 'default'"
                :disabled="busy || !employee || consentDeclined"
                data-testid="checkin-button"
                @click="press"
            >
                <component :is="next_action === 'checkout' ? LogOut : LogIn" class="!size-8" />
                <span>{{ busy ? $t(progress) : $t(buttonLabel) }}</span>
            </Button>

            <p class="flex items-start gap-2 text-xs text-muted-foreground">
                <MapPin class="mt-0.5 size-4 shrink-0" />
                <span>{{ geofence.required ? $t('attendance.checkin_page.location_hint') : $t('attendance.checkin_page.no_location_hint') }}</span>
            </p>

            <div class="flex flex-wrap justify-center gap-3 text-xs">
                <Link href="/me/attendance" class="text-primary hover:underline">{{ $t('attendance.pages.me') }}</Link>
                <Link href="/attendance-requests" class="text-primary hover:underline">{{ $t('attendance.pages.requests') }}</Link>
                <button v-if="needsConsent && consent.accepted" type="button" class="text-muted-foreground hover:underline" @click="withdraw">{{ $t('attendance.checkin_page.withdraw_consent') }}</button>
            </div>
        </div>

        <Dialog :open="noticeOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" :show-close-button="false" data-testid="privacy-notice" @interact-outside.prevent @escape-key-down.prevent>
                <DialogHeader>
                    <DialogTitle>{{ $t('attendance.notice.title') }}</DialogTitle>
                    <DialogDescription>{{ $t('attendance.notice.intro') }}</DialogDescription>
                </DialogHeader>
                <dl class="space-y-3 text-sm">
                    <div v-for="key in ['purpose', 'data', 'when', 'who', 'rights', 'alternative']" :key="key">
                        <dt class="font-medium">{{ $t(`attendance.notice.${key}_title`) }}</dt>
                        <dd class="text-muted-foreground">{{ $t(`attendance.notice.${key}_text`) }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">{{ $t('attendance.notice.retention_title') }}</dt>
                        <dd class="text-muted-foreground">{{ $t('attendance.notice.retention_text', { months: String(consent.retention_months) }) }}</dd>
                    </div>
                </dl>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" :disabled="consentBusy" @click="declineNotice">{{ $t('attendance.notice.decline') }}</Button>
                    <Button type="button" :disabled="consentBusy" data-testid="accept-notice" @click="acceptNotice">{{ $t('attendance.notice.accept') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="resultOpen">
            <DialogContent class="sm:max-w-sm" data-testid="checkin-result">
                <DialogHeader v-if="result">
                    <DialogTitle :class="result.kind === 'error' ? 'text-red-700 dark:text-red-300' : result.warning ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300'">{{ $t(result.title) }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ $t('attendance.checkin_page.result') }}</DialogDescription>
                </DialogHeader>
                <div v-if="result" class="space-y-2 text-sm">
                    <template v-if="result.kind === 'success'">
                        <p class="font-mono text-3xl tabular-nums">{{ hhmmss(result.lines[0]) }}</p>
                        <p v-if="result.lines[1]">{{ result.lines[1] }}</p>
                    </template>
                    <template v-else>
                        <p v-for="(line, i) in result.lines.filter(Boolean)" :key="i">{{ line.startsWith('attendance.') ? $t(line) : line }}</p>
                    </template>
                    <p v-if="result.hint" class="rounded-md bg-muted p-2 text-xs text-muted-foreground">{{ $t(result.hint) }}</p>
                </div>
                <DialogFooter><Button type="button" @click="resultOpen = false">{{ $t('common.actions.close') }}</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
