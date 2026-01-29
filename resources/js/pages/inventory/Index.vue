<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MagnifyingGlassIcon,
    AdjustmentsHorizontalIcon,
    ExclamationTriangleIcon,
    PlusIcon,
    MinusIcon,
} from '@heroicons/vue/20/solid';
import { ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';

interface Warehouse {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
}

interface InventoryItem {
    id: number;
    product_title: string;
    product_id: number;
    variant_id: number;
    variant_title: string | null;
    sku: string;
    warehouse_id: number;
    warehouse_name: string;
    quantity: number;
    reserved_quantity: number;
    available_quantity: number;
    incoming_quantity: number;
    reorder_point: number | null;
    bin_location: string | null;
    unit_cost: number | null;
    needs_reorder: boolean;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    inventory: PaginatedData<InventoryItem>;
    warehouses: Warehouse[];
    selectedWarehouseId: number | null;
    stats: {
        total_skus: number;
        total_units: number;
        low_stock_count: number;
        total_value: number;
    };
    filters: {
        search: string;
        low_stock: boolean;
    };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
];

const search = ref(props.filters.search);
const lowStockOnly = ref(props.filters.low_stock);
const selectedWarehouse = ref(props.selectedWarehouseId);

// Adjustment modal
const adjustmentModal = ref(false);
const adjustmentItem = ref<InventoryItem | null>(null);
const adjustmentType = ref<'add' | 'remove'>('add');
const adjustmentQuantity = ref(0);
const adjustmentReason = ref('');
const adjustmentProcessing = ref(false);

const debouncedSearch = useDebounceFn(() => {
    applyFilters();
}, 300);

watch(search, () => {
    debouncedSearch();
});

watch([selectedWarehouse, lowStockOnly], () => {
    applyFilters();
});

function applyFilters() {
    router.get('/inventory', {
        warehouse_id: selectedWarehouse.value,
        search: search.value || undefined,
        low_stock: lowStockOnly.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function openAdjustment(item: InventoryItem, type: 'add' | 'remove') {
    adjustmentItem.value = item;
    adjustmentType.value = type;
    adjustmentQuantity.value = 0;
    adjustmentReason.value = '';
    adjustmentModal.value = true;
}

function submitAdjustment() {
    if (!adjustmentItem.value || adjustmentQuantity.value <= 0) return;

    adjustmentProcessing.value = true;

    const adjustment = adjustmentType.value === 'add'
        ? adjustmentQuantity.value
        : -adjustmentQuantity.value;

    router.post('/inventory/adjust', {
        inventory_id: adjustmentItem.value.id,
        adjustment: adjustment,
        type: adjustmentType.value === 'add' ? 'receipt' : 'adjustment',
        reason: adjustmentReason.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            adjustmentModal.value = false;
            adjustmentItem.value = null;
            adjustmentProcessing.value = false;
        },
        onError: () => {
            adjustmentProcessing.value = false;
        },
    });
}

function formatCurrency(value: number | null) {
    if (value === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
}
</script>

<template>
    <Head title="Inventory" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Inventory</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Track stock levels across all your warehouses
                </p>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 mb-6 sm:grid-cols-4">
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total SKUs</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.total_skus.toLocaleString() }}</p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Units</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.total_units.toLocaleString() }}</p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Low Stock</p>
                    <p class="mt-1 text-2xl font-semibold" :class="stats.low_stock_count > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white'">
                        {{ stats.low_stock_count }}
                    </p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Value</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ formatCurrency(stats.total_value) }}</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="mb-4 flex flex-wrap items-center gap-4">
                <!-- Warehouse selector -->
                <div class="flex-1 sm:flex-none sm:w-48">
                    <select
                        v-model="selectedWarehouse"
                        class="block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option :value="null">All Warehouses</option>
                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                            {{ warehouse.name }}
                            <span v-if="warehouse.is_default"> (Default)</span>
                        </option>
                    </select>
                </div>

                <!-- Search -->
                <div class="relative flex-1 sm:max-w-xs">
                    <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by SKU or product..."
                        class="block w-full rounded-md border-0 bg-white py-1.5 pl-10 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <!-- Low stock filter -->
                <label class="flex items-center gap-2">
                    <input
                        v-model="lowStockOnly"
                        type="checkbox"
                        class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                    />
                    <span class="text-sm text-gray-700 dark:text-gray-300">Low stock only</span>
                </label>
            </div>

            <!-- Table -->
            <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="inventory.data.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Product / SKU
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Warehouse
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    On Hand
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Available
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Reserved
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Bin
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Unit Cost
                                </th>
                                <th scope="col" class="relative px-4 py-3">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="item in inventory.data" :key="item.id" :class="item.needs_reorder ? 'bg-amber-50 dark:bg-amber-900/10' : ''">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <ExclamationTriangleIcon
                                            v-if="item.needs_reorder"
                                            class="size-5 text-amber-500"
                                            title="Low stock"
                                        />
                                        <div>
                                            <Link
                                                :href="`/products/${item.product_id}`"
                                                class="text-sm font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                            >
                                                {{ item.product_title }}
                                            </Link>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ item.sku }}
                                                <span v-if="item.variant_title"> - {{ item.variant_title }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ item.warehouse_name }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                                    {{ item.quantity }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                    {{ item.available_quantity }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                    {{ item.reserved_quantity }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ item.bin_location || '-' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                    {{ formatCurrency(item.unit_cost) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            type="button"
                                            class="rounded p-1 text-green-600 hover:bg-green-50 dark:text-green-400 dark:hover:bg-green-900/20"
                                            title="Add stock"
                                            @click="openAdjustment(item, 'add')"
                                        >
                                            <PlusIcon class="size-5" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded p-1 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"
                                            title="Remove stock"
                                            @click="openAdjustment(item, 'remove')"
                                        >
                                            <MinusIcon class="size-5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Empty state -->
                    <div v-else class="px-6 py-12 text-center">
                        <AdjustmentsHorizontalIcon class="mx-auto h-12 w-12 text-gray-400" />
                        <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No inventory</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ filters.search || filters.low_stock ? 'No items match your filters.' : 'Create products and add stock to see inventory here.' }}
                        </p>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="inventory.last_page > 1" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <div class="text-sm text-gray-700 dark:text-gray-300">
                        Showing {{ ((inventory.current_page - 1) * inventory.per_page) + 1 }} to {{ Math.min(inventory.current_page * inventory.per_page, inventory.total) }} of {{ inventory.total }} results
                    </div>
                    <div class="flex gap-1">
                        <template v-for="link in inventory.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="rounded-md px-3 py-1 text-sm"
                                :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="rounded-md px-3 py-1 text-sm text-gray-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Adjustment Modal -->
        <Teleport to="body">
            <div v-if="adjustmentModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:p-6 dark:bg-gray-800">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full" :class="adjustmentType === 'add' ? 'bg-green-100 dark:bg-green-900' : 'bg-red-100 dark:bg-red-900'">
                                    <PlusIcon v-if="adjustmentType === 'add'" class="h-6 w-6 text-green-600 dark:text-green-400" />
                                    <MinusIcon v-else class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        {{ adjustmentType === 'add' ? 'Add Stock' : 'Remove Stock' }}
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ adjustmentItem?.product_title }}
                                            <span v-if="adjustmentItem?.variant_title"> - {{ adjustmentItem?.variant_title }}</span>
                                        </p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">
                                            SKU: {{ adjustmentItem?.sku }} | Current: {{ adjustmentItem?.quantity }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 space-y-4">
                                <div>
                                    <label for="quantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Quantity
                                    </label>
                                    <input
                                        id="quantity"
                                        v-model.number="adjustmentQuantity"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Reason (optional)
                                    </label>
                                    <input
                                        id="reason"
                                        v-model="adjustmentReason"
                                        type="text"
                                        placeholder="e.g., Received shipment, Damaged goods"
                                        class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:flex sm:flex-row-reverse gap-3">
                                <button
                                    type="button"
                                    :disabled="adjustmentProcessing || adjustmentQuantity <= 0"
                                    class="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm sm:w-auto disabled:opacity-50"
                                    :class="adjustmentType === 'add' ? 'bg-green-600 hover:bg-green-500' : 'bg-red-600 hover:bg-red-500'"
                                    @click="submitAdjustment"
                                >
                                    {{ adjustmentProcessing ? 'Saving...' : (adjustmentType === 'add' ? 'Add Stock' : 'Remove Stock') }}
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="adjustmentModal = false"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
