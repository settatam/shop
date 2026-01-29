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
    ClockIcon,
    CubeIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArrowPathIcon,
    BanknotesIcon,
    TrashIcon,
    PaperAirplaneIcon,
    DocumentTextIcon,
    PrinterIcon,
    ArrowDownTrayIcon,
} from '@heroicons/vue/24/outline';
import CollectPaymentModal from '@/components/payments/CollectPaymentModal.vue';
import VendorEditModal from '@/components/vendors/VendorEditModal.vue';
import { PencilIcon } from '@heroicons/vue/20/solid';

interface Vendor {
    id: number;
    name: string;
    company_name?: string;
    display_name?: string;
    email?: string;
    phone?: string;
}

interface User {
    id: number;
    name: string;
}

interface Product {
    id: number;
    title: string;
    sku?: string;
    image?: string;
}

interface MemoItem {
    id: number;
    product_id: number;
    sku?: string;
    title: string;
    description?: string;
    price: number;
    cost: number;
    tenor: number;
    due_date?: string;
    effective_due_date?: string;
    is_returned: boolean;
    can_be_returned: boolean;
    quantity: number;
    profit: number;
    product?: Product;
}

interface Order {
    id: number;
    order_number: string;
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

interface Memo {
    id: number;
    memo_number: string;
    status: string;
    tenure: number;
    subtotal: number;
    tax: number;
    tax_rate: number;
    charge_taxes: boolean;
    shipping_cost: number;
    total: number;

    // Payment adjustments
    discount_value?: number;
    discount_unit?: string;
    discount_reason?: string;
    discount_amount?: number;
    service_fee_value?: number;
    service_fee_unit?: string;
    service_fee_reason?: string;
    service_fee_amount?: number;
    tax_type?: string;
    tax_amount?: number;
    grand_total?: number;
    total_paid?: number;
    balance_due?: number;

    description?: string;
    duration?: number;
    days_with_vendor: number;
    due_date?: string;
    is_overdue: boolean;
    created_at: string;
    updated_at: string;

    is_pending: boolean;
    is_sent_to_vendor: boolean;
    is_vendor_received: boolean;
    is_vendor_returned: boolean;
    is_payment_received: boolean;
    is_archived: boolean;
    is_cancelled: boolean;

    can_be_sent_to_vendor: boolean;
    can_be_marked_as_received: boolean;
    can_receive_payment: boolean;
    can_be_cancelled: boolean;

