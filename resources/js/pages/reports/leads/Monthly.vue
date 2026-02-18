<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
import { computed } from 'vue';
import StatCard from '@/components/charts/StatCard.vue';
import AreaChart from '@/components/charts/AreaChart.vue';

interface MonthRow {
    date: string;
    start_date: string;
    end_date: string;
    kits_requested: number;
    kit_req_declined: number;
    kit_req_declined_pct: number;
    kits_received: number;
    kits_received_pct: number;
    kits_rec_rejected: number;
    kits_returned: number;
    offers_declined: number;
    offers_given: number;
    offers_pending: number;
    offers_accepted: number;
    estimated_value: number;
    final_offer: number;
    profit: number;
    profit_pct: number;
}

interface Totals {
    kits_requested: number;
    kit_req_declined: number;
    kit_req_declined_pct: number;
    kits_received: number;
    kits_received_pct: number;
    kits_rec_rejected: number;
    kits_returned: number;
    offers_declined: number;
    offers_given: number;
    offers_pending: number;
    offers_accepted: number;
    estimated_value: number;
    final_offer: number;
    profit: number;
    profit_pct: number;
}

const props = defineProps<{
    monthlyData: MonthRow[];
    totals: Totals;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Leads Report', href: '/reports/leads' },
    { title: 'Monthly', href: '/reports/leads/monthly' },
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
    return value.toFixed(1) + '%';
}

// Chart data
const chartLabels = computed(() => props.monthlyData.map((row) => row.date));
const kitsRequestedData = computed(() =>
    props.monthlyData.map((row) => row.kits_requested),
);
const kitsReceivedData = computed(() =>
    props.monthlyData.map((row) => row.kits_received),
);
const offersAcceptedData = computed(() =>
    props.monthlyData.map((row) => row.offers_accepted),
);
const profitData = computed(() => props.monthlyData.map((row) => row.profit));

// Trends
const requestedTrend = computed(() => {
    if (props.monthlyData.length < 2) return 0;
    const current =
        props.monthlyData[props.monthlyData.length - 1]?.kits_requested || 0;
    const previous =
        props.monthlyData[props.monthlyData.length - 2]?.kits_requested || 0;
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

function viewMonth(row: MonthRow): void {
    router.visit(
        `/transactions?date_from=${row.start_date}&date_to=${row.end_date}&type=mail_in`,
    );
}
</script>

<template>
    <Head title="Leads Report (Month over Month)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Leads Report
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Month over Month - Past 13 Months
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/leads/yearly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Year over Year
                    </Link>
                    <Link
                        href="/reports/leads"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Month to Date
                    </Link>
                    <a
                        href="/reports/leads/monthly/export"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <ArrowDownTrayIcon class="size-4" />
                        Export CSV
                    </a>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Total Kits Requested"
                    :value="totals.kits_requested.toLocaleString()"
                    :trend="requestedTrend"
                    trend-label="vs last month"
                    :sparkline-data="kitsRequestedData"
                />
                <StatCard
                    title="Total Kits Received"
                    :value="totals.kits_received.toLocaleString()"
                    :subtitle="`${totals.kits_received_pct}% of requested`"
                    :sparkline-data="kitsReceivedData"
                />
                <StatCard
                    title="Total Offers Accepted"
                    :value="totals.offers_accepted.toLocaleString()"
                    :sparkline-data="offersAcceptedData"
                />
                <StatCard
                    title="Total Profit"
                    :value="formatCurrency(totals.profit)"
                    :trend="profitTrend"
                    trend-label="vs last month"
                    :sparkline-data="profitData"
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
                        Leads Funnel by Month
                    </h3>
                </div>
                <div class="p-4">
                    <AreaChart
                        v-if="monthlyData.length > 0"
                        :labels="chartLabels"
                        :datasets="[
                            {
                                label: 'Kits Requested',
                                data: kitsRequestedData,
                                color: '#6366f1',
                            },
                            {
                                label: 'Kits Received',
                                data: kitsReceivedData,
                                color: '#f59e0b',
                            },
                            {
                                label: 'Offers Accepted',
                                data: offersAcceptedData,
                                color: '#22c55e',
                            },
                        ]"
                        :height="250"
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
                                    Month
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Kits Req.
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Declined
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Declined %
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Kits Rec.
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Rec. %
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Rec. Rejected
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Returned
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Offers
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Pending
                                </th>
                                <th
                                    class="px-3 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Accepted
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
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                        >
                            <tr
                                v-for="row in monthlyData"
                                :key="row.date"
                                class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
                                @click="viewMonth(row)"
                            >
                                <td
                                    class="px-3 py-4 text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.date }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.kits_requested }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-red-600 dark:text-red-400"
                                >
                                    {{ row.kit_req_declined }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ formatPercent(row.kit_req_declined_pct) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.kits_received }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ formatPercent(row.kits_received_pct) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-red-600 dark:text-red-400"
                                >
                                    {{ row.kits_rec_rejected }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ row.kits_returned }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ row.offers_given }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-yellow-600 dark:text-yellow-400"
                                >
                                    {{ row.offers_pending }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-green-600 dark:text-green-400"
                                >
                                    {{ row.offers_accepted }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(row.estimated_value) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        row.profit >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatCurrency(row.profit) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap"
                                    :class="
                                        row.profit_pct >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatPercent(row.profit_pct) }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="monthlyData.length === 0">
                                <td
                                    colspan="14"
                                    class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No leads data found.
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
                                    class="px-3 py-4 text-sm text-gray-900 dark:text-white"
                                >
                                    TOTALS
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.kits_requested }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-red-600 dark:text-red-400"
                                >
                                    {{ totals.kit_req_declined }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ formatPercent(totals.kit_req_declined_pct) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.kits_received }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ formatPercent(totals.kits_received_pct) }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-red-600 dark:text-red-400"
                                >
                                    {{ totals.kits_rec_rejected }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    {{ totals.kits_returned }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ totals.offers_given }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-yellow-600 dark:text-yellow-400"
                                >
                                    {{ totals.offers_pending }}
                                </td>
                                <td
                                    class="px-3 py-4 text-right text-sm whitespace-nowrap text-green-600 dark:text-green-400"
                                >
                                    {{ totals.offers_accepted }}
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
                                        totals.profit_pct >= 0
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-red-600 dark:text-red-400'
                                    "
                                >
                                    {{ formatPercent(totals.profit_pct) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
