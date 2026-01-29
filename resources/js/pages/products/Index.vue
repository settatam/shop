<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { useWidget } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import MassEditSheet from '@/components/products/MassEditSheet.vue';
import GiaScannerModal from '@/components/products/GiaScannerModal.vue';
import { onMounted, ref, watch } from 'vue';
import { PlusIcon, CameraIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

interface Category {
    id: number;
    name: string;
}

interface Brand {
    id: number;
    name: string;
}

interface Warehouse {
    id: number;
    name: string;
    code: string | null;
    is_default: boolean;
}

interface Props {
    categories: Category[];
    brands: Brand[];
    warehouses: Warehouse[];
}

interface SelectedProduct {
    id: number;
    title: string;
    category_id: number | null;
    category_name: string | null;
    brand_id: number | null;
    brand_name: string | null;
    is_published: boolean;
    template_name: string | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
];

// Widget setup
const { data, loading, loadWidget, setPage, setSort, setSearch, updateFilter } = useWidget('Products\\ProductsTable');

// Filters
const selectedCategory = ref<string>('');
const selectedBrand = ref<string>('');
const selectedStatus = ref<string>('');

// Mass edit state
const showMassEditSheet = ref(false);
const selectedProducts = ref<SelectedProduct[]>([]);
const loadingProducts = ref(false);

// Reference to DataTable for clearing selection
const dataTableRef = ref<InstanceType<typeof DataTable> | null>(null);

// GIA Scanner state
const showGiaScanner = ref(false);

// Load widget on mount
onMounted(() => {
    loadWidget();
});

// Watch filter changes
watch([selectedCategory, selectedBrand, selectedStatus], () => {
    updateFilter({
        category_id: selectedCategory.value || undefined,
        brand_id: selectedBrand.value || undefined,
        status: selectedStatus.value || undefined,
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

// Handle bulk action modal (mass edit)
async function handleBulkActionModal(action: string, ids: (number | string)[]) {
    if (action === 'mass_edit') {
        loadingProducts.value = true;

        // Map IDs to product data from the current widget data
        const products: SelectedProduct[] = [];
        const items = data.value?.data?.items || [];

        for (const item of items) {
            // Get the ID from the item (handle typed cell format)
            const itemId = typeof item.id === 'object' && 'data' in item.id
                ? (item.id as { data: number }).data
                : item.id as number;

            if (ids.includes(itemId)) {
                // Extract data from typed cells
                const getDataValue = <T>(field: unknown): T | null => {
                    if (typeof field === 'object' && field !== null && 'data' in field) {
                        return (field as { data: T }).data;
                    }
                    return field as T;
                };

                products.push({
                    id: itemId,
                    title: getDataValue<string>(item.title) || '',
                    category_id: null, // Not available in table data
                    category_name: getDataValue<string>(item.category) || null,
                    brand_id: null, // Not available in table data
                    brand_name: null,
                    is_published: getDataValue<string>(item.status) === 'Published',
                    template_name: null, // Would need to fetch from API
                });
            }
        }

        selectedProducts.value = products;
        showMassEditSheet.value = true;
        loadingProducts.value = false;
    }
}

// Handle mass edit success
function handleMassEditSuccess() {
    // Clear selection in DataTable
    dataTableRef.value?.clearSelection();
    // Reload widget data
    loadWidget();
}

// Handle GIA scan success
function handleGiaScanSuccess(productId: number) {
    // Reload widget data
    loadWidget();
}
</script>

<template>
    <Head title="Products" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Products</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage your product catalog
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600"
                        @click="showGiaScanner = true"
                    >
                        <CameraIcon class="-ml-0.5 size-5" aria-hidden="true" />
                        Scan GIA Card
                    </button>
                    <Link
                        href="/products/create"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        <PlusIcon class="-ml-0.5 size-5" aria-hidden="true" />
                        Add Product
                    </Link>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-4">
                <select
                    v-model="selectedCategory"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Categories</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">
                        {{ category.name }}
                    </option>
                </select>

                <select
                    v-model="selectedBrand"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Brands</option>
                    <option v-for="brand in brands" :key="brand.id" :value="brand.id">
                        {{ brand.name }}
                    </option>
                </select>

                <select
                    v-model="selectedStatus"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Statuses</option>
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                </select>
            </div>

            <!-- Data Table -->
            <DataTable
                v-if="data"
                ref="dataTableRef"
                :data="data"
                :loading="loading"
                bulk-action-url="/products/bulk-action"
                enable-quick-view
                quick-view-field="title"
                @page-change="handlePageChange"
                @sort-change="handleSortChange"
                @search="handleSearch"
                @bulk-action-modal="handleBulkActionModal"
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

        <!-- Mass Edit Sheet -->
        <MassEditSheet
            v-model:open="showMassEditSheet"
            :products="selectedProducts"
            :categories="categories"
            :brands="brands"
            @success="handleMassEditSuccess"
        />

        <!-- GIA Scanner Modal -->
        <GiaScannerModal
            v-model:open="showGiaScanner"
            :categories="categories"
            :brands="brands"
            :warehouses="warehouses"
            @success="handleGiaScanSuccess"
        />
    </AppLayout>
</template>
