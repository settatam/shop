<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { NotesSection } from '@/components/notes';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    ArrowLeftIcon,
    UserIcon,
    CalendarIcon,
    CubeIcon,
    CheckCircleIcon,
    XCircleIcon,
    BanknotesIcon,
    TrashIcon,
    TruckIcon,
    ShoppingBagIcon,
    PrinterIcon,
    ArrowDownTrayIcon,
    MapPinIcon,
    ArrowsRightLeftIcon,
    ScaleIcon,
} from '@heroicons/vue/24/outline';
import CollectPaymentModal from '@/components/payments/CollectPaymentModal.vue';

interface Customer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email?: string;
    phone?: string;
}

interface User {
    id: number;
    name: string;
}

interface Warehouse {
    id: number;
    name: string;
}

interface Product {
    id: number;
    title: string;
    image?: string;
}

interface OrderItem {
    id: number;
    product_id: number;
    product_variant_id?: number;
    sku?: string;
    title: string;
    quantity: number;
    price: number;
    cost?: number;
    discount: number;
    tax?: number;
    line_total: number;
    line_profit?: number;
    notes?: string;
    product?: Product;
}

interface Invoice {
    id: number;
    invoice_number: string;
    status: string;
    total: number;
    balance_due: number;
}

interface Payment {
    id: number;
    amount: number;
    payment_method: string;
    status: string;
    reference?: string;
    notes?: string;
    paid_at?: string;
    user?: User;
}

interface TradeInItem {
    id: number;
    title: string;
    description?: string;
    buy_price: number;
    precious_metal?: string;
    condition?: string;
    dwt?: number;
}

interface TradeInTransaction {
    id: number;
    transaction_number: string;
    final_offer: number;
    status: string;
    items: TradeInItem[];
}

interface Order {
    id: number;
    invoice_number?: string;
    status: string;
    sub_total: number;
    sales_tax: number;
    tax_rate: number;
    shipping_cost: number;
    discount_cost: number;
    trade_in_credit: number;
    total: number;
    total_paid?: number;
    balance_due?: number;
    notes?: string;
    billing_address?: Record<string, string>;
    shipping_address?: Record<string, string>;
    date_of_purchase?: string;
    source_platform?: string;
    external_marketplace_id?: string;
    created_at: string;
    updated_at: string;

    is_draft: boolean;
    is_pending: boolean;
    is_confirmed: boolean;
    is_paid: boolean;
    is_cancelled: boolean;
    is_fully_paid: boolean;
    is_from_external_platform: boolean;
    has_trade_in: boolean;

    can_be_confirmed: boolean;
    can_be_shipped: boolean;
    can_be_delivered: boolean;
    can_be_completed: boolean;
    can_be_cancelled: boolean;
    can_receive_payment: boolean;
    can_be_deleted: boolean;

    customer?: Customer;
    user?: User;
    warehouse?: Warehouse;
    items: OrderItem[];
    item_count: number;
    invoice?: Invoice;
    payments?: Payment[];
    trade_in_transaction?: TradeInTransaction;
    note_entries: Note[];
}

interface Status {
    value: string;
    label: string;
}

interface PaymentMethod {
    value: string;
    label: string;
}

interface NoteUser {
    id: number;
    name: string;
}

interface Note {
    id: number;
    content: string;
    user: NoteUser | null;
    created_at: string;
    updated_at: string;
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
    order: Order;
    statuses: Status[];
    paymentMethods: PaymentMethod[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: props.order.invoice_number || `Order #${props.order.id}`, href: `/orders/${props.order.id}` },
];

// Payment modal
const showPaymentModal = ref(false);
const isProcessing = ref(false);

const statusColors: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    processing: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    shipped: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    delivered: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    refunded: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    partial_payment: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
};

const statusLabels: Record<string, string> = {
    draft: 'Draft',
    pending: 'Pending',
    confirmed: 'Confirmed',
    processing: 'Processing',
    shipped: 'Shipped',
    delivered: 'Delivered',
    completed: 'Completed',
    cancelled: 'Cancelled',
    refunded: 'Refunded',
    partial_payment: 'Partial Payment',
};