    vendor?: Vendor;
    user?: User;
    items: MemoItem[];
    active_items_count: number;
    returned_items_count: number;
    order?: Order;
    invoice?: Invoice;
    payments?: Payment[];
    note_entries: Note[];
}

interface Status {
    value: string;
    label: string;
}

interface PaymentTerm {
    value: number;
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
    memo: Memo;
    statuses: Status[];
    paymentTerms: PaymentTerm[];
    paymentMethods: PaymentMethod[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Memos', href: '/memos' },
    { title: props.memo.memo_number, href: `/memos/${props.memo.id}` },
];

// Payment modal
const showPaymentModal = ref(false);
const showVendorEditModal = ref(false);
const isProcessing = ref(false);

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    sent_to_vendor: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    vendor_received: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    vendor_returned: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    payment_received: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    archived: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

const statusLabels: Record<string, string> = {
    pending: 'Pending',
    sent_to_vendor: 'Sent to Vendor',
    vendor_received: 'With Vendor',
    vendor_returned: 'Returned',
    payment_received: 'Payment Received',
    archived: 'Archived',
    cancelled: 'Cancelled',
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

// Actions
function sendToVendor() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/memos/${props.memo.id}/send-to-vendor`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function markReceived() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/memos/${props.memo.id}/mark-received`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function returnItem(itemId: number) {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/memos/${props.memo.id}/return-item/${itemId}`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function cancelMemo() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to cancel this memo? All items will be returned to stock.')) return;
    isProcessing.value = true;
    router.post(`/memos/${props.memo.id}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function deleteMemo() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to delete this memo? This action cannot be undone.')) return;
    isProcessing.value = true;
    router.delete(`/memos/${props.memo.id}`, {
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
const hasInvoice = computed(() => !!props.memo.invoice);

function viewInvoice() {
    if (!props.memo.invoice) return;
    router.visit(`/invoices/${props.memo.invoice.id}`);
}

function printInvoice() {
    if (!props.memo.invoice) return;
    // Open PDF in new window for printing
    window.open(`/invoices/${props.memo.invoice.id}/pdf/stream`, '_blank');
}

function downloadInvoice() {
    if (!props.memo.invoice) return;
    // Trigger download
    window.location.href = `/invoices/${props.memo.invoice.id}/pdf`;
}

function printPackingSlip() {
    window.open(`/memos/${props.memo.id}/packing-slip/stream`, '_blank');
}

const activeItems = computed(() => props.memo.items.filter(item => !item.is_returned));
const returnedItems = computed(() => props.memo.items.filter(item => item.is_returned));
</script>

<template>
    <Head :title="`Memo ${memo.memo_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Header -->
                <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link href="/memos" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ memo.memo_number }}</h1>
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[memo.status]]">
                                    {{ statusLabels[memo.status] }}
                                </span>
                                <span v-if="memo.is_overdue" class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-300">
                                    Overdue
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Created {{ formatDate(memo.created_at) }}
                                <span v-if="memo.vendor"> for {{ memo.vendor.display_name || memo.vendor.name }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="memo.can_be_sent_to_vendor"
                            type="button"
                            @click="sendToVendor"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            <PaperAirplaneIcon class="size-4" />
                            Send to Vendor
                        </button>

                        <button
                            v-if="memo.can_be_marked_as_received"
                            type="button"
                            @click="markReceived"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            <CheckCircleIcon class="size-4" />
                            Mark Received
                        </button>

                        <button
                            v-if="memo.can_receive_payment"
                            type="button"
                            @click="openPaymentModal"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <BanknotesIcon class="size-4" />
                            Receive Payment
                        </button>

                        <button
                            v-if="memo.can_be_cancelled"
                            type="button"
                            @click="cancelMemo"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-yellow-600 px-4 py-2 text-sm font-medium text-white hover:bg-yellow-500 disabled:opacity-50"
                        >
                            <XCircleIcon class="size-4" />
                            Cancel
                        </button>

                        <button
                            v-if="memo.is_pending"
                            type="button"
                            @click="deleteMemo"
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
                            @click="viewInvoice"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-100 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-200 dark:bg-indigo-900 dark:text-indigo-300 dark:hover:bg-indigo-800"
                        >
                            <DocumentTextIcon class="size-4" />
                            View Invoice
                        </button>

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

                        <!-- Packing Slip Actions -->
                        <button
                            type="button"
                            @click="printPackingSlip"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <DocumentTextIcon class="size-4" />
                            Packing Slip
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
                                    Items ({{ memo.active_items_count }} active, {{ memo.returned_items_count }} returned)
                                </h2>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div
                                    v-for="item in memo.items"
                                    :key="item.id"
                                    :class="['flex items-center gap-4 p-4', item.is_returned ? 'bg-gray-50 opacity-60 dark:bg-gray-800/50' : '']"
                                >
                                    <div class="flex size-16 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                        <img v-if="item.product?.image" :src="item.product.image" class="size-16 rounded object-cover" />
                                        <CubeIcon v-else class="size-8 text-gray-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="item.sku">SKU: {{ item.sku }}</span>
                                            <span v-if="item.sku && item.tenor"> | </span>
                                            <span v-if="item.tenor">{{ item.tenor }} days</span>
                                        </p>
                                        <p v-if="item.effective_due_date" class="text-sm text-gray-500 dark:text-gray-400">
                                            Due: {{ formatDate(item.effective_due_date) }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.price) }}</p>
                                        <p v-if="item.is_returned" class="text-sm text-red-600 dark:text-red-400">Returned</p>
                                        <button
                                            v-else-if="item.can_be_returned && memo.is_vendor_received"
                                            type="button"
                                            @click="returnItem(item.id)"
                                            :disabled="isProcessing"
                                            class="mt-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-500 disabled:opacity-50"
                                        >
                                            <ArrowPathIcon class="size-4" />
                                            Return
                                        </button>
                                    </div>
                                </div>
                                <div v-if="memo.items.length === 0" class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No items in this memo.
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div v-if="memo.description" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-3 text-lg font-medium text-gray-900 dark:text-white">Notes</h2>
                            <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ memo.description }}</p>
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
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(memo.subtotal) }}</dd>
                                </div>
                                <div v-if="(memo.discount_amount ?? 0) > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Discount</dt>
                                    <dd>-{{ formatCurrency(memo.discount_amount ?? 0) }}</dd>
                                </div>
                                <div v-if="(memo.service_fee_amount ?? 0) > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Service Fee</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(memo.service_fee_amount ?? 0) }}</dd>
                                </div>
                                <div v-if="(memo.tax_amount ?? memo.tax) > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Tax</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(memo.tax_amount ?? memo.tax) }}</dd>
                                </div>
                                <div v-if="(memo.shipping_cost ?? 0) > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Shipping</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(memo.shipping_cost ?? 0) }}</dd>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-medium dark:border-gray-700">
                                    <dt class="text-gray-900 dark:text-white">Grand Total</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(memo.grand_total ?? memo.total) }}</dd>
                                </div>
                                <div v-if="(memo.total_paid ?? 0) > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Amount Paid</dt>
                                    <dd>-{{ formatCurrency(memo.total_paid ?? 0) }}</dd>
                                </div>
                                <div class="flex justify-between text-base font-bold">
                                    <dt class="text-gray-900 dark:text-white">Balance Due</dt>
                                    <dd class="text-indigo-600 dark:text-indigo-400">{{ formatCurrency(memo.balance_due ?? memo.total) }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Payment History -->
                        <div v-if="memo.payments && memo.payments.length > 0" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Payment History</h2>
                            <div class="space-y-3">
                                <div v-for="payment in memo.payments" :key="payment.id" class="flex items-center justify-between rounded-lg bg-gray-50 p-3 dark:bg-gray-700">
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
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(memo.created_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="memo.due_date" class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Due Date</dt>
                                        <dd :class="memo.is_overdue ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'">
                                            {{ formatDate(memo.due_date) }}
                                        </dd>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <ClockIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Days with Vendor</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ memo.days_with_vendor }} days</dd>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <ClockIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Default Tenure</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ memo.tenure }} days</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Vendor -->
                        <div v-if="memo.vendor" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Vendor</h2>
                                <button
                                    type="button"
                                    class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                                    title="Edit vendor"
                                    @click="showVendorEditModal = true"
                                >
                                    <PencilIcon class="size-4" />
                                </button>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                    <UserIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div class="flex-1">
                                    <Link :href="`/vendors/${memo.vendor.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ memo.vendor.display_name || memo.vendor.name }}
                                    </Link>
                                    <p v-if="memo.vendor.company_name" class="text-sm text-gray-500 dark:text-gray-400">{{ memo.vendor.company_name }}</p>
                                    <p v-if="memo.vendor.email" class="text-sm text-gray-500 dark:text-gray-400">{{ memo.vendor.email }}</p>
                                    <p v-if="memo.vendor.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ memo.vendor.phone }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Employee -->
                        <div v-if="memo.user" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Handled By</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                </div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ memo.user.name }}</p>
                            </div>
                        </div>

                        <!-- Order reference -->
                        <div v-if="memo.order" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Related Order</h2>
                            <Link :href="`/orders/${memo.order.id}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                {{ memo.order.order_number }}
                            </Link>
                        </div>

                        <!-- Notes -->
                        <NotesSection
                            :notes="memo.note_entries"
                            notable-type="memo"
                            :notable-id="memo.id"
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
            model-type="memo"
            :model="memo"
            :title="memo.memo_number"
            :subtitle="memo.vendor?.display_name || memo.vendor?.name || ''"
            @close="closePaymentModal"
            @success="onPaymentSuccess"
        />

        <!-- Vendor Edit Modal -->
        <VendorEditModal
            v-if="memo.vendor"
            :show="showVendorEditModal"
            :vendor="memo.vendor"
            @close="showVendorEditModal = false"
            @saved="showVendorEditModal = false"
        />
    </AppLayout>
</template>
