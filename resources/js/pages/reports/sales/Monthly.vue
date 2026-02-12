<script setup lang="ts">
import AreaChart from '@/components/charts/AreaChart.vue';
import BarChart from '@/components/charts/BarChart.vue';
import StatCard from '@/components/charts/StatCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Channel {
    id: number | null;
    name: string;
    code: string;
    type: string;
    is_local: boolean;
    color: string | null;
}

interface MonthRow {
    date: string;
    start_date: string;
    end_date: string;
    sales_count: number;
    items_sold: number;
    total_cost: number;
    total_wholesale_value: number;
    total_sales_price: number;
    total_service_fee: number;
    total_tax: number;
    total_shipping: number;
    total_paid: number;
    gross_profit: number;
    profit_percent: number;
    [key: string]: string | number; // Allow dynamic channel keys like total_shopify, total_in_store
}

interface Totals {
    sales_count: number;
    items_sold: number;
    total_cost: number;
    total_wholesale_value: number;
    total_sales_price: number;
    total_service_fee: number;
    total_tax: number;
    total_shipping: number;
    total_paid: number;
    gross_profit: number;
    profit_percent: number;
    [key: string]: number; // Allow dynamic channel keys
}

const props = defineProps<{
    monthlyData: MonthRow[];
    totals: Totals;
    channels: Channel[];
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

function getChannelTotal(row: MonthRow, channel: Channel): number {
    const key = getChannelKey(channel);
    return (row[key] as number) ?? 0;
}

function getTotalsChannelValue(channel: Channel): number {
    const key = getChannelKey(channel);
    return props.totals[key] ?? 0;
}

// Chart data
const chartLabels = computed(() => props.monthlyData.map((row) => row.date));

const revenueData = computed(() =>
    props.monthlyData.map((row) => row.total_paid),
);
const profitData = computed(() =>
    props.monthlyData.map((row) => row.gross_profit),
);
const salesCountData = computed(() =>
    props.monthlyData.map((row) => row.sales_count),
);

// Calculate trend (comparing last month to previous)
const revenueTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current =
        props.monthlyData[props.monthlyData.length - 1]?.total_paid || 0;
    const previous =
        props.monthlyData[props.monthlyData.length - 2]?.total_paid || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const profitTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current =
        props.monthlyData[props.monthlyData.length - 1]?.gross_profit || 0;
    const previous =
        props.monthlyData[props.monthlyData.length - 2]?.gross_profit || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const salesTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current =
        props.monthlyData[props.monthlyData.length - 1]?.sales_count || 0;
    const previous =
        props.monthlyData[props.monthlyData.length - 2]?.sales_count || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

// Average profit margin
const avgProfitMargin = computed(() => {
    if (props.totals.total_paid === 0) return 0;
    return (props.totals.gross_profit / props.totals.total_paid) * 100;
});

function viewSales(row: MonthRow): void {
    router.visit(`/orders?from_date=${row.start_date}&to_date=${row.end_date}`);
}
</script>

<template>
    <Head title="Monthly Sales Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Month over Month Sales Report
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Aggregated sales for the past 13 months
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <a
                        href="/reports/sales/monthly/export"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
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
                    trend-label="vs last month"
                    :sparkline-data="revenueData"
                />
                <StatCard
                    title="Gross Profit"
                    :value="formatCurrency(totals.gross_profit)"
                    :trend="profitTrend"
                    trend-label="vs last month"
                    :sparkline-data="profitData"
                />
                <StatCard
                    title="Total Sales"
                    :value="totals.sales_count.toLocaleString()"
                    :trend="salesTrend"
                    trend-label="vs last month"
                    :sparkline-data="salesCountData"
                />
                <StatCard
                    title="Avg Profit Margin"
                    :value="formatPercent(avgProfitMargin)"
                    :trend="0"
                />
            </div>

            <!-- Charts Row -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Revenue & Profit Chart -->
                <div
                    class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <div
                        class="border-b border-gray-200 px-4 py-4 dark:border-gray-700"
                    >
                        <h3
                            class="text-base font-semibold text-gray-900 dark:text-white"
                        >
                            Revenue vs Profit
                        </h3>
                    </div>
                    <div class="p-4">
                        <AreaChart
                            v-if="monthlyData.length > 0"
                            :labels="chartLabels"
                            :datasets="[
                                {
                                    label: 'Revenue',
                                    data: revenueData,
                                    color: '#6366f1',
                                },
                                {
                                    label: 'Profit',
                                    data: profitData,
                                    color: '#22c55e',
                                },
                            ]"
                            :height="250"
                            :format-value="formatCurrencyShort"
                        />
                        <div
                            v-else
                            class="flex h-64 items-center justify-center text-gray-500"
                        >
                            No data available
                        </div>
                    </div>
                </div>

                <!-- Sales Count Bar Chart -->
                <div
                    class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <div
                        class="border-b border-gray-200 px-4 py-4 dark:border-gray-700"
                    >
                        <h3
                            class="text-base font-semibold text-gray-900 dark:text-white"
                        >
                            Sales Volume by Month
                        </h3>
                    </div>
                    <div class="p-4">
                        <BarChart
                            v-if="monthlyData.length > 0"
                            :labels="chartLabels"
                            :datasets="[
                                {
                                    label: 'Sales',
                                    data: salesCountData,
                                    color: '#6366f1',
                                },
                            ]"
                            :height="250"
                            :show-legend="false"
                        />
                        <div
                            v-else
                            class="flex h-64 items-center justify-center text-gray-500"
                        >
                            No data available
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div
                class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10"
            >
                <div class="overflow-x-auto">
                    <table
                        class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                    >
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Month
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Sales #
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Items Sold
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Total Cost
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Wholesale Value
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Sales Price
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Service Fee
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Tax
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Shipping
                                </th>
                                <!-- Dynamic channel columns -->
                                <th
                                    v-for="channel in channels"
                                    :key="channel.code"
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    {{ channel.name }}
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Total Paid
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Gross Profit
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Profit %
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                        >
                            <tr
                                v-for="row in monthlyData"
                                :key="row.date"
                                class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
                                @click="viewSales(row)"
                            >
                                <td
                                    class="px-3 py-4 text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.date }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.sales_count }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.items_sold }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.total_cost) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{
                                        formatCurrency(
                                            row.total_wholesale_value,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.total_sales_price) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.total_service_fee) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.total_tax) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.total_shipping) }}
                                </td>
                                <!-- Dynamic channel values -->
                                <td
                                    v-for="channel in channels"
                                    :key="channel.code"
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{
                                        formatCurrency(
                                            getChannelTotal(row, channel),
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.total_paid) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        row.gross_profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(row.gross_profit) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        row.profit_percent >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatPercent(row.profit_percent) }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="monthlyData.length === 0">
                                <td
                                    :colspan="12 + channels.length"
                                    class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No sales data found.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot
                            v-if="monthlyData.length > 0"
                            class="bg-gray-100 dark:bg-gray-700"
                        >
                            <tr class="font-semibold">
                                <td
                                    class="px-3 py-4 text-sm text-gray-900 dark:text-white"
                                >
                                    TOTALS
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.sales_count }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.items_sold }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.total_cost) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{
                                        formatCurrency(
                                            totals.total_wholesale_value,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{
                                        formatCurrency(totals.total_sales_price)
                                    }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{
                                        formatCurrency(totals.total_service_fee)
                                    }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.total_tax) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.total_shipping) }}
                                </td>
                                <!-- Dynamic channel totals -->
                                <td
                                    v-for="channel in channels"
                                    :key="channel.code"
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{
                                        formatCurrency(
                                            getTotalsChannelValue(channel),
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.total_paid) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        totals.gross_profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(totals.gross_profit) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        totals.profit_percent >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
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
