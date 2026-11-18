<script setup lang="ts">
import { onMounted, ref } from 'vue';
import BalanceCard from '@/components/leave/BalanceCard.vue';
import type { Balance } from '@/lib/leave';

const props = defineProps<{ employeeId: number }>();
const balances = ref<Balance[]>([]);
const loading = ref(true);
const failed = ref(false);

onMounted(async () => {
    try {
        const response = await fetch(`/leave/employees/${props.employeeId}/balances`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(String(response.status));
        }
        balances.value = ((await response.json()) as { balances: Balance[] }).balances;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <p v-if="loading" class="text-sm text-muted-foreground">…</p>
        <p v-else-if="failed" class="text-sm text-red-600">{{ $t('leave.me.load_failed') }}</p>
        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <BalanceCard v-for="b in balances" :key="b.type.id" :balance="b" />
        </div>
    </div>
</template>
