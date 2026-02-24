<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import StatCard from '@/components/charts/StatCard.vue';
import BarChart from '@/components/charts/BarChart.vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import ReportTable from '@/components/widgets/ReportTable.vue';

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

interface Category {
    value: number;
    label: string;
    depth: number;
    isLeaf: boolean;
}

interface CategoryBreakdownRow {
    category_id: number;
    category_name: string;
    is_leaf: boolean;
    parent_id: number | null;
    root_category_id: number | null;
    items_count: number;
    transactions_count: number;
    total_purchase: number;
    total_estimated_value: number;
    total_profit: number;
}

interface Filters {
    category_id?: string;
}

const props = defineProps<{
    monthlyData: MonthRow[];
    totals: Totals;
    startMonth: number;
    startYear: number;
    endMonth: number;
    endYear: number;
    dateRangeLabel: string;
    categories: Category[];
    categoryBreakdown: CategoryBreakdownRow[];
    filters: Filters;
}>();

// Date range filters
const startMonth = ref(props.startMonth);
const startYear = ref(props.startYear);
const endMonth = ref(props.endMonth);
const endYear = ref(props.endYear);
const categoryId = ref(props.filters?.category_id || '');

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
    const params: Record<string, string | number> = {
        start_month: startMonth.value,
        start_year: startYear.value,
        end_month: endMonth.value,
        end_year: endYear.value,
    };
    if (categoryId.value) {
        params.category_id = categoryId.value;
    }
    router.get('/reports/buys/monthly', params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetToLast12Months() {
    const now = new Date();
    const past = new Date(now.getFullYear(), now.getMonth() - 12, 1);
    startMonth.value = past.getMonth() + 1;
    startYear.value = past.getFullYear();
    endMonth.value = now.getMonth() + 1;
    endYear.value = now.getFullYear();
    applyFilters();
}

const queryParams = computed(() => {
    let params = `start_month=${startMonth.value}&start_year=${startYear.value}&end_month=${endMonth.value}&end_year=${endYear.value}`;
    if (categoryId.value) {
        params += `&category_id=${categoryId.value}`;
    }
    return params;
});

const monthlyExportUrl = computed(() => `/reports/buys/monthly/export?${queryParams.value}`);
const monthlyEmailUrl = computed(() => `/reports/buys/monthly/email?${queryParams.value}`);
const categoryExportUrl = computed(() => `/reports/buys/monthly/export/categories?${queryParams.value}`);
const categoryEmailUrl = computed(() => `/reports/buys/monthly/email/categories?${queryParams.value}`);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Buys Report', href: '/reports/buys' },
    { title: 'Monthly', href: '/reports/buys/monthly' },
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
const chartLabels = computed(() => props.monthlyData.map((row) => row.date));
const purchaseAmtData = computed(() =>
    props.monthlyData.map((row) => row.purchase_amt),
);
const estimatedValueData = computed(() =>
    props.monthlyData.map((row) => row.estimated_value),
);
const profitData = computed(() => props.monthlyData.map((row) => row.profit));
const buysCountData = computed(() =>
    props.monthlyData.map((row) => row.buys_count),
);

// Trends
const purchaseTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current =
        props.monthlyData[props.monthlyData.length - 1]?.purchase_amt || 0;
    const previous =
        props.monthlyData[props.monthlyData.length - 2]?.purchase_amt || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const profitTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current =
        props.monthlyData[props.monthlyData.length - 1]?.profit || 0;
    const previous =
        props.monthlyData[props.monthlyData.length - 2]?.profit || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

const buysTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current =
        props.monthlyData[props.monthlyData.length - 1]?.buys_count || 0;
    const previous =
        props.monthlyData[props.monthlyData.length - 2]?.buys_count || 0;
    if (previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / Math.abs(previous)) * 100;
});

// Average profit margin
const avgProfitMargin = computed(() => {
    if (props.totals.estimated_value === 0) return 0;
    return (props.totals.profit / props.totals.estimated_value) * 100;
});

function viewBuys(row: MonthRow): void {
    router.visit(
        `/transactions?date_from=${row.start_date}&date_to=${row.end_date}&status=payment_processed`,
    );
}

function viewCategoryBuys(row: CategoryBreakdownRow): void {
    const fromDate = `${startYear.value}-${String(startMonth.value).padStart(2, '0')}-01`;
    const endDate = new Date(endYear.value, endMonth.value, 0);
    const toDate = `${endYear.value}-${String(endMonth.value).padStart(2, '0')}-${String(endDate.getDate()).padStart(2, '0')}`;

    const params = new URLSearchParams({ from_date: fromDate, to_date: toDate });
    if (row.category_id === 0) {
        params.set('parent_category_id', '0');
    } else if (!row.parent_id) {
        params.set('parent_category_id', String(row.category_id));
    } else {
        params.set('parent_category_id', String(row.root_category_id));
        params.set('subcategory_id', String(row.category_id));
    }
    router.visit(`/buys/items?${params.toString()}`);
}
</script>

