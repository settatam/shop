<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import DataTable from '@/components/widgets/DataTable.vue';
import { DatePicker } from '@/components/ui/date-picker';
import { useWidget, type WidgetFilter } from '@/composables/useWidget';
import type { BreadcrumbItemType } from '@/types';
import { XMarkIcon } from '@heroicons/vue/20/solid';

interface FilterOption {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Invoices', href: '/invoices' },
];

// Get URL query params
function getUrlParams(): WidgetFilter {
    const params = new URLSearchParams(window.location.search);
    const filter: WidgetFilter = {};
    if (params.get('status')) filter.status = params.get('status') || undefined;
    if (params.get('payment_method')) filter.payment_method = params.get('payment_method') || undefined;
    if (params.get('from_date')) filter['dates.from'] = params.get('from_date') || undefined;
    if (params.get('to_date')) filter['dates.to'] = params.get('to_date') || undefined;
    return filter;
}

const initialParams = getUrlParams();

// Widget setup with initial filter from URL
const { data, loading, loadWidget, setPage, setSort, setSearch, updateFilter } = useWidget('Invoices\\InvoicesTable', initialParams);

// Filters - initialize from URL params
const selectedStatus = ref<string>(initialParams.status as string || '');
const selectedPaymentMethod = ref<string>(initialParams.payment_method as string || '');
const fromDate = ref<string>(initialParams['dates.from'] as string || '');
const toDate = ref<string>(initialParams['dates.to'] as string || '');

// Reference to DataTable
const dataTableRef = ref<InstanceType<typeof DataTable> | null>(null);

// Get available filters from widget data
const availablePaymentMethods = computed<FilterOption[]>(() => data.value?.filters?.available?.payment_methods || []);

// Status options
const statusOptions: FilterOption[] = [
    { value: '', label: 'All Statuses' },
    { value: 'pending', label: 'Pending' },
    { value: 'partial', label: 'Partial' },
    { value: 'paid', label: 'Paid' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'void', label: 'Void' },
    { value: 'refunded', label: 'Refunded' },
];

// Load widget on mount
onMounted(() => {
    loadWidget();
});

// Watch filter changes
watch([selectedStatus, selectedPaymentMethod, fromDate, toDate], () => {
    updateFilter({
        status: selectedStatus.value || undefined,
        payment_method: selectedPaymentMethod.value || undefined,
        'dates.from': fromDate.value || undefined,
        'dates.to': toDate.value || undefined,
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

function clearFilters() {
    selectedStatus.value = '';
    selectedPaymentMethod.value = '';
    fromDate.value = '';
    toDate.value = '';
}

const hasActiveFilters = computed(() => {
    return selectedStatus.value || selectedPaymentMethod.value || fromDate.value || toDate.value;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Page header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Invoices</h1>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                        View and manage all invoices for orders, repairs, and memos.
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                    <select
                        v-model="selectedStatus"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option v-for="status in statusOptions" :key="status.value" :value="status.value">
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
                @page-change="handlePageChange"
                @sort-change="handleSortChange"
                @search="handleSearch"
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
