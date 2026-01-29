<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    ordersByStatus: Record<string, number>;
}

const props = defineProps<Props>();

const statuses = [
    { key: 'pending', label: 'Pending', color: 'bg-yellow-500' },
    { key: 'confirmed', label: 'Confirmed', color: 'bg-blue-500' },
    { key: 'processing', label: 'Processing', color: 'bg-indigo-500' },
    { key: 'shipped', label: 'Shipped', color: 'bg-purple-500' },
    { key: 'delivered', label: 'Delivered', color: 'bg-green-500' },
    { key: 'completed', label: 'Completed', color: 'bg-green-600' },
    { key: 'cancelled', label: 'Cancelled', color: 'bg-red-500' },
];

const totalOrders = computed(() => {
    return Object.values(props.ordersByStatus).reduce((sum, count) => sum + count, 0);
});

function getPercentage(count: number): number {
    if (totalOrders.value === 0) return 0;
    return (count / totalOrders.value) * 100;
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Orders by Status</h3>
            <Link href="/orders" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
                View all
            </Link>
        </div>

        <div class="px-4 py-5 sm:px-6">
            <!-- Status bars -->
            <div class="space-y-2">
                <Link
                    v-for="status in statuses"
                    :key="status.key"
                    :href="`/orders?status=${status.key}`"
                    class="-mx-2 flex items-center gap-x-4 rounded-lg px-2 py-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                >
                    <div class="w-24 flex-shrink-0 text-sm text-gray-600 dark:text-gray-400">
                        {{ status.label }}
                    </div>
                    <div class="flex-1">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div
                                :class="[status.color, 'h-full rounded-full transition-all duration-300']"
                                :style="{ width: `${getPercentage(ordersByStatus[status.key] || 0)}%` }"
                            />
                        </div>
                    </div>
                    <div class="w-12 flex-shrink-0 text-right text-sm font-medium text-gray-900 dark:text-white">
                        {{ ordersByStatus[status.key] || 0 }}
                    </div>
                </Link>
            </div>

            <!-- Total -->
            <Link
                href="/orders"
                class="-mx-2 mt-4 flex items-center justify-between rounded-lg border-t border-gray-200 px-2 py-4 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/50"
            >
                <span class="text-sm font-medium text-gray-900 dark:text-white">Total Orders</span>
                <span class="text-lg font-semibold text-gray-900 dark:text-white">{{ totalOrders }}</span>
            </Link>
        </div>
    </div>
</template>
