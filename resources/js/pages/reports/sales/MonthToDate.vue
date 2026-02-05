<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
import { computed } from 'vue';
import StatCard from '@/components/charts/StatCard.vue';
import AreaChart from '@/components/charts/AreaChart.vue';

interface Channel {
    id: number | null;
    name: string;
    code: string;
    type: string;
    is_local: boolean;
    color: string | null;
}

interface DayRow {
    date: string;
    sales_count: number;
    items_sold: number;
    total_cost: number;
    total_wholesale_value: number;
    total_sales_price: number;
    total_paid: number;
    gross_profit: number;
    profit_percent: number;
    [key: string]: string | number; // Allow dynamic channel keys
}

interface Totals {
    sales_count: number;
    items_sold: number;
    total_cost: number;
    total_wholesale_value: number;
    total_sales_price: number;
    total_paid: number;
    gross_profit: number;
    profit_percent: number;
    [key: string]: number; // Allow dynamic channel keys
}

const props = defineProps<{
    dailyData: DayRow[];
    totals: Totals;
    month: string;
    channels: Channel[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Sales (Month to Date)', href: '/reports/sales/mtd' },
];

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatCurrencyShort(value: number): string {
    if (value >= 1000000) {
        return '$' + (value / 1000000).toFixed(1) + 'M';
    }
    if (value >= 1000) {
        return '$' + (value / 1000).toFixed(1) + 'K';
    }
    return '$' + value.toFixed(0);
}

function formatPercent(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'percent',
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    }).format(value / 100);
}

function getChannelKey(channel: Channel): string {
    return `total_${channel.code}`;
}

function getChannelTotal(row: DayRow, channel: Channel): number {
    const key = getChannelKey(channel);
    return (row[key] as number) ?? 0;
}

function getTotalsChannelValue(channel: Channel): number {
    const key = getChannelKey(channel);
    return props.totals[key] ?? 0;
}

// Chart data
const chartLabels = computed(() => props.dailyData.map(row => {
    // Extract just the day number from the date
    const parts = row.date.split('/');
    return parts.length > 1 ? parts[1] : row.date;
}));

const revenueData = computed(() => props.dailyData.map(row => row.total_paid));
const profitData = computed(() => props.dailyData.map(row => row.gross_profit));
const salesCountData = computed(() => props.dailyData.map(row => row.sales_count));

// Calculate averages
const avgDailyRevenue = computed(() => {
    if (props.dailyData.length === 0) return 0;
    return props.totals.total_paid / props.dailyData.length;
});

const avgDailyProfit = computed(() => {
    if (props.dailyData.length === 0) return 0;
    return props.totals.gross_profit / props.dailyData.length;
});

// Week over week trend (compare last 7 days vs previous 7 days)
const revenueTrend = computed(() => {
    if (props.dailyData.length < 14) return 0;
    const last7 = props.dailyData.slice(-7).reduce((sum, row) => sum + row.total_paid, 0);
    const prev7 = props.dailyData.slice(-14, -7).reduce((sum, row) => sum + row.total_paid, 0);
    if (prev7 === 0) return last7 > 0 ? 100 : 0;
    return ((last7 - prev7) / Math.abs(prev7)) * 100;
});

// Average profit margin
const avgProfitMargin = computed(() => {
    if (props.totals.total_paid === 0) return 0;
    return (props.totals.gross_profit / props.totals.total_paid) * 100;
});
</script>

<template>
    <Head title="Month to Date Sales Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Month to Date Sales Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Daily breakdown for {{ month }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <a
                        href="/reports/sales/mtd/export"
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
                    title="Total Revenue"
                    :value="formatCurrency(totals.total_paid)"
                    :trend="revenueTrend"
                    trend-label="vs prev week"
                    :sparkline-data="revenueData"
                />
                <StatCard
                    title="Gross Profit"
                    :value="formatCurrency(totals.gross_profit)"
                    :sparkline-data="profitData"
                />
                <StatCard
                    title="Avg Daily Revenue"
                    :value="formatCurrency(avgDailyRevenue)"
                />
                <StatCard
                    title="Profit Margin"
                    :value="formatPercent(avgProfitMargin)"
                />
            </div>

            <!-- Chart -->
            <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Daily Revenue & Profit</h3>
                </div>
                <div class="p-4">
                    <AreaChart
                        v-if="dailyData.length > 0"
                        :labels="chartLabels"
                        :datasets="[
                            { label: 'Revenue', data: revenueData, color: '#6366f1' },
                            { label: 'Profit', data: profitData, color: '#22c55e' },
                        ]"
                        :height="250"
                        :format-value="formatCurrencyShort"
                    />
                    <div v-else class="flex h-64 items-center justify-center text-gray-500">
                        No data available
                    </div>
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
                                <!-- Dynamic channel columns -->
                                <th
                                    v-for="channel in channels"
                                    :key="channel.code"
                                    class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300"
                                >
                                    {{ channel.name }}
                                </th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Paid</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Gross Profit</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="row in dailyData" :key="row.date" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900 dark:text-white">{{ row.date }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ row.sales_count }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ row.items_sold }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.total_cost) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.total_wholesale_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.total_sales_price) }}</td>
                                <!-- Dynamic channel values -->
                                <td
                                    v-for="channel in channels"
                                    :key="channel.code"
                                    class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-500 dark:text-gray-400"
                                >
                                    {{ formatCurrency(getChannelTotal(row, channel)) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(row.total_paid) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right" :class="row.gross_profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(row.gross_profit) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right" :class="row.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatPercent(row.profit_percent) }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="dailyData.length === 0">
                                <td :colspan="9 + channels.length" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No sales data found for this month.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="dailyData.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td class="px-3 py-4 text-sm text-gray-900 dark:text-white">TOTALS</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ totals.sales_count }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ totals.items_sold }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.total_cost) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.total_wholesale_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.total_sales_price) }}</td>
                                <!-- Dynamic channel totals -->
                                <td
                                    v-for="channel in channels"
                                    :key="channel.code"
                                    class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-500 dark:text-gray-400"
                                >
                                    {{ formatCurrency(getTotalsChannelValue(channel)) }}
                                </td>
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
