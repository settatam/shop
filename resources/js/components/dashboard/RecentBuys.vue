<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useDate } from '@/composables/useDate';
import { BanknotesIcon } from '@heroicons/vue/24/outline';

interface Customer {
    name: string;
    email: string;
}

interface Buy {
    id: number;
    transaction_number: string;
    customer: Customer | null;
    final_offer: number | null;
    preliminary_offer: number | null;
    status: string;
    type: string;
    created_at: string;
}

interface Props {
    buys: Buy[];
}

defineProps<Props>();
const { fromNow } = useDate();

function formatCurrency(value: number | null): string {
    if (value === null) return '-';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function getStatusClasses(status: string): string {
    const classes: Record<string, string> = {
        // In-house workflow
        pending: 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20',
        items_received: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
        items_reviewed: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20',
        offer_given: 'bg-purple-50 text-purple-700 ring-purple-600/20 dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/20',
        offer_accepted: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20',
        offer_declined: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
        payment_pending: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20',
        payment_processed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20',
        cancelled: 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
        // Online workflow
        pending_kit_request: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
        kit_request_confirmed: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
        kit_request_rejected: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
        kit_request_on_hold: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20',
        kit_sent: 'bg-cyan-50 text-cyan-700 ring-cyan-600/20 dark:bg-cyan-500/10 dark:text-cyan-400 dark:ring-cyan-500/20',
        kit_delivered: 'bg-cyan-50 text-cyan-700 ring-cyan-600/20 dark:bg-cyan-500/10 dark:text-cyan-400 dark:ring-cyan-500/20',
        return_requested: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20',
        items_returned: 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
    };
    return classes[status] || classes.pending;
}

function formatStatus(status: string): string {
    return status.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
}

function getTypeLabel(type: string): string {
    return type === 'in_house' ? 'In-House' : 'Mail-In';
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <div class="flex items-center gap-x-2">
                <BanknotesIcon class="h-5 w-5 text-green-500" />
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Recent Buys</h3>
            </div>
            <Link href="/transactions" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
                View all
            </Link>
        </div>

        <!-- Empty state -->
        <div v-if="buys.length === 0" class="px-4 py-12 text-center sm:px-6">
            <BanknotesIcon class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" />
            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No buys</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Buy transactions will appear here.
            </p>
        </div>

        <!-- Buys list -->
        <ul v-else role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
            <li v-for="buy in buys" :key="buy.id" class="px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                    <div class="flex min-w-0 flex-1 items-center gap-x-4">
                        <div class="min-w-0 flex-auto">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ buy.transaction_number }}
                            </p>
                            <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ buy.customer?.name || 'Walk-in' }}
                                <span class="ml-2 inline-flex items-center rounded-md bg-gray-50 px-1.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-500/20">
                                    {{ getTypeLabel(buy.type) }}
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-y-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ formatCurrency(buy.final_offer || buy.preliminary_offer) }}
                        </p>
                        <span
                            :class="[
                                getStatusClasses(buy.status),
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                            ]"
                        >
                            {{ formatStatus(buy.status) }}
                        </span>
                    </div>
                </div>
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ fromNow(buy.created_at) }}
                </div>
            </li>
        </ul>
    </div>
</template>
