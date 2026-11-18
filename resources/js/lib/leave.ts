/**
 * Shared types and helpers for the Cuti screens. Days arrive as decimal
 * strings (3 dp); the browser only formats them, it never computes balances.
 */
export type LeaveTypeRow = {
    id: number;
    code: string;
    name: string;
    name_en: string | null;
    display_name: string;
    colour: string | null;
    has_balance: boolean;
    entitlement_basis: string;
    entitlement_basis_label: string;
    pay_mode: string;
    pay_mode_label: string;
    is_active: boolean;
    [key: string]: unknown;
};

export type CapRoom = { cap_id: number; name: string; cap_days: string; used: string; room: string };
export type ExpiringLot = { lot_id: number; kind: string; remaining: string; date_to: string };

export type Balance = {
    type: LeaveTypeRow;
    leave_type_id: number;
    date: string;
    has_balance: boolean;
    credited: string;
    taken: string;
    encashed: string;
    reduced: string;
    carried_out: string;
    expired: string;
    reserved: string;
    remaining: string;
    available: string;
    cap_limited: boolean;
    caps: CapRoom[];
    expiring: ExpiringLot[];
};

export type ApplicationRow = {
    id: number;
    number: string | null;
    employee_id: number;
    employee: string | null;
    employee_no: string | null;
    leave_type_id: number;
    leave_type: string | null;
    leave_type_code: string | null;
    colour: string | null;
    from_date: string;
    to_date: string;
    half_day_date: string | null;
    half_day_session: string | null;
    total_days: string;
    unpaid_days: string;
    calendar_days: number;
    status: string;
    status_label: string;
    is_on_behalf: boolean;
    is_backdated: boolean;
    submitted_at: string | null;
};

export type DayRow = {
    date: string;
    day_kind: string;
    day_kind_label: string;
    session: string;
    portion: string;
    unpaid_portion: string;
    holiday_name?: string | null;
};

/** "3.000" -> "3", "2.500" -> "2.5" (display only). */
export function days(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const text = String(value);
    if (!text.includes('.')) {
        return text;
    }

    return text.replace(/\.?0+$/, '') || '0';
}

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

/** POST JSON or FormData and read a JSON answer (preview endpoints). */
export async function postJson<T>(url: string, body: Record<string, unknown> | FormData): Promise<T> {
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
            'X-Requested-With': 'XMLHttpRequest',
            ...(isForm ? {} : { 'Content-Type': 'application/json' }),
        },
        body: isForm ? body : JSON.stringify(body),
    });
    if (!response.ok && response.status !== 422) {
        throw new Error(String(response.status));
    }

    return (await response.json()) as T;
}

export function toQuery(params: Record<string, unknown>): string {
    const q = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') {
            q.append(key, String(value));
        }
    }

    return q.toString();
}