function formatDate(dateString: string): string {
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

function formatAddress(address?: Record<string, string>): string {
    if (!address) return '';
    const parts = [
        address.address_line_1,
        address.address_line_2,
        [address.city, address.state, address.postal_code].filter(Boolean).join(', '),
        address.country,
    ].filter(Boolean);
    return parts.join('\n');
}

// Actions
function confirmOrder() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/confirm`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function shipOrder() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/ship`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function deliverOrder() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/deliver`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function completeOrder() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/complete`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function cancelOrder() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to cancel this order? Stock will be restored.')) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function deleteOrder() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to delete this order? This action cannot be undone.')) return;
    isProcessing.value = true;
    router.delete(`/orders/${props.order.id}`, {
        onFinish: () => { isProcessing.value = false; },
    });
}

function openPaymentModal() {
    showPaymentModal.value = true;
}

function closePaymentModal() {
    showPaymentModal.value = false;
}

function onPaymentSuccess() {
    showPaymentModal.value = false;
    router.reload();
}

// Invoice functions
const hasInvoice = computed(() => !!props.order.invoice);

function printInvoice() {
    if (!props.order.invoice) return;
    window.open(`/invoices/${props.order.invoice.id}/pdf/stream`, '_blank');
}

function downloadInvoice() {
    if (!props.order.invoice) return;
    window.location.href = `/invoices/${props.order.invoice.id}/pdf`;
}

const totalProfit = computed(() => {
    return props.order.items.reduce((sum, item) => sum + (item.line_profit ?? 0), 0);
});
</script>

