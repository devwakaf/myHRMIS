import type { Auth, Flash, OrganisationSummary } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: string;
            locales: string[];
            auth: Auth;
            organisation: OrganisationSummary | null;
            organisations: OrganisationSummary[];
            pendingApprovals: number;
            attendanceReviewCount: number;
            flash: Flash;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
        $t: (key: string, replacements?: Record<string, string | number>) => string;
        $tChoice: (key: string, count: number, replacements?: Record<string, string | number>) => string;
    }
}
