<script setup lang="ts">
import { DatePicker } from '@/components/ui/date-picker';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import ReportTable from '@/components/widgets/ReportTable.vue';
import { computed, ref } from 'vue';

interface ItemRow {
    id: number;
    order_id: number;
    order_number: string;
    date: string;
    customer: string;
    lead: string;
    sku: string;
    product_name: string;
    category: string;
    quantity: number;
    unit_price: number;
    wholesale_value: number;
    cost: number;
    total: number;
    profit: number;
    marketplace: string;
    payment_type: string;
    vendor: string;
}

interface Totals {
    quantity: number;
    wholesale_value: number;
    cost: number;
    total: number;
    profit: number;
}

const props = defineProps<{
    items: ItemRow[];
    totals: Totals;
    startDate: string;
    endDate: string;
    dateRangeLabel: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Sales (Daily Items)', href: '/reports/sales/daily-items' },
];

const startDate = ref(props.startDate);
const endDate = ref(props.endDate);

function applyDateFilter() {
    router.get(
        '/reports/sales/daily-items',
        {
            start_date: startDate.value,
            end_date: endDate.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function resetToToday() {
    const today = new Date().toISOString().split('T')[0];
    startDate.value = today;
    endDate.value = today;
    applyDateFilter();
}

const exportUrl = computed(
    () => `/reports/sales/daily-items/export?start_date=${startDate.value}&end_date=${endDate.value}`,
);
const emailUrl = computed(
    () => `/reports/sales/daily-items/email?start_date=${startDate.value}&end_date=${endDate.value}`,
);

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
    <Head title="Daily Sales Report (Items)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Daily Sales Report (Items)
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ dateRangeLabel }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        :href="`/reports/sales/daily?start_date=${startDate}&end_date=${endDate}`"
                        class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                    >
                        View by Orders
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
                        @update:model-value="applyDateFilter"
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
                        @update:model-value="applyDateFilter"
                    />
                </div>
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    @click="resetToToday"
                >
                    Today
                </button>
            </div>

            <!-- Data Table -->
            <ReportTable title="Daily Sales Items" :export-url="exportUrl" :email-url="emailUrl">
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
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Order #
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
                                    SKU
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Product
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Category
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Qty
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Unit Price
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Wholesale
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Cost
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Total
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Profit
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Marketplace
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
                                v-for="item in items"
                                :key="item.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ item.date }}
                                </td>
                                <td class="px-3 py-4 text-sm whitespace-nowrap">
                                    <Link
                                        :href="`/orders/${item.order_id}`"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        {{ item.order_number }}
                                    </Link>
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ item.customer }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ item.lead }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ item.sku }}
                                </td>
                                <td
                                    class="max-w-[200px] truncate px-3 py-4 text-sm text-gray-900 dark:text-white"
                                    :title="item.product_name"
                                >
                                    {{ item.product_name }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ item.category }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ item.quantity }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(item.unit_price) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(item.wholesale_value) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(item.cost) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(item.total) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        item.profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(item.profit) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ item.marketplace }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ item.payment_type }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ item.vendor }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="items.length === 0">
                                <td
                                    colspan="16"
                                    class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No items found for the selected date.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot
                            v-if="items.length > 0"
                            class="bg-gray-100 dark:bg-gray-700"
                        >
                            <tr class="font-semibold">
                                <td
                                    colspan="7"
                                    class="px-3 py-4 text-sm text-gray-900 dark:text-white"
                                >
                                    TOTALS
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.quantity }}
                                </td>
                                <td class="px-3 py-4"></td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(totals.wholesale_value) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCostValue(totals.cost) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.total) }}
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
                                <td colspan="3" class="px-3 py-4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            </ReportTable>
        </div>
    </AppLayout>
</template>
