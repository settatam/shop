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
    BanknotesIcon,
    TrashIcon,
    PaperAirplaneIcon,
    PrinterIcon,
    ArrowDownTrayIcon,
    WrenchScrewdriverIcon,
    DocumentTextIcon,
} from '@heroicons/vue/24/outline';
import CollectPaymentModal from '@/components/payments/CollectPaymentModal.vue';
import CustomerEditModal from '@/components/customers/CustomerEditModal.vue';
import VendorEditModal from '@/components/vendors/VendorEditModal.vue';
import { PencilIcon } from '@heroicons/vue/20/solid';

interface Customer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email?: string;
    phone_number?: string;
    company_name?: string;
}

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

interface Category {
    id: number;
    name: string;
}

interface Product {
    id: number;
    title: string;
}

interface RepairItem {
    id: number;
    product_id?: number;
    category_id?: number;
    sku?: string;
    title: string;
    description?: string;
    vendor_cost: number;
    customer_cost: number;
    status: string;
    dwt?: number;
    precious_metal?: string;
    profit: number;
    category?: Category;
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

interface Repair {
    id: number;
    repair_number: string;
    status: string;
    subtotal: number;
    tax: number;
    tax_rate: number;
    service_fee: number;
    shipping_cost: number;
    discount: number;
    total: number;
    grand_total: number;
    description?: string;
    is_appraisal: boolean;
    repair_days?: number;
    vendor_total: number;
    customer_total: number;
    total_paid: number;
    balance_due: number;

    // Payment adjustment fields
    charge_taxes?: boolean;
    tax_type?: string;
    payment_tax_rate?: number;
    discount_value?: number;
    discount_unit?: string;
    discount_reason?: string;
    service_fee_value?: number;
    service_fee_unit?: string;
    service_fee_reason?: string;

    date_sent_to_vendor?: string;
    date_received_by_vendor?: string;
    date_completed?: string;
    created_at: string;
    updated_at: string;

    is_pending: boolean;
    is_sent_to_vendor: boolean;
    is_received_by_vendor: boolean;
    is_completed: boolean;
    is_payment_received: boolean;
    is_cancelled: boolean;
    is_fully_paid: boolean;

    can_be_sent_to_vendor: boolean;
    can_be_marked_as_received: boolean;
    can_be_completed: boolean;
    can_receive_payment: boolean;
    can_be_cancelled: boolean;

