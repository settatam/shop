<script setup lang="ts">
import { DatePicker } from '@/components/ui/date-picker';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ReportTable from '@/components/widgets/ReportTable.vue';
import StatCard from '@/components/charts/StatCard.vue';
import AreaChart from '@/components/charts/AreaChart.vue';
import BarChart from '@/components/charts/BarChart.vue';

interface DayRow {
    period: string;
    date: string;
    items_added: number;
    cost_added: number;
    wholesale_added: number;
    items_removed: number;
    cost_removed: number;
    wholesale_removed: number;
    net_items: number;
    net_cost: number;
    net_wholesale: number;
}

interface Totals {
    items_added: number;
    cost_added: number;
    wholesale_added: number;
    items_removed: number;
    cost_removed: number;
    wholesale_removed: number;
    net_items: number;
    net_cost: number;
    net_wholesale: number;
}

const props = defineProps<{
    dailyData: DayRow[];
    totals: Totals;
    startDate: string;
    endDate: string;
    dateRangeLabel: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Inventory', href: '/reports/inventory' },
    { title: 'Daily', href: '/reports/inventory/daily' },
];

const startDate = ref(props.startDate);
const endDate = ref(props.endDate);

function applyFilters() {
    router.get('/reports/inventory/daily', {
        start_date: startDate.value,
        end_date: endDate.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetToCurrentMonth() {
    const now = new Date();
    startDate.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;
    endDate.value = now.toISOString().split('T')[0];
    applyFilters();
}

const queryParams = computed(() => {
    return `start_date=${startDate.value}&end_date=${endDate.value}`;
});

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

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

// Chart data
const chartLabels = computed(() => props.dailyData.map(row => {
    const d = new Date(row.date + 'T00:00:00');
    return `${d.getMonth() + 1}/${d.getDate()}`;
}));
const addedData = computed(() => props.dailyData.map(row => row.cost_added));
const removedData = computed(() => props.dailyData.map(row => row.cost_removed));
const netData = computed(() => props.dailyData.map(row => row.net_cost));
const itemsAddedData = computed(() => props.dailyData.map(row => row.items_added));
const itemsRemovedData = computed(() => props.dailyData.map(row => row.items_removed));

// Average daily cost added
const avgDailyAdded = computed(() => {
    if (props.dailyData.length === 0) return 0;
    return props.totals.cost_added / props.dailyData.length;
});

const exportUrl = computed(() => `/reports/inventory/daily/export?${queryParams.value}`);
const emailUrl = computed(() => `/reports/inventory/daily/email?${queryParams.value}`);
</script>

<template>
    <Head title="Inventory Report - Daily" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Inventory Report</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Daily - {{ dateRangeLabel }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/inventory"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Current
                    </Link>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Start Date</label>
                    <DatePicker v-model="startDate" @update:model-value="applyFilters" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">End Date</label>
                    <DatePicker v-model="endDate" @update:model-value="applyFilters" />
                </div>
                <button
                    type="button"
                    class="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    @click="resetToCurrentMonth"
                >
                    This Month
                </button>
            </div>

            <!-- Cost Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title="Cost Added"
                    :value="formatCurrency(totals.cost_added)"
                    :sparkline-data="addedData"
                />
                <StatCard
                    title="Cost Removed"
                    :value="formatCurrency(totals.cost_removed)"
                    :sparkline-data="removedData"
                />
                <StatCard
                    title="Net Cost"
                    :value="formatCurrency(totals.net_cost)"
                    :sparkline-data="netData"
                />
                <StatCard
                    title="Avg Daily Added"
                    :value="formatCurrency(avgDailyAdded)"
                />
            </div>

            <!-- Wholesale Stat Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard
                    title="Wholesale Added"
                    :value="formatCurrency(totals.wholesale_added)"
                />
                <StatCard
                    title="Wholesale Removed"
                    :value="formatCurrency(totals.wholesale_removed)"
                />
                <StatCard
                    title="Net Wholesale"
                    :value="formatCurrency(totals.net_wholesale)"
                />
            </div>

            <!-- Charts Row -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Cost Activity Chart -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Daily Cost Activity</h3>
                    </div>
                    <div class="p-4">
                        <AreaChart
                            v-if="dailyData.length > 0"
                            :labels="chartLabels"
                            :datasets="[
                                { label: 'Added', data: addedData, color: '#22c55e' },
                                { label: 'Removed', data: removedData, color: '#ef4444' },
                            ]"
                            :height="250"
                            :format-value="formatCurrencyShort"
                        />
                        <div v-else class="flex h-64 items-center justify-center text-gray-500">
                            No data available
                        </div>
                    </div>
                </div>

                <!-- Items Volume Chart -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Daily Items Volume</h3>
                    </div>
                    <div class="p-4">
                        <BarChart
                            v-if="dailyData.length > 0"
                            :labels="chartLabels"
                            :datasets="[
                                { label: 'Added', data: itemsAddedData, color: '#22c55e' },
                                { label: 'Removed', data: itemsRemovedData, color: '#ef4444' },
                            ]"
                            :height="250"
                        />
                        <div v-else class="flex h-64 items-center justify-center text-gray-500">
                            No data available
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <ReportTable title="Daily Inventory Data" :export-url="exportUrl" :email-url="emailUrl">
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Items Added</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost Added ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Wholesale Added ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Items Removed</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Cost Removed ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Wholesale Removed ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Items</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Cost ($)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-300">Net Wholesale ($)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr v-for="row in dailyData" :key="row.date" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-gray-900 dark:text-white">{{ row.period }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="row.items_added > 0">+{{ formatNumber(row.items_added) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="row.cost_added > 0">+{{ formatCurrency(row.cost_added) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    <span v-if="row.wholesale_added > 0">+{{ formatCurrency(row.wholesale_added) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="row.items_removed > 0">-{{ formatNumber(row.items_removed) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="row.cost_removed > 0">-{{ formatCurrency(row.cost_removed) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    <span v-if="row.wholesale_removed > 0">-{{ formatCurrency(row.wholesale_removed) }}</span>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.net_items >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ row.net_items >= 0 ? '+' : '' }}{{ formatNumber(row.net_items) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.net_cost >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ row.net_cost >= 0 ? '+' : '' }}{{ formatCurrency(row.net_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="row.net_wholesale >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ row.net_wholesale >= 0 ? '+' : '' }}{{ formatCurrency(row.net_wholesale) }}
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="dailyData.length === 0">
                                <td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No inventory activity found.
                                </td>
                            </tr>
                        </tbody>
                        <!-- Totals row -->
                        <tfoot v-if="dailyData.length > 0" class="bg-gray-100 dark:bg-gray-700">
                            <tr class="font-semibold">
                                <td class="px-4 py-4 text-sm text-gray-900 dark:text-white">TOTALS</td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    +{{ formatNumber(totals.items_added) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    +{{ formatCurrency(totals.cost_added) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-green-600 dark:text-green-400">
                                    +{{ formatCurrency(totals.wholesale_added) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    -{{ formatNumber(totals.items_removed) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    -{{ formatCurrency(totals.cost_removed) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right text-red-600 dark:text-red-400">
                                    -{{ formatCurrency(totals.wholesale_removed) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.net_items >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ totals.net_items >= 0 ? '+' : '' }}{{ formatNumber(totals.net_items) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.net_cost >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ totals.net_cost >= 0 ? '+' : '' }}{{ formatCurrency(totals.net_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-right" :class="totals.net_wholesale >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                    {{ totals.net_wholesale >= 0 ? '+' : '' }}{{ formatCurrency(totals.net_wholesale) }}
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
