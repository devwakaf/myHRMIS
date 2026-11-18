<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TabNav from '@/components/app/TabNav.vue';
import ApplicationTable from '@/components/leave/ApplicationTable.vue';
import BalanceCard from '@/components/leave/BalanceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import { days } from '@/lib/leave';
import type { ApplicationRow, Balance } from '@/lib/leave';

type Compensatory = {
    id: number;
    number: string | null;
    work_from: string;
    work_to: string;
    days_claimed: string;
    status: string;
    status_label: string;
};

const props = defineProps<{
    employee: { id: number; name: string; employee_no: string };
    date: string;
    balances: Balance[];
    applications: ApplicationRow[];
    compensatory_requests: Compensatory[];
}>();

const tab = ref('applications');
const asAt = ref(props.date);

function changeDate() {
    router.get('/leave/me', { date: asAt.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="$t('leave.pages.me')" />
    <AppLayout :breadcrumbs="[{ title: 'leave.pages.me', href: '/leave/me' }]">
        <div class="space-y-6 p-4 md:p-6">
            <PageHeader :title="$t('leave.pages.me')" :description="`${employee.name} · ${employee.employee_no}`">
                <template #actions>
                    <label class="flex items-center gap-2 text-xs">{{ $t('leave.me.as_at') }}<Input v-model="asAt" type="date" class="w-40" @change="changeDate" /></label>
                    <Button as-child><Link href="/leave/applications/create"><Plus class="size-4" />{{ $t('leave.pages.apply') }}</Link></Button>
                </template>
            </PageHeader>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <BalanceCard v-for="b in balances" :key="b.type.id" :balance="b" />
            </div>

            <div>
                <TabNav
                    v-model="tab"
                    :tabs="[
                        { key: 'applications', label: $t('leave.me.my_applications') },
                        { key: 'compensatory', label: $t('leave.pages.compensatory') },
                    ]"
                />
                <ApplicationTable v-if="tab === 'applications'" :rows="applications" />
                <div v-else class="space-y-3">
                    <div class="flex justify-end"><Button variant="outline" as-child><Link href="/leave/compensatory-requests">{{ $t('leave.pages.compensatory') }}</Link></Button></div>
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr>
                                    <th class="px-3 py-2">{{ $t('common.fields.number') }}</th>
                                    <th class="px-3 py-2">{{ $t('leave.fields.work_period') }}</th>
                                    <th class="px-3 py-2 text-right">{{ $t('leave.fields.days_claimed') }}</th>
                                    <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in compensatory_requests" :key="r.id" class="border-t">
                                    <td class="px-3 py-2 font-mono">{{ r.number ?? `#${r.id}` }}</td>
                                    <td class="px-3 py-2">{{ formatDate(r.work_from) }} – {{ formatDate(r.work_to) }}</td>
                                    <td class="px-3 py-2 text-right">{{ days(r.days_claimed) }}</td>
                                    <td class="px-3 py-2"><StatusBadge :status="r.status" :label="r.status_label" /></td>
                                </tr>
                                <tr v-if="!compensatory_requests.length"><td colspan="4" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
