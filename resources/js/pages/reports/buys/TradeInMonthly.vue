<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';

interface MonthRow {
    date: string;
    start_date: string;
    end_date: string;
    buys_count: number;
    purchase_amt: number;
    estimated_value: number;
    profit: number;
    profit_percent: number;
    avg_buy_price: number;
}

interface Totals {
    buys_count: number;
    purchase_amt: number;
    estimated_value: number;
    profit: number;
    profit_percent: number;
    avg_buy_price: number;
}

defineProps<{
    monthlyData: MonthRow[];
    totals: Totals;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Buys (Trade-In)', href: '/reports/buys/trade-in' },
    { title: 'Monthly', href: '/reports/buys/trade-in/monthly' },
];

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatPercent(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'percent',
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    }).format(value / 100);
}

function viewBuys(row: MonthRow): void {
    router.visit(`/transactions?date_from=${row.start_date}&date_to=${row.end_date}&status=payment_processed`);
}
</script>

<template>
    <Head title="Trade-In Buys Report (Month over Month)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Trade-In Buys Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Month over Month - Past 13 Months
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/buys/trade-in/yearly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Year over Year
                    </Link>
                    <Link
                        href="/reports/buys/trade-in"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Month to Date
                    </Link>
                    <a
                        href="/reports/buys/trade-in/monthly/export"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <ArrowDownTrayIcon class="size-4" />
                        Export CSV
                    </a>
                </div>
            </div>

            <!-- Data Table -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300"># of Buys</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Purchase Amt</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Estimated Value</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit %</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Avg Buy Price</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="row in monthlyData" :key="row.date" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700" @click="viewBuys(row)">
                                <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-gray-900 dark:text-white">{{ row.date }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">{{ row.buys_count }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.purchase_amt) }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.estimated_value) }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(row.profit) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatPercent(row.profit_percent) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.avg_buy_price) }}</td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="monthlyData.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No buys data found.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="monthlyData.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td class="px-4 py-4 text-sm text-gray-900 dark:text-white">TOTALS</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">{{ totals.buys_count }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.purchase_amt) }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.estimated_value) }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(totals.profit) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatPercent(totals.profit_percent) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.avg_buy_price) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
