<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { useWidget, type WidgetFilter } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import { DatePicker } from '@/components/ui/date-picker';
import { computed, onMounted, ref, watch } from 'vue';
import { XMarkIcon } from '@heroicons/vue/20/solid';
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';

interface FilterOption {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
];

function getUrlParams(): WidgetFilter {
    const params = new URLSearchParams(window.location.search);
    const filter: WidgetFilter = {};
    if (params.get('lead_source_id')) filter.lead_source_id = params.get('lead_source_id') || undefined;
    if (params.get('date_from')) filter.date_from = params.get('date_from') || undefined;
    if (params.get('date_to')) filter.date_to = params.get('date_to') || undefined;
    return filter;
}

const initialParams = getUrlParams();

const { data, loading, loadWidget, setPage, setSort, setSearch, setPerPage, updateFilter } = useWidget('Customers\\CustomersTable', initialParams);

const selectedLeadSource = ref<string>(initialParams.lead_source_id as string || '');
const dateFrom = ref<string>(initialParams.date_from as string || '');
const dateTo = ref<string>(initialParams.date_to as string || '');

const dataTableRef = ref<InstanceType<typeof DataTable> | null>(null);

const availableLeadSources = computed<FilterOption[]>(() => data.value?.filters?.available?.lead_sources || []);

onMounted(() => {
    loadWidget();
});

watch([selectedLeadSource, dateFrom, dateTo], () => {
    updateFilter({
        lead_source_id: selectedLeadSource.value || undefined,
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

function handlePerPageChange(perPage: number) {
    setPerPage(perPage);
}

function handleBulkActionSuccess() {
    dataTableRef.value?.clearSelection();
    loadWidget();
}

function clearFilters() {
    selectedLeadSource.value = '';
    dateFrom.value = '';
    dateTo.value = '';
}

const hasActiveFilters = computed(() => {
    return selectedLeadSource.value || dateFrom.value || dateTo.value;
});

// Lightbox state for ID photo
const showLightbox = ref(false);
const lightboxImage = ref<{ url: string; name: string } | null>(null);

const closeLightbox = () => {
    showLightbox.value = false;
    lightboxImage.value = null;
};
</script>

<template>
    <Head title="Customers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Customers</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage your customer database
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Lead Source</label>
                    <select
                        v-model="selectedLeadSource"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option value="">All Lead Sources</option>
                        <option v-for="source in availableLeadSources" :key="source.value" :value="source.value">
                            {{ source.label }}
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

            <!-- Data Table -->
            <DataTable
                v-if="data"
                ref="dataTableRef"
                :data="data"
                :loading="loading"
                bulk-action-url="/customers/bulk-action"
                @page-change="handlePageChange"
                @sort-change="handleSortChange"
                @search="handleSearch"
                @per-page-change="handlePerPageChange"
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

        <!-- ID Lightbox Dialog -->
        <Dialog :open="showLightbox" @update:open="closeLightbox">
            <DialogContent class="max-w-3xl p-0 overflow-hidden bg-black/90">
                <DialogTitle class="sr-only">{{ lightboxImage?.name }} - ID Photo</DialogTitle>
                <div class="relative">
                    <button
                        type="button"
                        class="absolute right-2 top-2 z-10 rounded-full bg-black/50 p-2 text-white hover:bg-black/70 focus:outline-none focus:ring-2 focus:ring-white"
                        @click="closeLightbox"
                    >
                        <XMarkIcon class="size-5" />
                    </button>
                    <img
                        v-if="lightboxImage"
                        :src="lightboxImage.url"
                        :alt="`${lightboxImage.name} ID`"
                        class="max-h-[80vh] w-full object-contain"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
