<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    ArrowLeftIcon,
    UserIcon,
    CalendarIcon,
    CubeIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArrowPathIcon,
    ArchiveBoxArrowDownIcon,
    ClipboardDocumentListIcon,
    TruckIcon,
    BanknotesIcon,
} from '@heroicons/vue/24/outline';

interface LeadSource {
    id: number;
    name: string;
}

interface Customer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email?: string;
    phone?: string;
    lead_source?: LeadSource;
}

interface User {
    id: number;
    name: string;
}

interface Order {
    id: number;
    invoice_number?: string;
    status: string;
    total: number;
    created_at: string;
}

interface ReturnPolicy {
    id: number;
    name: string;
    restocking_fee_percent: number;
}

interface Product {
    id: number;
    title: string;
    image?: string;
    sku?: string;
}

interface ReturnItem {
    id: number;
    order_item_id: number;
    product_variant_id?: number;
    quantity: number;
    unit_price: number;
    line_total: number;
    condition?: string;
    reason?: string;
    notes?: string;
    restock: boolean;
    restocked: boolean;
    restocked_at?: string;
    product?: Product;
}

interface ProductReturn {
    id: number;
    return_number: string;
    status: string;
    type: string;
    subtotal: number;
    restocking_fee: number;
    refund_amount: number;
    refund_method?: string;
    reason?: string;
    customer_notes?: string;
    internal_notes?: string;
    requested_at?: string;
    approved_at?: string;
    received_at?: string;
    completed_at?: string;
    created_at: string;
    updated_at: string;

    is_pending: boolean;
    is_approved: boolean;
    is_processing: boolean;
    is_completed: boolean;
    is_rejected: boolean;
    is_cancelled: boolean;

    can_be_approved: boolean;
    can_be_processed: boolean;
    can_be_rejected: boolean;
    can_be_cancelled: boolean;

    order?: Order;
    customer?: Customer;
    return_policy?: ReturnPolicy;
    processed_by?: User;
    items: ReturnItem[];
    item_count: number;
}

interface Status {
    value: string;
    label: string;
}

interface RefundMethod {
    value: string;
    label: string;
}

interface ActivityItem {
    id: number;
    activity: string;
    description: string;
    user: { name: string } | null;
    changes: Record<string, { old: string; new: string }> | null;
    time: string;
    created_at: string;
    icon: string;
    color: string;
}

interface ActivityDay {
    date: string;
    dateTime: string;
    items: ActivityItem[];
}

interface Props {
    productReturn: ProductReturn;
    statuses: Status[];
    refundMethods: RefundMethod[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: 'Returns', href: '/returns' },
    { title: props.productReturn.return_number, href: `/returns/${props.productReturn.id}` },
];

const isProcessing = ref(false);
const showRejectModal = ref(false);
const rejectReason = ref('');
const showCompleteModal = ref(false);
const selectedRefundMethod = ref('original_payment');

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    approved: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    processing: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    cancelled: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
};

const statusLabels: Record<string, string> = {
    pending: 'Pending',
    approved: 'Approved',
    processing: 'Processing',
    completed: 'Completed',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

const typeLabels: Record<string, string> = {
    return: 'Return',
    exchange: 'Exchange',
};

function formatDate(dateString?: string): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

// Actions
function approveReturn() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/returns/${props.productReturn.id}/approve`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function openRejectModal() {
    rejectReason.value = '';
    showRejectModal.value = true;
}

function rejectReturn() {
    if (isProcessing.value || !rejectReason.value) return;
    isProcessing.value = true;
    router.post(`/returns/${props.productReturn.id}/reject`, {
        reason: rejectReason.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            isProcessing.value = false;
            showRejectModal.value = false;
        },
    });
}

function processReturn() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/returns/${props.productReturn.id}/process`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function receiveItems() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/returns/${props.productReturn.id}/receive`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function openCompleteModal() {
    selectedRefundMethod.value = 'original_payment';
    showCompleteModal.value = true;
}

function completeReturn() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/returns/${props.productReturn.id}/complete`, {
        refund_method: selectedRefundMethod.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            isProcessing.value = false;
            showCompleteModal.value = false;
        },
    });
}

