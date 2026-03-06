<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { NotesSection } from '@/components/notes';
import ProductSearch from '@/components/products/ProductSearch.vue';
import AddItemModal from '@/components/transactions/AddItemModal.vue';
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
    PlusIcon,
    MagnifyingGlassIcon,
    MapPinIcon,
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
    address_line1?: string;
    address_line2?: string;
    city?: string;
    state?: string;
    postal_code?: string;
    country?: string;
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
    days_with_vendor?: number | null;
    is_returned: boolean;
    can_be_returned: boolean;
    quantity: number;
    profit: number;
    product?: Product;
}

interface Order {
    id: number;
    invoice_number: string;
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

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level: number;
    template_id: number | null;
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
    date_sent_to_vendor?: string;
    date_vendor_received?: string;
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
    can_be_marked_as_returned: boolean;
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
    categories: Category[];
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
const showAddItemModal = ref(false);
const showProductSearch = ref(false);
const editingItem = ref(null);
const isProcessing = ref(false);

const disabledProductIds = computed(() =>
    props.memo.items.filter(i => i.product_id && !i.is_returned).map(i => i.product_id),
);

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

function markReturned() {
    if (isProcessing.value) return;
    if (!confirm('Mark this memo as returned? All remaining items will be returned to stock.')) return;
    isProcessing.value = true;
    router.post(`/memos/${props.memo.id}/mark-returned`, {}, {
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

function updateItemField(itemId: number, field: 'cost' | 'price' | 'tenor', value: string) {
    const numValue = parseFloat(value) || 0;
    const item = props.memo.items.find(i => i.id === itemId);
    if (!item) return;

    // Only update if value changed
    if (item[field] === numValue) return;

    router.patch(`/memos/${props.memo.id}/items/${itemId}`, {
        [field]: numValue,
    }, {
        preserveScroll: true,
    });
}

function changeStatus(newStatus: string) {
    if (isProcessing.value) return;
    if (newStatus === props.memo.status) return;

    isProcessing.value = true;
    router.post(`/memos/${props.memo.id}/change-status`, {
        status: newStatus,
    }, {
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
    window.open(`/invoices/${props.memo.invoice.id}/print`, '_blank');
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

// Can edit items until payment is received
const canEditItems = computed(() => !props.memo.is_payment_received && !props.memo.is_archived);

// Status workflow steps
const statusSteps = computed(() => {
    const steps = [
        { key: 'pending', label: 'Pending', completed: !props.memo.is_pending, current: props.memo.is_pending },
        { key: 'sent_to_vendor', label: 'Sent to Vendor', completed: props.memo.is_vendor_received || props.memo.is_vendor_returned || props.memo.is_payment_received, current: props.memo.is_sent_to_vendor },
        { key: 'vendor_received', label: 'With Vendor', completed: props.memo.is_vendor_returned || props.memo.is_payment_received, current: props.memo.is_vendor_received },
    ];

    // Add final step based on outcome
    if (props.memo.is_vendor_returned) {
        steps.push({ key: 'vendor_returned', label: 'Returned', completed: true, current: true });
    } else if (props.memo.is_payment_received) {
        steps.push({ key: 'payment_received', label: 'Payment Received', completed: true, current: true });
    } else {
        // Show both possible outcomes
        steps.push({ key: 'outcome', label: 'Payment / Return', completed: false, current: false });
    }

    return steps;
});

// Add item functions
function handleProductSelect(product: any) {
    router.post(`/memos/${props.memo.id}/add-item`, {
        product_id: product.id,
        price: product.price || 0,
        cost: product.cost || 0,
        tenor: props.memo.tenure,
    }, {
        preserveScroll: true,
        onSuccess: () => { showProductSearch.value = false; },
    });
}

function openAddItemModal() {
    editingItem.value = null;
    showAddItemModal.value = true;
}

function handleSaveItem(item: any) {
    router.post(`/memos/${props.memo.id}/add-item`, {
        title: item.title,
        description: item.description,
        category_id: item.category_id,
        cost: item.buy_price || 0,
        price: item.price || 0,
        tenor: item.tenor || props.memo.tenure,
    }, {
        preserveScroll: true,
        onSuccess: () => { showAddItemModal.value = false; },
    });
}

function removeItem(itemId: number) {
    if (!confirm('Remove this item from the memo?')) return;
    router.delete(`/memos/${props.memo.id}/items/${itemId}`, {
        preserveScroll: true,
    });
}

const vendorAddress = computed(() => {
    if (!props.memo.vendor) return '';
    const parts = [
        props.memo.vendor.address_line1,
        props.memo.vendor.address_line2,
        [props.memo.vendor.city, props.memo.vendor.state].filter(Boolean).join(', '),
        props.memo.vendor.postal_code,
    ].filter(Boolean);
    return parts.join(', ');
});
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
                                <select
                                    :value="memo.status"
                                    @change="changeStatus(($event.target as HTMLSelectElement).value)"
                                    :disabled="isProcessing"
                                    :class="['rounded-full px-3 py-1 text-xs font-medium border-0 cursor-pointer focus:ring-2 focus:ring-indigo-500', statusColors[memo.status]]"
                                >
                                    <option v-for="status in statuses" :key="status.value" :value="status.value">
                                        {{ status.label }}
                                    </option>
                                </select>
                                <span v-if="memo.is_overdue" class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-300">
                                    Overdue
                                </span>
                                <Link v-if="memo.order" :href="`/orders/${memo.order.id}`" class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-300 dark:hover:bg-blue-800">
                                    <BanknotesIcon class="size-3" />
                                    Sale: {{ memo.order.invoice_number }}
                                </Link>
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
                            v-if="memo.is_pending"
                            type="button"
                            @click="showProductSearch = true"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500"
                        >
                            <PlusIcon class="size-4" />
                            Add Item
                        </button>

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
                            v-if="memo.can_be_marked_as_returned"
                            type="button"
                            @click="markReturned"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-500 disabled:opacity-50"
                        >
                            <ArrowPathIcon class="size-4" />
                            Mark Returned
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

                <!-- Status Workflow -->
                <div class="mb-6 rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <nav aria-label="Progress">
                        <ol class="flex items-center">
                            <li v-for="(step, stepIdx) in statusSteps" :key="step.key" :class="[stepIdx !== statusSteps.length - 1 ? 'flex-1' : '', 'relative']">
                                <div v-if="step.completed" class="group flex items-center">
                                    <span class="flex items-center">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-600">
                                            <CheckCircleIcon class="size-6 text-white" aria-hidden="true" />
                                        </span>
                                    </span>
                                    <span class="ml-3 text-sm font-medium text-gray-900 dark:text-white">{{ step.label }}</span>
                                </div>
                                <div v-else-if="step.current" class="flex items-center" aria-current="step">
                                    <span class="flex items-center">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-indigo-600 bg-white dark:bg-gray-700">
                                            <span class="text-indigo-600 dark:text-indigo-400 font-semibold">{{ stepIdx + 1 }}</span>
                                        </span>
                                    </span>
                                    <span class="ml-3 text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ step.label }}</span>
                                </div>
                                <div v-else class="group flex items-center">
                                    <span class="flex items-center">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-700">
                                            <span class="text-gray-500 dark:text-gray-400">{{ stepIdx + 1 }}</span>
                                        </span>
                                    </span>
                                    <span class="ml-3 text-sm font-medium text-gray-500 dark:text-gray-400">{{ step.label }}</span>
                                </div>
                                <!-- Connector line -->
                                <div v-if="stepIdx !== statusSteps.length - 1" class="absolute left-5 top-5 -ml-px mt-0.5 h-0.5 w-full bg-gray-300 dark:bg-gray-600" aria-hidden="true">
                                    <div :class="[step.completed ? 'bg-indigo-600' : '', 'h-full transition-all']" :style="{ width: step.completed ? '100%' : '0%' }"></div>
                                </div>
                            </li>
                        </ol>
                    </nav>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main content -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Items -->
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">
                                        Items ({{ memo.active_items_count }} active, {{ memo.returned_items_count }} returned)
                                    </h2>
                                    <div v-if="memo.is_pending" class="flex items-center gap-3">
                                        <button
                                            type="button"
                                            @click="showProductSearch = !showProductSearch"
                                            class="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                        >
                                            <MagnifyingGlassIcon class="size-4" />
                                            Search Product
                                        </button>
                                        <button
                                            type="button"
                                            @click="openAddItemModal"
                                            class="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                        >
                                            <PlusIcon class="size-4" />
                                            Add Custom Item
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Search -->
                            <div v-if="showProductSearch && memo.is_pending" class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <ProductSearch
                                    search-url="/memos/search-products"
                                    :disabled-product-ids="disabledProductIds"
                                    @select="handleProductSelect"
                                />
                            </div>

                            <!-- Items Table -->
                            <div>
                                <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="w-[40%] px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Item</th>
                                            <th class="px-2 py-2 text-right text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Cost</th>
                                            <th class="px-2 py-2 text-right text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Amount</th>
                                            <th class="px-2 py-2 text-center text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Terms</th>
                                            <th class="px-2 py-2 text-center text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Due</th>
                                            <th class="px-2 py-2 text-center text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Days</th>
                                            <th class="px-2 py-2 text-right text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Status</th>
                                            <th v-if="memo.is_pending" class="w-8 px-2 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                        <tr
                                            v-for="item in memo.items"
                                            :key="item.id"
                                            :class="item.is_returned ? 'bg-gray-50 opacity-60 dark:bg-gray-800/50' : ''"
                                        >
                                            <td class="px-3 py-2.5">
                                                <div class="flex items-center gap-2">
                                                    <div class="flex size-8 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                                        <img v-if="item.product?.image" :src="item.product.image" class="size-8 rounded object-cover" />
                                                        <CubeIcon v-else class="size-4 text-gray-400" />
                                                    </div>
                                                    <div class="min-w-0">
                                                        <p class="truncate text-xs font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                                        <p v-if="item.sku" class="truncate text-[10px] text-gray-500 dark:text-gray-400">{{ item.sku }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-2 py-2.5 text-right text-xs text-gray-500 dark:text-gray-400">
                                                <template v-if="canEditItems && !item.is_returned">
                                                    <div class="relative inline-block">
                                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-xs text-gray-400">$</span>
                                                        <input
                                                            type="number"
                                                            :value="item.cost"
                                                            step="0.01"
                                                            min="0"
                                                            @blur="updateItemField(item.id, 'cost', $event.target.value)"
                                                            @keyup.enter="($event.target as HTMLInputElement).blur()"
                                                            class="w-24 rounded border-0 py-1 pl-4 pr-1 text-right text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                    </div>
                                                </template>
                                                <template v-else>
                                                    {{ formatCurrency(item.cost) }}
                                                </template>
                                            </td>
                                            <td class="whitespace-nowrap px-2 py-2.5 text-right text-xs font-medium text-gray-900 dark:text-white">
                                                <template v-if="canEditItems && !item.is_returned">
                                                    <div class="relative inline-block">
                                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-xs text-gray-400">$</span>
                                                        <input
                                                            type="number"
                                                            :value="item.price"
                                                            step="0.01"
                                                            min="0"
                                                            @blur="updateItemField(item.id, 'price', $event.target.value)"
                                                            @keyup.enter="($event.target as HTMLInputElement).blur()"
                                                            class="w-24 rounded border-0 py-1 pl-4 pr-1 text-right text-sm font-medium text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                    </div>
                                                </template>
                                                <template v-else>
                                                    {{ formatCurrency(item.price) }}
                                                </template>
                                            </td>
                                            <td class="whitespace-nowrap px-2 py-2.5 text-center text-xs text-gray-500 dark:text-gray-400">
                                                <template v-if="canEditItems && !item.is_returned">
                                                    <div class="inline-flex items-center gap-0.5">
                                                        <input
                                                            type="number"
                                                            :value="item.tenor"
                                                            min="1"
                                                            @blur="updateItemField(item.id, 'tenor', $event.target.value)"
                                                            @keyup.enter="($event.target as HTMLInputElement).blur()"
                                                            class="w-11 rounded border-0 py-0.5 px-1 text-center text-xs text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                        <span class="text-[10px]">d</span>
                                                    </div>
                                                </template>
                                                <template v-else>
                                                    {{ item.tenor }}d
                                                </template>
                                            </td>
                                            <td class="whitespace-nowrap px-2 py-2.5 text-center text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="item.effective_due_date">{{ new Date(item.effective_due_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) }}</span>
                                                <span v-else class="text-gray-400">-</span>
                                            </td>
                                            <td class="whitespace-nowrap px-2 py-2.5 text-center text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="item.days_with_vendor !== null && item.days_with_vendor !== undefined" :class="item.days_with_vendor > (item.tenor || 30) ? 'text-red-600 font-medium dark:text-red-400' : ''">
                                                    {{ item.days_with_vendor }}
                                                </span>
                                                <span v-else class="text-gray-400">-</span>
                                            </td>
                                            <td class="whitespace-nowrap px-2 py-2.5 text-right">
                                                <span v-if="item.is_returned" class="inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-800 dark:bg-red-900 dark:text-red-300">
                                                    Returned
                                                </span>
                                                <button
                                                    v-else-if="item.can_be_returned && memo.is_vendor_received"
                                                    type="button"
                                                    @click="returnItem(item.id)"
                                                    :disabled="isProcessing"
                                                    class="inline-flex items-center gap-0.5 text-xs text-indigo-600 hover:text-indigo-500 disabled:opacity-50"
                                                >
                                                    <ArrowPathIcon class="size-3" />
                                                    Return
                                                </button>
                                                <span v-else class="inline-flex items-center rounded-full bg-green-100 px-1.5 py-0.5 text-[10px] font-medium text-green-800 dark:bg-green-900 dark:text-green-300">
                                                    Active
                                                </span>
                                            </td>
                                            <td v-if="memo.is_pending" class="whitespace-nowrap px-4 py-4 text-right">
                                                <button
                                                    type="button"
                                                    @click="removeItem(item.id)"
                                                    class="text-gray-400 hover:text-red-500 dark:hover:text-red-400"
                                                    title="Remove item"
                                                >
                                                    <TrashIcon class="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="memo.items.length === 0">
                                            <td :colspan="memo.is_pending ? 8 : 7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                                No items in this memo.
                                                <button
                                                    v-if="memo.is_pending"
                                                    type="button"
                                                    @click="showProductSearch = true"
                                                    class="ml-2 text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                                >
                                                    Add your first item
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Status Change -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Status</h2>
                            <div class="flex flex-wrap items-center gap-3">
                                <select
                                    :value="memo.status"
                                    @change="changeStatus(($event.target as HTMLSelectElement).value)"
                                    :disabled="isProcessing"
                                    :class="['rounded-full px-3 py-1.5 text-sm font-medium border-0 cursor-pointer focus:ring-2 focus:ring-indigo-500', statusColors[memo.status]]"
                                >
                                    <option v-for="status in statuses" :key="status.value" :value="status.value">
                                        {{ status.label }}
                                    </option>
                                </select>
                                <span v-if="memo.is_overdue" class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-300">
                                    Overdue
                                </span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    Last updated {{ formatDate(memo.updated_at) }}
                                </span>
                            </div>
                        </div>

                        <!-- Internal Notes -->
                        <div v-if="memo.description" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-3 text-lg font-medium text-gray-900 dark:text-white">Internal Notes</h2>
                            <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ memo.description }}</p>
                        </div>

                        <!-- Notes Section -->
                        <NotesSection
                            :notes="memo.note_entries"
                            notable-type="memo"
                            :notable-id="memo.id"
                        />

                        <!-- Activity Log -->
                        <ActivityTimeline :activities="activityLogs" />
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

                        <!-- Invoice -->
                        <div v-if="hasInvoice" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Invoice</h2>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ memo.invoice!.invoice_number || 'Invoice' }}</span>
                                    <span :class="[
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                        memo.invoice!.status === 'paid' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300'
                                    ]">
                                        {{ memo.invoice!.status === 'paid' ? 'Paid' : memo.invoice!.status }}
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        @click="viewInvoice"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/50 dark:text-indigo-300 dark:hover:bg-indigo-900"
                                    >
                                        <DocumentTextIcon class="size-3.5" />
                                        View
                                    </button>
                                    <button
                                        type="button"
                                        @click="printInvoice"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                    >
                                        <PrinterIcon class="size-3.5" />
                                        Print
                                    </button>
                                    <button
                                        type="button"
                                        @click="downloadInvoice"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                    >
                                        <ArrowDownTrayIcon class="size-3.5" />
                                        Download PDF
                                    </button>
                                </div>
                            </div>
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
                            <div class="flex items-start gap-3">
                                <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                    <UserIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div class="flex-1 space-y-1">
                                    <Link :href="`/vendors/${memo.vendor.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ memo.vendor.name }}
                                    </Link>
                                    <p v-if="memo.vendor.company_name" class="text-sm text-gray-500 dark:text-gray-400">{{ memo.vendor.company_name }}</p>
                                    <p v-if="memo.vendor.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ memo.vendor.phone }}</p>
                                    <p v-if="memo.vendor.email" class="text-sm text-gray-500 dark:text-gray-400">{{ memo.vendor.email }}</p>
                                    <div v-if="vendorAddress" class="flex items-start gap-1.5 pt-1">
                                        <MapPinIcon class="mt-0.5 size-3.5 shrink-0 text-gray-400" />
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ vendorAddress }}</p>
                                    </div>
                                </div>
                            </div>
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
                                <div v-if="memo.date_sent_to_vendor" class="flex items-start gap-3">
                                    <PaperAirplaneIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Sent to Vendor</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(memo.date_sent_to_vendor) }}</dd>
                                    </div>
                                </div>
                                <div v-if="memo.date_vendor_received" class="flex items-start gap-3">
                                    <CheckCircleIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Vendor Received</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(memo.date_vendor_received) }}</dd>
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
                                <div v-if="memo.date_vendor_received" class="flex items-start gap-3">
                                    <ClockIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Days with Vendor</dt>
                                        <dd :class="memo.is_overdue ? 'text-red-600 dark:text-red-400 font-medium' : 'text-gray-900 dark:text-white'">
                                            {{ memo.days_with_vendor }} days
                                        </dd>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <ClockIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Default Terms</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ memo.tenure }} days</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Salesperson -->
                        <div v-if="memo.store_user || memo.user" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Salesperson</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ memo.store_user?.name || memo.user.name }}</p>
                                    <p v-if="memo.created_by_user && memo.created_by_user.id !== memo.user?.id" class="text-sm text-gray-500 dark:text-gray-400">
                                        Created by {{ memo.created_by_user.name }}
                                    </p>
                                </div>
                            </div>
                        </div>


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

        <!-- Add Item Modal -->
        <AddItemModal
            :open="showAddItemModal"
            :categories="categories"
            :editing-item="editingItem"
            mode="memo"
            @close="showAddItemModal = false"
            @save="handleSaveItem"
        />
    </AppLayout>
</template>
