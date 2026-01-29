<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    CheckCircleIcon,
    ExclamationCircleIcon,
} from '@heroicons/vue/20/solid';

interface ProductVariant {
    id: number;
    sku: string;
    title: string;
}

interface PurchaseOrderItem {
    id: number;
    product_variant: ProductVariant | null;
    vendor_sku: string | null;
    quantity_ordered: number;
    quantity_received: number;
    remaining_quantity: number;
    unit_cost: number;
    is_fully_received: boolean;
}

interface Vendor {
    id: number;
    name: string;
    code: string | null;
}

interface Warehouse {
    id: number;
    name: string;
    code: string | null;
}

interface PurchaseOrder {
    id: number;
    po_number: string;
    status: string;
    vendor: Vendor;
    warehouse: Warehouse;
    items: PurchaseOrderItem[];
}

interface Props {
    purchaseOrder: PurchaseOrder;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchase Orders', href: '/purchase-orders' },
    { title: props.purchaseOrder.po_number, href: `/purchase-orders/${props.purchaseOrder.id}` },
    { title: 'Receive Items', href: `/purchase-orders/${props.purchaseOrder.id}/receive` },
];

// Track receiving quantities for each item
interface ReceiveItem {
    purchase_order_item_id: number;
    quantity_to_receive: number;
    unit_cost: number;
    notes: string;
}

const receiveItems = ref<ReceiveItem[]>(
    props.purchaseOrder.items
        .filter(item => !item.is_fully_received)
        .map(item => ({
            purchase_order_item_id: item.id,
            quantity_to_receive: item.remaining_quantity,
            unit_cost: item.unit_cost,
            notes: '',
        }))
);

const receiptNotes = ref('');
const isSubmitting = ref(false);

// Find original item by id
function getOriginalItem(itemId: number): PurchaseOrderItem | undefined {
    return props.purchaseOrder.items.find(i => i.id === itemId);
}

// Calculate totals
const totalItemsToReceive = computed(() => {
    return receiveItems.value.reduce((sum, item) => sum + (item.quantity_to_receive || 0), 0);
});

const totalValue = computed(() => {
    return receiveItems.value.reduce((sum, item) => {
        return sum + (item.quantity_to_receive || 0) * (item.unit_cost || 0);
    }, 0);
});

const canSubmit = computed(() => {
    return totalItemsToReceive.value > 0 && !isSubmitting.value;
});

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function receiveAll(itemIndex: number) {
    const receiveItem = receiveItems.value[itemIndex];
    const originalItem = getOriginalItem(receiveItem.purchase_order_item_id);
    if (originalItem) {
        receiveItem.quantity_to_receive = originalItem.remaining_quantity;
    }
}

function clearItem(itemIndex: number) {
    receiveItems.value[itemIndex].quantity_to_receive = 0;
}

