/**
 * Shared types and helpers for the Kehadiran screens. Times arrive as ISO-8601 strings in the
 * organisation's timezone; the browser only slices and formats them, it never converts zones.
 */
export type EmployeeRef = { id: number; employee_no: string; full_name: string } | null;

export type ShiftRef = { code: string; colour?: string | null; time?: string; name?: string } | null;

export type AttendanceRow = {
    id: number;
    employee_id: number;
    employee: EmployeeRef;
    attendance_date: string;
    status: string;
    status_label: string;
    attendance_status: string;
    attendance_status_label: string;
    half_day_basis: string | null;
    half_day_session: string | null;
    other_half_status: string | null;
    day_type: string;
    day_type_label: string;
    is_half_day_off: boolean;
    holiday_label: string | null;
    shift: ShiftRef;
    scheduled_start_at: string | null;
    scheduled_end_at: string | null;
    in_at: string | null;
    out_at: string | null;
    punch_count: number;
    worked_minutes: number | null;
    worked_hours: string | null;
    normal_minutes: number | null;
    is_late: boolean;
    late_minutes: number;
    is_early_exit: boolean;
    early_exit_minutes: number;
    ot_detected_minutes: number;
    source: string;
    source_label: string;
    leave_application_id: number | null;
    attendance_request_id: number | null;
    supersedes_id: number | null;
    superseded_by_id: number | null;
    review_flag: string | null;
    remarks: string | null;
    cancel_reason: string | null;
};

export type CheckinRow = {
    id: number;
    employee_id: number;
    employee: EmployeeRef;
    punched_at: string;
    received_at: string;
    client_reported_at: string | null;
    clock_skew_seconds: number | null;
    skew_warning: boolean;
    log_type: string | null;
    source: string;
    source_label: string;
    geofence_result: string;
    geofence_result_label: string;
    location: string | null;
    distance_m: string | null;
    accuracy_m: string | null;
    latitude: string | null;
    longitude: string | null;
    shift_date: string | null;
    processing_state: string;
    processing_state_label: string;
    review_reason: string | null;
    review_reason_label: string | null;
    review_note: string | null;
    attendance_id: number | null;
    voided_at: string | null;
    void_reason: string | null;
};

export type RequestDay = { work_date: string; outcome: string; outcome_label: string; attendance_id: number | null };

export type PlanDay = { date: string; outcome: string; outcome_label?: string; reason?: string | null; [key: string]: unknown };

export type AttendanceRequestRow = {
    id: number;
    number: string | null;
    employee_id: number;
    employee: EmployeeRef;
    kind: string;
    kind_label: string;
    from_date: string;
    to_date: string;
    is_half_day: boolean;
    half_day_date: string | null;
    half_day_session: string | null;
    include_rest_days: boolean;
    shift_type_id: number | null;
    duty_location: string | null;
    explanation: string;
    status: string;
    status_label: string;
    days: RequestDay[];
};

export type ShiftRequestRow = {
    id: number;
    number: string | null;
    employee_id: number;
    employee: EmployeeRef;
    shift_type_id: number;
    shift: { code: string; name: string; time: string } | null;
    from_date: string;
    to_date: string;
    reason: string | null;
    status: string;
    status_label: string;
};

export type ClaimLine = {
    id: number;
    attendance_id: number | null;
    work_date: string;
    day_type: string;
    day_type_label: string;
    worked_minutes: number;
    normal_minutes: number;
    within_normal_minutes: number;
    beyond_normal_minutes: number;
    orp_days: string;
    multiplier: string;
    amount: string | null;
    flags: string[];
    note: string | null;
    is_manual: boolean;
};

export type ClaimRow = {
    id: number;
    number: string | null;
    employee_id: number;
    employee: EmployeeRef;
    period_month: string;
    status: string;
    status_label: string;
    eligibility: string;
    eligibility_label: string;
    total_ot_minutes: number;
    total_hours: string;
    cap_counted_minutes: number;
    cap_minutes: number | null;
    cap_exceeded: boolean;
    cap_override_reason: string | null;
    posted_payroll_run_id: number | null;
    wage_source: string | null;
    monthly_wage_snapshot: string | null;
    orp: string | null;
    hrp: string | null;
    total_amount: string | null;
    wage_missing: boolean;
    lines?: ClaimLine[];
};

/** Local clock part of an ISO string: "2026-11-02T08:03:12+08:00" -> "08:03". */
export function hhmm(iso: string | null | undefined): string {
    return iso ? iso.slice(11, 16) : '—';
}

export function hhmmss(iso: string | null | undefined): string {
    return iso ? iso.slice(11, 19) : '—';
}

/** Minutes as "H:MM" (display only). */
export function hoursMinutes(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) {
        return '—';
    }
    const sign = minutes < 0 ? '-' : '';
    const abs = Math.abs(minutes);

    return `${sign}${Math.floor(abs / 60)}:${String(abs % 60).padStart(2, '0')}`;
}

/** Offset of an ISO string in minutes ("+08:00" -> 480). */
export function isoOffsetMinutes(iso: string): number {
    const match = /([+-])(\d{2}):(\d{2})$/.exec(iso);
    if (!match) {
        return 0;
    }

    return (match[1] === '-' ? -1 : 1) * (Number(match[2]) * 60 + Number(match[3]));
}

/** "2026-11-02" for a Date shifted to the given offset. */
export function localDateOf(epochMs: number, offsetMinutes: number): string {
    return new Date(epochMs + offsetMinutes * 60000).toISOString().slice(0, 10);
}

export function localTimeOf(epochMs: number, offsetMinutes: number): string {
    return new Date(epochMs + offsetMinutes * 60000).toISOString().slice(11, 19);
}

const TONES: Record<string, string> = {
    present: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    absent: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    half_day: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    on_leave: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
    wfh: 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200',
    on_duty: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
};

export function statusTone(status: string): string {
    return TONES[status] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
}

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

/** JSON request that keeps the HTTP status (check-in answers 201 / 409 / 422 / 429 with different bodies). */
export async function jsonRequest<T>(method: string, url: string, body?: Record<string, unknown>): Promise<{ status: number; data: T }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    let data = {} as T;
    try {
        data = (await response.json()) as T;
    } catch {
        // empty body
    }

    return { status: response.status, data };
}

export function toQuery(params: Record<string, unknown>): string {
    const q = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '' && value !== false) {
            q.append(key, String(value));
        }
    }

    return q.toString();
}

/** First message of a Laravel 422 body. */
export function firstError(data: unknown): string | null {
    const body = data as { message?: string; errors?: Record<string, string[]> };
    const first = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;

    return first ?? body?.message ?? null;
}
