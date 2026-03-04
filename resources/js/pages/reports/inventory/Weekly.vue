<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ReportTable from '@/components/widgets/ReportTable.vue';
import StatCard from '@/components/charts/StatCard.vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import BarChart from '@/components/charts/BarChart.vue';

interface WeekRow {
    period: string;
    week_start: string;
    items_added: number;
    cost_added: number;
    wholesale_added: number;
    items_removed: number;
    cost_removed: number;
    wholesale_removed: number;
    net_items: number;
    net_cost: number;
    net_wholesale: number;
}

interface Totals {
    items_added: number;
    cost_added: number;
    wholesale_added: number;
    items_removed: number;
    cost_removed: number;
    wholesale_removed: number;
    net_items: number;
    net_cost: number;
    net_wholesale: number;
}

const props = defineProps<{
    weeklyData: WeekRow[];
    totals: Totals;
    startMonth: number;
    startYear: number;
    endMonth: number;
    endYear: number;
    dateRangeLabel: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Inventory', href: '/reports/inventory' },
    { title: 'Week over Week', href: '/reports/inventory/weekly' },
];

// Date range filters
const startMonth = ref(props.startMonth);
const startYear = ref(props.startYear);
const endMonth = ref(props.endMonth);
const endYear = ref(props.endYear);

const months = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

const currentYear = new Date().getFullYear();
const years = Array.from({ length: 10 }, (_, i) => currentYear - i);

function applyFilters() {
    router.get('/reports/inventory/weekly', {
        start_month: startMonth.value,
        start_year: startYear.value,
        end_month: endMonth.value,
        end_year: endYear.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetToLast3Months() {
    const now = new Date();
    const past = new Date(now.getFullYear(), now.getMonth() - 3, 1);
    startMonth.value = past.getMonth() + 1;
    startYear.value = past.getFullYear();
    endMonth.value = now.getMonth() + 1;
    endYear.value = now.getFullYear();
    applyFilters();
}

const queryParams = computed(() => {
    return `start_month=${startMonth.value}&start_year=${startYear.value}&end_month=${endMonth.value}&end_year=${endYear.value}`;
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

const exportUrl = computed(() => `/reports/inventory/weekly/export?${queryParams.value}`);
const emailUrl = computed(() => `/reports/inventory/weekly/email?${queryParams.value}`);
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
                        Week over Week - {{ dateRangeLabel }}
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

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Start Month</label>
                    <div class="flex items-center gap-1">
                        <select
                            v-model="startMonth"
                            class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            @change="applyFilters"
                        >
                            <option v-for="month in months" :key="month.value" :value="month.value">
                                {{ month.label }}
                            </option>
                        </select>
                        <select
                            v-model="startYear"
                            class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            @change="applyFilters"
                        >
                            <option v-for="year in years" :key="year" :value="year">
                                {{ year }}
                            </option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">End Month</label>
                    <div class="flex items-center gap-1">
                        <select
                            v-model="endMonth"
                            class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            @change="applyFilters"
                        >
                            <option v-for="month in months" :key="month.value" :value="month.value">
                                {{ month.label }}
                            </option>
                        </select>
                        <select
                            v-model="endYear"
                            class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            @change="applyFilters"
                        >
                            <option v-for="year in years" :key="year" :value="year">
                                {{ year }}
                            </option>
                        </select>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    @click="resetToLast3Months"
                >
                    Last 3 Months
                </button>
            </div>

            <!-- Cost Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Cost Added"
                    :value="formatCurrency(totals.cost_added)"
                    :sparkline-data="addedData"
                />
                <StatCard
                    title="Cost Removed"
                    :value="formatCurrency(totals.cost_removed)"
                    :sparkline-data="removedData"
                />
                <StatCard
                    title="Net Cost"
                    :value="formatCurrency(totals.net_cost)"
                    :sparkline-data="netData"
                />
                <StatCard
                    title="Net Items"
                    :value="formatNumber(totals.net_items)"
                />
            </div>

            <!-- Wholesale Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard
                    title="Wholesale Added"
                    :value="formatCurrency(totals.wholesale_added)"
                />
                <StatCard
                    title="Wholesale Removed"
                    :value="formatCurrency(totals.wholesale_removed)"
                />
                <StatCard
                    title="Net Wholesale"
                    :value="formatCurrency(totals.net_wholesale)"
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
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Wholesale Added ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Items Removed</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost Removed ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Wholesale Removed ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Items</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Cost ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Wholesale ($)</th>
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
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="row.wholesale_added > 0">+{{ formatCurrency(row.wholesale_added) }}</span>
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
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="row.wholesale_removed > 0">-{{ formatCurrency(row.wholesale_removed) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.net_items >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ row.net_items >= 0 ? '+' : '' }}{{ formatNumber(row.net_items) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.net_cost >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ row.net_cost >= 0 ? '+' : '' }}{{ formatCurrency(row.net_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.net_wholesale >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ row.net_wholesale >= 0 ? '+' : '' }}{{ formatCurrency(row.net_wholesale) }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="weeklyData.length === 0">
                                <td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
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
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    +{{ formatCurrency(totals.wholesale_added) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    -{{ formatNumber(totals.items_removed) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    -{{ formatCurrency(totals.cost_removed) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    -{{ formatCurrency(totals.wholesale_removed) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.net_items >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ totals.net_items >= 0 ? '+' : '' }}{{ formatNumber(totals.net_items) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.net_cost >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ totals.net_cost >= 0 ? '+' : '' }}{{ formatCurrency(totals.net_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.net_wholesale >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ totals.net_wholesale >= 0 ? '+' : '' }}{{ formatCurrency(totals.net_wholesale) }}
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
