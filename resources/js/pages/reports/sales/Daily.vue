<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { DatePicker } from '@/components/ui/date-picker';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
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
    profit: number;
    tax: number;
    shipping_cost: number;
    total: number;
}

const props = defineProps<{
    orders: OrderRow[];
    totals: Totals;
    date: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Sales (Daily)', href: '/reports/sales/daily' },
];

const selectedDate = ref(props.date);

function handleDateChange(newDate: string) {
    if (newDate && newDate !== props.date) {
        router.get('/reports/sales/daily', { date: newDate }, {
            preserveState: true,
            preserveScroll: true,
        });
    }
}

const exportUrl = computed(() => `/reports/sales/daily/export?date=${selectedDate.value}`);

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}
</script>

<template>
    <Head title="Daily Sales Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Daily Sales Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Individual sales for the selected day
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <a
                        :href="exportUrl"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <ArrowDownTrayIcon class="size-4" />
                        Export CSV
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Date</label>
                    <DatePicker
                        v-model="selectedDate"
                        placeholder="Select date"
                        class="w-[180px]"
                        @update:model-value="handleDateChange"
                    />
                </div>
            </div>

            <!-- Data Table -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Date</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Order ID</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Customer</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Lead</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Status</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Marketplace</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300"># Items</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Categories</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Wholesale</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Sub Total</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Tax</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Shipping</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Total</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Payment</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Vendor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="order in orders" :key="order.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900 dark:text-white">{{ order.date }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <Link :href="`/orders/${order.id}`" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                                        {{ order.order_id }}
                                    </Link>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900 dark:text-white">{{ order.customer }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ order.lead }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset"
                                        :class="{
                                            'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20': order.status === 'completed' || order.status === 'paid',
                                            'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20': order.status === 'pending',
                                            'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20': order.status === 'processing',
                                            'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20': !['completed', 'paid', 'pending', 'processing'].includes(order.status),
                                        }"
                                    >
                                        {{ order.status }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ order.marketplace }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ order.num_items }}</td>
                                <td class="max-w-[200px] truncate px-3 py-4 text-sm text-gray-500 dark:text-gray-400" :title="order.categories">{{ order.categories }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(order.cost) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(order.wholesale_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(order.sub_total) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right" :class="order.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(order.profit) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(order.tax) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(order.shipping_cost) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right font-medium text-gray-900 dark:text-white">{{ formatCurrency(order.total) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ order.payment_type }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ order.vendor }}</td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="orders.length === 0">
                                <td colspan="17" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No sales found for the selected date.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="orders.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td colspan="6" class="px-3 py-4 text-sm text-gray-900 dark:text-white">TOTALS</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ totals.num_items }}</td>
                                <td class="px-3 py-4"></td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.cost) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.wholesale_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.sub_total) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right" :class="totals.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatCurrency(totals.profit) }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.tax) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.shipping_cost) }}</td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.total) }}</td>
                                <td colspan="2" class="px-3 py-4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