    customer?: Customer;
    vendor?: Vendor;
    user?: User;
    items: RepairItem[];
    order?: Order;
    invoice?: Invoice;
    payments?: Payment[];
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
    repair: Repair;
    statuses: Status[];
    paymentMethods: PaymentMethod[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Repairs', href: '/repairs' },
    { title: props.repair.repair_number, href: `/repairs/${props.repair.id}` },
];

// Payment modal
const showPaymentModal = ref(false);
const showCustomerEditModal = ref(false);
const showVendorEditModal = ref(false);
const isProcessing = ref(false);

// Computed repair model for payment modal with converted tax_rate
const repairForPayment = computed(() => ({
    ...props.repair,
    // Use payment_tax_rate (converted to percentage) for the payment modal
    tax_rate: props.repair.payment_tax_rate ?? props.repair.tax_rate,
}));

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    sent_to_vendor: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    received_by_vendor: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    payment_received: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    refunded: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    archived: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
};

const statusLabels: Record<string, string> = {
    pending: 'Pending',
    sent_to_vendor: 'Sent to Vendor',
    received_by_vendor: 'With Vendor',
    completed: 'Completed',
    payment_received: 'Payment Received',
    refunded: 'Refunded',
    cancelled: 'Cancelled',
    archived: 'Archived',
};

const statuses = [
    { value: 'pending', label: 'Pending' },
    { value: 'sent_to_vendor', label: 'Sent to Vendor' },
    { value: 'received_by_vendor', label: 'With Vendor' },
    { value: 'completed', label: 'Completed' },
    { value: 'payment_received', label: 'Payment Received' },
    { value: 'refunded', label: 'Refunded' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'archived', label: 'Archived' },
];

function changeStatus(newStatus: string) {
    if (isProcessing.value) return;
    if (newStatus === props.repair.status) return;

    isProcessing.value = true;
    router.post(`/repairs/${props.repair.id}/change-status`, {
        status: newStatus,
    }, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

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
    router.post(`/repairs/${props.repair.id}/send-to-vendor`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function markReceived() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/repairs/${props.repair.id}/mark-received`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function markCompleted() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/repairs/${props.repair.id}/mark-completed`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function cancelRepair() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to cancel this repair?')) return;
    isProcessing.value = true;
    router.post(`/repairs/${props.repair.id}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function deleteRepair() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to delete this repair? This action cannot be undone.')) return;
    isProcessing.value = true;
    router.delete(`/repairs/${props.repair.id}`, {
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
const hasInvoice = computed(() => !!props.repair.invoice);

function viewInvoice() {
    if (!props.repair.invoice) return;
    router.visit(`/invoices/${props.repair.invoice.id}`);
}

function printInvoice() {
    if (!props.repair.invoice) return;
    window.open(`/invoices/${props.repair.invoice.id}/pdf/stream`, '_blank');
}

function downloadInvoice() {
    if (!props.repair.invoice) return;
    window.location.href = `/invoices/${props.repair.invoice.id}/pdf`;
}

function printPackingSlip() {
    window.open(`/repairs/${props.repair.id}/packing-slip/stream`, '_blank');
}

const profit = computed(() => props.repair.customer_total - props.repair.vendor_total);
</script>

<template>
    <Head :title="`Repair ${repair.repair_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Header -->
                <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link href="/repairs" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ repair.repair_number }}</h1>
                                <select
                                    :value="repair.status"
                                    @change="changeStatus(($event.target as HTMLSelectElement).value)"
                                    :disabled="isProcessing"
                                    :class="['rounded-full px-3 py-1 text-xs font-medium border-0 cursor-pointer focus:ring-2 focus:ring-indigo-500', statusColors[repair.status]]"
                                >
                                    <option v-for="status in statuses" :key="status.value" :value="status.value">
                                        {{ status.label }}
                                    </option>
                                </select>
                                <span v-if="repair.is_appraisal" class="inline-flex items-center rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900 dark:text-purple-300">
                                    Appraisal
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Created {{ formatDate(repair.created_at) }}
                                <span v-if="repair.customer"> for {{ repair.customer.full_name }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="repair.can_be_sent_to_vendor"
                            type="button"
                            @click="sendToVendor"
                            :disabled="isProcessing || !repair.vendor"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            <PaperAirplaneIcon class="size-4" />
                            Send to Vendor
                        </button>

                        <button
                            v-if="repair.can_be_marked_as_received"
                            type="button"
                            @click="markReceived"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            <CheckCircleIcon class="size-4" />
                            Mark Received
                        </button>

                        <button
                            v-if="repair.can_be_completed"
                            type="button"
                            @click="markCompleted"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <WrenchScrewdriverIcon class="size-4" />
                            Mark Completed
                        </button>

                        <button
                            v-if="repair.can_receive_payment"
                            type="button"
                            @click="openPaymentModal"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <BanknotesIcon class="size-4" />
                            Receive Payment
                        </button>

                        <button
                            v-if="repair.can_be_cancelled"
                            type="button"
                            @click="cancelRepair"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-yellow-600 px-4 py-2 text-sm font-medium text-white hover:bg-yellow-500 disabled:opacity-50"
                        >
                            <XCircleIcon class="size-4" />
                            Cancel
                        </button>

                        <button
                            v-if="repair.is_pending"
                            type="button"
                            @click="deleteRepair"
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
                                    Repair Items ({{ repair.items.length }})
                                </h2>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Item</th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Vendor Cost</th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Customer Cost</th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Profit</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                        <tr v-for="item in repair.items" :key="item.id">
                                            <td class="whitespace-nowrap px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex size-10 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                                        <CubeIcon class="size-5 text-gray-400" />
                                                    </div>
                                                    <div>
                                                        <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                                        <p v-if="item.category" class="text-sm text-gray-500 dark:text-gray-400">{{ item.category.name }}</p>
                                                        <p v-if="item.description" class="text-sm text-gray-500 dark:text-gray-400">{{ item.description }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-500 dark:text-gray-400">
                                                {{ formatCurrency(item.vendor_cost) }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.customer_cost) }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm" :class="item.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                                {{ formatCurrency(item.profit) }}
                                            </td>
                                        </tr>
                                        <tr v-if="repair.items.length === 0">
                                            <td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                                No items in this repair.
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <td class="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900 dark:text-white">Total</td>
                                            <td class="whitespace-nowrap px-6 py-3 text-right text-sm font-medium text-gray-500 dark:text-gray-400">
                                                {{ formatCurrency(repair.vendor_total) }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                                                {{ formatCurrency(repair.customer_total) }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-3 text-right text-sm font-medium" :class="profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                                {{ formatCurrency(profit) }}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Description -->
                        <div v-if="repair.description" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-3 text-lg font-medium text-gray-900 dark:text-white">Description</h2>
                            <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ repair.description }}</p>
                        </div>

                        <!-- Notes -->
                        <NotesSection
                            :notes="repair.note_entries"
                            notable-type="repair"
                            :notable-id="repair.id"
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
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(repair.subtotal) }}</dd>
                                </div>
                                <div v-if="repair.service_fee > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Service Fee</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(repair.service_fee) }}</dd>
                                </div>
                                <div v-if="repair.discount > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Discount</dt>
                                    <dd>-{{ formatCurrency(repair.discount) }}</dd>
                                </div>
                                <div v-if="repair.tax > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Tax ({{ (repair.tax_rate * 100).toFixed(2) }}%)</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(repair.tax) }}</dd>
                                </div>
                                <div v-if="repair.shipping_cost > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Shipping</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(repair.shipping_cost) }}</dd>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-medium dark:border-gray-700">
                                    <dt class="text-gray-900 dark:text-white">Total</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(repair.total) }}</dd>
                                </div>
                                <div v-if="repair.total_paid > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Amount Paid</dt>
                                    <dd>-{{ formatCurrency(repair.total_paid) }}</dd>
                                </div>
                                <div class="flex justify-between text-base font-bold">
                                    <dt class="text-gray-900 dark:text-white">Balance Due</dt>
                                    <dd class="text-indigo-600 dark:text-indigo-400">{{ formatCurrency(repair.balance_due) }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Customer -->
                        <div v-if="repair.customer" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Customer</h2>
                                <button
                                    type="button"
                                    class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                                    title="Edit customer"
                                    @click="showCustomerEditModal = true"
                                >
                                    <PencilIcon class="size-4" />
                                </button>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900">
                                    <UserIcon class="size-6 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div class="flex-1">
                                    <Link :href="`/customers/${repair.customer.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ repair.customer.full_name }}
                                    </Link>
                                    <p v-if="repair.customer.company_name" class="text-sm text-gray-500 dark:text-gray-400">{{ repair.customer.company_name }}</p>
                                    <p v-if="repair.customer.email" class="text-sm text-gray-500 dark:text-gray-400">{{ repair.customer.email }}</p>
                                    <p v-if="repair.customer.phone_number" class="text-sm text-gray-500 dark:text-gray-400">{{ repair.customer.phone_number }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Vendor -->
                        <div v-if="repair.vendor" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Repair Vendor</h2>
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
                                    <WrenchScrewdriverIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div class="flex-1">
                                    <Link :href="`/vendors/${repair.vendor.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ repair.vendor.display_name || repair.vendor.name }}
                                    </Link>
                                    <p v-if="repair.vendor.company_name" class="text-sm text-gray-500 dark:text-gray-400">{{ repair.vendor.company_name }}</p>
                                    <p v-if="repair.vendor.email" class="text-sm text-gray-500 dark:text-gray-400">{{ repair.vendor.email }}</p>
                                    <p v-if="repair.vendor.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ repair.vendor.phone }}</p>
                                </div>
                            </div>
                        </div>
                        <div v-else class="rounded-lg border-2 border-dashed border-yellow-300 bg-yellow-50 p-6 dark:border-yellow-600 dark:bg-yellow-900/20">
                            <h2 class="mb-2 text-lg font-medium text-yellow-800 dark:text-yellow-300">No Vendor Assigned</h2>
                            <p class="text-sm text-yellow-700 dark:text-yellow-400">
                                A vendor must be assigned before this repair can be sent out.
                            </p>
                        </div>

                        <!-- Payment History -->
                        <div v-if="repair.payments && repair.payments.length > 0" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Payment History</h2>
                            <div class="space-y-3">
                                <div v-for="payment in repair.payments" :key="payment.id" class="flex items-center justify-between rounded-lg bg-gray-50 p-3 dark:bg-gray-700">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(payment.amount) }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ payment.payment_method }}
                                            <span v-if="payment.paid_at"> &bull; {{ formatDate(payment.paid_at) }}</span>
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

                        <!-- Timeline / Details -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Timeline</h2>
                            <dl class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Created</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(repair.created_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="repair.date_sent_to_vendor" class="flex items-start gap-3">
                                    <PaperAirplaneIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Sent to Vendor</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(repair.date_sent_to_vendor) }}</dd>
                                    </div>
                                </div>
                                <div v-if="repair.date_received_by_vendor" class="flex items-start gap-3">
                                    <CheckCircleIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Received by Vendor</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(repair.date_received_by_vendor) }}</dd>
                                    </div>
                                </div>
                                <div v-if="repair.date_completed" class="flex items-start gap-3">
                                    <WrenchScrewdriverIcon class="size-5 shrink-0 text-green-500" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Completed</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(repair.date_completed) }}</dd>
                                    </div>
                                </div>
                                <div v-if="repair.repair_days !== null && repair.repair_days !== undefined" class="flex items-start gap-3">
                                    <ClockIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Repair Duration</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ repair.repair_days }} days</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Employee -->
                        <div v-if="repair.user" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Handled By</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                </div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ repair.user.name }}</p>
                            </div>
                        </div>

                        <!-- Order reference -->
                        <div v-if="repair.order" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Related Order</h2>
                            <Link :href="`/orders/${repair.order.id}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                {{ repair.order.order_number }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modal -->
        <CollectPaymentModal
            :show="showPaymentModal"
            model-type="repair"
            :model="repairForPayment"
            :title="repair.repair_number"
            :subtitle="repair.customer?.full_name || ''"
            @close="closePaymentModal"
            @success="onPaymentSuccess"
        />

        <!-- Customer Edit Modal -->
        <CustomerEditModal
            v-if="repair.customer"
            :show="showCustomerEditModal"
            :customer="repair.customer"
            entity-type="repair"
            :entity-id="repair.id"
            @close="showCustomerEditModal = false"
            @saved="showCustomerEditModal = false"
        />

        <!-- Vendor Edit Modal -->
        <VendorEditModal
            v-if="repair.vendor"
            :show="showVendorEditModal"
            :vendor="repair.vendor"
            @close="showVendorEditModal = false"
            @saved="showVendorEditModal = false"
        />
    </AppLayout>
</template>
