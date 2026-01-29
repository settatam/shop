<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { useWidget } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import MassEditSheet from '@/components/products/MassEditSheet.vue';
import GiaScannerModal from '@/components/products/GiaScannerModal.vue';
import { onMounted, ref, watch } from 'vue';
import { PlusIcon, CameraIcon, MagnifyingGlassIcon, AdjustmentsHorizontalIcon } from '@heroicons/vue/20/solid';

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

// Advanced search state (custom feature)
const showAdvancedSearch = ref(false);
const advancedFilters = ref({
    sku: '',
    priceMin: '',
    priceMax: '',
    quantityMin: '',
    quantityMax: '',
    dateFrom: '',
    dateTo: '',
});

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

// Apply advanced filters (custom feature)
function applyAdvancedFilters() {
    updateFilter({
        category_id: selectedCategory.value || undefined,
        brand_id: selectedBrand.value || undefined,
        status: selectedStatus.value || undefined,
        sku: advancedFilters.value.sku || undefined,
        price_min: advancedFilters.value.priceMin || undefined,
        price_max: advancedFilters.value.priceMax || undefined,
        quantity_min: advancedFilters.value.quantityMin || undefined,
        quantity_max: advancedFilters.value.quantityMax || undefined,
        date_from: advancedFilters.value.dateFrom || undefined,
        date_to: advancedFilters.value.dateTo || undefined,
        page: 1,
    });
    showAdvancedSearch.value = false;
}

// Clear advanced filters (custom feature)
function clearAdvancedFilters() {
    advancedFilters.value = {
        sku: '',
        priceMin: '',
        priceMax: '',
        quantityMin: '',
        quantityMax: '',
        dateFrom: '',
        dateTo: '',
    };
    applyAdvancedFilters();
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
                    category_id: null,
                    category_name: getDataValue<string>(item.category) || null,
                    brand_id: null,
                    brand_name: null,
                    is_published: getDataValue<string>(item.status) === 'Published',
                    template_name: null,
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
    dataTableRef.value?.clearSelection();
    loadWidget();
}

// Handle GIA scan success
function handleGiaScanSuccess(productId: number) {
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

                <!-- Advanced Search Toggle (Custom Feature) -->
                <button
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-600"
                    :class="{ 'bg-indigo-50 text-indigo-700 ring-indigo-300 dark:bg-indigo-900/50 dark:text-indigo-300': showAdvancedSearch }"
                    @click="showAdvancedSearch = !showAdvancedSearch"
                >
                    <AdjustmentsHorizontalIcon class="-ml-0.5 size-4" aria-hidden="true" />
                    Advanced Search
                </button>
            </div>

            <!-- Advanced Search Panel (Custom Feature) -->
            <div
                v-if="showAdvancedSearch"
                class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
            >
                <h3 class="mb-4 text-sm font-medium text-gray-900 dark:text-white">Advanced Filters</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <!-- SKU Search -->
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">SKU</label>
                        <input
                            v-model="advancedFilters.sku"
                            type="text"
                            placeholder="Search by SKU..."
                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        />
                    </div>

                    <!-- Price Range -->
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Price Range</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                v-model="advancedFilters.priceMin"
                                type="number"
                                placeholder="Min"
                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                            <input
                                v-model="advancedFilters.priceMax"
                                type="number"
                                placeholder="Max"
                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                    </div>

                    <!-- Quantity Range -->
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Quantity Range</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                v-model="advancedFilters.quantityMin"
                                type="number"
                                placeholder="Min"
                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                            <input
                                v-model="advancedFilters.quantityMax"
                                type="number"
                                placeholder="Max"
                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                    </div>

                    <!-- Date Range -->
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Created Date Range</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                v-model="advancedFilters.dateFrom"
                                type="date"
                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                            <input
                                v-model="advancedFilters.dateTo"
                                type="date"
                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-600"
                        @click="clearAdvancedFilters"
                    >
                        Clear Filters
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="applyAdvancedFilters"
                    >
                        <MagnifyingGlassIcon class="-ml-0.5 size-4" aria-hidden="true" />
                        Apply Filters
                    </button>
                </div>
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