function submitReceive() {
    if (!canSubmit.value) return;

    isSubmitting.value = true;

    const itemsToSubmit = receiveItems.value
        .filter(item => item.quantity_to_receive > 0)
        .map(item => ({
            purchase_order_item_id: item.purchase_order_item_id,
            quantity_received: item.quantity_to_receive,
            unit_cost: item.unit_cost,
            notes: item.notes || null,
        }));

    router.post(`/purchase-orders/${props.purchaseOrder.id}/receive`, {
        notes: receiptNotes.value || null,
        items: itemsToSubmit,
    }, {
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`Receive - ${purchaseOrder.po_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                        Receive Items - {{ purchaseOrder.po_number }}
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ purchaseOrder.vendor.name }} <span class="mx-2">&bull;</span>
                        Destination: {{ purchaseOrder.warehouse.name }}
                    </p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Items to Receive -->
                <div class="lg:col-span-2">
                    <div class="bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Items to Receive</h3>
                        </div>

                        <div v-if="receiveItems.length === 0" class="px-4 py-12 text-center">
                            <CheckCircleIcon class="mx-auto size-12 text-green-400" />
                            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                                All items have been fully received.
                            </p>
                        </div>

                        <div v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                            <div
                                v-for="(receiveItem, index) in receiveItems"
                                :key="receiveItem.purchase_order_item_id"
                                class="px-4 py-4 sm:px-6"
                            >
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                                    <!-- Product Info -->
                                    <div class="flex-1 min-w-0">
                                        <p class="font-medium text-gray-900 dark:text-white">
                                            {{ getOriginalItem(receiveItem.purchase_order_item_id)?.product_variant?.title || 'Unknown Product' }}
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            SKU: {{ getOriginalItem(receiveItem.purchase_order_item_id)?.product_variant?.sku }}
                                            <template v-if="getOriginalItem(receiveItem.purchase_order_item_id)?.vendor_sku">
                                                &bull; Vendor SKU: {{ getOriginalItem(receiveItem.purchase_order_item_id)?.vendor_sku }}
                                            </template>
                                        </p>
                                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            Ordered: {{ getOriginalItem(receiveItem.purchase_order_item_id)?.quantity_ordered }}
                                            &bull; Previously Received: {{ getOriginalItem(receiveItem.purchase_order_item_id)?.quantity_received }}
                                            &bull; <span class="font-medium text-indigo-600 dark:text-indigo-400">Remaining: {{ getOriginalItem(receiveItem.purchase_order_item_id)?.remaining_quantity }}</span>
                                        </div>
                                    </div>

                                    <!-- Receive Inputs -->
                                    <div class="flex flex-wrap items-end gap-3">
                                        <!-- Quantity -->
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
                                                Quantity
                                            </label>
                                            <input
                                                v-model.number="receiveItem.quantity_to_receive"
                                                type="number"
                                                min="0"
                                                :max="getOriginalItem(receiveItem.purchase_order_item_id)?.remaining_quantity"
                                                class="block w-20 rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>

                                        <!-- Unit Cost -->
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
                                                Unit Cost
                                            </label>
                                            <div class="relative">
                                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 dark:text-gray-400 text-sm">$</span>
                                                <input
                                                    v-model.number="receiveItem.unit_cost"
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    class="block w-24 rounded-md border-0 py-1.5 pl-7 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>

                                        <!-- Quick Actions -->
                                        <div class="flex gap-1">
                                            <button
                                                type="button"
                                                class="rounded px-2 py-1.5 text-xs font-medium text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-gray-700"
                                                @click="receiveAll(index)"
                                            >
                                                All
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded px-2 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700"
                                                @click="clearItem(index)"
                                            >
                                                Clear
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Notes -->
                                <div class="mt-3">
                                    <input
                                        v-model="receiveItem.notes"
                                        type="text"
                                        placeholder="Item notes (optional)"
                                        class="block w-full rounded-md border-0 py-1.5 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>

                                <!-- Warning if receiving more than remaining -->
                                <div
                                    v-if="receiveItem.quantity_to_receive > (getOriginalItem(receiveItem.purchase_order_item_id)?.remaining_quantity || 0)"
                                    class="mt-2 flex items-center gap-2 text-amber-600 dark:text-amber-400"
                                >
                                    <ExclamationCircleIcon class="size-4" />
                                    <span class="text-xs">Quantity exceeds remaining. Will be capped at {{ getOriginalItem(receiveItem.purchase_order_item_id)?.remaining_quantity }}.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Sidebar -->
                <div class="space-y-6">
                    <div class="bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10 sticky top-4">
                        <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Receipt Summary</h3>
                        </div>
                        <div class="px-4 py-4 sm:px-6 space-y-4">
                            <!-- Receipt Notes -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Receipt Notes
                                </label>
                                <textarea
                                    v-model="receiptNotes"
                                    rows="2"
                                    placeholder="General notes for this receipt..."
                                    class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>

                            <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-2">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Items to Receive</span>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ totalItemsToReceive }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Total Value</span>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(totalValue) }}</span>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-4 space-y-2">
                                <button
                                    type="button"
                                    :disabled="!canSubmit"
                                    class="w-full inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                    @click="submitReceive"
                                >
                                    {{ isSubmitting ? 'Processing...' : 'Receive Items' }}
                                </button>
                                <button
                                    type="button"
                                    class="w-full inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="router.get(`/purchase-orders/${purchaseOrder.id}`)"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
