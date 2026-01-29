<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';

interface MonthRow {
    date: string;
    sales_count: number;
    items_sold: number;
    total_cost: number;
    total_wholesale_value: number;
    total_sales_price: number;
    total_shopify: number;
    total_reb: number;
    total_paid: number;
    gross_profit: number;
    profit_percent: number;
}

interface Totals {
    sales_count: number;
    items_sold: number;
    total_cost: number;
    total_wholesale_value: number;
    total_sales_price: number;
    total_shopify: number;
    total_reb: number;
    total_paid: number;
    gross_profit: number;
    profit_percent: number;
}

defineProps<{
    monthlyData: MonthRow[];
    totals: Totals;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Sales (Month over Month)', href: '/reports/sales/monthly' },
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
</script>

<template>
    <Head title="Monthly Sales Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Month over Month Sales Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Aggregated sales for the past 13 months
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/sales/monthly/export"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <ArrowDownTrayIcon class="size-4" />
                        Export CSV
                    </Link>
                </div>
            </div>

            <!-- Data Table -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Date</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Sales #</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Items Sold</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Cost</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Wholesale Value</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Sales Price</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Shopify</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">REB</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Paid</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Gross Profit</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="row in monthlyData" :key="row.date" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900 dark:text-white">{{ row.date }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ row.sales_count }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ row.items_sold }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.total_cost) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.total_wholesale_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.total_sales_price) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatCurrency(row.total_shopify) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatCurrency(row.total_reb) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.total_paid) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right" :class="row.gross_profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(row.gross_profit) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right" :class="row.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatPercent(row.profit_percent) }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="monthlyData.length === 0">
                                <td colspan="11" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No sales data found.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="monthlyData.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td class="px-3 py-4 text-sm text-gray-900 dark:text-white">TOTALS</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ totals.sales_count }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ totals.items_sold }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.total_cost) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.total_wholesale_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.total_sales_price) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatCurrency(totals.total_shopify) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatCurrency(totals.total_reb) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.total_paid) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right" :class="totals.gross_profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(totals.gross_profit) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right" :class="totals.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatPercent(totals.profit_percent) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
