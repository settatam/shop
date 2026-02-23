<script setup lang="ts">
import { DatePicker } from '@/components/ui/date-picker';
import AppLayout from '@/layouts/AppLayout.vue';
import ReportTable from '@/components/widgets/ReportTable.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface OrderRow {
    id: number;
    date: string;
    order_id: string;
    customer: string;
    lead: string;
    status: string;
    marketplace: string;
    num_items: number;
    categories: string;
    cost: number;
    wholesale_value: number;
    sub_total: number;
    service_fee: number;
    profit: number;
    tax: number;
    shipping_cost: number;
    total: number;
    payment_type: string;
    vendor: string;
}

interface Totals {
    num_items: number;
    cost: number;
    wholesale_value: number;
    sub_total: number;
    service_fee: number;
    profit: number;
    tax: number;
    shipping_cost: number;
    total: number;
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
    orders: OrderRow[];
    totals: Totals;
    startDate: string;
    endDate: string;
    dateRangeLabel: string;
    categories: Category[];
    categoryBreakdown: CategoryBreakdownRow[];
    filters: Filters;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Sales (Daily)', href: '/reports/sales/daily' },
];

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
    router.get('/reports/sales/daily', params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetToToday() {
    const today = new Date().toISOString().split('T')[0];
    startDate.value = today;
    endDate.value = today;
    applyFilters();
}

const queryParams = computed(() => {
    let params = `start_date=${startDate.value}&end_date=${endDate.value}`;
    if (categoryId.value) {
        params += `&category_id=${categoryId.value}`;
    }
    return params;
});

const ordersExportUrl = computed(() => `/reports/sales/daily/export?${queryParams.value}`);
const ordersEmailUrl = computed(() => `/reports/sales/daily/email?${queryParams.value}`);
const categoryExportUrl = computed(() => `/reports/sales/daily/export/categories?${queryParams.value}`);
const categoryEmailUrl = computed(() => `/reports/sales/daily/email/categories?${queryParams.value}`);

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatCostValue(value: number): string {
    if (value <= 0) {
        return '-';
    }
    return formatCurrency(value);
}
</script>

<template>
    <Head title="Daily Sales Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Daily Sales Report
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ dateRangeLabel }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        :href="`/reports/sales/daily-items?start_date=${startDate}&end_date=${endDate}`"
                        class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                    >
                        View by Items
                    </Link>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300"
                        >Start Date</label
                    >
                    <DatePicker
                        v-model="startDate"
                        placeholder="Start date"
                        class="w-[160px]"
                        @update:model-value="applyFilters"
                    />
                </div>
                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300"
                        >End Date</label
                    >
                    <DatePicker
                        v-model="endDate"
                        placeholder="End date"
                        class="w-[160px]"
                        @update:model-value="applyFilters"
                    />
                </div>
                <div v-if="categories.length > 0">
                    <label
                        class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300"
                        >Category</label
                    >
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
                    @click="resetToToday"
                >
                    Today
                </button>
            </div>

            <!-- Category Breakdown -->
            <ReportTable
                v-if="categoryBreakdown.length > 0"
                title="Sales by Category"
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
                                    Orders
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Items
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Cost
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Sales
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
                                    {{ row.orders_count }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white"
                                >
                                    {{ row.items_sold }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(row.total_cost) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.total_sales) }}
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
                title="Daily Orders"
                :export-url="ordersExportUrl"
                :email-url="ordersEmailUrl"
            >
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
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Order ID
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Customer
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Lead
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Marketplace
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    # Items
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Categories
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Cost
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Wholesale
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Sub Total
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Service Fee
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Profit
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
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Total
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Payment
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Vendor
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                        >
                            <tr
                                v-for="order in orders"
                                :key="order.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ order.date }}
                                </td>
                                <td class="px-3 py-4 text-sm whitespace-nowrap">
                                    <Link
                                        :href="`/orders/${order.id}`"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        {{ order.order_id }}
                                    </Link>
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ order.customer }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ order.lead }}
                                </td>
                                <td class="px-3 py-4 text-sm whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset"
                                        :class="{
                                            'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20':
                                                order.status === 'completed' ||
                                                order.status === 'paid',
                                            'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20':
                                                order.status === 'pending',
                                            'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20':
                                                order.status === 'processing',
                                            'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20':
                                                ![
                                                    'completed',
                                                    'paid',
                                                    'pending',
                                                    'processing',
                                                ].includes(order.status),
                                        }"
                                    >
                                        {{ order.status }}
                                    </span>
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ order.marketplace }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ order.num_items }}
                                </td>
                                <td
                                    class="max-w-[200px] truncate px-3 py-4 text-sm text-gray-500 dark:text-gray-400"
                                    :title="order.categories"
                                >
                                    {{ order.categories }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(order.cost) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(order.wholesale_value) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(order.sub_total) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(order.service_fee) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        order.profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(order.profit) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(order.tax) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(order.shipping_cost) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(order.total) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ order.payment_type }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ order.vendor }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="orders.length === 0">
                                <td
                                    colspan="18"
                                    class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No sales found for the selected date.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot
                            v-if="orders.length > 0"
                            class="bg-gray-100 dark:bg-gray-700"
                        >
                            <tr class="font-semibold">
                                <td
                                    colspan="6"
                                    class="px-3 py-4 text-sm text-gray-900 dark:text-white"
                                >
                                    TOTALS
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.num_items }}
                                </td>
                                <td class="px-3 py-4"></td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(totals.cost) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(totals.wholesale_value) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.sub_total) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.service_fee) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        totals.profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(totals.profit) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.tax) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.shipping_cost) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.total) }}
                                </td>
                                <td colspan="2" class="px-3 py-4"></td>
                            </tr>
                        </tfoot>
                    </table>
            </ReportTable>
        </div>
    </AppLayout>
</template>
