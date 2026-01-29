<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    ArrowLeftIcon,
    MagnifyingGlassIcon,
    CubeIcon,
    CheckIcon,
    MinusIcon,
    PlusIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

interface Customer {
    id: number;
    full_name: string;
    email?: string;
}

interface Product {
    id: number;
    title: string;
    image?: string;
}

interface OrderItem {
    id: number;
    product_id?: number;
    product_variant_id?: number;
    sku?: string;
    title: string;
    quantity: number;
    price: number;
    line_total: number;
    product?: Product;
}

interface Order {
    id: number;
    invoice_number?: string;
    status: string;
    total: number;
    created_at: string;
    customer?: Customer;
    items: OrderItem[];
}

interface ReturnPolicy {
    id: number;
    name: string;
    description?: string;
    return_window_days: number;
    restocking_fee_percent: number;
    allow_refund: boolean;
    allow_store_credit: boolean;
    allow_exchange: boolean;
    is_default: boolean;
}

interface Option {
    value: string;
    label: string;
}

interface SelectedItem {
    order_item_id: number;
    product_variant_id?: number;
    title: string;
    sku?: string;
    image?: string;
    max_quantity: number;
    quantity: number;
    unit_price: number;
    condition: string;
    reason: string;
    notes: string;
    restock: boolean;
}

interface Props {
    policies: ReturnPolicy[];
    selectedOrder?: Order;
    types: Option[];
    conditions: Option[];
    reasons: Option[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: 'Returns', href: '/returns' },
    { title: 'New Return', href: '/returns/create' },
];

// State
const isSearching = ref(false);
const searchQuery = ref('');
const searchResults = ref<Order[]>([]);
const selectedOrder = ref<Order | null>(props.selectedOrder || null);
const selectedItems = ref<SelectedItem[]>([]);
const returnType = ref<string>('return');
const selectedPolicyId = ref<number | null>(props.policies.find(p => p.is_default)?.id ?? props.policies[0]?.id ?? null);
const reason = ref('');
const customerNotes = ref('');
const internalNotes = ref('');
const isSubmitting = ref(false);
const errors = ref<Record<string, string>>({});

// Computed
const selectedPolicy = computed(() => {
    return props.policies.find(p => p.id === selectedPolicyId.value);
});

const subtotal = computed(() => {
    return selectedItems.value.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
});

const restockingFee = computed(() => {
    if (!selectedPolicy.value || selectedPolicy.value.restocking_fee_percent <= 0) {
        return 0;
    }
    return subtotal.value * (selectedPolicy.value.restocking_fee_percent / 100);
});

const refundAmount = computed(() => {
    return Math.max(0, subtotal.value - restockingFee.value);
});

const canSubmit = computed(() => {
    return selectedOrder.value && selectedItems.value.length > 0 && !isSubmitting.value;
});

// Methods
async function searchOrders() {
    if (!searchQuery.value.trim()) {
        searchResults.value = [];
        return;
    }

    isSearching.value = true;
    try {
        const response = await fetch(`/returns/search-orders?query=${encodeURIComponent(searchQuery.value)}`);
        const data = await response.json();
        searchResults.value = data.orders;
    } catch (error) {
        console.error('Failed to search orders:', error);
    } finally {
        isSearching.value = false;
    }
}

function selectOrder(order: Order) {
    selectedOrder.value = order;
    selectedItems.value = [];
    searchQuery.value = '';
    searchResults.value = [];
}

function clearOrder() {
    selectedOrder.value = null;
    selectedItems.value = [];
}

function isItemSelected(itemId: number): boolean {
    return selectedItems.value.some(item => item.order_item_id === itemId);
}

function toggleItem(orderItem: OrderItem) {
    const existingIndex = selectedItems.value.findIndex(item => item.order_item_id === orderItem.id);

    if (existingIndex >= 0) {
        selectedItems.value.splice(existingIndex, 1);
    } else {
        selectedItems.value.push({
            order_item_id: orderItem.id,
            product_variant_id: orderItem.product_variant_id,
            title: orderItem.title,
            sku: orderItem.sku,
            image: orderItem.product?.image,
            max_quantity: orderItem.quantity,
            quantity: orderItem.quantity,
            unit_price: orderItem.price,
            condition: '',
            reason: '',
            notes: '',
            restock: true,
        });
    }
}

function updateItemQuantity(index: number, delta: number) {
    const item = selectedItems.value[index];
    const newQuantity = item.quantity + delta;
    if (newQuantity >= 1 && newQuantity <= item.max_quantity) {
        item.quantity = newQuantity;
    }
}

