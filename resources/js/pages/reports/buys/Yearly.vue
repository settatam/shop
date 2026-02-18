<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
import { computed } from 'vue';
import StatCard from '@/components/charts/StatCard.vue';
import BarChart from '@/components/charts/BarChart.vue';

interface YearRow {
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

const props = defineProps<{
    yearlyData: YearRow[];
    totals: Totals;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Buys Report', href: '/reports/buys' },
    { title: 'Yearly', href: '/reports/buys/yearly' },
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

// Chart data
const chartLabels = computed(() => props.yearlyData.map((row) => row.date));
const purchaseAmtData = computed(() =>
    props.yearlyData.map((row) => row.purchase_amt),
);
const estimatedValueData = computed(() =>
    props.yearlyData.map((row) => row.estimated_value),
);
const profitData = computed(() => props.yearlyData.map((row) => row.profit));
const buysCountData = computed(() =>
    props.yearlyData.map((row) => row.buys_count),
);

// Trends
const purchaseTrend = computed(() => {
    if (props.yearlyData.length < 2) return 0;
    const current =
        props.yearlyData[props.yearlyData.length - 1]?.purchase_amt || 0;
    const previous =
        props.yearlyData[props.yearlyData.length - 2]?.purchase_amt || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const profitTrend = computed(() => {
    if (props.yearlyData.length < 2) return 0;
    const current =
        props.yearlyData[props.yearlyData.length - 1]?.profit || 0;
    const previous =
        props.yearlyData[props.yearlyData.length - 2]?.profit || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const buysTrend = computed(() => {
    if (props.yearlyData.length < 2) return 0;
    const current =
        props.yearlyData[props.yearlyData.length - 1]?.buys_count || 0;
    const previous =
        props.yearlyData[props.yearlyData.length - 2]?.buys_count || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

// Average profit margin
const avgProfitMargin = computed(() => {
    if (props.totals.estimated_value === 0) return 0;
    return (props.totals.profit / props.totals.estimated_value) * 100;
});

function viewBuys(row: YearRow): void {
    router.visit(
        `/transactions?date_from=${row.start_date}&date_to=${row.end_date}&status=payment_processed`,
    );
}
</script>

<template>
    <Head title="Buys Report (Year over Year)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Buys Report
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Year over Year - Past 5 Years
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/buys/monthly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Month over Month
                    </Link>
                    <Link
                        href="/reports/buys"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Month to Date
                    </Link>
                    <a
                        href="/reports/buys/yearly/export"
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
                    title="Total Purchased"
                    :value="formatCurrency(totals.purchase_amt)"
                    :trend="purchaseTrend"
                    trend-label="vs last year"
                    :sparkline-data="purchaseAmtData"
                />
                <StatCard
                    title="Total Expected Profit"
                    :value="formatCurrency(totals.profit)"
                    :trend="profitTrend"
                    trend-label="vs last year"
                    :sparkline-data="profitData"
                />
                <StatCard
                    title="Total Buys"
                    :value="totals.buys_count.toLocaleString()"
                    :trend="buysTrend"
                    trend-label="vs last year"
                    :sparkline-data="buysCountData"
                />
                <StatCard
                    title="Avg Profit Margin"
                    :value="formatPercent(avgProfitMargin)"
                />
            </div>

            <!-- Chart -->
            <div
                class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
            >
                <div
                    class="border-b border-gray-200 px-4 py-4 dark:border-gray-700"
                >
                    <h3
                        class="text-base font-semibold text-gray-900 dark:text-white"
                    >
                        Yearly Purchase vs Estimated Value
                    </h3>
                </div>
                <div class="p-4">
                    <BarChart
                        v-if="yearlyData.length > 0"
                        :labels="chartLabels"
                        :datasets="[
                            {
                                label: 'Estimated Value',
                                data: estimatedValueData,
                                color: '#6366f1',
                            },
                            {
                                label: 'Purchase Amount',
                                data: purchaseAmtData,
                                color: '#f59e0b',
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
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Year
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    # of Buys
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Purchase Amt
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Estimated Value
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Profit
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Profit %
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Avg Buy Price
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                        >
                            <tr
                                v-for="row in yearlyData"
                                :key="row.date"
                                class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
                                @click="viewBuys(row)"
                            >
                                <td
                                    class="px-4 py-4 text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.date }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.buys_count }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.purchase_amt) }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.estimated_value) }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        row.profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(row.profit) }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        row.profit_percent >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatPercent(row.profit_percent) }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.avg_buy_price) }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="yearlyData.length === 0">
                                <td
                                    colspan="7"
                                    class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No buys data found.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot
                            v-if="yearlyData.length > 0"
                            class="bg-gray-100 dark:bg-gray-700"
                        >
                            <tr class="font-semibold">
                                <td
                                    class="px-4 py-4 text-sm text-gray-900 dark:text-white"
                                >
                                    TOTALS
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.buys_count }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.purchase_amt) }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.estimated_value) }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        totals.profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(totals.profit) }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        totals.profit_percent >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatPercent(totals.profit_percent) }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.avg_buy_price) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