<template>
    <Head title="Buys Report (Month over Month)" />

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
                        Month over Month - {{ dateRangeLabel }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/buys/yearly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Year over Year
                    </Link>
                    <Link
                        href="/reports/buys"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Daily
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
                            <option
                                v-for="month in months"
                                :key="month.value"
                                :value="month.value"
                            >
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
                            <option
                                v-for="month in months"
                                :key="month.value"
                                :value="month.value"
                            >
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
                <div v-if="categories.length > 0">
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Category</label>
                    <select
                        v-model="categoryId"
                        class="block w-[200px] rounded-md border-gray-300 py-2 pr-10 pl-3 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        @change="applyFilters"
                    >
                        <option value="">All Categories</option>
                        <option
                            v-for="cat in categories"
                            :key="cat.value"
                            :value="cat.value"
                        >
                            {{ '\u00A0\u00A0'.repeat(cat.depth) }}{{ cat.isLeaf ? '' : '📁 ' }}{{ cat.label }}
                        </option>
                    </select>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    @click="resetToLast12Months"
                >
                    Last 12 Months
                </button>
            </div>

            <!-- Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Total Purchased"
                    :value="formatCurrency(totals.purchase_amt)"
                    :trend="purchaseTrend"
                    trend-label="vs last month"
                    :sparkline-data="purchaseAmtData"
                />
                <StatCard
                    title="Total Expected Profit"
                    :value="formatCurrency(totals.profit)"
                    :trend="profitTrend"
                    trend-label="vs last month"
                    :sparkline-data="profitData"
                />
                <StatCard
                    title="Total Buys"
                    :value="totals.buys_count.toLocaleString()"
                    :trend="buysTrend"
                    trend-label="vs last month"
                    :sparkline-data="buysCountData"
                />
                <StatCard
                    title="Avg Profit Margin"
                    :value="formatPercent(avgProfitMargin)"
                />
            </div>

            <!-- Charts Row -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Purchase vs Value Chart -->
                <div
                    class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <div
                        class="border-b border-gray-200 px-4 py-4 dark:border-gray-700"
                    >
                        <h3
                            class="text-base font-semibold text-gray-900 dark:text-white"
                        >
                            Purchase Amount vs Estimated Value
                        </h3>
                    </div>
                    <div class="p-4">
                        <AreaChart
                            v-if="monthlyData.length > 0"
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

                <!-- Buys Count Bar Chart -->
                <div
                    class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <div
                        class="border-b border-gray-200 px-4 py-4 dark:border-gray-700"
                    >
                        <h3
                            class="text-base font-semibold text-gray-900 dark:text-white"
                        >
                            Buys Volume by Month
                        </h3>
                    </div>
                    <div class="p-4">
                        <BarChart
                            v-if="monthlyData.length > 0"
                            :labels="chartLabels"
                            :datasets="[
                                {
                                    label: 'Buys',
                                    data: buysCountData,
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

            <!-- Category Breakdown -->
            <ReportTable
                v-if="categoryBreakdown.length > 0"
                title="Buys by Category"
                :export-url="categoryExportUrl"
                :email-url="categoryEmailUrl"
            >
                <table
                    class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                >
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                            >
                                Category
                            </th>
                            <th
                                class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                            >
                                Transactions
                            </th>
                            <th
                                class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                            >
                                Items
                            </th>
                            <th
                                class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                            >
                                Purchase Amt
                            </th>
                            <th
                                class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                            >
                                Est. Value
                            </th>
                            <th
                                class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                            >
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                    >
                        <tr
                            v-for="row in categoryBreakdown"
                            :key="row.category_id"
                            class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
                            @click="viewCategoryBuys(row)"
                        >
                            <td
                                class="px-4 py-3 text-sm text-gray-900 dark:text-white"
                            >
                                <span
                                    v-if="!row.is_leaf"
                                    class="mr-1 text-gray-400"
                                    >📁</span
                                >
                                <span class="hover:underline">{{ row.category_name }}</span>
                            </td>
                            <td
                                class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white"
                            >
                                {{ row.transactions_count }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white"
                            >
                                {{ row.items_count }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white"
                            >
                                {{ formatCurrency(row.total_purchase) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white"
                            >
                                {{ formatCurrency(row.total_estimated_value) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right text-sm"
                                :class="
                                    row.total_profit >= 0
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-red-600 dark:text-red-400'
                                "
                            >
                                {{ formatCurrency(row.total_profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </ReportTable>

            <!-- Data Table -->
            <ReportTable
                title="Monthly Buys Data"
                :export-url="monthlyExportUrl"
                :email-url="monthlyEmailUrl"
            >
                <table
                    class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                >
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th
                                class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                            >
                                Month
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
                            v-for="row in monthlyData"
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
                        <tr v-if="monthlyData.length === 0">
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
                        v-if="monthlyData.length > 0"
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
            </ReportTable>
        </div>
    </AppLayout>
</template>
