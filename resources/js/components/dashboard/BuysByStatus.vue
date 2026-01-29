<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    buysByStatus: Record<string, number>;
}

const props = defineProps<Props>();

// In-store workflow statuses (matching StatusService definitions)
const inStoreStatuses = [
    // Items Phase
    { key: 'pending', label: 'Pending', color: 'bg-yellow-500' },
    { key: 'items_received', label: 'Items Received', color: 'bg-blue-500' },
    { key: 'items_reviewed', label: 'Items Reviewed', color: 'bg-indigo-500' },
    // Offer Phase
    { key: 'offer_given', label: 'Offer Given', color: 'bg-purple-500' },
    { key: 'offer_accepted', label: 'Offer Accepted', color: 'bg-emerald-500' },
    { key: 'offer_declined', label: 'Offer Declined', color: 'bg-red-500' },
    // Payment Phase
    { key: 'payment_pending', label: 'Payment Pending', color: 'bg-orange-500' },
    { key: 'payment_processed', label: 'Payment Processed', color: 'bg-green-500' },
    // Return/Cancellation
    { key: 'return_requested', label: 'Return Requested', color: 'bg-orange-500' },
    { key: 'items_returned', label: 'Items Returned', color: 'bg-gray-500' },
    { key: 'cancelled', label: 'Cancelled', color: 'bg-gray-500' },
];

// Online workflow statuses (shown separately if there are any)
const onlineStatuses = [
    // Kit Request Phase
    { key: 'pending_kit_request', label: 'Pending Kit Request', color: 'bg-yellow-500' },
    { key: 'kit_request_confirmed', label: 'Kit Confirmed', color: 'bg-green-500' },
    { key: 'kit_request_rejected', label: 'Kit Rejected', color: 'bg-red-500' },
    { key: 'kit_request_on_hold', label: 'Kit On Hold', color: 'bg-gray-500' },
    // Kit Shipping Phase
    { key: 'kit_sent', label: 'Kit Sent', color: 'bg-blue-500' },
    { key: 'kit_delivered', label: 'Kit Delivered', color: 'bg-indigo-500' },
];

// Combined statuses - only show online statuses if they have values
const statuses = computed(() => {
    const hasOnlineStatuses = onlineStatuses.some((s) => (props.buysByStatus[s.key] || 0) > 0);
    return hasOnlineStatuses ? [...onlineStatuses, ...inStoreStatuses] : inStoreStatuses;
});

const totalBuys = computed(() => {
    return Object.values(props.buysByStatus).reduce((sum, count) => sum + count, 0);
});

function getPercentage(count: number): number {
    if (totalBuys.value === 0) return 0;
    return (count / totalBuys.value) * 100;
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Buys by Status</h3>
            <Link href="/transactions" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
                View all
            </Link>
        </div>

        <div class="px-4 py-5 sm:px-6">
            <!-- Status bars -->
            <div class="space-y-2">
                <Link
                    v-for="status in statuses"
                    :key="status.key"
                    :href="`/transactions?status=${status.key}`"
                    class="-mx-2 flex items-center gap-x-4 rounded-lg px-2 py-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                >
                    <div class="w-28 flex-shrink-0 text-sm text-gray-600 dark:text-gray-400">
                        {{ status.label }}
                    </div>
                    <div class="flex-1">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div
                                :class="[status.color, 'h-full rounded-full transition-all duration-300']"
                                :style="{ width: `${getPercentage(buysByStatus[status.key] || 0)}%` }"
                            />
                        </div>
                    </div>
                    <div class="w-12 flex-shrink-0 text-right text-sm font-medium text-gray-900 dark:text-white">
                        {{ buysByStatus[status.key] || 0 }}
                    </div>
                </Link>
            </div>

            <!-- Total -->
            <Link
                href="/transactions"
                class="-mx-2 mt-4 flex items-center justify-between rounded-lg border-t border-gray-200 px-2 py-4 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/50"
            >
                <span class="text-sm font-medium text-gray-900 dark:text-white">Total Buys</span>
                <span class="text-lg font-semibold text-gray-900 dark:text-white">{{ totalBuys }}</span>
            </Link>
        </div>
    </div>
</template>