function removeItem(index: number) {
    selectedItems.value.splice(index, 1);
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function submitReturn() {
    if (!canSubmit.value) return;

    isSubmitting.value = true;
    errors.value = {};

    const data = {
        order_id: selectedOrder.value!.id,
        return_policy_id: selectedPolicyId.value,
        type: returnType.value,
        reason: reason.value,
        customer_notes: customerNotes.value,
        internal_notes: internalNotes.value,
        items: selectedItems.value.map(item => ({
            order_item_id: item.order_item_id,
            product_variant_id: item.product_variant_id,
            quantity: item.quantity,
            unit_price: item.unit_price,
            condition: item.condition,
            reason: item.reason,
            notes: item.notes,
            restock: item.restock,
        })),
    };

    router.post('/returns', data, {
        onError: (errs) => {
            errors.value = errs;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

// Debounce search
let searchTimeout: ReturnType<typeof setTimeout>;
watch(searchQuery, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(searchOrders, 300);
});
</script>

<template>
    <Head title="Create Return" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-5xl">
                <!-- Header -->
                <div class="mb-6 flex items-center gap-4">
                    <Link href="/returns" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                        <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Create Return</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Process a return or exchange for an order
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main Form -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Order Selection -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Select Order</h2>

                            <div v-if="!selectedOrder">
                                <!-- Search -->
                                <div class="relative">
                                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search by order number, customer name, or email..."
                                        class="block w-full rounded-md border-0 py-2 pl-10 pr-4 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 sm:text-sm"
                                    />
                                </div>

                                <!-- Search Results -->
                                <div v-if="searchResults.length > 0" class="mt-4 divide-y divide-gray-200 rounded-md border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                                    <button
                                        v-for="order in searchResults"
                                        :key="order.id"
                                        type="button"
                                        @click="selectOrder(order)"
                                        class="flex w-full items-center justify-between p-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700"
                                    >
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-white">
                                                {{ order.invoice_number || `Order #${order.id}` }}
                                            </p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ order.customer?.full_name || 'Walk-in Customer' }}
                                                &bull; {{ formatDate(order.created_at) }}
                                            </p>
                                        </div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(order.total) }}</p>
                                    </button>
                                </div>

                                <div v-else-if="searchQuery && !isSearching" class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No orders found matching "{{ searchQuery }}"
                                </div>

                                <div v-if="isSearching" class="mt-4 flex justify-center">
                                    <div class="size-6 animate-spin rounded-full border-2 border-gray-300 border-t-indigo-600" />
                                </div>
                            </div>

                            <!-- Selected Order -->
                            <div v-else class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">
                                            {{ selectedOrder.invoice_number || `Order #${selectedOrder.id}` }}
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ selectedOrder.customer?.full_name || 'Walk-in Customer' }}
                                            &bull; {{ formatDate(selectedOrder.created_at) }}
                                            &bull; {{ formatCurrency(selectedOrder.total) }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        @click="clearOrder"
                                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                                    >
                                        <XMarkIcon class="size-5" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Item Selection -->
                        <div v-if="selectedOrder" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Select Items to Return</h2>

                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div
                                    v-for="item in selectedOrder.items"
                                    :key="item.id"
                                    class="flex items-center gap-4 py-4"
                                >
                                    <button
                                        type="button"
                                        @click="toggleItem(item)"
                                        :class="[
                                            'flex size-6 shrink-0 items-center justify-center rounded border-2',
                                            isItemSelected(item.id)
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-gray-300 dark:border-gray-600'
                                        ]"
                                    >
                                        <CheckIcon v-if="isItemSelected(item.id)" class="size-4" />
                                    </button>
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                        <img v-if="item.product?.image" :src="item.product.image" class="size-12 rounded object-cover" />
                                        <CubeIcon v-else class="size-6 text-gray-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="item.sku">SKU: {{ item.sku }} &bull; </span>
                                            Qty: {{ item.quantity }} &bull; {{ formatCurrency(item.price) }} each
                                        </p>
                                    </div>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.line_total) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Items Details -->
                        <div v-if="selectedItems.length > 0" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Return Item Details</h2>

                            <div class="space-y-6">
                                <div
                                    v-for="(item, index) in selectedItems"
                                    :key="item.order_item_id"
                                    class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                                >
                                    <div class="mb-4 flex items-start justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="flex size-10 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                                <img v-if="item.image" :src="item.image" class="size-10 rounded object-cover" />
                                                <CubeIcon v-else class="size-5 text-gray-400" />
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                                <p v-if="item.sku" class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.sku }}</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            @click="removeItem(index)"
                                            class="rounded p-1 text-gray-400 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-900/30"
                                        >
                                            <XMarkIcon class="size-5" />
                                        </button>
                                    </div>

                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <!-- Quantity -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Quantity</label>
                                            <div class="mt-1 flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    @click="updateItemQuantity(index, -1)"
                                                    :disabled="item.quantity <= 1"
                                                    class="rounded bg-gray-100 p-1 hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:hover:bg-gray-600"
                                                >
                                                    <MinusIcon class="size-4" />
                                                </button>
                                                <span class="w-8 text-center font-medium text-gray-900 dark:text-white">{{ item.quantity }}</span>
                                                <button
                                                    type="button"
                                                    @click="updateItemQuantity(index, 1)"
                                                    :disabled="item.quantity >= item.max_quantity"
                                                    class="rounded bg-gray-100 p-1 hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:hover:bg-gray-600"
                                                >
                                                    <PlusIcon class="size-4" />
                                                </button>
                                                <span class="text-sm text-gray-500 dark:text-gray-400">of {{ item.max_quantity }}</span>
                                            </div>
                                        </div>

                                        <!-- Condition -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Condition</label>
                                            <select
                                                v-model="item.condition"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                                            >
                                                <option value="">Select condition...</option>
                                                <option v-for="condition in conditions" :key="condition.value" :value="condition.value">
                                                    {{ condition.label }}
                                                </option>
                                            </select>
                                        </div>

                                        <!-- Reason -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason</label>
                                            <select
                                                v-model="item.reason"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                                            >
                                                <option value="">Select reason...</option>
                                                <option v-for="r in reasons" :key="r.value" :value="r.value">
                                                    {{ r.label }}
                                                </option>
                                            </select>
                                        </div>

                                        <!-- Restock -->
                                        <div class="flex items-center">
                                            <label class="flex items-center gap-2">
                                                <input
                                                    v-model="item.restock"
                                                    type="checkbox"
                                                    class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                                />
                                                <span class="text-sm text-gray-700 dark:text-gray-300">Return to inventory</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div class="mt-4">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                        <textarea
                                            v-model="item.notes"
                                            rows="2"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                                            placeholder="Additional notes about this item..."
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Return Details -->
                        <div v-if="selectedOrder" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Return Details</h2>

                            <div class="space-y-4">
                                <!-- Type -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Return Type</label>
                                    <div class="mt-2 flex gap-4">
                                        <label v-for="type in types" :key="type.value" class="flex items-center gap-2">
                                            <input
                                                v-model="returnType"
                                                type="radio"
                                                :value="type.value"
                                                class="size-4 border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                            />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ type.label }}</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Policy -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Return Policy</label>
                                    <select
                                        v-model="selectedPolicyId"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                                    >
                                        <option :value="null">No Policy</option>
                                        <option v-for="policy in policies" :key="policy.id" :value="policy.id">
                                            {{ policy.name }} ({{ policy.restocking_fee_percent }}% restocking fee)
                                        </option>
                                    </select>
                                </div>

                                <!-- Reason -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Overall Reason</label>
                                    <textarea
                                        v-model="reason"
                                        rows="2"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                                        placeholder="General reason for the return..."
                                    />
                                </div>

                                <!-- Customer Notes -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Customer Notes</label>
                                    <textarea
                                        v-model="customerNotes"
                                        rows="2"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                                        placeholder="Notes from the customer..."
                                    />
                                </div>

                                <!-- Internal Notes -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Internal Notes</label>
                                    <textarea
                                        v-model="internalNotes"
                                        rows="2"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                                        placeholder="Internal notes (not visible to customer)..."
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Sidebar -->
                    <div class="lg:col-span-1">
                        <div class="sticky top-4 space-y-6">
                            <!-- Summary Card -->
                            <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                                <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Summary</h2>

                                <div v-if="selectedItems.length === 0" class="text-center text-sm text-gray-500 dark:text-gray-400">
                                    Select items to return
                                </div>

                                <div v-else>
                                    <!-- Items List -->
                                    <div class="mb-4 space-y-2">
                                        <div
                                            v-for="item in selectedItems"
                                            :key="item.order_item_id"
                                            class="flex justify-between text-sm"
                                        >
                                            <span class="truncate text-gray-600 dark:text-gray-400">
                                                {{ item.title }} (x{{ item.quantity }})
                                            </span>
                                            <span class="shrink-0 text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.quantity * item.unit_price) }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="space-y-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                                        <div class="flex justify-between text-sm">
                                            <span class="text-gray-500 dark:text-gray-400">Subtotal</span>
                                            <span class="text-gray-900 dark:text-white">{{ formatCurrency(subtotal) }}</span>
                                        </div>
                                        <div v-if="restockingFee > 0" class="flex justify-between text-sm text-red-600 dark:text-red-400">
                                            <span>Restocking Fee ({{ selectedPolicy?.restocking_fee_percent }}%)</span>
                                            <span>-{{ formatCurrency(restockingFee) }}</span>
                                        </div>
                                        <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-bold dark:border-gray-700">
                                            <span class="text-gray-900 dark:text-white">Refund Amount</span>
                                            <span class="text-green-600 dark:text-green-400">{{ formatCurrency(refundAmount) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button
                                type="button"
                                @click="submitReturn"
                                :disabled="!canSubmit"
                                class="w-full rounded-md bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span v-if="isSubmitting">Creating Return...</span>
                                <span v-else>Create Return</span>
                            </button>

                            <!-- Error Messages -->
                            <div v-if="Object.keys(errors).length > 0" class="rounded-md bg-red-50 p-4 dark:bg-red-900/30">
                                <ul class="list-disc space-y-1 pl-5 text-sm text-red-700 dark:text-red-400">
                                    <li v-for="(error, key) in errors" :key="key">{{ error }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
