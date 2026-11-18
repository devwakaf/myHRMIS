/**
 * Display formats (spec-00 A3), same in both locales:
 * date dd/mm/yyyy, datetime dd/mm/yyyy HH:mm, money "RM 1,234.56" / "-RM 1,234.56".
 * The browser never computes money; amounts arrive as decimal strings.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const [datePart] = value.split(/[T ]/);
    const [y, m, d] = datePart.split('-');

    return y && m && d ? `${d}/${m}/${y}` : value;
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const [datePart, timePart = ''] = value.replace('T', ' ').split(' ');

    return `${formatDate(datePart)} ${timePart.slice(0, 5)}`.trim();
}

export function formatMoney(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    let text = String(value).trim();
    const negative = text.startsWith('-');
    text = text.replace('-', '');
    const [whole, fraction = '00'] = text.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${negative ? '-' : ''}RM ${grouped}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}

export function useFormat() {
    return { formatDate, formatDateTime, formatMoney };
}
