<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
import { computed } from 'vue';
import StatCard from '@/components/charts/StatCard.vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import BarChart from '@/components/charts/BarChart.vue';

interface MonthRow {
    period: string;
    month_start: string;
    items_added: number;
    cost_added: number;
    items_removed: number;
    cost_removed: number;
    net_items: number;
    net_cost: number;
}

interface Totals {
    items_added: number;
    cost_added: number;
    items_removed: number;
    cost_removed: number;
    net_items: number;
    net_cost: number;
}

const props = defineProps<{
    monthlyData: MonthRow[];
    totals: Totals;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Inventory', href: '/reports/inventory' },
    { title: 'Month over Month', href: '/reports/inventory/monthly' },
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

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

// Chart data
const chartLabels = computed(() => props.monthlyData.map(row => row.period));
const addedData = computed(() => props.monthlyData.map(row => row.cost_added));
const removedData = computed(() => props.monthlyData.map(row => row.cost_removed));
const netData = computed(() => props.monthlyData.map(row => row.net_cost));
const itemsAddedData = computed(() => props.monthlyData.map(row => row.items_added));
const itemsRemovedData = computed(() => props.monthlyData.map(row => row.items_removed));

// Trends (compare last month vs previous month)
const addedTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current = props.monthlyData[props.monthlyData.length - 1]?.cost_added || 0;
    const previous = props.monthlyData[props.monthlyData.length - 2]?.cost_added || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const netTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current = props.monthlyData[props.monthlyData.length - 1]?.net_cost || 0;
    const previous = props.monthlyData[props.monthlyData.length - 2]?.net_cost || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

// Average monthly cost added
const avgMonthlyAdded = computed(() => {
    if (props.monthlyData.length === 0) return 0;
    return props.totals.cost_added / props.monthlyData.length;
});
</script>

<template>
    <Head title="Inventory Report - Month over Month" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Inventory Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Month over Month - Past 13 Months
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/inventory"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Current
                    </Link>
                    <a
                        href="/reports/inventory/monthly/export"
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
                    title="Total Added"
                    :value="formatCurrency(totals.cost_added)"
                    :trend="addedTrend"
                    trend-label="vs last month"
                    :sparkline-data="addedData"
                />
                <StatCard
                    title="Total Removed"
                    :value="formatCurrency(totals.cost_removed)"
                    :sparkline-data="removedData"
                />
                <StatCard
                    title="Net Change"
                    :value="formatCurrency(totals.net_cost)"
                    :trend="netTrend"
                    trend-label="vs last month"
                    :sparkline-data="netData"
                />
                <StatCard
                    title="Avg Monthly Added"
                    :value="formatCurrency(avgMonthlyAdded)"
                />
            </div>

            <!-- Charts Row -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Cost Activity Chart -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Monthly Cost Activity</h3>
                    </div>
                    <div class="p-4">
                        <AreaChart
                            v-if="monthlyData.length > 0"
                            :labels="chartLabels"
                            :datasets="[
                                { label: 'Added', data: addedData, color: '#22c55e' },
                                { label: 'Removed', data: removedData, color: '#ef4444' },
                            ]"
                            :height="250"
                            :format-value="formatCurrencyShort"
                        />
                        <div v-else class="flex h-64 items-center justify-center text-gray-500">
                            No data available
                        </div>
                    </div>
                </div>

                <!-- Items Volume Chart -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Monthly Items Volume</h3>
                    </div>
                    <div class="p-4">
                        <BarChart
                            v-if="monthlyData.length > 0"
                            :labels="chartLabels"
                            :datasets="[
                                { label: 'Added', data: itemsAddedData, color: '#22c55e' },
                                { label: 'Removed', data: itemsRemovedData, color: '#ef4444' },
                            ]"
                            :height="250"
                        />
                        <div v-else class="flex h-64 items-center justify-center text-gray-500">
                            No data available
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Month</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Items Added</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost Added ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Items Removed</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost Removed ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Items</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Cost ($)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="row in monthlyData" :key="row.month_start" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-gray-900 dark:text-white">{{ row.period }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="row.items_added > 0">+{{ formatNumber(row.items_added) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="row.cost_added > 0">+{{ formatCurrency(row.cost_added) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="row.items_removed > 0">-{{ formatNumber(row.items_removed) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="row.cost_removed > 0">-{{ formatCurrency(row.cost_removed) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.net_items >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ row.net_items >= 0 ? '+' : '' }}{{ formatNumber(row.net_items) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.net_cost >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ row.net_cost >= 0 ? '+' : '' }}{{ formatCurrency(row.net_cost) }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="monthlyData.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No inventory activity found.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="monthlyData.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td class="px-4 py-4 text-sm text-gray-900 dark:text-white">TOTALS</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    +{{ formatNumber(totals.items_added) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    +{{ formatCurrency(totals.cost_added) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    -{{ formatNumber(totals.items_removed) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    -{{ formatCurrency(totals.cost_removed) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.net_items >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ totals.net_items >= 0 ? '+' : '' }}{{ formatNumber(totals.net_items) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.net_cost >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ totals.net_cost >= 0 ? '+' : '' }}{{ formatCurrency(totals.net_cost) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
