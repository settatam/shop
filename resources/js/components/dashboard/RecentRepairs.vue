<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useDate } from '@/composables/useDate';
import { WrenchScrewdriverIcon } from '@heroicons/vue/24/outline';

interface Customer {
    name: string;
    email: string;
}

interface Vendor {
    name: string;
}

interface Repair {
    id: number;
    repair_number: string;
    customer: Customer | null;
    vendor: Vendor | null;
    total: number;
    status: string;
    is_appraisal: boolean;
    created_at: string;
}

interface Props {
    repairs: Repair[];
}

defineProps<Props>();
const { fromNow } = useDate();

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function getStatusClasses(status: string): string {
    const classes: Record<string, string> = {
        pending: 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20',
        sent_to_vendor: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
        received_by_vendor: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20',
        completed: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20',
        payment_received: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20',
        refunded: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20',
        cancelled: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
        archived: 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
    };
    return classes[status] || classes.pending;
}

function formatStatus(status: string): string {
    return status.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <div class="flex items-center gap-x-2">
                <WrenchScrewdriverIcon class="h-5 w-5 text-orange-500" />
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Recent Repairs</h3>
            </div>
            <Link href="/repairs" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
                View all
            </Link>
        </div>

        <!-- Empty state -->
        <div v-if="repairs.length === 0" class="px-4 py-12 text-center sm:px-6">
            <WrenchScrewdriverIcon class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" />
            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No repairs</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Repair jobs will appear here.
            </p>
        </div>

        <!-- Repairs list -->
        <ul v-else role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
            <li v-for="repair in repairs" :key="repair.id" class="px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                    <div class="flex min-w-0 flex-1 items-center gap-x-4">
                        <div class="min-w-0 flex-auto">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ repair.repair_number }}
                                <span
                                    v-if="repair.is_appraisal"
                                    class="ml-2 inline-flex items-center rounded-md bg-purple-50 px-1.5 py-0.5 text-xs font-medium text-purple-700 ring-1 ring-inset ring-purple-700/10 dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/20"
                                >
                                    Appraisal
                                </span>
                            </p>
                            <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ repair.customer?.name || 'No customer' }}
                                <span v-if="repair.vendor" class="text-gray-400"> &bull; Vendor: {{ repair.vendor.name }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-y-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ formatCurrency(repair.total) }}
                        </p>
                        <span
                            :class="[
                                getStatusClasses(repair.status),
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                            ]"
                        >
                            {{ formatStatus(repair.status) }}
                        </span>
                    </div>
                </div>
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ fromNow(repair.created_at) }}
                </div>
            </li>
        </ul>
    </div>
</template>
