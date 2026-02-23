<script setup lang="ts">
import AreaChart from '@/components/charts/AreaChart.vue';
import StatCard from '@/components/charts/StatCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { DatePicker } from '@/components/ui/date-picker';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import ReportTable from '@/components/widgets/ReportTable.vue';
import { computed, ref } from 'vue';

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
    date_key: string;
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
    [key: string]: string | number; // Allow dynamic channel keys
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
    items_sold: number;
    orders_count: number;
    total_cost: number;
    total_wholesale: number;
    total_sales: number;
    total_profit: number;
}

interface Filters {
    category_id?: string;
}

const props = defineProps<{
    dailyData: DayRow[];
    totals: Totals;
    startDate: string;
    endDate: string;
    dateRangeLabel: string;
    channels: Channel[];
    categories: Category[];
    categoryBreakdown: CategoryBreakdownRow[];
    filters: Filters;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Sales (Month to Date)', href: '/reports/sales/mtd' },
];

// Date range filters
const startDate = ref(props.startDate);
const endDate = ref(props.endDate);
const categoryId = ref(props.filters?.category_id || '');

function applyFilters() {
    const params: Record<string, string> = {
        start_date: startDate.value,
        end_date: endDate.value,
    };
    if (categoryId.value) {
        params.category_id = categoryId.value;
    }
    router.get('/reports/sales/mtd', params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetToCurrentMonth() {
    const now = new Date();
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
    startDate.value = firstDay.toISOString().split('T')[0];
    endDate.value = now.toISOString().split('T')[0];
    applyFilters();
}

const exportUrl = computed(() => {
    let url = `/reports/sales/mtd/export?start_date=${startDate.value}&end_date=${endDate.value}`;
    if (categoryId.value) {
        url += `&category_id=${categoryId.value}`;
    }
    return url;
});

const emailUrl = computed(() => {
    let url = `/reports/sales/mtd/email?start_date=${startDate.value}&end_date=${endDate.value}`;
    if (categoryId.value) {
        url += `&category_id=${categoryId.value}`;
    }
    return url;
});

const categoryExportUrl = computed(() => {
    let url = `/reports/sales/mtd/category-breakdown/export?start_date=${startDate.value}&end_date=${endDate.value}`;
    if (categoryId.value) {
        url += `&category_id=${categoryId.value}`;
    }
    return url;
});

const categoryEmailUrl = computed(() => {
    let url = `/reports/sales/mtd/category-breakdown/email?start_date=${startDate.value}&end_date=${endDate.value}`;
    if (categoryId.value) {
        url += `&category_id=${categoryId.value}`;
    }
    return url;
});

function formatCostValue(value: number): string {
    if (value <= 0) {
        return '-';
    }
    return formatCurrency(value);
}

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
const chartLabels = computed(() =>
    props.dailyData.map((row) => {
        // Extract just the day number from the date
        const parts = row.date.split('/');
        return parts.length > 1 ? parts[1] : row.date;
    }),
);

const revenueData = computed(() =>
    props.dailyData.map((row) => row.total_paid),
);
const profitData = computed(() =>
    props.dailyData.map((row) => row.gross_profit),
);
const salesCountData = computed(() =>
    props.dailyData.map((row) => row.sales_count),
);

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
    const last7 = props.dailyData
        .slice(-7)
        .reduce((sum, row) => sum + row.total_paid, 0);
    const prev7 = props.dailyData
        .slice(-14, -7)
        .reduce((sum, row) => sum + row.total_paid, 0);
    if (prev7 === 0) return last7 > 0 ? 100 : 0;
    return ((last7 - prev7) / Math.abs(prev7)) * 100;
});

// Average profit margin
const avgProfitMargin = computed(() => {
    if (props.totals.total_paid === 0) return 0;
    return (props.totals.gross_profit / props.totals.total_paid) * 100;
});

function viewSales(row: DayRow): void {
    router.visit(`/reports/sales/daily?start_date=${row.date_key}&end_date=${row.date_key}`);
}
</script>

<template>
    <Head title="Month to Date Sales Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Month to Date Sales Report
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ dateRangeLabel }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                </div>
            </div>

            <!-- Date Range Filter -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Start Date</label>
                    <DatePicker
                        v-model="startDate"
                        placeholder="Start date"
                        class="w-[160px]"
                        @update:model-value="applyFilters"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">End Date</label>
                    <DatePicker
                        v-model="endDate"
                        placeholder="End date"
                        class="w-[160px]"
                        @update:model-value="applyFilters"
                    />
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
                            {{ '\u00A0\u00A0'.repeat(cat.depth)
                            }}{{ cat.isLeaf ? '' : '📁 ' }}{{ cat.label }}
                        </option>
                    </select>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    @click="resetToCurrentMonth"
                >
                    Current Month
                </button>
            </div>

            <!-- Category Breakdown -->
            <ReportTable
                v-if="categoryBreakdown.length > 0"
                title="Sales by Category"
                :export-url="categoryExportUrl"
                :email-url="categoryEmailUrl"
            >
            <div
                class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10"
            >
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">Category</th>
                                <th class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">Orders</th>
                                <th class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">Items</th>
                                <th class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">Cost</th>
                                <th class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">Sales</th>
                                <th class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr
                                v-for="row in categoryBreakdown"
                                :key="row.category_id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                    <span v-if="!row.is_leaf" class="mr-1 text-gray-400">📁</span>
                                    {{ row.category_name }}
                                </td>
                                <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white">{{ row.orders_count }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white">{{ row.items_sold }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white">{{ formatCostValue(row.total_cost) }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white">{{ formatCurrency(row.total_sales) }}</td>
                                <td
                                    class="px-4 py-3 text-right text-sm"
                                    :class="row.total_profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                >
                                    {{ formatCurrency(row.total_profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </ReportTable>

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
            <div
                class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
            >
                <div
                    class="border-b border-gray-200 px-4 py-4 dark:border-gray-700"
                >
                    <h3
                        class="text-base font-semibold text-gray-900 dark:text-white"
                    >
                        Daily Revenue & Profit
                    </h3>
                </div>
                <div class="p-4">
                    <AreaChart
                        v-if="dailyData.length > 0"
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

            <!-- Data Table -->
            <ReportTable title="Daily Sales Data" :export-url="exportUrl" :email-url="emailUrl">
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
                                    Date
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
                                v-for="row in dailyData"
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
                            <tr v-if="dailyData.length === 0">
                                <td
                                    :colspan="12 + channels.length"
                                    class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No sales data found for this month.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot
                            v-if="dailyData.length > 0"
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
            </ReportTable>
        </div>
    </AppLayout>
</template>
