<script setup lang="ts">
import PortalLayout from '@/layouts/portal/PortalLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface Transaction {
    id: number;
    transaction_number: string;
    status: string;
    type: string;
    final_offer: string | null;
    created_at: string;
    latest_offer: { amount: string; status: string } | null;
    items: any[];
}

defineProps<{
    transactions: {
        data: Transaction[];
        links: any[];
        current_page: number;
        last_page: number;
    };
}>();

const statusLabels: Record<string, string> = {
    pending_kit_request: 'Pending Kit Request',
    kit_request_confirmed: 'Kit Request Confirmed',
    kit_sent: 'Kit Sent',
    kit_delivered: 'Kit Delivered',
    pending: 'Pending',
    items_received: 'Items Received',
    items_reviewed: 'Items Reviewed',
    offer_given: 'Offer Given',
    offer_accepted: 'Offer Accepted',
    offer_declined: 'Offer Declined',
    payment_pending: 'Payment Pending',
    payment_processed: 'Payment Processed',
    return_requested: 'Return Requested',
    items_returned: 'Items Returned',
    cancelled: 'Cancelled',
};

const statusColors: Record<string, string> = {
    offer_given: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    offer_accepted: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    offer_declined: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    payment_processed: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
};

function getStatusColor(status: string): string {
    return statusColors[status] ?? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function formatCurrency(amount: string | null): string {
    if (!amount) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(parseFloat(amount));
}
</script>

<template>
    <PortalLayout title="Transactions">
        <Head title="Transactions" />

        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Your Transactions</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">View the status of your buy transactions</p>

        <div v-if="transactions.data.length === 0" class="mt-12 text-center">
            <p class="text-gray-500 dark:text-gray-400">No transactions found.</p>
        </div>

        <div v-else class="mt-6 space-y-4">
            <Link
                v-for="txn in transactions.data"
                :key="txn.id"
                :href="`/p/transactions/${txn.id}`"
                class="block rounded-lg border border-gray-200 bg-white p-6 transition hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white">
                            {{ txn.transaction_number }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ formatDate(txn.created_at) }} &middot; {{ txn.items.length }} item{{ txn.items.length !== 1 ? 's' : '' }}
                        </p>
                    </div>
                    <div class="text-right">
                        <span
                            :class="['inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium', getStatusColor(txn.status)]"
                        >
                            {{ statusLabels[txn.status] ?? txn.status }}
                        </span>
                        <p v-if="txn.latest_offer" class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                            {{ formatCurrency(txn.latest_offer.amount) }}
                        </p>
                    </div>
                </div>
            </Link>
        </div>

        <!-- Pagination -->
        <div v-if="transactions.last_page > 1" class="mt-6 flex justify-center gap-2">
            <Link
                v-for="link in transactions.links"
                :key="link.label"
                :href="link.url ?? ''"
                :class="[
                    'rounded-md px-3 py-2 text-sm',
                    link.active
                        ? 'bg-indigo-600 text-white'
                        : link.url
                            ? 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'
                            : 'cursor-default text-gray-400 dark:text-gray-600',
                ]"
                v-html="link.label"
                :preserve-state="true"
            />
        </div>
    </PortalLayout>
</template>
