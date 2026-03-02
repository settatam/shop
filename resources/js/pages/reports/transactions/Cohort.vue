<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, reactive } from 'vue';
import StatCard from '@/components/charts/StatCard.vue';
import ReportTable from '@/components/widgets/ReportTable.vue';
import DatePicker from '@/components/ui/date-picker/DatePicker.vue';
import CohortDrilldownSheet from '@/components/reports/CohortDrilldownSheet.vue';

interface DataRow {
    period: string;
    start_date: string;
    end_date: string;
    kits_requested: number;
    kits_declined: number;
    kits_declined_percent: number;
    kits_received: number;
    kits_received_percent: number;
    kits_rejected: number;
    kits_returned: number;
    offers_given: number;
    offers_declined: number;
    offers_pending: number;
    offers_accepted: number;
    estimated_value: number;
    profit: number;
    profit_percent: number;
    actionable_received_no_offer: number;
    actionable_offer_no_response: number;
    actionable_delivered_not_received: number;
}

interface Totals {
    kits_requested: number;
    kits_declined: number;
    kits_declined_percent: number;
    kits_received: number;
    kits_received_percent: number;
    kits_rejected: number;
    kits_returned: number;
    offers_given: number;
    offers_declined: number;
    offers_pending: number;
    offers_accepted: number;
    estimated_value: number;
    profit: number;
    profit_percent: number;
    actionable_received_no_offer: number;
    actionable_offer_no_response: number;
    actionable_delivered_not_received: number;
}

const props = defineProps<{
    cohortData: DataRow[];
    totals: Totals;
    startDate?: string;
    endDate?: string;
    granularity?: string;
    statuses: Record<string, string>;
    filters: {
        status?: string;
    };
}>();

const startDate = ref(props.startDate || '');
const endDate = ref(props.endDate || '');
const selectedStatus = ref(props.filters.status || '');
const selectedGranularity = ref(props.granularity || 'monthly');
const drilldownSheet = ref<InstanceType<typeof CohortDrilldownSheet> | null>(null);
const expandedRows = reactive(new Set<string>());

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Reports', href: '#' },
    { title: 'Transactions', href: '/reports/transactions/monthly' },
    { title: 'Cohort Analysis', href: '/reports/transactions/cohort' },
]);

const granularityLabels: Record<string, string> = {
    daily: 'Daily',
    monthly: 'Monthly',
    yearly: 'Yearly',
};

const subtitle = computed(() => {
    const label = granularityLabels[selectedGranularity.value] || 'Monthly';
    if (startDate.value && endDate.value) {
        return `Cohort Analysis (${label}) — ${startDate.value} to ${endDate.value}`;
    }
    if (selectedGranularity.value === 'daily') {
        return `Cohort Analysis (${label}) — Current Month`;
    }
    if (selectedGranularity.value === 'yearly') {
        return `Cohort Analysis (${label}) — Past 5 Years`;
    }
    return `Cohort Analysis (${label}) — Past 13 Months`;
});

