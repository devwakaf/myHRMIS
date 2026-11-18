<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ClipboardCheck, LogIn, LogOut, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';

type Stats = {
    pending_approvals: number;
    active_employees: number | null;
    joiners_this_month: number | null;
    leaving_this_month: number | null;
};

const props = defineProps<{
    stats: Stats | null;
    upcoming: { employee_id: number; full_name: string; kind_label: string; date: string; days_left: number; detail: string | null }[];
    me: { id: number; name: string; employee_no: string; designation: string | null; department: string | null } | null;
}>();

const page = usePage();
const cards = computed(() => {
    if (!props.stats) {
        return [];
    }

    return [
        { key: 'pending_approvals', value: props.stats.pending_approvals, icon: ClipboardCheck, href: '/approvals' },
        { key: 'active_employees', value: props.stats.active_employees, icon: Users, href: '/employees' },
        { key: 'joiners_this_month', value: props.stats.joiners_this_month, icon: LogIn, href: null },
        { key: 'leaving_this_month', value: props.stats.leaving_this_month, icon: LogOut, href: null },
    ].filter((c) => c.value !== null);
});
</script>

<template>
    <Head :title="$t('common.nav.dashboard')" />

    <AppLayout :breadcrumbs="[{ title: 'common.nav.dashboard', href: '/dashboard' }]">
        <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
            <PageHeader
                :title="$t('common.dashboard.welcome', { name: page.props.auth.user.name })"
                :description="page.props.organisation?.name ?? $t('common.dashboard.no_organisation')"
            />

            <div v-if="!stats" class="rounded-lg border p-6 text-sm">
                {{ $t('common.dashboard.no_organisation_hint') }}
                <Link href="/organisations/create" class="ml-1 underline">{{ $t('core.organisation.create') }}</Link>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <component
                    :is="card.href ? Link : 'div'"
                    v-for="card in cards"
                    :key="card.key"
                    :href="card.href ?? undefined"
                    class="flex items-center gap-4 rounded-xl border p-4 transition hover:bg-accent/40"
                >
                    <component :is="card.icon" class="size-8 text-primary" />
                    <div>
                        <p class="text-2xl font-semibold">{{ card.value }}</p>
                        <p class="text-sm text-muted-foreground">{{ $t(`common.dashboard.${card.key}`) }}</p>
                    </div>
                </component>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div v-if="me" class="rounded-xl border p-4">
                    <h2 class="mb-2 font-medium">{{ $t('common.nav.me') }}</h2>
                    <p class="text-sm font-medium">{{ me.name }}</p>
                    <p class="text-sm text-muted-foreground">{{ me.employee_no }} · {{ me.designation }}</p>
                    <p class="text-sm text-muted-foreground">{{ me.department }}</p>
                    <Link href="/me" class="mt-3 inline-block text-sm underline">{{ $t('common.actions.view') }}</Link>
                </div>

                <div v-if="upcoming.length" class="rounded-xl border p-4 lg:col-span-2">
                    <h2 class="mb-3 font-medium">{{ $t('common.dashboard.upcoming') }}</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="(row, i) in upcoming" :key="i" class="border-t first:border-t-0">
                                <td class="py-1.5">
                                    <Link :href="`/employees/${row.employee_id}`" class="hover:underline">{{ row.full_name }}</Link>
                                </td>
                                <td class="py-1.5">{{ row.kind_label }}<span v-if="row.detail" class="text-muted-foreground"> · {{ row.detail }}</span></td>
                                <td class="py-1.5 text-right">{{ formatDate(row.date) }}</td>
                                <td class="py-1.5 text-right text-muted-foreground">{{ $t('common.dashboard.days_left', { days: String(row.days_left) }) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
