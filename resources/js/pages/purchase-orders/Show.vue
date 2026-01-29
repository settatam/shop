<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    DocumentTextIcon,
    BuildingOffice2Icon,
    BuildingStorefrontIcon,
    UserIcon,
    CalendarIcon,
    TruckIcon,
    PencilIcon,
    TrashIcon,
    CheckIcon,
    XMarkIcon,
    ArrowPathIcon,
    LockClosedIcon,
    ClipboardDocumentListIcon,
} from '@heroicons/vue/20/solid';

interface Vendor {
    id: number;
    name: string;
    code: string | null;
    email: string | null;
    phone: string | null;
}

interface Warehouse {
    id: number;
    name: string;
    code: string | null;
}

interface User {
    id: number;
    name: string;
}

interface ProductVariant {
    id: number;
    sku: string;
    title: string | null;
}

interface PurchaseOrderItem {
    id: number;
    product_variant: ProductVariant | null;
    vendor_sku: string | null;
    description: string | null;
    quantity_ordered: number;
    quantity_received: number;
    remaining_quantity: number;
    unit_cost: number;
    discount_percent: number;
    tax_rate: number;
    line_total: number;
    notes: string | null;
    is_fully_received: boolean;
}

interface Receipt {
    id: number;
    receipt_number: string;
    received_by: User | null;
    received_at: string | null;
    total_quantity: number;
    notes: string | null;
}

interface PurchaseOrder {
    id: number;
    po_number: string;
    status: string;
    vendor: Vendor | null;
    warehouse: Warehouse | null;
    created_by: User | null;
    approved_by: User | null;
    subtotal: number;
    tax_amount: number;
    shipping_cost: number;
    discount_amount: number;
    total: number;
    order_date: string | null;
    expected_date: string | null;
    approved_at: string | null;
    submitted_at: string | null;
    closed_at: string | null;
    cancelled_at: string | null;
    shipping_method: string | null;
    tracking_number: string | null;
    vendor_notes: string | null;
    internal_notes: string | null;
    receiving_progress: number;
    total_ordered_quantity: number;
    total_received_quantity: number;
    items: PurchaseOrderItem[];
    receipts: Receipt[];
    created_at: string;
}

interface Props {
    purchaseOrder: PurchaseOrder;
    statuses: string[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchase Orders', href: '/purchase-orders' },
    { title: props.purchaseOrder.po_number, href: `/purchase-orders/${props.purchaseOrder.id}` },
];

// Modal state
const showDeleteModal = ref(false);
const showCancelModal = ref(false);
const isSubmitting = ref(false);

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const formatStatus = (status: string) => {
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        draft: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        submitted: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
        approved: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
        partial: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
        received: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300',
        closed: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        cancelled: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
    };
    return colors[status] || 'bg-gray-100 text-gray-700';
};

const canEdit = computed(() => props.purchaseOrder.status === 'draft');
const canDelete = computed(() => props.purchaseOrder.status === 'draft');
const canSubmit = computed(() => props.purchaseOrder.status === 'draft');
const canApprove = computed(() => props.purchaseOrder.status === 'submitted');
const canReceive = computed(() => ['approved', 'partial'].includes(props.purchaseOrder.status));
const canCancel = computed(() => !['received', 'closed', 'cancelled'].includes(props.purchaseOrder.status));
const canClose = computed(() => ['partial', 'received'].includes(props.purchaseOrder.status));

function submitPO() {
    isSubmitting.value = true;
    router.post(`/purchase-orders/${props.purchaseOrder.id}/submit`, {}, {
        onFinish: () => isSubmitting.value = false,
    });
}

function approvePO() {
    isSubmitting.value = true;
    router.post(`/purchase-orders/${props.purchaseOrder.id}/approve`, {}, {
        onFinish: () => isSubmitting.value = false,
    });
}

function cancelPO() {
    isSubmitting.value = true;
    router.post(`/purchase-orders/${props.purchaseOrder.id}/cancel`, {}, {
        onFinish: () => {
            isSubmitting.value = false;
            showCancelModal.value = false;
        },
    });
}

