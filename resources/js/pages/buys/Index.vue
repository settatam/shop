<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { useWidget, type WidgetFilter } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import { DatePicker } from '@/components/ui/date-picker';
import { computed, onMounted, ref, watch } from 'vue';
import { XMarkIcon, PlusIcon } from '@heroicons/vue/20/solid';

interface FilterOption {
    value: string;
    label: string;
    color?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Buys', href: '/buys' },
];

// Get URL query params
function getUrlParams(): WidgetFilter {
    const params = new URLSearchParams(window.location.search);
    const filter: WidgetFilter = {};
    if (params.get('status')) filter.status = params.get('status') || undefined;
    if (params.get('payment_method')) filter.payment_method = params.get('payment_method') || undefined;
    if (params.get('min_amount')) filter.min_amount = params.get('min_amount') || undefined;
    if (params.get('max_amount')) filter.max_amount = params.get('max_amount') || undefined;
    if (params.get('from_date')) filter.from_date = params.get('from_date') || undefined;
    if (params.get('to_date')) filter.to_date = params.get('to_date') || undefined;
    return filter;
}

const initialParams = getUrlParams();

// Widget setup with initial filter from URL
const { data, loading, loadWidget, setPage, setSort, setSearch, setPerPage, updateFilter } = useWidget('Buys\\BuysTable', initialParams);

// Filters - initialize from URL params
const selectedStatus = ref<string>(initialParams.status as string || '');
const selectedPaymentMethod = ref<string>(initialParams.payment_method as string || '');
const minAmount = ref<string>(initialParams.min_amount as string || '');
const maxAmount = ref<string>(initialParams.max_amount as string || '');
const fromDate = ref<string>(initialParams.from_date as string || '');
const toDate = ref<string>(initialParams.to_date as string || '');

// Reference to DataTable for clearing selection
const dataTableRef = ref<InstanceType<typeof DataTable> | null>(null);

// Get available filters from widget data
const availableStatuses = computed<FilterOption[]>(() => data.value?.filters?.available?.statuses || []);
const availablePaymentMethods = computed<FilterOption[]>(() => data.value?.filters?.available?.payment_methods || []);

// Load widget on mount
onMounted(() => {
    loadWidget();
});

// Watch filter changes
watch([selectedStatus, selectedPaymentMethod, minAmount, maxAmount, fromDate, toDate], () => {
    updateFilter({
        status: selectedStatus.value || undefined,
        payment_method: selectedPaymentMethod.value || undefined,
        min_amount: minAmount.value || undefined,
        max_amount: maxAmount.value || undefined,
        from_date: fromDate.value || undefined,
        to_date: toDate.value || undefined,
        page: 1,
    });
});

function handlePageChange(page: number) {
    setPage(page);
}

function handleSortChange(field: string, desc: boolean) {
    setSort(field, desc);
}

function handleSearch(term: string) {
    setSearch(term);
}

function handlePerPageChange(perPage: number) {
    setPerPage(perPage);
}

function clearFilters() {
    selectedStatus.value = '';
    selectedPaymentMethod.value = '';
    minAmount.value = '';
    maxAmount.value = '';
    fromDate.value = '';
    toDate.value = '';
}

const hasActiveFilters = computed(() => {
    return selectedStatus.value || selectedPaymentMethod.value || minAmount.value || maxAmount.value || fromDate.value || toDate.value;
});
</script>

<template>
    <Head title="Buys by Transaction" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Buys by Transaction</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        View completed buy transactions with payment details
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        href="/buys/items"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View All Items
                    </Link>
                    <Link
                        href="/transactions/buy"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        <PlusIcon class="size-4" />
                        Create New
                    </Link>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div v-if="availableStatuses.length > 0">
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                    <select
                        v-model="selectedStatus"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option value="">All Statuses</option>
                        <option v-for="status in availableStatuses" :key="status.value" :value="status.value">
                            {{ status.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Payment Method</label>
                    <select
                        v-model="selectedPaymentMethod"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option value="">All Methods</option>
                        <option v-for="method in availablePaymentMethods" :key="method.value" :value="method.value">
                            {{ method.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Min Amount</label>
                    <input
                        v-model="minAmount"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        class="w-28 rounded-md border-0 bg-white py-1.5 px-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Max Amount</label>
                    <input
                        v-model="maxAmount"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        class="w-28 rounded-md border-0 bg-white py-1.5 px-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">From Date</label>
                    <DatePicker
                        v-model="fromDate"
                        placeholder="From date"
                        class="w-[160px]"
                    />
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">To Date</label>
                    <DatePicker
                        v-model="toDate"
                        placeholder="To date"
                        class="w-[160px]"
                    />
                </div>

                <!-- Clear filters -->
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 pb-1.5"
                    @click="clearFilters"
                >
                    <XMarkIcon class="size-4" />
                    Clear filters
                </button>
            </div>

            <!-- Data Table -->
            <DataTable
                v-if="data"
                ref="dataTableRef"
                :data="data"
                :loading="loading"
                :show-totals="true"
                :total-columns="[
                    { key: 'purchase_price', format: 'currency' },
                    { key: 'est_value', format: 'currency' },
                    { key: 'est_profit', format: 'currency' },
                ]"
                @page-change="handlePageChange"
                @sort-change="handleSortChange"
                @search="handleSearch"
                @per-page-change="handlePerPageChange"
            />

            <!-- Loading skeleton -->
            <div v-else class="animate-pulse">
                <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                    <div class="border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                        <div class="h-6 w-32 rounded bg-gray-200 dark:bg-gray-700" />
                    </div>
                    <div class="p-4">
                        <div class="space-y-3">
                            <div v-for="i in 5" :key="i" class="flex gap-4">
                                <div class="h-10 w-10 rounded bg-gray-200 dark:bg-gray-700" />
                                <div class="flex-1 space-y-2">
                                    <div class="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700" />
                                    <div class="h-3 w-1/2 rounded bg-gray-200 dark:bg-gray-700" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
