<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';

interface DrilldownTransaction {
    id: number;
    transaction_number: string;
    customer_name: string | null;
    customer_email: string | null;
    status: string;
    status_label: string;
    final_offer: number | null;
    created_at: string;
    url: string;
}

const open = ref(false);
const loading = ref(false);
const transactions = ref<DrilldownTransaction[]>([]);
const metricLabel = ref('');

function formatCurrency(value: number | null): string {
    if (value === null || value === undefined) {
        return '—';
    }
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

async function load(params: {
    startDate: string;
    endDate: string;
    metric: string;
    metricLabel: string;
    status?: string;
}): Promise<void> {
    open.value = true;
    loading.value = true;
    metricLabel.value = params.metricLabel;
    transactions.value = [];

    const query = new URLSearchParams({
        start_date: params.startDate,
        end_date: params.endDate,
        metric: params.metric,
    });

    if (params.status) {
        query.set('status', params.status);
    }

    try {
        const response = await fetch(`/reports/transactions/cohort/drilldown?${query.toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.ok) {
            const data = await response.json();
            transactions.value = data.transactions;
        }
    } finally {
        loading.value = false;
    }
}

defineExpose({ load });
</script>

<template>
    <Sheet :open="open" @update:open="(val: boolean) => (open = val)">
        <SheetContent side="right" class="w-full sm:w-[65vw] sm:max-w-none overflow-y-auto">
            <SheetHeader>
                <SheetTitle>{{ metricLabel }}</SheetTitle>
                <SheetDescription>
                    {{ loading ? 'Loading...' : `${transactions.length} transaction${transactions.length !== 1 ? 's' : ''}` }}
                </SheetDescription>
            </SheetHeader>

            <div class="py-4">
                <!-- Loading skeleton -->
                <div v-if="loading" class="space-y-3">
                    <div v-for="i in 8" :key="i" class="flex gap-4">
                        <div class="h-4 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        <div class="h-4 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        <div class="h-4 w-20 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        <div class="h-4 w-16 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        <div class="h-4 w-16 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else-if="transactions.length === 0" class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    No transactions found for this metric.
                </div>

                <!-- Transactions table -->
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Transaction #</th>
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Customer</th>
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Status</th>
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Date</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Offer</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="t in transactions" :key="t.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="whitespace-nowrap px-3 py-2 text-sm">
                                    <Link :href="t.url" class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                        {{ t.transaction_number }}
                                    </Link>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-900 dark:text-white">
                                    <div>{{ t.customer_name || '—' }}</div>
                                    <div v-if="t.customer_email" class="text-xs text-gray-500 dark:text-gray-400">{{ t.customer_email }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ t.status_label }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ t.created_at }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-sm text-right text-gray-500 dark:text-gray-400">
                                    {{ formatCurrency(t.final_offer) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