function closePO() {
    isSubmitting.value = true;
    router.post(`/purchase-orders/${props.purchaseOrder.id}/close`, {}, {
        onFinish: () => isSubmitting.value = false,
    });
}

function deletePO() {
    router.delete(`/purchase-orders/${props.purchaseOrder.id}`, {
        onSuccess: () => showDeleteModal.value = false,
    });
}
</script>

<template>
    <Head :title="`PO ${purchaseOrder.po_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-900">
                        <DocumentTextIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                            {{ purchaseOrder.po_number }}
                        </h1>
                        <div class="mt-1 flex items-center gap-3">
                            <span
                                :class="[
                                    'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
                                    getStatusColor(purchaseOrder.status),
                                ]"
                            >
                                {{ formatStatus(purchaseOrder.status) }}
                            </span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                Created {{ purchaseOrder.created_at }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="canEdit"
                        :href="`/purchase-orders/${purchaseOrder.id}/edit`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <PencilIcon class="-ml-0.5 size-4" />
                        Edit
                    </Link>

                    <button
                        v-if="canSubmit"
                        type="button"
                        :disabled="isSubmitting"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 disabled:opacity-50"
                        @click="submitPO"
                    >
                        <ArrowPathIcon class="-ml-0.5 size-4" />
                        Submit
                    </button>

                    <button
                        v-if="canApprove"
                        type="button"
                        :disabled="isSubmitting"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50"
                        @click="approvePO"
                    >
                        <CheckIcon class="-ml-0.5 size-4" />
                        Approve
                    </button>

                    <Link
                        v-if="canReceive"
                        :href="`/purchase-orders/${purchaseOrder.id}/receive`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        <ClipboardDocumentListIcon class="-ml-0.5 size-4" />
                        Receive Items
                    </Link>

                    <button
                        v-if="canClose"
                        type="button"
                        :disabled="isSubmitting"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-gray-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500 disabled:opacity-50"
                        @click="closePO"
                    >
                        <LockClosedIcon class="-ml-0.5 size-4" />
                        Close
                    </button>

                    <button
                        v-if="canCancel"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50 dark:bg-gray-700 dark:ring-red-600 dark:hover:bg-red-900/20"
                        @click="showCancelModal = true"
                    >
                        <XMarkIcon class="-ml-0.5 size-4" />
                        Cancel
                    </button>

                    <button
                        v-if="canDelete"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50 dark:bg-gray-700 dark:ring-red-600 dark:hover:bg-red-900/20"
                        @click="showDeleteModal = true"
                    >
                        <TrashIcon class="-ml-0.5 size-4" />
                        Delete
                    </button>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Items Table -->
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Items</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ purchaseOrder.items.length }} item{{ purchaseOrder.items.length === 1 ? '' : 's' }} |
                                {{ purchaseOrder.total_received_quantity }} of {{ purchaseOrder.total_ordered_quantity }} received
                            </p>
                        </div>

                        <!-- Progress Bar -->
                        <div v-if="purchaseOrder.receiving_progress > 0" class="px-4 pt-4 sm:px-6">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600 dark:text-gray-400">Receiving Progress</span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ purchaseOrder.receiving_progress }}%</span>
                            </div>
                            <div class="mt-2 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-2 rounded-full bg-indigo-600"
                                    :style="{ width: `${purchaseOrder.receiving_progress}%` }"
                                />
                            </div>
                        </div>

                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                        Product
                                    </th>
                                    <th scope="col" class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                        Qty Ordered
                                    </th>
                                    <th scope="col" class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white md:table-cell">
                                        Qty Received
                                    </th>
                                    <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">
                                        Unit Cost
                                    </th>
                                    <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="item in purchaseOrder.items" :key="item.id">
                                    <td class="py-4 pl-4 pr-3 sm:pl-6">
                                        <div class="min-w-0">
                                            <div class="font-medium text-gray-900 dark:text-white">
                                                {{ item.product_variant?.title || item.description || 'Unknown Product' }}
                                            </div>
                                            <div v-if="item.product_variant?.sku" class="text-sm text-gray-500 dark:text-gray-400">
                                                SKU: {{ item.product_variant.sku }}
                                            </div>
                                            <div v-if="item.vendor_sku" class="text-sm text-gray-500 dark:text-gray-400">
                                                Vendor SKU: {{ item.vendor_sku }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="hidden whitespace-nowrap px-3 py-4 text-right text-sm text-gray-900 dark:text-white sm:table-cell">
                                        {{ item.quantity_ordered }}
                                    </td>
                                    <td class="hidden whitespace-nowrap px-3 py-4 text-right text-sm md:table-cell">
                                        <span
                                            :class="[
                                                item.is_fully_received
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : item.quantity_received > 0
                                                        ? 'text-yellow-600 dark:text-yellow-400'
                                                        : 'text-gray-900 dark:text-white',
                                            ]"
                                        >
                                            {{ item.quantity_received }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-right text-sm text-gray-900 dark:text-white">
                                        {{ formatCurrency(item.unit_cost) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-right text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(item.line_total) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <td colspan="4" class="py-3.5 pl-4 pr-3 text-right text-sm font-medium text-gray-900 dark:text-white sm:pl-6">
                                        Subtotal
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3.5 text-right text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(purchaseOrder.subtotal) }}
                                    </td>
                                </tr>
                                <tr v-if="purchaseOrder.tax_amount > 0">
                                    <td colspan="4" class="py-2 pl-4 pr-3 text-right text-sm text-gray-600 dark:text-gray-400 sm:pl-6">
                                        Tax
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-sm text-gray-600 dark:text-gray-400">
                                        {{ formatCurrency(purchaseOrder.tax_amount) }}
                                    </td>
                                </tr>
                                <tr v-if="purchaseOrder.shipping_cost > 0">
                                    <td colspan="4" class="py-2 pl-4 pr-3 text-right text-sm text-gray-600 dark:text-gray-400 sm:pl-6">
                                        Shipping
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-sm text-gray-600 dark:text-gray-400">
                                        {{ formatCurrency(purchaseOrder.shipping_cost) }}
                                    </td>
                                </tr>
                                <tr v-if="purchaseOrder.discount_amount > 0">
                                    <td colspan="4" class="py-2 pl-4 pr-3 text-right text-sm text-gray-600 dark:text-gray-400 sm:pl-6">
                                        Discount
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-sm text-red-600 dark:text-red-400">
                                        -{{ formatCurrency(purchaseOrder.discount_amount) }}
                                    </td>
                                </tr>
                                <tr class="border-t border-gray-200 dark:border-gray-600">
                                    <td colspan="4" class="py-3.5 pl-4 pr-3 text-right text-base font-semibold text-gray-900 dark:text-white sm:pl-6">
                                        Total
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3.5 text-right text-base font-semibold text-gray-900 dark:text-white">
                                        {{ formatCurrency(purchaseOrder.total) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Receipts -->
                    <div v-if="purchaseOrder.receipts.length > 0" class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Receiving History</h3>
                        </div>
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                            <li v-for="receipt in purchaseOrder.receipts" :key="receipt.id" class="px-4 py-4 sm:px-6">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">
                                            {{ receipt.receipt_number }}
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ receipt.total_quantity }} items received
                                            <span v-if="receipt.received_by"> by {{ receipt.received_by.name }}</span>
                                        </p>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ receipt.received_at }}
                                    </div>
                                </div>
                                <p v-if="receipt.notes" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                    {{ receipt.notes }}
                                </p>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Details Card -->
                    <div class="bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Details</h3>
                        </div>
                        <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                            <!-- Vendor -->
                            <div class="px-4 py-3 sm:px-6">
                                <dt class="flex items-center gap-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                                    <BuildingOffice2Icon class="size-4" />
                                    Vendor
                                </dt>
                                <dd class="mt-1">
                                    <Link
                                        v-if="purchaseOrder.vendor"
                                        :href="`/vendors/${purchaseOrder.vendor.id}`"
                                        class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    >
                                        {{ purchaseOrder.vendor.name }}
                                    </Link>
                                    <span v-else class="text-sm text-gray-400">-</span>
                                </dd>
                            </div>

                            <!-- Warehouse -->
                            <div class="px-4 py-3 sm:px-6">
                                <dt class="flex items-center gap-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                                    <BuildingStorefrontIcon class="size-4" />
                                    Warehouse
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                    {{ purchaseOrder.warehouse?.name || '-' }}
                                </dd>
                            </div>

                            <!-- Order Date -->
                            <div class="px-4 py-3 sm:px-6">
                                <dt class="flex items-center gap-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                                    <CalendarIcon class="size-4" />
                                    Order Date
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                    {{ purchaseOrder.order_date || '-' }}
                                </dd>
                            </div>

                            <!-- Expected Date -->
                            <div class="px-4 py-3 sm:px-6">
                                <dt class="flex items-center gap-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                                    <TruckIcon class="size-4" />
                                    Expected Delivery
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                    {{ purchaseOrder.expected_date || '-' }}
                                </dd>
                            </div>

                            <!-- Shipping Method -->
                            <div v-if="purchaseOrder.shipping_method" class="px-4 py-3 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Shipping Method
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                    {{ purchaseOrder.shipping_method }}
                                </dd>
                            </div>

                            <!-- Tracking Number -->
                            <div v-if="purchaseOrder.tracking_number" class="px-4 py-3 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Tracking Number
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                    {{ purchaseOrder.tracking_number }}
                                </dd>
                            </div>

                            <!-- Created By -->
                            <div class="px-4 py-3 sm:px-6">
                                <dt class="flex items-center gap-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                                    <UserIcon class="size-4" />
                                    Created By
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                    {{ purchaseOrder.created_by?.name || '-' }}
                                </dd>
                            </div>

                            <!-- Approved By -->
                            <div v-if="purchaseOrder.approved_by" class="px-4 py-3 sm:px-6">
                                <dt class="flex items-center gap-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                                    <CheckIcon class="size-4" />
                                    Approved By
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                    {{ purchaseOrder.approved_by.name }}
                                    <span class="text-gray-500 dark:text-gray-400">
                                        ({{ purchaseOrder.approved_at }})
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Notes -->
                    <div v-if="purchaseOrder.vendor_notes || purchaseOrder.internal_notes" class="bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-4 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Notes</h3>
                        </div>
                        <div class="px-4 py-4 sm:px-6 space-y-4">
                            <div v-if="purchaseOrder.vendor_notes">
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Vendor Notes</p>
                                <p class="mt-1 text-sm text-gray-900 dark:text-white whitespace-pre-wrap">
                                    {{ purchaseOrder.vendor_notes }}
                                </p>
                            </div>
                            <div v-if="purchaseOrder.internal_notes">
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Internal Notes</p>
                                <p class="mt-1 text-sm text-gray-900 dark:text-white whitespace-pre-wrap">
                                    {{ purchaseOrder.internal_notes }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10 dark:bg-red-900">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete Purchase Order
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Are you sure you want to delete {{ purchaseOrder.po_number }}? This action cannot be undone.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                                <button
                                    type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:w-auto"
                                    @click="deletePO"
                                >
                                    Delete
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showDeleteModal = false"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Cancel Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showCancelModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-yellow-100 sm:mx-0 sm:h-10 sm:w-10 dark:bg-yellow-900">
                                    <XMarkIcon class="h-6 w-6 text-yellow-600 dark:text-yellow-400" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Cancel Purchase Order
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Are you sure you want to cancel {{ purchaseOrder.po_number }}?
                                            <span v-if="['approved', 'partial'].includes(purchaseOrder.status)">
                                                This will also remove any pending incoming inventory.
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                                <button
                                    type="button"
                                    :disabled="isSubmitting"
                                    class="inline-flex w-full justify-center rounded-md bg-yellow-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500 sm:w-auto disabled:opacity-50"
                                    @click="cancelPO"
                                >
                                    {{ isSubmitting ? 'Cancelling...' : 'Cancel PO' }}
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showCancelModal = false"
                                >
                                    Go Back
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
