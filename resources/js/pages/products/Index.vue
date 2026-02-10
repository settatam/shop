<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { useWidget } from '@/composables/useWidget';
import DataTable from '@/components/widgets/DataTable.vue';
import MassEditSheet from '@/components/products/MassEditSheet.vue';
import GiaScannerModal from '@/components/products/GiaScannerModal.vue';
import AdvancedSearchModal from '@/components/products/AdvancedSearchModal.vue';
import { onMounted, ref, watch, computed } from 'vue';
import { PlusIcon, CameraIcon, FunnelIcon, XMarkIcon, MagnifyingGlassIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

interface Category {
    id: number;
    name: string;
    parent_id: number | null;
}

interface Level2Category {
    id: number;
    name: string;
}

interface Level3Category {
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

interface Tag {
    id: number;
    name: string;
    color: string | null;
}

interface Marketplace {
    id: number;
    name: string;
}

interface FilterOption {
    value: string;
    label: string;
}

interface Props {
    categories: Category[];
    level2Categories: Level2Category[];
    level3ByParent: Record<number, Level3Category[]>;
    brands: Brand[];
    warehouses: Warehouse[];
    tags: Tag[];
    marketplaces: Marketplace[];
    types: FilterOption[];
    stoneShapes: FilterOption[];
    ringSizes: FilterOption[];
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

// Show/hide advanced filters
const showAdvancedFilters = ref(false);

// Filter state
const filters = ref({
    category_level2_id: '',
    category_level3_id: '',
    brand_id: '',
    status: '',
    stock: '',
    type: '',
    marketplace_id: '',
    tag_ids: [] as string[],
    from_date: '',
    to_date: '',
    min_price: '',
    max_price: '',
    min_cost: '',
    max_cost: '',
    stone_shape: '',
    min_stone_weight: '',
    max_stone_weight: '',
    ring_size: '',
});

// Computed: Level 3 categories based on selected Level 2
const availableLevel3Categories = computed(() => {
    if (!filters.value.category_level2_id) return [];
    const l2Id = Number(filters.value.category_level2_id);
    return props.level3ByParent[l2Id] || [];
});

// Watch level2 changes to reset level3
watch(() => filters.value.category_level2_id, () => {
    filters.value.category_level3_id = '';
});

// Count active filters
const activeFilterCount = computed(() => {
    let count = 0;
    if (filters.value.category_level2_id) count++;
    if (filters.value.category_level3_id) count++;
    if (filters.value.brand_id) count++;
    if (filters.value.status) count++;
    if (filters.value.stock) count++;
    if (filters.value.type) count++;
    if (filters.value.marketplace_id) count++;
    if (filters.value.tag_ids.length > 0) count++;
    if (filters.value.from_date || filters.value.to_date) count++;
    if (filters.value.min_price || filters.value.max_price) count++;
    if (filters.value.min_cost || filters.value.max_cost) count++;
    if (filters.value.stone_shape) count++;
    if (filters.value.min_stone_weight || filters.value.max_stone_weight) count++;
    if (filters.value.ring_size) count++;
    return count;
});

// Mass edit state
const showMassEditSheet = ref(false);
const selectedProducts = ref<SelectedProduct[]>([]);
const loadingProducts = ref(false);

// Reference to DataTable for clearing selection
const dataTableRef = ref<InstanceType<typeof DataTable> | null>(null);

// GIA Scanner state
const showGiaScanner = ref(false);

// Advanced Search state
const showAdvancedSearch = ref(false);

// Load widget on mount
onMounted(() => {
    loadWidget();
});

// Watch filter changes (debounced)
let filterTimeout: ReturnType<typeof setTimeout> | null = null;
watch(filters, () => {
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
}, { deep: true });

function applyFilters() {
    const filterParams: Record<string, unknown> = {
        page: 1,
    };

    // Use level3 if selected, otherwise use level2 (which includes all its children)
    if (filters.value.category_level3_id) {
        filterParams.category_id = filters.value.category_level3_id;
    } else if (filters.value.category_level2_id) {
        filterParams.category_id = filters.value.category_level2_id;
    }
    if (filters.value.brand_id) filterParams.brand_id = filters.value.brand_id;
    if (filters.value.status) filterParams.status = filters.value.status;
    if (filters.value.stock) filterParams.stock = filters.value.stock;
    if (filters.value.type) filterParams.type = filters.value.type;
    if (filters.value.marketplace_id) filterParams.marketplace_id = filters.value.marketplace_id;
    if (filters.value.tag_ids.length > 0) filterParams.tag_ids = filters.value.tag_ids.join(',');
    if (filters.value.from_date) filterParams.from_date = filters.value.from_date;
    if (filters.value.to_date) filterParams.to_date = filters.value.to_date;
    if (filters.value.min_price) filterParams.min_price = filters.value.min_price;
    if (filters.value.max_price) filterParams.max_price = filters.value.max_price;
    if (filters.value.min_cost) filterParams.min_cost = filters.value.min_cost;
    if (filters.value.max_cost) filterParams.max_cost = filters.value.max_cost;
    if (filters.value.stone_shape) filterParams.stone_shape = filters.value.stone_shape;
    if (filters.value.min_stone_weight) filterParams.min_stone_weight = filters.value.min_stone_weight;
    if (filters.value.max_stone_weight) filterParams.max_stone_weight = filters.value.max_stone_weight;
    if (filters.value.ring_size) filterParams.ring_size = filters.value.ring_size;

    updateFilter(filterParams);
}

function clearAllFilters() {
    filters.value = {
        category_level2_id: '',
        category_level3_id: '',
        brand_id: '',
        status: '',
        stock: '',
        type: '',
        marketplace_id: '',
        tag_ids: [],
        from_date: '',
        to_date: '',
        min_price: '',
        max_price: '',
        min_cost: '',
        max_cost: '',
        stone_shape: '',
        min_stone_weight: '',
        max_stone_weight: '',
        ring_size: '',
    };
}

function toggleTag(tagId: string) {
    const index = filters.value.tag_ids.indexOf(tagId);
    if (index === -1) {
        filters.value.tag_ids.push(tagId);
    } else {
        filters.value.tag_ids.splice(index, 1);
    }
}

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
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                        @click="showAdvancedSearch = true"
                    >
                        <MagnifyingGlassIcon class="-ml-0.5 size-5" aria-hidden="true" />
                        Advanced Search
                    </button>
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

            <!-- Primary Filters Row -->
            <div class="flex flex-wrap items-center gap-3">
                <!-- Date Range -->
                <div class="flex items-center gap-2">
                    <input
                        v-model="filters.from_date"
                        type="date"
                        placeholder="From"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                    <span class="text-gray-500">to</span>
                    <input
                        v-model="filters.to_date"
                        type="date"
                        placeholder="To"
                        class="rounded-md border-0 bg-white py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <!-- Brand -->
                <select
                    v-model="filters.brand_id"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Brands</option>
                    <option v-for="brand in brands" :key="brand.id" :value="brand.id">
                        {{ brand.name }}
                    </option>
                </select>

                <!-- Category Level 2 -->
                <select
                    v-model="filters.category_level2_id"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Categories</option>
                    <option v-for="category in level2Categories" :key="category.id" :value="category.id">
                        {{ category.name }}
                    </option>
                </select>

                <!-- Category Level 3 (Product Type) - only shows when Level 2 is selected -->
                <select
                    v-if="filters.category_level2_id && availableLevel3Categories.length > 0"
                    v-model="filters.category_level3_id"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Types</option>
                    <option v-for="category in availableLevel3Categories" :key="category.id" :value="category.id">
                        {{ category.name }}
                    </option>
                </select>

                <!-- Type -->
                <select
                    v-if="types.length > 0"
                    v-model="filters.type"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Types</option>
                    <option v-for="type in types" :key="type.value" :value="type.value">
                        {{ type.label }}
                    </option>
                </select>

                <!-- Status -->
                <select
                    v-model="filters.status"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="archive">Archive</option>
                    <option value="sold">Sold</option>
                    <option value="in_memo">In Memo</option>
                    <option value="in_repair">In Repair</option>
                </select>

                <!-- Stock -->
                <select
                    v-model="filters.stock"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Stock</option>
                    <option value="in_stock">In Stock</option>
                    <option value="out_of_stock">Out of Stock</option>
                </select>

                <!-- Toggle Advanced Filters -->
                <button
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-md px-3 py-1.5 text-sm font-medium ring-1 ring-inset"
                    :class="showAdvancedFilters
                        ? 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-300 dark:ring-indigo-700'
                        : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-600'"
                    @click="showAdvancedFilters = !showAdvancedFilters"
                >
                    <FunnelIcon class="-ml-0.5 size-4" aria-hidden="true" />
                    More Filters
                    <span v-if="activeFilterCount > 0" class="ml-1 rounded-full bg-indigo-600 px-2 py-0.5 text-xs text-white">
                        {{ activeFilterCount }}
                    </span>
                </button>

                <!-- Clear Filters -->
                <button
                    v-if="activeFilterCount > 0"
                    type="button"
                    class="inline-flex items-center gap-x-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    @click="clearAllFilters"
                >
                    <XMarkIcon class="size-4" aria-hidden="true" />
                    Clear all
                </button>
            </div>

            <!-- Advanced Filters Panel -->
            <div v-if="showAdvancedFilters" class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <!-- Listed In (Marketplace) -->
                    <div v-if="marketplaces.length > 0">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Listed In</label>
                        <select
                            v-model="filters.marketplace_id"
                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        >
                            <option value="">All Marketplaces</option>
                            <option v-for="marketplace in marketplaces" :key="marketplace.id" :value="marketplace.id">
                                {{ marketplace.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Tags -->
                    <div v-if="tags.length > 0" class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tags</label>
                        <div class="mt-1 flex flex-wrap gap-2">
                            <button
                                v-for="tag in tags"
                                :key="tag.id"
                                type="button"
                                class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium transition-colors"
                                :class="filters.tag_ids.includes(String(tag.id))
                                    ? 'bg-indigo-600 text-white'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'"
                                @click="toggleTag(String(tag.id))"
                            >
                                <span
                                    v-if="tag.color"
                                    class="mr-1.5 size-2 rounded-full"
                                    :style="{ backgroundColor: tag.color }"
                                />
                                {{ tag.name }}
                            </button>
                        </div>
                    </div>

                    <!-- Price Range -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Price Range</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                v-model="filters.min_price"
                                type="number"
                                placeholder="Min"
                                min="0"
                                step="0.01"
                                class="block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                            <span class="text-gray-500">-</span>
                            <input
                                v-model="filters.max_price"
                                type="number"
                                placeholder="Max"
                                min="0"
                                step="0.01"
                                class="block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                    </div>

                    <!-- Cost Range -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cost Range</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                v-model="filters.min_cost"
                                type="number"
                                placeholder="Min"
                                min="0"
                                step="0.01"
                                class="block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                            <span class="text-gray-500">-</span>
                            <input
                                v-model="filters.max_cost"
                                type="number"
                                placeholder="Max"
                                min="0"
                                step="0.01"
                                class="block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                    </div>

                    <!-- Stone Shape -->
                    <div v-if="stoneShapes.length > 0">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stone Shape</label>
                        <select
                            v-model="filters.stone_shape"
                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        >
                            <option value="">All Shapes</option>
                            <option v-for="shape in stoneShapes" :key="shape.value" :value="shape.value">
                                {{ shape.label }}
                            </option>
                        </select>
                    </div>

                    <!-- Stone Weight -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stone Weight (ct)</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                v-model="filters.min_stone_weight"
                                type="number"
                                placeholder="Min"
                                min="0"
                                step="0.01"
                                class="block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                            <span class="text-gray-500">-</span>
                            <input
                                v-model="filters.max_stone_weight"
                                type="number"
                                placeholder="Max"
                                min="0"
                                step="0.01"
                                class="block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                    </div>

                    <!-- Ring Size -->
                    <div v-if="ringSizes.length > 0">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Ring Size</label>
                        <select
                            v-model="filters.ring_size"
                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        >
                            <option value="">All Sizes</option>
                            <option v-for="size in ringSizes" :key="size.value" :value="size.value">
                                {{ size.label }}
                            </option>
                        </select>
                    </div>
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

        <!-- Advanced Search Modal -->
        <AdvancedSearchModal v-model:open="showAdvancedSearch" />
    </AppLayout>
</template>
