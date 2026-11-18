export type User = {
    id: number;
    name: string;
    email: string;
    locale: string;
    is_super_admin: boolean;
    email_verified_at: string | null;
    employee_id: number | null;
    avatar?: string;
    created_at?: string;
    updated_at?: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    abilities: string[];
};

export type OrganisationSummary = {
    id: number;
    name: string;
    short_code: string;
};

export type Flash = {
    success: string | null;
    error: string | null;
    warnings: string[];
    status: string | null;
};

export type Option<V = string | number> = {
    value: V;
    label: string;
    is_active?: boolean;
    [key: string]: unknown;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
