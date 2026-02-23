<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ReportTable from '@/components/widgets/ReportTable.vue';
import StatCard from '@/components/charts/StatCard.vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import BarChart from '@/components/charts/BarChart.vue';

interface WeekRow {
    period: string;
    week_start: string;
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

interface FilterInfo {
    type: string;
    value: string;
    label: string;
}

const props = defineProps<{
    weeklyData: WeekRow[];
    totals: Totals;
    filter?: FilterInfo;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const items: BreadcrumbItem[] = [
        { title: 'Reports', href: '#' },
        { title: 'Inventory', href: '/reports/inventory' },
        { title: 'Week over Week', href: '/reports/inventory/weekly' },
    ];

    if (props.filter) {
        items.push({ title: props.filter.label, href: '#' });
    }

    return items;
});

const subtitle = computed(() => {
    if (props.filter) {
        return `Weekly Breakdown for ${props.filter.label}`;
    }
    return 'Week over Week - Past 13 Weeks';
});

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
const chartLabels = computed(() => props.weeklyData.map(row => row.period.split(',')[0]));
const addedData = computed(() => props.weeklyData.map(row => row.cost_added));
const removedData = computed(() => props.weeklyData.map(row => row.cost_removed));
const netData = computed(() => props.weeklyData.map(row => row.net_cost));
const itemsAddedData = computed(() => props.weeklyData.map(row => row.items_added));
const itemsRemovedData = computed(() => props.weeklyData.map(row => row.items_removed));

// Trends (compare last week vs previous week)
const addedTrend = computed(() => {
    if (props.weeklyData.length < 2) return 0;
    const current = props.weeklyData[props.weeklyData.length - 1]?.cost_added || 0;
    const previous = props.weeklyData[props.weeklyData.length - 2]?.cost_added || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const netTrend = computed(() => {
    if (props.weeklyData.length < 2) return 0;
    const current = props.weeklyData[props.weeklyData.length - 1]?.net_cost || 0;
    const previous = props.weeklyData[props.weeklyData.length - 2]?.net_cost || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const exportUrl = '/reports/inventory/weekly/export';
const emailUrl = '/reports/inventory/weekly/email';
</script>

<template>
    <Head title="Inventory Report - Week over Week" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Inventory Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ subtitle }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/inventory"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Current
                    </Link>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Total Added"
                    :value="formatCurrency(totals.cost_added)"
                    :trend="addedTrend"
                    trend-label="vs last week"
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
                    trend-label="vs last week"
                    :sparkline-data="netData"
                />
                <StatCard
                    title="Net Items"
                    :value="formatNumber(totals.net_items)"
                />
            </div>

            <!-- Charts Row -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Cost Activity Chart -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Weekly Cost Activity</h3>
                    </div>
                    <div class="p-4">
                        <AreaChart
                            v-if="weeklyData.length > 0"
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
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Weekly Items Volume</h3>
                    </div>
                    <div class="p-4">
                        <BarChart
                            v-if="weeklyData.length > 0"
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
            <ReportTable title="Weekly Inventory Data" :export-url="exportUrl" :email-url="emailUrl">
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Week</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Items Added</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost Added ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Items Removed</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost Removed ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Items</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Cost ($)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="row in weeklyData" :key="row.week_start" class="hover:bg-gray-50 dark:hover:bg-gray-700">
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
                            <tr v-if="weeklyData.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No inventory activity found.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="weeklyData.length > 0" class="bg-gray-100 dark:bg-gray-700">
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
            </ReportTable>
        </div>
    </AppLayout>
</template>
