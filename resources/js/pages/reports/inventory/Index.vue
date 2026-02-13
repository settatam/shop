<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDownTrayIcon, ChevronRightIcon, HomeIcon, Squares2X2Icon, FolderIcon } from '@heroicons/vue/20/solid';
import { computed } from 'vue';
import StatCard from '@/components/charts/StatCard.vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import BarChart from '@/components/charts/BarChart.vue';

interface CategoryRow {
    category_id: number | null;
    category: string;
    total_stock: number;
    total_value: number;
    added_this_week: number;
    cost_added: number;
    deleted_this_week: number;
    deleted_cost: number;
    projected_profit: number;
    has_children?: boolean;
}

interface Totals {
    total_stock: number;
    total_value: number;
    added_this_week: number;
    cost_added: number;
    deleted_this_week: number;
    deleted_cost: number;
    projected_profit: number;
}

interface WeeklyTrend {
    week: string;
    added: number;
    removed: number;
    net: number;
}

interface CategoryBreadcrumb {
    id: number;
    name: string;
}

interface CurrentCategory {
    id: number;
    name: string;
}

const props = defineProps<{
    categoryData: CategoryRow[];
    totals: Totals;
    weeklyTrend: WeeklyTrend[];
    currentCategory?: CurrentCategory | null;
    breadcrumb?: CategoryBreadcrumb[];
    viewAll?: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Inventory', href: '/reports/inventory' },
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

// Navigation functions
function navigateToCategory(categoryId: number | null) {
    if (categoryId === null) return;
    router.get('/reports/inventory', { category_id: categoryId }, { preserveState: true });
}

function navigateToRoot() {
    router.get('/reports/inventory', {}, { preserveState: true });
}

function toggleViewAll() {
    const params: Record<string, string | number | boolean> = { view_all: !props.viewAll };
    if (props.currentCategory) {
        params.category_id = props.currentCategory.id;
    }
    router.get('/reports/inventory', params, { preserveState: true });
}

function viewCategoryItems(categoryId: number | null, event: Event) {
    event.stopPropagation();
    if (categoryId === null) {
        // For uncategorized, link to products without category
        router.get('/products', { stock: 'in_stock', uncategorized: '1' });
    } else {
        router.get('/products', { category_id: categoryId, stock: 'in_stock' });
    }
}

// Get display title based on current state
const displayTitle = computed(() => {
    if (props.viewAll) return 'All Categories';
    if (props.currentCategory) return props.currentCategory.name;
    return 'Top-Level Categories';
});

// Chart data
const chartLabels = computed(() => props.weeklyTrend.map(row => row.week));
const addedData = computed(() => props.weeklyTrend.map(row => row.added));
const removedData = computed(() => props.weeklyTrend.map(row => row.removed));
const netData = computed(() => props.weeklyTrend.map(row => row.net));

// Sparkline data for stat cards
const valueByCategory = computed(() => props.categoryData.map(row => row.total_value));
const stockByCategory = computed(() => props.categoryData.map(row => row.total_stock));

// Week over week trend for additions
const addedTrend = computed(() => {
    if (props.weeklyTrend.length < 2) return 0;
    const current = props.weeklyTrend[props.weeklyTrend.length - 1]?.added || 0;
    const previous = props.weeklyTrend[props.weeklyTrend.length - 2]?.added || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

// Profit margin calculation
const profitMargin = computed(() => {
    if (props.totals.total_value === 0) return 0;
    return (props.totals.projected_profit / props.totals.total_value) * 100;
});

// Category bar chart data
const categoryLabels = computed(() => props.categoryData.slice(0, 10).map(row => row.category));
const categoryValueData = computed(() => props.categoryData.slice(0, 10).map(row => row.total_value));

// Current view totals (sum of visible categories)
const currentViewTotals = computed(() => ({
    total_stock: props.categoryData.reduce((sum, row) => sum + row.total_stock, 0),
    total_value: props.categoryData.reduce((sum, row) => sum + row.total_value, 0),
    added_this_week: props.categoryData.reduce((sum, row) => sum + row.added_this_week, 0),
    cost_added: props.categoryData.reduce((sum, row) => sum + row.cost_added, 0),
    deleted_this_week: props.categoryData.reduce((sum, row) => sum + row.deleted_this_week, 0),
    deleted_cost: props.categoryData.reduce((sum, row) => sum + row.deleted_cost, 0),
    projected_profit: props.categoryData.reduce((sum, row) => sum + row.projected_profit, 0),
}));
</script>

<template>
    <Head title="Inventory Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Inventory Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Inventory value by category with weekly activity
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/inventory/weekly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        Weekly
                    </Link>
                    <Link
                        href="/reports/inventory/monthly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        Monthly
                    </Link>
                    <Link
                        href="/reports/inventory/yearly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        Yearly
                    </Link>
                    <a
                        href="/reports/inventory/export"
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
                    title="Total Inventory Value"
                    :value="formatCurrency(totals.total_value)"
                    :sparkline-data="valueByCategory"
                />
                <StatCard
                    title="Total Stock"
                    :value="formatNumber(totals.total_stock)"
                    :sparkline-data="stockByCategory"
                />
                <StatCard
                    title="Added This Week"
                    :value="formatCurrency(totals.cost_added)"
                    :trend="addedTrend"
                    trend-label="vs last week"
                    :sparkline-data="addedData"
                />
                <!-- Projected Profit - temporarily hidden
                <StatCard
                    title="Projected Profit"
                    :value="formatCurrency(totals.projected_profit)"
                    :class="totals.projected_profit >= 0 ? '' : 'text-red-600'"
                />
                -->
            </div>

            <!-- Charts Row -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Weekly Activity Chart -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Weekly Inventory Activity</h3>
                    </div>
                    <div class="p-4">
                        <AreaChart
                            v-if="weeklyTrend.length > 0"
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

                <!-- Top Categories by Value Chart -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Top Categories by Value</h3>
                    </div>
                    <div class="p-4">
                        <BarChart
                            v-if="categoryData.length > 0"
                            :labels="categoryLabels"
                            :datasets="[
                                { label: 'Value', data: categoryValueData, color: '#6366f1' },
                            ]"
                            :height="250"
                            :show-legend="false"
                            :format-value="formatCurrencyShort"
                        />
                        <div v-else class="flex h-64 items-center justify-center text-gray-500">
                            No data available
                        </div>
                    </div>
                </div>
            </div>

            <!-- Category Navigation & Data Table -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <!-- Category Breadcrumb Navigation -->
                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <nav class="flex items-center gap-2">
                            <!-- Home/Root link -->
                            <button
                                @click="navigateToRoot"
                                class="flex items-center gap-1 rounded px-2 py-1 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                :class="{ 'text-indigo-600 dark:text-indigo-400': !currentCategory && !viewAll }"
                            >
                                <HomeIcon class="size-4" />
                                <span>All Categories</span>
                            </button>

                            <!-- Breadcrumb items -->
                            <template v-if="breadcrumb && breadcrumb.length > 0">
                                <template v-for="crumb in breadcrumb" :key="crumb.id">
                                    <ChevronRightIcon class="size-4 text-gray-400" />
                                    <button
                                        @click="navigateToCategory(crumb.id)"
                                        class="rounded px-2 py-1 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                    >
                                        {{ crumb.name }}
                                    </button>
                                </template>
                            </template>

                            <!-- Current category -->
                            <template v-if="currentCategory">
                                <ChevronRightIcon class="size-4 text-gray-400" />
                                <span class="rounded bg-indigo-50 px-2 py-1 text-sm font-semibold text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">
                                    {{ currentCategory.name }}
                                </span>
                            </template>
                        </nav>

                        <!-- View toggle -->
                        <button
                            @click="toggleViewAll"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                            :class="viewAll
                                ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300'
                                : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700'"
                        >
                            <Squares2X2Icon class="size-4" />
                            {{ viewAll ? 'Hierarchical View' : 'Flat View' }}
                        </button>
                    </div>

                    <!-- Current view description -->
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        <template v-if="viewAll">
                            Showing all categories in a flat list
                        </template>
                        <template v-else-if="currentCategory">
                            Showing subcategories of "{{ currentCategory.name }}"
                        </template>
                        <template v-else>
                            Showing top-level categories. Click a category to drill down.
                        </template>
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Category</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Stock</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Value ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Added This Week</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost Added ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Deleted This Week</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Deleted Cost ($)</th>
                                <!-- <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Projected Profit ($)</th> -->
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr
                                v-for="row in categoryData"
                                :key="row.category_id ?? 'uncategorized'"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                                :class="{ 'cursor-pointer': row.has_children && row.category_id !== null }"
                                @click="row.has_children && row.category_id !== null ? navigateToCategory(row.category_id) : null"
                            >
                                <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-gray-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <FolderIcon
                                            v-if="row.has_children"
                                            class="size-4 text-amber-500"
                                        />
                                        <span>{{ row.category }}</span>
                                        <ChevronRightIcon
                                            v-if="row.has_children"
                                            class="size-4 text-gray-400"
                                        />
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right">
                                    <button
                                        v-if="row.total_stock > 0"
                                        @click="viewCategoryItems(row.category_id, $event)"
                                        class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        {{ formatNumber(row.total_stock) }}
                                    </button>
                                    <span v-else class="text-gray-400">0</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">
                                    {{ formatCurrency(row.total_value) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="row.added_this_week > 0">+{{ formatNumber(row.added_this_week) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="row.cost_added > 0">+{{ formatCurrency(row.cost_added) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="row.deleted_this_week > 0">-{{ formatNumber(row.deleted_this_week) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="row.deleted_cost > 0">-{{ formatCurrency(row.deleted_cost) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <!-- <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.projected_profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(row.projected_profit) }}
                                </td> -->
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="categoryData.length === 0">
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    <template v-if="currentCategory">
                                        No subcategories with inventory found in "{{ currentCategory.name }}".
                                    </template>
                                    <template v-else>
                                        No inventory data found.
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row for current view -->
                        <tfoot v-if="categoryData.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td class="px-4 py-4 text-sm text-gray-900 dark:text-white">
                                    {{ viewAll || currentCategory ? 'SUBTOTAL' : 'TOTALS' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">
                                    {{ formatNumber(currentViewTotals.total_stock) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-gray-900 dark:text-white">
                                    {{ formatCurrency(currentViewTotals.total_value) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="currentViewTotals.added_this_week > 0">+{{ formatNumber(currentViewTotals.added_this_week) }}</span>
                                    <span v-else>-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="currentViewTotals.cost_added > 0">+{{ formatCurrency(currentViewTotals.cost_added) }}</span>
                                    <span v-else>-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="currentViewTotals.deleted_this_week > 0">-{{ formatNumber(currentViewTotals.deleted_this_week) }}</span>
                                    <span v-else>-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="currentViewTotals.deleted_cost > 0">-{{ formatCurrency(currentViewTotals.deleted_cost) }}</span>
                                    <span v-else>-</span>
                                </td>
                                <!-- <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="currentViewTotals.projected_profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(currentViewTotals.projected_profit) }}
                                </td> -->
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
