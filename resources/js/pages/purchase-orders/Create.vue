<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import {
    PlusIcon,
    TrashIcon,
    MagnifyingGlassIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';
import { useDebounceFn } from '@vueuse/core';

interface Vendor {
    id: number;
    name: string;
    code: string | null;
    payment_terms: string | null;
    lead_time_days: number | null;
}

interface Warehouse {
    id: number;
    name: string;
    code: string | null;
    is_default: boolean;
}

interface ProductVariant {
    id: number;
    sku: string;
    product: {
        id: number;
        title: string;
    };
}

interface LineItem {
    id: string; // temporary client-side ID
    product_variant_id: number | null;
    product_variant: ProductVariant | null;
    vendor_sku: string;
    description: string;
    quantity_ordered: number;
    unit_cost: number;
    discount_percent: number;
    tax_rate: number;
    notes: string;
}

interface Props {
    vendors: Vendor[];
    warehouses: Warehouse[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchase Orders', href: '/purchase-orders' },
    { title: 'Create', href: '/purchase-orders/create' },
];

// Form
const form = useForm({
    vendor_id: '',
    warehouse_id: '',
    order_date: new Date().toISOString().split('T')[0],
    expected_date: '',
    shipping_method: '',
    vendor_notes: '',
    internal_notes: '',
    tax_amount: 0,
    shipping_cost: 0,
    discount_amount: 0,
    items: [] as Array<{
        product_variant_id: number;
        vendor_sku: string;
        description: string;
        quantity_ordered: number;
        unit_cost: number;
        discount_percent: number;
        tax_rate: number;
        notes: string;
    }>,
});

// Line items (for UI management)
const lineItems = ref<LineItem[]>([]);

// Product search
const showProductSearch = ref(false);
const productSearchQuery = ref('');
const productSearchResults = ref<ProductVariant[]>([]);
const isSearching = ref(false);

// Set default warehouse
const defaultWarehouse = props.warehouses.find(w => w.is_default);
if (defaultWarehouse) {
    form.warehouse_id = String(defaultWarehouse.id);
}

// Selected vendor
const selectedVendor = computed(() => {
    return props.vendors.find(v => v.id === Number(form.vendor_id));
});

// When vendor changes, update expected date based on lead time
watch(() => form.vendor_id, () => {
    if (selectedVendor.value?.lead_time_days) {
        const date = new Date();
        date.setDate(date.getDate() + selectedVendor.value.lead_time_days);
        form.expected_date = date.toISOString().split('T')[0];
    }
});

// Calculate totals
const subtotal = computed(() => {
    return lineItems.value.reduce((sum, item) => {
        const itemSubtotal = item.quantity_ordered * item.unit_cost;
        const discount = itemSubtotal * (item.discount_percent / 100);
        const afterDiscount = itemSubtotal - discount;
        const tax = afterDiscount * (item.tax_rate / 100);
        return sum + afterDiscount + tax;
    }, 0);
});

const total = computed(() => {
    return subtotal.value + form.tax_amount + form.shipping_cost - form.discount_amount;
});

// Product search
const debouncedSearch = useDebounceFn(async () => {
    if (!productSearchQuery.value || productSearchQuery.value.length < 2) {
        productSearchResults.value = [];
        return;
    }

    isSearching.value = true;
    try {
        const response = await fetch(`/api/v1/products?search=${encodeURIComponent(productSearchQuery.value)}&per_page=10`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json();
        // Flatten products to variants
        const variants: ProductVariant[] = [];
        for (const product of data.data || []) {
            for (const variant of product.variants || []) {
                variants.push({
                    id: variant.id,
                    sku: variant.sku,
                    product: {
                        id: product.id,
                        title: product.title,
                    },
                });
            }
            // If no variants, create one from product
            if (!product.variants || product.variants.length === 0) {
                if (product.default_variant) {
                    variants.push({
                        id: product.default_variant.id,
                        sku: product.default_variant.sku || product.handle,
                        product: {
                            id: product.id,
                            title: product.title,
                        },
                    });
                }
            }
        }
        productSearchResults.value = variants;
    } catch (error) {
        console.error('Error searching products:', error);
        productSearchResults.value = [];
    } finally {
        isSearching.value = false;
    }
}, 300);

watch(productSearchQuery, () => {
    debouncedSearch();
});

function openProductSearch() {
    productSearchQuery.value = '';
    productSearchResults.value = [];
    showProductSearch.value = true;
}

function addProduct(variant: ProductVariant) {
    // Check if already added
    if (lineItems.value.some(item => item.product_variant_id === variant.id)) {
        // Focus on existing item's quantity
        return;
    }

    lineItems.value.push({
        id: `item-${Date.now()}`,
        product_variant_id: variant.id,
        product_variant: variant,
        vendor_sku: '',
        description: '',
        quantity_ordered: 1,
        unit_cost: 0,
        discount_percent: 0,
        tax_rate: 0,
        notes: '',
    });

    showProductSearch.value = false;
}

function removeItem(index: number) {
    lineItems.value.splice(index, 1);
}

function formatCurrency(value: number) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function getItemTotal(item: LineItem) {
    const itemSubtotal = item.quantity_ordered * item.unit_cost;
    const discount = itemSubtotal * (item.discount_percent / 100);
    const afterDiscount = itemSubtotal - discount;
    const tax = afterDiscount * (item.tax_rate / 100);
    return afterDiscount + tax;
}

function submitForm() {
    if (lineItems.value.length === 0) {
        alert('Please add at least one item to the purchase order.');
        return;
    }

    // Prepare items for submission
    form.items = lineItems.value.map(item => ({
        product_variant_id: item.product_variant_id!,
        vendor_sku: item.vendor_sku,
        description: item.description,
        quantity_ordered: item.quantity_ordered,
        unit_cost: item.unit_cost,
        discount_percent: item.discount_percent,
        tax_rate: item.tax_rate,
        notes: item.notes,
    }));

    form.post('/purchase-orders');
}
</script>

<template>
    <Head title="Create Purchase Order" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Create Purchase Order</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Order products from your vendors
                    </p>
                </div>
            </div>

            <form @submit.prevent="submitForm" class="space-y-6">
                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Main Form -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Basic Info -->
                        <div class="bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Order Details</h3>
                            </div>
                            <div class="px-4 py-4 sm:px-6 grid gap-4 sm:grid-cols-2">
                                <!-- Vendor -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Vendor *
                                    </label>
                                    <select
                                        v-model="form.vendor_id"
                                        required
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option value="">Select a vendor...</option>
                                        <option v-for="vendor in vendors" :key="vendor.id" :value="String(vendor.id)">
                                            {{ vendor.name }}
                                            <template v-if="vendor.code"> ({{ vendor.code }})</template>
                                        </option>
                                    </select>
                                    <p v-if="form.errors.vendor_id" class="mt-1 text-sm text-red-600">{{ form.errors.vendor_id }}</p>
                                </div>

                                <!-- Warehouse -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Destination Warehouse *
                                    </label>
                                    <select
                                        v-model="form.warehouse_id"
                                        required
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option value="">Select a warehouse...</option>
                                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="String(warehouse.id)">
                                            {{ warehouse.name }}
                                            <template v-if="warehouse.is_default"> (Default)</template>
                                        </option>
                                    </select>
                                    <p v-if="form.errors.warehouse_id" class="mt-1 text-sm text-red-600">{{ form.errors.warehouse_id }}</p>
                                </div>

                                <!-- Order Date -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Order Date
                                    </label>
                                    <input
                                        v-model="form.order_date"
                                        type="date"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>

                                <!-- Expected Date -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Expected Delivery
                                    </label>
                                    <input
                                        v-model="form.expected_date"
                                        type="date"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>

                                <!-- Shipping Method -->
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Shipping Method
                                    </label>
                                    <input
                                        v-model="form.shipping_method"
                                        type="text"
                                        placeholder="e.g., Ground, Express, Freight"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Line Items -->
                        <div class="bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Items</h3>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    @click="openProductSearch"
                                >
                                    <PlusIcon class="-ml-0.5 size-4" />
                                    Add Product
                                </button>
                            </div>

                            <div v-if="lineItems.length === 0" class="px-4 py-12 text-center">
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    No items added yet. Click "Add Product" to search and add products.
                                </p>
                            </div>

                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                                Product
                                            </th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                                Qty
                                            </th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                                Unit Cost
                                            </th>
                                            <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">
                                                Total
                                            </th>
                                            <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                                <span class="sr-only">Remove</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="(item, index) in lineItems" :key="item.id">
                                            <td class="py-4 pl-4 pr-3 sm:pl-6">
                                                <div class="min-w-0">
                                                    <div class="font-medium text-gray-900 dark:text-white">
                                                        {{ item.product_variant?.product.title }}
                                                    </div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                                        SKU: {{ item.product_variant?.sku }}
                                                    </div>
                                                    <div class="mt-1">
                                                        <input
                                                            v-model="item.vendor_sku"
                                                            type="text"
                                                            placeholder="Vendor SKU (optional)"
                                                            class="block w-full max-w-xs rounded-md border-0 py-1 text-xs text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4">
                                                <input
                                                    v-model.number="item.quantity_ordered"
                                                    type="number"
                                                    min="1"
                                                    required
                                                    class="block w-20 rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4">
                                                <div class="relative">
                                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 dark:text-gray-400 text-sm">$</span>
                                                    <input
                                                        v-model.number="item.unit_cost"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        required
                                                        class="block w-28 rounded-md border-0 py-1.5 pl-7 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-right text-sm font-medium text-gray-900 dark:text-white">
                                                {{ formatCurrency(getItemTotal(item)) }}
                                            </td>
                                            <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right sm:pr-6">
                                                <button
                                                    type="button"
                                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                                    @click="removeItem(index)"
                                                >
                                                    <TrashIcon class="size-5" />
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Notes</h3>
                            </div>
                            <div class="px-4 py-4 sm:px-6 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Vendor Notes
                                    </label>
                                    <textarea
                                        v-model="form.vendor_notes"
                                        rows="3"
                                        placeholder="Notes visible to vendor..."
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Internal Notes
                                    </label>
                                    <textarea
                                        v-model="form.internal_notes"
                                        rows="3"
                                        placeholder="Internal notes..."
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar - Totals -->
                    <div class="space-y-6">
                        <div class="bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10 sticky top-4">
                            <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Order Summary</h3>
                            </div>
                            <div class="px-4 py-4 sm:px-6 space-y-4">
                                <!-- Items subtotal -->
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Subtotal ({{ lineItems.length }} items)</span>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(subtotal) }}</span>
                                </div>

                                <!-- Tax -->
                                <div class="flex items-center justify-between">
                                    <label class="text-sm text-gray-600 dark:text-gray-400">Tax</label>
                                    <div class="relative w-28">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 dark:text-gray-400 text-sm">$</span>
                                        <input
                                            v-model.number="form.tax_amount"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="block w-full rounded-md border-0 py-1.5 pl-7 text-right text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <!-- Shipping -->
                                <div class="flex items-center justify-between">
                                    <label class="text-sm text-gray-600 dark:text-gray-400">Shipping</label>
                                    <div class="relative w-28">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 dark:text-gray-400 text-sm">$</span>
                                        <input
                                            v-model.number="form.shipping_cost"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="block w-full rounded-md border-0 py-1.5 pl-7 text-right text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <!-- Discount -->
                                <div class="flex items-center justify-between">
                                    <label class="text-sm text-gray-600 dark:text-gray-400">Discount</label>
                                    <div class="relative w-28">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 dark:text-gray-400 text-sm">$</span>
                                        <input
                                            v-model.number="form.discount_amount"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="block w-full rounded-md border-0 py-1.5 pl-7 text-right text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                    <div class="flex justify-between">
                                        <span class="text-base font-semibold text-gray-900 dark:text-white">Total</span>
                                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ formatCurrency(total) }}</span>
                                    </div>
                                </div>

                                <!-- Submit Buttons -->
                                <div class="pt-4 space-y-2">
                                    <button
                                        type="submit"
                                        :disabled="form.processing || lineItems.length === 0"
                                        class="w-full inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {{ form.processing ? 'Creating...' : 'Create Purchase Order' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="w-full inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="router.get('/purchase-orders')"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Product Search Modal -->
        <Teleport to="body">
            <div v-if="showProductSearch" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showProductSearch = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-start justify-center p-4 pt-16 sm:p-6 sm:pt-24">
                        <div class="relative w-full max-w-xl transform overflow-hidden rounded-lg bg-white shadow-xl transition-all dark:bg-gray-800">
                            <!-- Search Input -->
                            <div class="relative">
                                <MagnifyingGlassIcon class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                <input
                                    v-model="productSearchQuery"
                                    type="text"
                                    placeholder="Search products by name or SKU..."
                                    class="block w-full border-0 py-4 pl-11 pr-4 text-gray-900 placeholder:text-gray-400 focus:ring-0 sm:text-sm dark:bg-gray-800 dark:text-white"
                                    autofocus
                                />
                                <button
                                    type="button"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-500"
                                    @click="showProductSearch = false"
                                >
                                    <XMarkIcon class="size-5" />
                                </button>
                            </div>

                            <!-- Results -->
                            <div class="max-h-80 overflow-y-auto border-t border-gray-200 dark:border-gray-700">
                                <div v-if="isSearching" class="px-4 py-8 text-center">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Searching...</p>
                                </div>
                                <div v-else-if="productSearchQuery.length < 2" class="px-4 py-8 text-center">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Type at least 2 characters to search</p>
                                </div>
                                <div v-else-if="productSearchResults.length === 0" class="px-4 py-8 text-center">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No products found</p>
                                </div>
                                <ul v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <li
                                        v-for="variant in productSearchResults"
                                        :key="variant.id"
                                        class="cursor-pointer px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700"
                                        @click="addProduct(variant)"
                                    >
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="font-medium text-gray-900 dark:text-white">
                                                    {{ variant.product.title }}
                                                </p>
                                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                                    SKU: {{ variant.sku }}
                                                </p>
                                            </div>
                                            <PlusIcon class="size-5 text-indigo-600 dark:text-indigo-400" />
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
