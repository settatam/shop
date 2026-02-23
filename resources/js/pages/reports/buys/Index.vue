<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ReportTable from '@/components/widgets/ReportTable.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import StatCard from '@/components/charts/StatCard.vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import { DatePicker } from '@/components/ui/date-picker';

interface DayRow {
    date: string;
    date_key: string;
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
    dailyData: DayRow[];
    totals: Totals;
    startDate: string;
    endDate: string;
    dateRangeLabel: string;
    categories: Category[];
    categoryBreakdown: CategoryBreakdownRow[];
    filters: Filters;
}>();

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
    router.get('/reports/buys', params, {
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

const queryParams = computed(() => {
    let params = `start_date=${startDate.value}&end_date=${endDate.value}`;
    if (categoryId.value) {
        params += `&category_id=${categoryId.value}`;
    }
    return params;
});

const dailyExportUrl = computed(() => `/reports/buys/export?${queryParams.value}`);
const dailyEmailUrl = computed(() => `/reports/buys/email?${queryParams.value}`);
const categoryExportUrl = computed(() => `/reports/buys/export/categories?${queryParams.value}`);
const categoryEmailUrl = computed(() => `/reports/buys/email/categories?${queryParams.value}`);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Buys Report', href: '/reports/buys' },
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
const chartLabels = computed(() =>
    props.dailyData.map((row) => {
        const parts = row.date.split('/');
        return parts.length > 1 ? parts[1] : row.date;
    }),
);

const purchaseAmtData = computed(() =>
    props.dailyData.map((row) => row.purchase_amt),
);
const estimatedValueData = computed(() =>
    props.dailyData.map((row) => row.estimated_value),
);
const profitData = computed(() => props.dailyData.map((row) => row.profit));
const buysCountData = computed(() =>
    props.dailyData.map((row) => row.buys_count),
);

// Week over week trend
const purchaseTrend = computed(() => {
    if (props.dailyData.length < 14) return 0;
    const last7 = props.dailyData
        .slice(-7)
        .reduce((sum, row) => sum + row.purchase_amt, 0);
    const prev7 = props.dailyData
        .slice(-14, -7)
        .reduce((sum, row) => sum + row.purchase_amt, 0);
    if (prev7 === 0) return last7 > 0 ? 100 : 0;
    return ((last7 - prev7) / Math.abs(prev7)) * 100;
});

const profitTrend = computed(() => {
    if (props.dailyData.length < 14) return 0;
    const last7 = props.dailyData
        .slice(-7)
        .reduce((sum, row) => sum + row.profit, 0);
    const prev7 = props.dailyData
        .slice(-14, -7)
        .reduce((sum, row) => sum + row.profit, 0);
    if (prev7 === 0) return last7 > 0 ? 100 : 0;
    return ((last7 - prev7) / Math.abs(prev7)) * 100;
});

function viewBuys(row: DayRow): void {
    router.visit(
        `/transactions?date_from=${row.date_key}&date_to=${row.date_key}&status=payment_processed`,
    );
}
</script>

<template>
    <Head title="Buys Report (MTD)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Buys Report
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ dateRangeLabel }}
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
                        href="/reports/buys/yearly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Yearly
                    </Link>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Start Date</label>
                    <DatePicker
                        v-model="startDate"
                        placeholder="Start date"
                        class="w-[160px]"
                        @update:model-value="applyFilters"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">End Date</label>
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
                            {{ '\u00A0\u00A0'.repeat(cat.depth) }}{{ cat.isLeaf ? '' : '📁 ' }}{{ cat.label }}
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

            <!-- Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Total Purchased"
                    :value="formatCurrency(totals.purchase_amt)"
                    :trend="purchaseTrend"
                    trend-label="vs prev week"
                    :sparkline-data="purchaseAmtData"
                />
                <StatCard
                    title="Estimated Value"
                    :value="formatCurrency(totals.estimated_value)"
                    :sparkline-data="estimatedValueData"
                />
                <StatCard
                    title="Expected Profit"
                    :value="formatCurrency(totals.profit)"
                    :trend="profitTrend"
                    trend-label="vs prev week"
                    :sparkline-data="profitData"
                />
                <StatCard
                    title="Avg Buy Price"
                    :value="formatCurrency(totals.avg_buy_price)"
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
                        Daily Purchase vs Estimated Value
                    </h3>
                </div>
                <div class="p-4">
                    <AreaChart
                        v-if="dailyData.length > 0"
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
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td
                                    class="px-4 py-3 text-sm text-gray-900 dark:text-white"
                                >
                                    <span
                                        v-if="!row.is_leaf"
                                        class="mr-1 text-gray-400"
                                        >📁</span
                                    >
                                    {{ row.category_name }}
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
                title="Daily Buys Data"
                :export-url="dailyExportUrl"
                :email-url="dailyEmailUrl"
            >
                    <table
                        class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                    >
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Date
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
                                v-for="row in dailyData"
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
                            <tr v-if="dailyData.length === 0">
                                <td
                                    colspan="7"
                                    class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No buys data found for this month.
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
