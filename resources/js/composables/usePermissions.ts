import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * UI hints only (spec-00 A7): every action is still authorised on the server.
 */
export function usePermissions() {
    const page = usePage();
    const abilities = computed(() => page.props.auth?.abilities ?? []);
    const isSuperAdmin = computed(() => Boolean(page.props.auth?.user?.is_super_admin));

    function can(...permissions: string[]): boolean {
        if (isSuperAdmin.value) {
            return true;
        }

        return permissions.some((p) => abilities.value.includes(p));
    }

    return { can, isSuperAdmin, abilities };
}