function applyFilters(): void {
    const params: Record<string, string> = {};
    if (startDate.value) {
        params.start_date = startDate.value;
    }
    if (endDate.value) {
        params.end_date = endDate.value;
    }
    if (selectedStatus.value) {
        params.status = selectedStatus.value;
    }
    if (selectedGranularity.value && selectedGranularity.value !== 'monthly') {
        params.granularity = selectedGranularity.value;
    }
    router.get('/reports/transactions/cohort', params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function openDrilldown(row: DataRow, metric: string, metricLabel: string): void {
    drilldownSheet.value?.load({
        startDate: row.start_date,
        endDate: row.end_date,
        metric,
        metricLabel: `${row.period} — ${metricLabel}`,
        status: selectedStatus.value || undefined,
    });
}

function toggleRow(period: string): void {
    if (expandedRows.has(period)) {
        expandedRows.delete(period);
    } else {
        expandedRows.add(period);
    }
}

function hasActionableLeads(row: DataRow): boolean {
    return (
        row.actionable_received_no_offer > 0 ||
        row.actionable_offer_no_response > 0 ||
        row.actionable_delivered_not_received > 0
    );
}

const exportUrl = computed(() => {
    const params = new URLSearchParams();
    if (startDate.value) {
        params.set('start_date', startDate.value);
    }
    if (endDate.value) {
        params.set('end_date', endDate.value);
    }
    if (selectedStatus.value) {
        params.set('status', selectedStatus.value);
    }
    if (selectedGranularity.value && selectedGranularity.value !== 'monthly') {
        params.set('granularity', selectedGranularity.value);
    }
    const query = params.toString();
    return '/reports/transactions/cohort/export' + (query ? `?${query}` : '');
});
const emailUrl = '/reports/transactions/cohort/email';
</script>

<template>
    <Head title="Transactions Report - Cohort Analysis" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Transactions Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ subtitle }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/transactions/monthly"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Activity
                    </Link>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Start Date</label>
                    <DatePicker v-model="startDate" placeholder="Start date" class="w-[160px]" @update:model-value="applyFilters" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">End Date</label>
                    <DatePicker v-model="endDate" placeholder="End date" class="w-[160px]" @update:model-value="applyFilters" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Granularity</label>
                    <select
                        v-model="selectedGranularity"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        @change="applyFilters"
                    >
                        <option value="daily">Daily</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
                    <select
                        v-model="selectedStatus"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        @change="applyFilters"
                    >
                        <option value="">All Statuses</option>
                        <option v-for="(label, value) in statuses" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Kits Requested"
                    :value="formatNumber(totals.kits_requested)"
                />
                <StatCard
                    title="Kits Received"
                    :value="formatNumber(totals.kits_received)"
                    :subtitle="`${totals.kits_received_percent}% of requests`"
                />
                <StatCard
                    title="Offers Accepted"
                    :value="formatNumber(totals.offers_accepted)"
                />
                <StatCard
                    title="Profit"
                    :value="formatCurrency(totals.profit)"
                    :subtitle="`${totals.profit_percent}% margin`"
                />
            </div>

            <!-- Data Table -->
            <ReportTable title="Cohort Analysis Data" :export-url="exportUrl" :email-url="emailUrl">
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cohort</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Kits Req.</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Declined</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Declined %</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Kits Rec.</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Rec. %</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Rejected</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Returned</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Offers</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Declined</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Pending</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Accepted</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Est. Value</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit</th>
                                <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Profit %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <template v-for="row in cohortData" :key="row.period">
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="whitespace-nowrap px-3 py-3 text-sm font-medium text-gray-900 dark:text-white">
                                        <button
                                            v-if="hasActionableLeads(row)"
                                            class="inline-flex items-center gap-1"
                                            @click="toggleRow(row.period)"
                                        >
                                            <svg
                                                class="h-4 w-4 text-gray-400 transition-transform"
                                                :class="{ 'rotate-90': expandedRows.has(row.period) }"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                            </svg>
                                            {{ row.period }}
                                        </button>
                                        <span v-else class="pl-5">{{ row.period }}</span>
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.kits_requested > 0 ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-gray-500 dark:text-gray-400'"
                                        @click.stop="row.kits_requested > 0 && openDrilldown(row, 'kits_requested', 'Kits Requested')"
                                    >
                                        {{ formatNumber(row.kits_requested) }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.kits_declined > 0 ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-gray-500 dark:text-gray-400'"
                                        @click.stop="row.kits_declined > 0 && openDrilldown(row, 'kits_declined', 'Kits Declined')"
                                    >
                                        {{ formatNumber(row.kits_declined) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ row.kits_declined_percent }}%</td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.kits_received > 0 ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-gray-500 dark:text-gray-400'"
                                        @click.stop="row.kits_received > 0 && openDrilldown(row, 'kits_received', 'Kits Received')"
                                    >
                                        {{ formatNumber(row.kits_received) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ row.kits_received_percent }}%</td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.kits_rejected > 0 ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-gray-500 dark:text-gray-400'"
                                        @click.stop="row.kits_rejected > 0 && openDrilldown(row, 'kits_rejected', 'Kits Rejected')"
                                    >
                                        {{ formatNumber(row.kits_rejected) }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.kits_returned > 0 ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-gray-500 dark:text-gray-400'"
                                        @click.stop="row.kits_returned > 0 && openDrilldown(row, 'kits_returned', 'Kits Returned')"
                                    >
                                        {{ formatNumber(row.kits_returned) }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.offers_given > 0 ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-gray-500 dark:text-gray-400'"
                                        @click.stop="row.offers_given > 0 && openDrilldown(row, 'offers_given', 'Offers Given')"
                                    >
                                        {{ formatNumber(row.offers_given) }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.offers_declined > 0 ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-gray-500 dark:text-gray-400'"
                                        @click.stop="row.offers_declined > 0 && openDrilldown(row, 'offers_declined', 'Offers Declined')"
                                    >
                                        {{ formatNumber(row.offers_declined) }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.offers_pending > 0 ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-gray-500 dark:text-gray-400'"
                                        @click.stop="row.offers_pending > 0 && openDrilldown(row, 'offers_pending', 'Offers Pending')"
                                    >
                                        {{ formatNumber(row.offers_pending) }}
                                    </td>
                                    <td
                                        class="whitespace-nowrap px-3 py-3 text-sm text-right"
                                        :class="row.offers_accepted > 0 ? 'text-green-600 dark:text-green-400 cursor-pointer hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-950 dark:hover:text-indigo-400' : 'text-green-600 dark:text-green-400'"
                                        @click.stop="row.offers_accepted > 0 && openDrilldown(row, 'offers_accepted', 'Offers Accepted')"
                                    >
                                        {{ formatNumber(row.offers_accepted) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-500 dark:text-gray-400">{{ formatCurrency(row.estimated_value) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-right" :class="row.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ formatCurrency(row.profit) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-right" :class="row.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ row.profit_percent }}%</td>
                                </tr>

                                <!-- Actionable leads row -->
                                <tr v-if="expandedRows.has(row.period) && hasActionableLeads(row)">
                                    <td colspan="15" class="bg-gray-50 px-4 py-3 dark:bg-gray-750">
                                        <div class="flex items-center gap-3">
                                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Actionable Leads:</span>
                                            <button
                                                v-if="row.actionable_received_no_offer > 0"
                                                class="inline-flex items-center gap-1.5 rounded-full bg-orange-100 px-3 py-1 text-xs font-medium text-orange-700 hover:bg-orange-200 dark:bg-orange-900/30 dark:text-orange-400 dark:hover:bg-orange-900/50"
                                                @click.stop="openDrilldown(row, 'received_no_offer', 'Received — No Offer')"
                                            >
                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-orange-500" />
                                                Received, No Offer ({{ row.actionable_received_no_offer }})
                                            </button>
                                            <button
                                                v-if="row.actionable_offer_no_response > 0"
                                                class="inline-flex items-center gap-1.5 rounded-full bg-yellow-100 px-3 py-1 text-xs font-medium text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-400 dark:hover:bg-yellow-900/50"
                                                @click.stop="openDrilldown(row, 'offer_no_response', 'Offer Sent — No Response')"
                                            >
                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-yellow-500" />
                                                Offer, No Response ({{ row.actionable_offer_no_response }})
                                            </button>
                                            <button
                                                v-if="row.actionable_delivered_not_received > 0"
                                                class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50"
                                                @click.stop="openDrilldown(row, 'delivered_not_received', 'Delivered — Not Received')"
                                            >
                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-red-500" />
                                                Delivered, Not Received ({{ row.actionable_delivered_not_received }})
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <!-- Empty state -->
                            <tr v-if="cohortData.length === 0">
                                <td colspan="15" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No transaction data found for this period.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="cohortData.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td class="px-3 py-3 text-sm text-gray-900 dark:text-white">TOTALS</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_requested) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_declined) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ totals.kits_declined_percent }}%</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_received) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ totals.kits_received_percent }}%</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_rejected) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.kits_returned) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.offers_given) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.offers_declined) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatNumber(totals.offers_pending) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-green-600 dark:text-green-400">{{ formatNumber(totals.offers_accepted) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(totals.estimated_value) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right" :class="totals.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ formatCurrency(totals.profit) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-sm text-right" :class="totals.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ totals.profit_percent }}%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            </ReportTable>
        </div>

        <CohortDrilldownSheet ref="drilldownSheet" />
    </AppLayout>
</template>