function cancelReturn() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to cancel this return?')) return;
    isProcessing.value = true;
    router.post(`/returns/${props.productReturn.id}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function restockItem(itemId: number) {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/returns/${props.productReturn.id}/items/${itemId}/restock`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function createReturnLabel() {
    router.post(`/returns/${props.productReturn.id}/create-label`);
}

const pendingRestockItems = computed(() => {
    return props.productReturn.items.filter(item => item.restock && !item.restocked);
});
</script>

<template>
    <Head :title="`Return ${productReturn.return_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Header -->
                <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link href="/returns" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                                    {{ productReturn.return_number }}
                                </h1>
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[productReturn.status]]">
                                    {{ statusLabels[productReturn.status] }}
                                </span>
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                    {{ typeLabels[productReturn.type] }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Requested {{ formatDate(productReturn.requested_at) }}
                                <span v-if="productReturn.customer"> by {{ productReturn.customer.full_name }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="productReturn.can_be_approved"
                            type="button"
                            @click="approveReturn"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <CheckCircleIcon class="size-4" />
                            Approve
                        </button>

                        <button
                            v-if="productReturn.can_be_rejected"
                            type="button"
                            @click="openRejectModal"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50"
                        >
                            <XCircleIcon class="size-4" />
                            Reject
                        </button>

                        <button
                            v-if="productReturn.can_be_processed"
                            type="button"
                            @click="processReturn"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            <ArrowPathIcon class="size-4" />
                            Process Return
                        </button>

                        <button
                            v-if="productReturn.is_approved"
                            type="button"
                            @click="createReturnLabel"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-500 disabled:opacity-50"
                        >
                            <TruckIcon class="size-4" />
                            Create Return Label
                        </button>

                        <button
                            v-if="productReturn.is_processing && !productReturn.received_at"
                            type="button"
                            @click="receiveItems"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 disabled:opacity-50"
                        >
                            <ArchiveBoxArrowDownIcon class="size-4" />
                            Mark Items Received
                        </button>

                        <button
                            v-if="productReturn.is_processing && productReturn.received_at"
                            type="button"
                            @click="openCompleteModal"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <BanknotesIcon class="size-4" />
                            Complete & Refund
                        </button>

                        <button
                            v-if="productReturn.can_be_cancelled"
                            type="button"
                            @click="cancelReturn"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-500 disabled:opacity-50"
                        >
                            <XCircleIcon class="size-4" />
                            Cancel
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main content -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Items -->
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">
                                    Return Items ({{ productReturn.item_count }})
                                </h2>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div
                                    v-for="item in productReturn.items"
                                    :key="item.id"
                                    class="flex items-center gap-4 p-4"
                                >
                                    <div class="flex size-16 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                        <img v-if="item.product?.image" :src="item.product.image" class="size-16 rounded object-cover" />
                                        <CubeIcon v-else class="size-8 text-gray-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <Link
                                            v-if="item.product?.id"
                                            :href="`/products/${item.product.id}`"
                                            class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                        >
                                            {{ item.product?.title || 'Unknown Product' }}
                                        </Link>
                                        <p v-else class="font-medium text-gray-900 dark:text-white">{{ item.product?.title || 'Unknown Product' }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="item.product?.sku">SKU: {{ item.product.sku }}</span>
                                            <span v-if="item.product?.sku && item.quantity > 1"> | </span>
                                            <span>Qty: {{ item.quantity }}</span>
                                        </p>
                                        <div class="mt-1 flex flex-wrap gap-2 text-xs">
                                            <span v-if="item.condition" class="rounded bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                                Condition: {{ item.condition }}
                                            </span>
                                            <span v-if="item.reason" class="rounded bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                                {{ item.reason }}
                                            </span>
                                            <span v-if="item.restocked" class="rounded bg-green-100 px-2 py-0.5 text-green-600 dark:bg-green-900 dark:text-green-400">
                                                Restocked
                                            </span>
                                            <span v-else-if="item.restock" class="rounded bg-yellow-100 px-2 py-0.5 text-yellow-600 dark:bg-yellow-900 dark:text-yellow-400">
                                                Pending Restock
                                            </span>
                                        </div>
                                        <p v-if="item.notes" class="mt-1 text-sm text-gray-400 dark:text-gray-500">{{ item.notes }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.line_total) }}</p>
                                        <p class="text-sm text-gray-500">{{ formatCurrency(item.unit_price) }} each</p>
                                        <button
                                            v-if="item.restock && !item.restocked && productReturn.is_processing"
                                            type="button"
                                            @click="restockItem(item.id)"
                                            :disabled="isProcessing"
                                            class="mt-2 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 disabled:opacity-50"
                                        >
                                            Restock Item
                                        </button>
                                    </div>
                                </div>
                                <div v-if="productReturn.items.length === 0" class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No items in this return.
                                </div>
                            </div>
                        </div>

                        <!-- Original Order -->
                        <div v-if="productReturn.order" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                <ClipboardDocumentListIcon class="size-5" />
                                Original Order
                            </h2>
                            <div class="flex items-center justify-between">
                                <div>
                                    <Link
                                        :href="`/orders/${productReturn.order.id}`"
                                        class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    >
                                        {{ productReturn.order.invoice_number || `Order #${productReturn.order.id}` }}
                                    </Link>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Placed {{ formatDate(productReturn.order.created_at) }}
                                    </p>
                                </div>
                                <p class="font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(productReturn.order.total) }}
                                </p>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div v-if="productReturn.reason || productReturn.customer_notes || productReturn.internal_notes" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Notes</h2>
                            <div class="space-y-4">
                                <div v-if="productReturn.reason">
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Return Reason</p>
                                    <p class="mt-1 whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ productReturn.reason }}</p>
                                </div>
                                <div v-if="productReturn.customer_notes">
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Customer Notes</p>
                                    <p class="mt-1 whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ productReturn.customer_notes }}</p>
                                </div>
                                <div v-if="productReturn.internal_notes">
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Internal Notes</p>
                                    <p class="mt-1 whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ productReturn.internal_notes }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6 lg:col-span-1">
                        <!-- Summary -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Summary</h2>
                            <dl class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(productReturn.subtotal) }}</dd>
                                </div>
                                <div v-if="(productReturn.restocking_fee ?? 0) > 0" class="flex justify-between text-sm text-red-600 dark:text-red-400">
                                    <dt>Restocking Fee</dt>
                                    <dd>-{{ formatCurrency(productReturn.restocking_fee ?? 0) }}</dd>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-bold dark:border-gray-700">
                                    <dt class="text-gray-900 dark:text-white">Refund Amount</dt>
                                    <dd class="text-green-600 dark:text-green-400">{{ formatCurrency(productReturn.refund_amount) }}</dd>
                                </div>
                                <div v-if="productReturn.refund_method" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Refund Method</dt>
                                    <dd class="text-gray-900 dark:text-white capitalize">{{ productReturn.refund_method.replace('_', ' ') }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Status Timeline -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Status</h2>
                            <dl class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Requested</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(productReturn.requested_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="productReturn.approved_at" class="flex items-start gap-3">
                                    <CheckCircleIcon class="size-5 shrink-0 text-green-500" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Approved</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(productReturn.approved_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="productReturn.received_at" class="flex items-start gap-3">
                                    <ArchiveBoxArrowDownIcon class="size-5 shrink-0 text-blue-500" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Items Received</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(productReturn.received_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="productReturn.completed_at" class="flex items-start gap-3">
                                    <BanknotesIcon class="size-5 shrink-0 text-green-500" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Completed</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(productReturn.completed_at) }}</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Customer -->
                        <div v-if="productReturn.customer" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Customer</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                    <UserIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <Link :href="`/customers/${productReturn.customer.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ productReturn.customer.full_name }}
                                    </Link>
                                    <p v-if="productReturn.customer.email" class="text-sm text-gray-500 dark:text-gray-400">{{ productReturn.customer.email }}</p>
                                    <p v-if="productReturn.customer.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ productReturn.customer.phone }}</p>
                                </div>
                            </div>
                            <!-- Lead Source -->
                            <div v-if="productReturn.customer.lead_source" class="mt-3 flex items-center gap-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Lead Source:</span>
                                <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30">
                                    {{ productReturn.customer.lead_source.name }}
                                </span>
                            </div>
                        </div>

                        <!-- Return Policy -->
                        <div v-if="productReturn.return_policy" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Return Policy</h2>
                            <p class="font-medium text-gray-900 dark:text-white">{{ productReturn.return_policy.name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Restocking Fee: {{ productReturn.return_policy.restocking_fee_percent }}%
                            </p>
                        </div>

                        <!-- Processed By -->
                        <div v-if="productReturn.processed_by" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Processed By</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                </div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ productReturn.processed_by.name }}</p>
                            </div>
                        </div>

                        <!-- Activity Log -->
                        <ActivityTimeline :activities="activityLogs" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Reject Modal -->
        <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Reject Return</h3>
                <div class="mb-4">
                    <label for="reject-reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason for rejection</label>
                    <textarea
                        id="reject-reason"
                        v-model="rejectReason"
                        rows="3"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                        placeholder="Enter the reason for rejecting this return..."
                    />
                </div>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        @click="showRejectModal = false"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="rejectReturn"
                        :disabled="!rejectReason || isProcessing"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50"
                    >
                        Reject Return
                    </button>
                </div>
            </div>
        </div>

        <!-- Complete Modal -->
        <div v-if="showCompleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Complete Return & Issue Refund</h3>
                <div class="mb-4">
                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                        You are about to issue a refund of <span class="font-bold text-green-600 dark:text-green-400">{{ formatCurrency(productReturn.refund_amount) }}</span>.
                    </p>
                    <label for="refund-method" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Refund Method</label>
                    <select
                        id="refund-method"
                        v-model="selectedRefundMethod"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm"
                    >
                        <option v-for="method in refundMethods" :key="method.value" :value="method.value">
                            {{ method.label }}
                        </option>
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        @click="showCompleteModal = false"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="completeReturn"
                        :disabled="isProcessing"
                        class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                    >
                        Complete & Refund
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
