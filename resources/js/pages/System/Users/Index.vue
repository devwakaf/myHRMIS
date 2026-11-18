<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import Pagination from '@/components/app/Pagination.vue';
import SelectInput from '@/components/app/SelectInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/composables/useFormat';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    email: string;
    locale: string;
    is_active: boolean;
    last_login_at: string | null;
    roles: string[];
    employee: string | null;
};

const props = defineProps<{
    users: Paginated<Row>;
    filters: { search: string };
    roleOptions: Option<string>[];
}>();

const search = ref(props.filters.search ?? '');
const open = ref(false);
const editing = ref<Row | null>(null);

const localeOptions: Option<string>[] = [
    { value: 'ms', label: 'Bahasa Melayu' },
    { value: 'en', label: 'English' },
];

const form = useForm({
    name: '',
    email: '',
    password: '',
    locale: 'ms',
    is_active: true,
    roles: [] as string[],
});

function applySearch() {
    router.get('/users', { search: search.value }, { preserveState: true, replace: true });
}

function create() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(row: Row) {
    editing.value = row;
    form.clearErrors();
    form.name = row.name;
    form.email = row.email;
    form.password = '';
    form.locale = row.locale;
    form.is_active = row.is_active;
    form.roles = row.roles.filter((r) => r !== 'pelulus');
    open.value = true;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) {
        form.put(`/users/${editing.value.id}`, options);
    } else {
        form.post('/users', options);
    }
}

function toggleRole(role: string) {
    form.roles = form.roles.includes(role) ? form.roles.filter((r) => r !== role) : [...form.roles, role];
}
</script>

<template>
    <Head :title="$t('common.nav.users')" />
    <AppLayout :breadcrumbs="[{ title: 'common.nav.users', href: '/users' }]">
        <div class="p-4 md:p-6">
            <PageHeader :title="$t('common.nav.users')" :description="$t('common.users.description')">
                <template #actions>
                    <Button @click="create"><Plus class="size-4" />{{ $t('common.users.add') }}</Button>
                </template>
            </PageHeader>

            <form class="mb-4 flex gap-2" @submit.prevent="applySearch">
                <Input v-model="search" :placeholder="$t('common.users.search')" class="max-w-sm" />
                <Button type="submit" variant="outline">{{ $t('common.actions.search') }}</Button>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2">{{ $t('auth.fields.name') }}</th>
                            <th class="px-3 py-2">{{ $t('auth.fields.email') }}</th>
                            <th class="px-3 py-2">{{ $t('common.users.roles') }}</th>
                            <th class="px-3 py-2">{{ $t('common.users.employee') }}</th>
                            <th class="px-3 py-2">{{ $t('common.users.last_login') }}</th>
                            <th class="px-3 py-2">{{ $t('common.fields.status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in users.data" :key="row.id" class="border-t">
                            <td class="px-3 py-2 font-medium">{{ row.name }}</td>
                            <td class="px-3 py-2">{{ row.email }}</td>
                            <td class="px-3 py-2">
                                <span v-for="r in row.roles" :key="r" class="mr-1 inline-block rounded bg-muted px-1.5 py-0.5 text-xs">{{ $t(`common.roles.${r}`) }}</span>
                            </td>
                            <td class="px-3 py-2">{{ row.employee ?? '—' }}</td>
                            <td class="px-3 py-2">{{ formatDateTime(row.last_login_at) || '—' }}</td>
                            <td class="px-3 py-2">
                                <StatusBadge :status="row.is_active ? 'active' : 'left'" :label="row.is_active ? $t('common.active') : $t('common.inactive')" />
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button size="sm" variant="outline" @click="edit(row)">{{ $t('common.actions.edit') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="!users.data.length">
                            <td colspan="7" class="px-3 py-6 text-center text-muted-foreground">{{ $t('common.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="users.links" :from="users.from" :to="users.to" :total="users.total" />
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ editing ? $t('common.users.edit') : $t('common.users.add') }}</DialogTitle>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="submit">
                    <FormField :label="$t('auth.fields.name')" :error="form.errors.name" required>
                        <Input v-model="form.name" />
                    </FormField>
                    <FormField :label="$t('auth.fields.email')" :error="form.errors.email" required>
                        <Input v-model="form.email" type="email" :disabled="!!editing" />
                    </FormField>
                    <FormField :label="$t('auth.fields.password')" :error="form.errors.password" :hint="editing ? $t('common.users.password_hint_edit') : $t('common.users.password_hint_new')">
                        <Input v-model="form.password" type="password" autocomplete="new-password" />
                    </FormField>
                    <FormField :label="$t('common.users.language')" :error="form.errors.locale" required>
                        <SelectInput v-model="form.locale" :options="localeOptions" :nullable="false" />
                    </FormField>
                    <FormField :label="$t('common.users.roles')" :error="form.errors.roles">
                        <div class="grid grid-cols-2 gap-2">
                            <label v-for="role in roleOptions" :key="role.value" class="flex items-center gap-2 text-sm">
                                <input type="checkbox" :checked="form.roles.includes(role.value)" @change="toggleRole(role.value)" />
                                {{ role.label }}
                            </label>
                        </div>
                    </FormField>
                    <label v-if="editing" class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_active" type="checkbox" /> {{ $t('common.active') }}
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">{{ $t('common.actions.cancel') }}</Button>
                        <Button type="submit" :disabled="form.processing">{{ $t('common.actions.save') }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
