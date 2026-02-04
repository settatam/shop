<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
import StatCard from '@/components/charts/StatCard.vue';

interface DataRow {
    period: string;
    kits_requested: number;
    kits_declined: number;
    kits_declined_percent: number;
    kits_received: number;
    kits_received_percent: number;
    kits_rejected: number;
    kits_returned: number;
    offers_given: number;
    offers_declined: number;
    offers_pending: number;
    offers_accepted: number;
    estimated_value: number;
    profit: number;
    profit_percent: number;
}

interface Totals {
    kits_requested: number;
    kits_declined: number;
    kits_declined_percent: number;
    kits_received: number;
    kits_received_percent: number;
    kits_rejected: number;
    kits_returned: number;
    offers_given: number;
    offers_declined: number;
    offers_pending: number;
    offers_accepted: number;
    estimated_value: number;
    profit: number;
    profit_percent: number;
}

const props = defineProps<{
    dailyData: DataRow[];
    totals: Totals;
    month: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Transactions', href: '/reports/transactions/daily' },
    { title: 'Daily', href: '/reports/transactions/daily' },
];

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}
</script>

<template>
    <Head title="Transactions Report - Daily" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Transactions Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Daily Breakdown - {{ month }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/transactions/weekly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Weekly
                    </Link>
                    <a
                        href="/reports/transactions/daily/export"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <ArrowDownTrayIcon class="size-4" />
                        Export CSV
                    </a>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Kits Requested"
                    :value="formatNumber(totals.kits_requested)"
                />
                <StatCard
                    title="Kits Received"
                    :value="formatNumber(totals.kits_received)"
                    :subtitle="`${totals.kits_received_percent}% of requests`"
                />
                <StatCard
                    title="Offers Accepted"
                    :value="formatNumber(totals.offers_accepted)"
                />
                <StatCard
                    title="Profit"
                    :value="formatCurrency(totals.profit)"
                    :subtitle="`${totals.profit_percent}% margin`"
                />
            </div>

            <!-- Data Table -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Date</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Kits Req.</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Declined</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Declined %</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Kits Rec.</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Rec. %</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Rejected</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Returned</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Offers</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Declined</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Pending</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Accepted</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Est. Value</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="row in dailyData" :key="row.period" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="whitespace-nowrap px-3 py-3 text-sm font-medium text-gray-900 dark:text-white">{{ row.period }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatNumber(row.kits_requested) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatNumber(row.kits_declined) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ row.kits_declined_percent }}%</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatNumber(row.kits_received) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ row.kits_received_percent }}%</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatNumber(row.kits_rejected) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatNumber(row.kits_returned) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatNumber(row.offers_given) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatNumber(row.offers_declined) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatNumber(row.offers_pending) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-green-600 dark:text-green-400">{{ formatNumber(row.offers_accepted) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatCurrency(row.estimated_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right" :class="row.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ formatCurrency(row.profit) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right" :class="row.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ row.profit_percent }}%</td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="dailyData.length === 0">
                                <td colspan="15" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No transaction data found for this period.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="dailyData.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td class="px-3 py-3 text-sm text-gray-900 dark:text-white">TOTALS</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_requested) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_declined) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ totals.kits_declined_percent }}%</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_received) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ totals.kits_received_percent }}%</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_rejected) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_returned) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.offers_given) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.offers_declined) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.offers_pending) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-green-600 dark:text-green-400">{{ formatNumber(totals.offers_accepted) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.estimated_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right" :class="totals.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ formatCurrency(totals.profit) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right" :class="totals.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ totals.profit_percent }}%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
