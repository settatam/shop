<script setup lang="ts">
import { DatePicker } from '@/components/ui/date-picker';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface TransactionRow {
    id: number;
    date: string;
    transaction_number: string;
    customer: string;
    type: string;
    source: string;
    num_items: number;
    purchase_amt: number;
    estimated_value: number;
    profit: number;
    profit_percent: number;
    user: string;
}

interface Totals {
    num_items: number;
    purchase_amt: number;
    estimated_value: number;
    profit: number;
    profit_percent: number;
}

const props = defineProps<{
    transactions: TransactionRow[];
    totals: Totals;
    startDate: string;
    endDate: string;
    dateRangeLabel: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Buys (Daily)', href: '/reports/buys/daily' },
];

const startDate = ref(props.startDate);
const endDate = ref(props.endDate);

function applyDateFilter() {
    router.get(
        '/reports/buys/daily',
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
    () => `/reports/buys/daily/export?start_date=${startDate.value}&end_date=${endDate.value}`,
);

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatPercent(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'percent',
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    }).format(value / 100);
}
</script>

<template>
    <Head title="Daily Buys Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Daily Buys Report
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ dateRangeLabel }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <a
                        :href="exportUrl"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <ArrowDownTrayIcon class="size-4" />
                        Export CSV
                    </a>
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
                                    Transaction #
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Customer
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Type
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Source
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    # Items
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Purchase Amt
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Est. Value
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Profit
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Profit %
                                </th>
                                <th
                                    class="px-3 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    User
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                        >
                            <tr
                                v-for="transaction in transactions"
                                :key="transaction.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ transaction.date }}
                                </td>
                                <td class="px-3 py-4 text-sm whitespace-nowrap">
                                    <Link
                                        :href="`/transactions/${transaction.id}`"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        {{ transaction.transaction_number }}
                                    </Link>
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ transaction.customer }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ transaction.type }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ transaction.source }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ transaction.num_items }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(transaction.purchase_amt) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(transaction.estimated_value) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        transaction.profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(transaction.profit) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        transaction.profit_percent >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatPercent(transaction.profit_percent) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ transaction.user }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="transactions.length === 0">
                                <td
                                    colspan="11"
                                    class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No transactions found for the selected date.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot
                            v-if="transactions.length > 0"
                            class="bg-gray-100 dark:bg-gray-700"
                        >
                            <tr class="font-semibold">
                                <td
                                    colspan="5"
                                    class="px-3 py-4 text-sm text-gray-900 dark:text-white"
                                >
                                    TOTALS
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.num_items }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.purchase_amt) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(totals.estimated_value) }}
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
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        totals.profit_percent >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatPercent(totals.profit_percent) }}
                                </td>
                                <td class="px-3 py-4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
