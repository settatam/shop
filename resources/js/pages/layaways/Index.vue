<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { useWidget, type WidgetFilter } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import { onMounted, ref, watch } from 'vue';
import { PlusIcon } from '@heroicons/vue/20/solid';

interface Status {
    value: string;
    label: string;
}

interface TermOption {
    value: number;
    label: string;
}

interface PaymentType {
    value: string;
    label: string;
}

interface Props {
    statuses: Status[];
    termOptions: TermOption[];
    paymentTypes: PaymentType[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Layaways', href: '/layaways' }];

// Get URL query params
function getUrlParams(): WidgetFilter {
    const params = new URLSearchParams(window.location.search);
    const filter: WidgetFilter = {};
    if (params.get('status')) filter.status = params.get('status') || undefined;
    if (params.get('payment_type')) filter.payment_type = params.get('payment_type') || undefined;
    if (params.get('date_from')) filter.date_from = params.get('date_from') || undefined;
    if (params.get('date_to')) filter.date_to = params.get('date_to') || undefined;
    if (params.get('overdue')) filter.overdue = params.get('overdue') === 'true';
    return filter;
}

const initialParams = getUrlParams();

// Widget setup with initial filter from URL
const { data, loading, loadWidget, setPage, setSort, setSearch, updateFilter } = useWidget(
    'Layaways\\LayawaysTable',
    initialParams,
);

// Filters - initialize from URL params
const selectedStatus = ref<string>(initialParams.status || '');
const selectedPaymentType = ref<string>(initialParams.payment_type || '');
const dateFrom = ref<string>(initialParams.date_from || '');
const dateTo = ref<string>(initialParams.date_to || '');
const showOverdueOnly = ref<boolean>(initialParams.overdue || false);

// Reference to DataTable for clearing selection
const dataTableRef = ref<InstanceType<typeof DataTable> | null>(null);

// Load widget on mount
onMounted(() => {
    loadWidget();
});

// Watch filter changes
watch([selectedStatus, selectedPaymentType, dateFrom, dateTo, showOverdueOnly], () => {
    updateFilter({
        status: selectedStatus.value || undefined,
        payment_type: selectedPaymentType.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        overdue: showOverdueOnly.value || undefined,
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

// Handle bulk action success
function handleBulkActionSuccess() {
    dataTableRef.value?.clearSelection();
    loadWidget();
}
</script>

<template>
    <Head title="Layaways" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Layaways</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage customer layaway plans and payment schedules
                    </p>
                </div>
                <Link
                    href="/layaways/create"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                >
                    <PlusIcon class="-ml-0.5 size-5" aria-hidden="true" />
                    New Layaway
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-4">
                <select
                    v-model="selectedStatus"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Statuses</option>
                    <option v-for="status in statuses" :key="status.value" :value="status.value">
                        {{ status.label }}
                    </option>
                </select>

                <select
                    v-model="selectedPaymentType"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Payment Types</option>
                    <option v-for="pt in paymentTypes" :key="pt.value" :value="pt.value">
                        {{ pt.label }}
                    </option>
                </select>

                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-500 dark:text-gray-400">From:</label>
                    <input
                        v-model="dateFrom"
                        type="date"
                        class="rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-500 dark:text-gray-400">To:</label>
                    <input
                        v-model="dateTo"
                        type="date"
                        class="rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <label class="flex items-center gap-2">
                    <input
                        v-model="showOverdueOnly"
                        type="checkbox"
                        class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                    />
                    <span class="text-sm text-gray-600 dark:text-gray-400">Overdue only</span>
                </label>
            </div>

            <!-- Data Table -->
            <DataTable
                v-if="data"
                ref="dataTableRef"
                :data="data"
                :loading="loading"
                bulk-action-url="/layaways/bulk-action"
                @page-change="handlePageChange"
                @sort-change="handleSortChange"
                @search="handleSearch"
                @bulk-action-success="handleBulkActionSuccess"
            />

            <!-- Loading skeleton -->
            <div v-else class="animate-pulse">
                <div
                    class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10"
                >
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
