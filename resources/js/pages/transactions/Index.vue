<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { useWidget, type WidgetFilter } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import { DatePicker } from '@/components/ui/date-picker';
import { computed, onMounted, ref, watch } from 'vue';
import { PlusIcon, XMarkIcon } from '@heroicons/vue/20/solid';

interface FilterOption {
    value: string;
    label: string;
    color?: string;
    icon?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transactions', href: '/transactions' },
];

// Get URL query params
function getUrlParams(): WidgetFilter {
    const params = new URLSearchParams(window.location.search);
    const filter: WidgetFilter = {};
    if (params.get('status')) filter.status = params.get('status') || undefined;
    if (params.get('type')) filter.type = params.get('type') || undefined;
    if (params.get('tags')) filter.tags = params.get('tags') || undefined;
    if (params.get('date_from')) filter.date_from = params.get('date_from') || undefined;
    if (params.get('date_to')) filter.date_to = params.get('date_to') || undefined;
    return filter;
}

const initialParams = getUrlParams();

// Widget setup with initial filter from URL
const { data, loading, loadWidget, setPage, setSort, setSearch, updateFilter } = useWidget('Transactions\\TransactionsTable', initialParams);

// Filters - initialize from URL params
const selectedStatus = ref<string>(initialParams.status || '');
const selectedType = ref<string>((initialParams.type as string) || '');
const selectedTags = ref<string[]>(initialParams.tags ? String(initialParams.tags).split(',') : []);
const dateFrom = ref<string>(initialParams.date_from as string || '');
const dateTo = ref<string>(initialParams.date_to as string || '');

// Reference to DataTable for clearing selection
const dataTableRef = ref<InstanceType<typeof DataTable> | null>(null);

// Get available filters from widget data
const availableStatuses = computed<FilterOption[]>(() => data.value?.filters?.available?.statuses || []);
const availableTypes = computed<FilterOption[]>(() => data.value?.filters?.available?.types || []);
const availableTags = computed<FilterOption[]>(() => data.value?.filters?.available?.tags || []);

// Get selected status details for display
const selectedStatusDetails = computed(() => {
    if (!selectedStatus.value) return null;
    return availableStatuses.value.find(s => s.value === selectedStatus.value);
});

// Load widget on mount
onMounted(() => {
    loadWidget();
});

// Watch filter changes
watch([selectedStatus, selectedType, selectedTags, dateFrom, dateTo], () => {
    updateFilter({
        status: selectedStatus.value || undefined,
        type: selectedType.value || undefined,
        tags: selectedTags.value.length > 0 ? selectedTags.value.join(',') : undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
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

function toggleTag(tagId: string) {
    const index = selectedTags.value.indexOf(tagId);
    if (index === -1) {
        selectedTags.value.push(tagId);
    } else {
        selectedTags.value.splice(index, 1);
    }
}

function clearFilters() {
    selectedStatus.value = '';
    selectedType.value = '';
    selectedTags.value = [];
    dateFrom.value = '';
    dateTo.value = '';
}

const hasActiveFilters = computed(() => {
    return selectedStatus.value || selectedType.value || selectedTags.value.length > 0 || dateFrom.value || dateTo.value;
});
</script>

<template>
    <Head title="Transactions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Transactions</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage buy transactions from customers
                    </p>
                </div>
                <Link
                    href="/transactions/buy"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                >
                    <PlusIcon class="-ml-0.5 size-5" aria-hidden="true" />
                    New In-Store Buy
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
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
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
                    <select
                        v-model="selectedType"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option value="">All Types</option>
                        <option v-for="type in availableTypes" :key="type.value" :value="type.value">
                            {{ type.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">From Date</label>
                    <DatePicker
                        v-model="dateFrom"
                        placeholder="From date"
                        class="w-[160px]"
                    />
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">To Date</label>
                    <DatePicker
                        v-model="dateTo"
                        placeholder="To date"
                        class="w-[160px]"
                    />
                </div>

                <!-- Tags filter -->
                <div v-if="availableTags.length > 0" class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Tags:</span>
                    <button
                        v-for="tag in availableTags"
                        :key="tag.value"
                        type="button"
                        :class="[
                            'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium transition-all',
                            selectedTags.includes(tag.value)
                                ? 'ring-2 ring-offset-1 ring-indigo-500'
                                : 'hover:ring-1 hover:ring-gray-300',
                        ]"
                        :style="tag.color ? { backgroundColor: `${tag.color}20`, color: tag.color } : { backgroundColor: '#f3f4f6', color: '#374151' }"
                        @click="toggleTag(tag.value)"
                    >
                        {{ tag.label }}
                    </button>
                </div>

                <!-- Clear filters -->
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                    @click="clearFilters"
                >
                    <XMarkIcon class="size-4" />
                    Clear filters
                </button>
            </div>

            <!-- Active status indicator -->
            <div v-if="selectedStatusDetails" class="flex items-center gap-2 rounded-lg bg-gray-50 px-4 py-2 dark:bg-gray-800">
                <span
                    class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium"
                    :style="selectedStatusDetails.color ? { backgroundColor: `${selectedStatusDetails.color}15`, color: selectedStatusDetails.color, border: `1px solid ${selectedStatusDetails.color}30` } : {}"
                >
                    {{ selectedStatusDetails.label }}
                </span>
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    Showing {{ data?.data?.total || 0 }} transactions in this status
                </span>
            </div>

            <!-- Data Table -->
            <DataTable
                v-if="data"
                ref="dataTableRef"
                :data="data"
                :loading="loading"
                bulk-action-url="/transactions/bulk-action"
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