<template>
    <Head :title="`Order ${order.invoice_number || order.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Header -->
                <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link href="/orders" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                                    {{ order.invoice_number || `Order #${order.id}` }}
                                </h1>
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[order.status]]">
                                    {{ statusLabels[order.status] }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Created {{ formatDate(order.created_at) }}
                                <span v-if="order.customer"> for {{ order.customer.full_name }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="order.can_be_confirmed"
                            type="button"
                            @click="confirmOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 disabled:opacity-50"
                        >
                            <CheckCircleIcon class="size-4" />
                            Confirm
                        </button>

                        <button
                            v-if="order.can_be_shipped"
                            type="button"
                            @click="shipOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-500 disabled:opacity-50"
                        >
                            <TruckIcon class="size-4" />
                            Mark Shipped
                        </button>

                        <button
                            v-if="order.can_be_delivered"
                            type="button"
                            @click="deliverOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <ShoppingBagIcon class="size-4" />
                            Mark Delivered
                        </button>

                        <button
                            v-if="order.can_be_completed"
                            type="button"
                            @click="completeOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <CheckCircleIcon class="size-4" />
                            Complete
                        </button>

                        <button
                            v-if="order.can_receive_payment"
                            type="button"
                            @click="openPaymentModal"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <BanknotesIcon class="size-4" />
                            Receive Payment
                        </button>

                        <button
                            v-if="order.can_be_cancelled"
                            type="button"
                            @click="cancelOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-yellow-600 px-4 py-2 text-sm font-medium text-white hover:bg-yellow-500 disabled:opacity-50"
                        >
                            <XCircleIcon class="size-4" />
                            Cancel
                        </button>

                        <button
                            v-if="order.can_be_deleted"
                            type="button"
                            @click="deleteOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50"
                        >
                            <TrashIcon class="size-4" />
                            Delete
                        </button>

                        <!-- Invoice Actions -->
                        <button
                            v-if="hasInvoice"
                            type="button"
                            @click="printInvoice"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <PrinterIcon class="size-4" />
                            Print Invoice
                        </button>

                        <button
                            v-if="hasInvoice"
                            type="button"
                            @click="downloadInvoice"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <ArrowDownTrayIcon class="size-4" />
                            Download PDF
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
                                    Items ({{ order.item_count }})
                                </h2>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div
                                    v-for="item in order.items"
                                    :key="item.id"
                                    class="flex items-center gap-4 p-4"
                                >
                                    <div class="flex size-16 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                        <img v-if="item.product?.image" :src="item.product.image" class="size-16 rounded object-cover" />
                                        <CubeIcon v-else class="size-8 text-gray-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <Link
                                            v-if="item.product_id"
                                            :href="`/products/${item.product_id}`"
                                            class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                        >
                                            {{ item.title }}
                                        </Link>
                                        <p v-else class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="item.sku">SKU: {{ item.sku }}</span>
                                            <span v-if="item.sku && item.quantity > 1"> | </span>
                                            <span v-if="item.quantity > 1">Qty: {{ item.quantity }}</span>
                                        </p>
                                        <p v-if="item.notes" class="mt-1 text-sm text-gray-400 dark:text-gray-500">{{ item.notes }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.line_total) }}</p>
                                        <p v-if="item.discount > 0" class="text-sm text-green-600 dark:text-green-400">
                                            -{{ formatCurrency(item.discount) }} discount
                                        </p>
                                        <p v-if="item.line_profit !== undefined" class="text-xs text-gray-400">
                                            Profit: {{ formatCurrency(item.line_profit) }}
                                        </p>
                                    </div>
                                </div>
                                <div v-if="order.items.length === 0" class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No items in this order.
                                </div>
                            </div>
                        </div>

                        <!-- Trade-In Transaction -->
                        <div v-if="order.has_trade_in && order.trade_in_transaction" class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <h2 class="flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                        <ArrowsRightLeftIcon class="size-5 text-green-600 dark:text-green-400" />
                                        Trade-In Items
                                    </h2>
                                    <div class="text-right">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">Ref: {{ order.trade_in_transaction.transaction_number }}</p>
                                        <p class="text-lg font-semibold text-green-600 dark:text-green-400">
                                            {{ formatCurrency(order.trade_in_transaction.final_offer) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div
                                    v-for="item in order.trade_in_transaction.items"
                                    :key="item.id"
                                    class="flex items-center gap-4 p-4"
                                >
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded bg-green-100 dark:bg-green-900">
                                        <ScaleIcon class="size-6 text-green-600 dark:text-green-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="item.precious_metal">{{ item.precious_metal }}</span>
                                            <span v-if="item.precious_metal && item.condition"> | </span>
                                            <span v-if="item.condition">{{ item.condition }}</span>
                                            <span v-if="(item.precious_metal || item.condition) && item.dwt"> | </span>
                                            <span v-if="item.dwt">{{ item.dwt }} DWT</span>
                                        </p>
                                        <p v-if="item.description" class="mt-1 text-sm text-gray-400 dark:text-gray-500">{{ item.description }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(item.buy_price) }}</p>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t border-gray-200 bg-gray-50 px-6 py-3 dark:border-gray-700 dark:bg-gray-700/50">
                                <Link
                                    :href="`/transactions/${order.trade_in_transaction.id}`"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    View Trade-In Transaction &rarr;
                                </Link>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div v-if="order.notes" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-3 text-lg font-medium text-gray-900 dark:text-white">Notes</h2>
                            <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ order.notes }}</p>
                        </div>

                        <!-- Addresses -->
                        <div v-if="order.shipping_address || order.billing_address" class="grid gap-6 md:grid-cols-2">
                            <div v-if="order.shipping_address" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                                <h2 class="mb-3 flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                    <MapPinIcon class="size-5" />
                                    Shipping Address
                                </h2>
                                <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ formatAddress(order.shipping_address) }}</p>
                            </div>
                            <div v-if="order.billing_address" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                                <h2 class="mb-3 flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                    <MapPinIcon class="size-5" />
                                    Billing Address
                                </h2>
                                <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ formatAddress(order.billing_address) }}</p>
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
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(order.sub_total) }}</dd>
                                </div>
                                <div v-if="(order.discount_cost ?? 0) > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Discount</dt>
                                    <dd>-{{ formatCurrency(order.discount_cost ?? 0) }}</dd>
                                </div>
                                <div v-if="(order.trade_in_credit ?? 0) > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Trade-In Credit</dt>
                                    <dd>-{{ formatCurrency(order.trade_in_credit ?? 0) }}</dd>
                                </div>
                                <div v-if="(order.shipping_cost ?? 0) > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Shipping</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(order.shipping_cost ?? 0) }}</dd>
                                </div>
                                <div v-if="(order.sales_tax ?? 0) > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Tax ({{ (order.tax_rate * 100).toFixed(2) }}%)</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(order.sales_tax ?? 0) }}</dd>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-medium dark:border-gray-700">
                                    <dt class="text-gray-900 dark:text-white">Total</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(order.total) }}</dd>
                                </div>
                                <div v-if="(order.total_paid ?? 0) > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Amount Paid</dt>
                                    <dd>-{{ formatCurrency(order.total_paid ?? 0) }}</dd>
                                </div>
                                <div class="flex justify-between text-base font-bold">
                                    <dt class="text-gray-900 dark:text-white">Balance Due</dt>
                                    <dd class="text-indigo-600 dark:text-indigo-400">{{ formatCurrency(order.balance_due ?? order.total) }}</dd>
                                </div>
                                <div v-if="totalProfit > 0" class="flex justify-between border-t border-gray-200 pt-3 text-sm dark:border-gray-700">
                                    <dt class="text-gray-500 dark:text-gray-400">Estimated Profit</dt>
                                    <dd class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(totalProfit) }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Payment History -->
                        <div v-if="order.payments && order.payments.length > 0" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Payment History</h2>
                            <div class="space-y-3">
                                <div v-for="payment in order.payments" :key="payment.id" class="flex items-center justify-between rounded-lg bg-gray-50 p-3 dark:bg-gray-700">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(payment.amount) }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ payment.payment_method }}
                                            <span v-if="payment.paid_at"> &bull; {{ formatDate(payment.paid_at) }}</span>
                                        </p>
                                        <p v-if="payment.user" class="text-xs text-gray-400 dark:text-gray-500">
                                            by {{ payment.user.name }}
                                        </p>
                                    </div>
                                    <span :class="[
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                        payment.status === 'completed' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-300'
                                    ]">
                                        {{ payment.status }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Details -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Details</h2>
                            <dl class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Created</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(order.created_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="order.date_of_purchase" class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Purchase Date</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(order.date_of_purchase) }}</dd>
                                    </div>
                                </div>
                                <div v-if="order.source_platform" class="flex items-start gap-3">
                                    <ShoppingBagIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Source</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ order.source_platform }}</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Customer -->
                        <div v-if="order.customer" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Customer</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                    <UserIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <Link :href="`/customers/${order.customer.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ order.customer.full_name }}
                                    </Link>
                                    <p v-if="order.customer.email" class="text-sm text-gray-500 dark:text-gray-400">{{ order.customer.email }}</p>
                                    <p v-if="order.customer.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ order.customer.phone }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Employee -->
                        <div v-if="order.user" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Handled By</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                </div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ order.user.name }}</p>
                            </div>
                        </div>

                        <!-- Warehouse -->
                        <div v-if="order.warehouse" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Location</h2>
                            <p class="font-medium text-gray-900 dark:text-white">{{ order.warehouse.name }}</p>
                        </div>

                        <!-- Notes -->
                        <NotesSection
                            :notes="order.note_entries"
                            notable-type="order"
                            :notable-id="order.id"
                        />

                        <!-- Activity Log -->
                        <ActivityTimeline :activities="activityLogs" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modal -->
        <CollectPaymentModal
            :show="showPaymentModal"
            model-type="order"
            :model="order"
            :title="order.invoice_number || `Order #${order.id}`"
            :subtitle="order.customer?.full_name || ''"
            @close="closePaymentModal"
            @success="onPaymentSuccess"
        />
    </AppLayout>
</template>
