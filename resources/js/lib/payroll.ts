/**
 * Shared types and helpers for the Payroll and Statutori screens. Money arrives as decimal
 * strings ("1234.50") and is only formatted here; the browser never computes payroll.
 */
import { trans } from 'laravel-vue-i18n';
import type { Option } from '@/types';

export { postJson, toQuery } from '@/lib/leave';

export type RateSetRow = {
    id: number;
    scheme: string;
    scheme_label: string;
    code: string;
    name: string | null;
    effective_from: string;
    effective_to: string | null;
    status: 'draft' | 'unverified' | 'verified';
    status_label: string;
    is_placeholder: boolean;
    source_url: string | null;
    source_ref: string | null;
    notes: string | null;
    fixture_passed_at: string | null;
    verified_at: string | null;
    created_by: number | null;
    verified_by: number | null;
};

export type FileRow = {
    id: number;
    kind: string;
    kind_label: string;
    period: string;
    payroll_run_id: number | null;
    file_name: string;
    record_count: number | null;
    total_amount: string;
    status: string;
    format_code: string | null;
    format_unverified: boolean;
    generated_at: string | null;
    download_url: string;
};

export type SlipLine = {
    code: string;
    name: string;
    kind: string;
    amount: string;
    full_cycle_amount: string;
    additional_amount: string;
    source: string;
    quantity: string | null;
    note: string | null;
    show_on_slip: boolean;
};

export type SlipData = {
    id: number;
    number: string;
    payroll_run_id: number;
    employee_id: number;
    period: string;
    actual_start: string;
    actual_end: string;
    payment_date: string;
    status: string;
    status_label: string;
    payment_status: string;
    payment_status_label: string;
    employee: {
        employee_no: string;
        full_name: string;
        ic_no: string | null;
        passport_no: string | null;
        kwsp_no: string | null;
        perkeso_no: string | null;
        tin: string | null;
        bank_account_last4: string | null;
    };
    days: Record<string, string | number | null>;
    lines: SlipLine[];
    gross_pay: string;
    total_deductions: string;
    net_pay: string;
    net_rounding_adjustment: string;
    employer_total: string;
    statutory: Record<string, string | boolean | null> | null;
    ytd: Record<string, unknown> | null;
    warnings: string[];
    has_errors: boolean;
    pcb_override_amount: string | null;
    lwp_override_days: string | null;
    trace: { calc: unknown; pcb_inputs: unknown } | null;
};

/** "2026-09" -> month number and year. */
export function splitPeriod(period: string): { year: number; month: number } {
    const [year, month] = period.split('-');

    return { year: Number(year), month: Number(month) };
}

/** Value of a `<input type="month">` ("2026-09") as the first day of that month. */
export function monthStart(period: string): string {
    return period.length === 7 ? `${period}-01` : period;
}

/** Turn an enum-style options list ({value,label}) into a lookup. */
export function labelOf(options: Option[] | undefined, value: string | number | null | undefined): string {
    if (value === null || value === undefined) {
        return '';
    }

    return options?.find((o) => String(o.value) === String(value))?.label ?? String(value);
}

/** "3.000" -> "3"; days are shown without trailing zeros. */
export function trimZeros(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const text = String(value);

    return text.includes('.') ? text.replace(/\.?0+$/, '') || '0' : text;
}

/** "2026-09" -> "September 2026" in the current language. */
export function monthLabel(period: string): string {
    const { year, month } = splitPeriod(period);

    return `${trans('common.months.' + month)} ${year}`;
}
