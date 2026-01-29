<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { useWidget } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import { onMounted } from 'vue';

interface Props {
    storeId: number;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Product Types', href: '/product-types' },
];

// Widget setup
const { data, loading, loadWidget, setPage, setSort, setSearch } = useWidget('ProductTypes\\ProductTypesTable');

// Load widget on mount
onMounted(() => {
    loadWidget();
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
</script>

<template>
    <Head title="Product Types" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Product Types</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Configure SKU, bucket, and barcode settings for product categories
                    </p>
                </div>
            </div>

            <!-- Data Table -->
            <DataTable
                v-if="data"
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
                                <div class="h-10 flex-1 rounded bg-gray-200 dark:bg-gray-700" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
