<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid';
import { TruckIcon } from '@heroicons/vue/24/outline';
import StatCard from '@/components/charts/StatCard.vue';
import { computed } from 'vue';

interface DayRow {
    date: string;
    date_key: string;
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

interface TrackingStatus {
    status: string | null;
    status_label: string | null;
    description: string | null;
    location: string | null;
    estimated_delivery: string | null;
    updated_at: string | null;
}

interface IncomingKit {
    id: number;
    transaction_number: string;
    return_shipped_at: string;
    days_in_transit: number;
    customer_name: string;
    customer_id: number | null;
    status: string;
    return_tracking: string | null;
    return_carrier: string | null;
    estimated_value: number;
    tracking_status: TrackingStatus | null;
}

const props = defineProps<{
    dailyData: DayRow[];
    totals: Totals;
    month: string;
    incomingKits?: IncomingKit[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Leads Report', href: '/reports/leads' },
];

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatPercent(value: number): string {
    return value.toFixed(1) + '%';
}

function getTrackingStatusBadge(kit: IncomingKit): {
    label: string;
    class: string;
} {
    const status = kit.tracking_status?.status;

    if (!status) {
        // Fall back to days-based status
        if (kit.days_in_transit >= 5) {
            return {
                label: 'Delayed',
                class: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            };
        } else if (kit.days_in_transit >= 3) {
            return {
                label: 'In Transit',
                class: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
            };
        }
        return {
            label: 'Shipped',
            class: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        };
    }

    switch (status) {
        case 'delivered':
            return {
                label: 'Delivered',
                class: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            };
        case 'out_for_delivery':
            return {
                label: 'Out for Delivery',
                class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
            };
        case 'in_transit':
            return {
                label: 'In Transit',
                class: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            };
        case 'picked_up':
            return {
                label: 'Picked Up',
                class: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
            };
        case 'exception':
            return {
                label: 'Exception',
                class: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            };
        case 'returned':
            return {
                label: 'Returned',
                class: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
            };
        case 'label_created':
            return {
                label: 'Label Created',
                class: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
            };
        default:
            return {
                label: kit.tracking_status?.status_label || 'Unknown',
                class: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
            };
    }
}

function getCarrierIcon(carrier: string | null): string {
    switch (carrier?.toLowerCase()) {
        case 'fedex':
            return 'FedEx';
        case 'ups':
            return 'UPS';
        case 'usps':
            return 'USPS';
        default:
            return carrier?.toUpperCase() || 'N/A';
    }
}

// Sparkline data
const kitsRequestedData = computed(() =>
    props.dailyData.map((row) => row.kits_requested),
);
const offersAcceptedData = computed(() =>
    props.dailyData.map((row) => row.offers_accepted),
);
const profitData = computed(() => props.dailyData.map((row) => row.profit));
</script>

<template>
    <Head title="Leads Report (MTD)" />

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
                        Month to Date - {{ month }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/leads/monthly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Month over Month
                    </Link>
                    <Link
                        href="/reports/leads/yearly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Yearly
                    </Link>
                    <Link
                        href="/reports/leads/daily-kits"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        Daily Kits
                    </Link>
                    <a
                        href="/reports/leads/export"
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
                    title="Kits Requested"
                    :value="totals.kits_requested.toLocaleString()"
                    :sparkline-data="kitsRequestedData"
                />
                <StatCard
                    title="Kits Received"
                    :value="totals.kits_received.toLocaleString()"
                    :subtitle="`${totals.kits_received_pct}% of requested`"
                />
                <StatCard
                    title="Offers Accepted"
                    :value="totals.offers_accepted.toLocaleString()"
                    :sparkline-data="offersAcceptedData"
                />
                <StatCard
                    title="Total Profit"
                    :value="formatCurrency(totals.profit)"
                    :subtitle="`${totals.profit_pct}% margin`"
                    :sparkline-data="profitData"
                />
            </div>

            <!-- Incoming Kits -->
            <div
                v-if="incomingKits && incomingKits.length > 0"
                class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10"
            >
                <div
                    class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700"
                >
                    <div class="flex items-center gap-2">
                        <TruckIcon
                            class="size-5 text-indigo-600 dark:text-indigo-400"
                        />
                        <h2
                            class="text-base font-semibold text-gray-900 dark:text-white"
                        >
                            Incoming Kits
                        </h2>
                        <span
                            class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400"
                        >
                            {{ incomingKits.length }} in transit
                        </span>
                    </div>
                    <Link
                        href="/reports/leads/daily-kits"
                        class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                    >
                        View all kits &rarr;
                    </Link>
                </div>
                <div class="overflow-x-auto">
                    <table
                        class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                    >
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th
                                    class="px-4 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Transaction
                                </th>
                                <th
                                    class="px-4 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Customer
                                </th>
                                <th
                                    class="px-4 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-4 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Location
                                </th>
                                <th
                                    class="px-4 py-2 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Est. Value
                                </th>
                                <th
                                    class="px-4 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Tracking
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                        >
                            <tr
                                v-for="kit in incomingKits"
                                :key="kit.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td
                                    class="px-4 py-2 text-sm font-medium whitespace-nowrap"
                                >
                                    <Link
                                        :href="`/transactions/${kit.id}`"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        {{ kit.transaction_number }}
                                    </Link>
                                </td>
                                <td
                                    class="px-4 py-2 text-sm whitespace-nowrap"
                                >
                                    <Link
                                        v-if="kit.customer_id"
                                        :href="`/customers/${kit.customer_id}`"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        {{ kit.customer_name }}
                                    </Link>
                                    <span
                                        v-else
                                        class="text-gray-500 dark:text-gray-400"
                                    >
                                        {{ kit.customer_name }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-sm whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="getTrackingStatusBadge(kit).class"
                                    >
                                        {{ getTrackingStatusBadge(kit).label }}
                                    </span>
                                    <div
                                        v-if="kit.tracking_status?.estimated_delivery"
                                        class="mt-0.5 text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        ETA:
                                        {{ kit.tracking_status.estimated_delivery }}
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-2 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    <span v-if="kit.tracking_status?.location">
                                        {{ kit.tracking_status.location }}
                                    </span>
                                    <span v-else class="text-xs">
                                        Shipped
                                        {{ kit.return_shipped_at }}
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-2 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ formatCurrency(kit.estimated_value) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-sm whitespace-nowrap"
                                >
                                    <div
                                        v-if="kit.return_tracking"
                                        class="flex flex-col"
                                    >
                                        <span
                                            class="text-xs font-medium text-gray-600 dark:text-gray-300"
                                        >
                                            {{ getCarrierIcon(kit.return_carrier) }}
                                        </span>
                                        <span
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ kit.return_tracking }}
                                        </span>
                                    </div>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
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
                                    Date
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
                                v-for="row in dailyData"
                                :key="row.date_key"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
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
                            <tr v-if="dailyData.length === 0">
                                <td
                                    colspan="14"
                                    class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    No leads data found for this month.
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
