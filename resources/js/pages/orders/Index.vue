<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { useWidget, type WidgetFilter } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import { onMounted, ref, watch, computed } from 'vue';
import { PlusIcon, FunnelIcon, XMarkIcon } from '@heroicons/vue/20/solid';

interface Option {
    value: string;
    label: string;
}

interface VendorOption {
    value: number;
    label: string;
}

interface Props {
    statuses: Option[];
    marketplaces: Option[];
    paymentMethods: Option[];
    vendors: VendorOption[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
];

// Get URL query params
function getUrlParams(): WidgetFilter {
    const params = new URLSearchParams(window.location.search);
    const filter: WidgetFilter = {};
    if (params.get('status')) filter.status = params.get('status') || undefined;
    if (params.get('paid')) filter.paid = params.get('paid') || undefined;
    if (params.get('marketplace')) filter.marketplace = params.get('marketplace') || undefined;
    if (params.get('payment_type')) filter.payment_type = params.get('payment_type') || undefined;
    if (params.get('vendor_id')) filter.vendor_id = params.get('vendor_id') || undefined;
    if (params.get('charge_tax')) filter.charge_tax = params.get('charge_tax') || undefined;
    if (params.get('from_date')) filter.from_date = params.get('from_date') || undefined;
    if (params.get('to_date')) filter.to_date = params.get('to_date') || undefined;
    if (params.get('min_price')) filter.min_price = params.get('min_price') || undefined;
    if (params.get('max_price')) filter.max_price = params.get('max_price') || undefined;
    return filter;
}

const initialParams = getUrlParams();

// Widget setup with initial filter from URL
const { data, loading, loadWidget, setPage, setSort, setSearch, updateFilter } = useWidget('Orders\\OrdersTable', initialParams);

// Show/hide advanced filters
const showAdvancedFilters = ref(false);

// Filters - initialize from URL params
const filters = ref({
    status: initialParams.status || '',
    marketplace: initialParams.marketplace || '',
    payment_type: initialParams.payment_type || '',
    vendor_id: initialParams.vendor_id || '',
    charge_tax: initialParams.charge_tax || '',
    from_date: initialParams.from_date || '',
    to_date: initialParams.to_date || '',
    min_price: initialParams.min_price || '',
    max_price: initialParams.max_price || '',
});

// Reference to DataTable for clearing selection
const dataTableRef = ref<InstanceType<typeof DataTable> | null>(null);

// Count active filters
const activeFilterCount = computed(() => {
    let count = 0;
    if (filters.value.status) count++;
    if (filters.value.marketplace) count++;
    if (filters.value.payment_type) count++;
    if (filters.value.vendor_id) count++;
    if (filters.value.charge_tax) count++;
    if (filters.value.from_date || filters.value.to_date) count++;
    if (filters.value.min_price || filters.value.max_price) count++;
    return count;
});

// Load widget on mount
onMounted(() => {
    loadWidget();
});

// Watch filter changes
watch(filters, () => {
    updateFilter({
        status: filters.value.status || undefined,
        marketplace: filters.value.marketplace || undefined,
        payment_type: filters.value.payment_type || undefined,
        vendor_id: filters.value.vendor_id || undefined,
        charge_tax: filters.value.charge_tax || undefined,
        from_date: filters.value.from_date || undefined,
        to_date: filters.value.to_date || undefined,
        min_price: filters.value.min_price || undefined,
        max_price: filters.value.max_price || undefined,
        page: 1,
    });
}, { deep: true });

function handlePageChange(page: number) {
    setPage(page);
}

function handleSortChange(field: string, desc: boolean) {
    setSort(field, desc);
}

function handleSearch(term: string) {
    setSearch(term);
}

// Handle bulk action success
function handleBulkActionSuccess() {
    dataTableRef.value?.clearSelection();
    loadWidget();
}

// Clear all filters
function clearFilters() {
    filters.value = {
        status: '',
        marketplace: '',
        payment_type: '',
        vendor_id: '',
        charge_tax: '',
        from_date: '',
        to_date: '',
        min_price: '',
        max_price: '',
    };
}
</script>

<template>
    <Head title="Orders" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Orders</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage sales orders and customer transactions
                    </p>
                </div>
                <Link
                    href="/orders/create"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                >
                    <PlusIcon class="-ml-0.5 size-5" aria-hidden="true" />
                    New Order
                </Link>
            </div>

            <!-- Filters -->
            <div class="space-y-4">
                <!-- Primary Filters Row -->
                <div class="flex flex-wrap items-center gap-4">
                    <select
                        v-model="filters.status"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option value="">All Statuses</option>
                        <option v-for="status in statuses" :key="status.value" :value="status.value">
                            {{ status.label }}
                        </option>
                    </select>

                    <select
                        v-model="filters.marketplace"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option value="">All Marketplaces</option>
                        <option v-for="mp in marketplaces" :key="mp.value" :value="mp.value">
                            {{ mp.label }}
                        </option>
                    </select>

                    <select
                        v-model="filters.payment_type"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option value="">All Payment Types</option>
                        <option v-for="pm in paymentMethods" :key="pm.value" :value="pm.value">
                            {{ pm.label }}
                        </option>
                    </select>

                    <!-- Toggle Advanced Filters -->
                    <button
                        type="button"
                        @click="showAdvancedFilters = !showAdvancedFilters"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <FunnelIcon class="-ml-0.5 size-4" aria-hidden="true" />
                        More Filters
                        <span v-if="activeFilterCount > 0" class="ml-1 inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200">
                            {{ activeFilterCount }}
                        </span>
                    </button>

                    <!-- Clear Filters -->
                    <button
                        v-if="activeFilterCount > 0"
                        type="button"
                        @click="clearFilters"
                        class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        <XMarkIcon class="size-4" aria-hidden="true" />
                        Clear filters
                    </button>
                </div>

                <!-- Advanced Filters -->
                <div v-if="showAdvancedFilters" class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <!-- Date Range -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">From Date</label>
                            <input
                                v-model="filters.from_date"
                                type="date"
                                class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">To Date</label>
                            <input
                                v-model="filters.to_date"
                                type="date"
                                class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>

                        <!-- Price Range -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Min Price</label>
                            <input
                                v-model="filters.min_price"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max Price</label>
                            <input
                                v-model="filters.max_price"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>

                        <!-- Vendor -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Vendor</label>
                            <select
                                v-model="filters.vendor_id"
                                class="block w-full rounded-md border-0 bg-white pl-3 pr-10 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            >
                                <option value="">All Vendors</option>
                                <option v-for="vendor in vendors" :key="vendor.value" :value="vendor.value">
                                    {{ vendor.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Charge Tax -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tax Charged</label>
                            <select
                                v-model="filters.charge_tax"
                                class="block w-full rounded-md border-0 bg-white pl-3 pr-10 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            >
                                <option value="">All</option>
                                <option value="yes">Yes (Tax Charged)</option>
                                <option value="no">No (No Tax)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <DataTable
                v-if="data"
                ref="dataTableRef"
                :data="data"
                :loading="loading"
                bulk-action-url="/orders/bulk-action"
                @page-change="handlePageChange"
                @sort-change="handleSortChange"
                @search="handleSearch"
                @bulk-action-success="handleBulkActionSuccess"
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
