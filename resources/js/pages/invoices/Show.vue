<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    ArrowLeftIcon,
    PrinterIcon,
    ArrowDownTrayIcon,
    DocumentTextIcon,
    UserIcon,
    CalendarIcon,
    BanknotesIcon,
} from '@heroicons/vue/24/outline';

interface Customer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email?: string;
    phone_number?: string;
}

interface User {
    id: number;
    name: string;
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

interface InvoiceableItem {
    id: number;
    title?: string;
    sku?: string;
    price?: number;
    customer_cost?: number;
    quantity?: number;
}

interface Invoiceable {
    id: number;
    items?: InvoiceableItem[];
    memo_number?: string;
    repair_number?: string;
    order_number?: string;
}

interface Invoice {
    id: number;
    invoice_number: string;
    status: string;
    subtotal: number;
    tax: number;
    shipping: number;
    discount: number;
    service_fee: number;
    total: number;
    total_paid: number;
    balance_due: number;
    due_date?: string;
    created_at: string;
    invoiceable_type: string;
    invoiceable_id: number;
    customer?: Customer;
    invoiceable?: Invoiceable;
    payments?: Payment[];
}

interface Props {
    invoice: Invoice;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Invoices', href: '/invoices' },
    { title: props.invoice.invoice_number, href: `/invoices/${props.invoice.id}` },
];

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    partial: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    paid: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    void: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    overdue: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

const statusLabels: Record<string, string> = {
    pending: 'Pending',
    partial: 'Partial',
    paid: 'Paid',
    void: 'Void',
    overdue: 'Overdue',
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

function printInvoice() {
    window.open(`/invoices/${props.invoice.id}/pdf/stream`, '_blank');
}

function downloadInvoice() {
    window.location.href = `/invoices/${props.invoice.id}/pdf`;
}

const invoiceableType = computed(() => {
    const type = props.invoice.invoiceable_type;
    if (type.includes('Memo')) return 'Memo';
    if (type.includes('Repair')) return 'Repair';
    if (type.includes('Order')) return 'Order';
    return 'Unknown';
});

const invoiceableNumber = computed(() => {
    const inv = props.invoice.invoiceable;
    if (!inv) return '';
    return inv.memo_number || inv.repair_number || inv.order_number || `#${inv.id}`;
});

const invoiceableLink = computed(() => {
    const type = invoiceableType.value.toLowerCase();
    const id = props.invoice.invoiceable_id;
    if (type === 'memo') return `/memos/${id}`;
    if (type === 'repair') return `/repairs/${id}`;
    if (type === 'order') return `/orders/${id}`;
    return '#';
});

const items = computed(() => {
    return props.invoice.invoiceable?.items || [];
});
</script>

<template>
    <Head :title="`Invoice ${invoice.invoice_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-4xl">
                <!-- Header -->
                <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link href="/invoices" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ invoice.invoice_number }}</h1>
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[invoice.status]]">
                                    {{ statusLabels[invoice.status] }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Created {{ formatDate(invoice.created_at) }}
                            </p>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="`/invoices/${invoice.id}/print`"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <PrinterIcon class="size-4" />
                            Print
                        </Link>

                        <button
                            type="button"
                            @click="downloadInvoice"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            <ArrowDownTrayIcon class="size-4" />
                            Download PDF
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main content -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Invoice Details Card -->
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Invoice Details</h2>
                            </div>
                            <div class="p-6">
                                <dl class="grid grid-cols-2 gap-4">
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Invoice Number</dt>
                                        <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ invoice.invoice_number }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                                        <dd class="mt-1">
                                            <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[invoice.status]]">
                                                {{ statusLabels[invoice.status] }}
                                            </span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Source</dt>
                                        <dd class="mt-1">
                                            <Link :href="invoiceableLink" class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                {{ invoiceableType }} {{ invoiceableNumber }}
                                            </Link>
                                        </dd>
                                    </div>
                                    <div v-if="invoice.due_date">
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Due Date</dt>
                                        <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ formatDate(invoice.due_date) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Line Items -->
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Line Items</h2>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Item</th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Qty</th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Price</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                        <tr v-for="item in items" :key="item.id">
                                            <td class="whitespace-nowrap px-6 py-4">
                                                <p class="font-medium text-gray-900 dark:text-white">{{ item.title || 'Item' }}</p>
                                                <p v-if="item.sku" class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.sku }}</p>
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-500 dark:text-gray-400">
                                                {{ item.quantity || 1 }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.price || item.customer_cost || 0) }}
                                            </td>
                                        </tr>
                                        <tr v-if="items.length === 0">
                                            <td colspan="3" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                                No items available.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Payment History -->
                        <div v-if="invoice.payments && invoice.payments.length > 0" class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Payment History</h2>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div v-for="payment in invoice.payments" :key="payment.id" class="flex items-center justify-between p-4">
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
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6 lg:col-span-1">
                        <!-- Summary -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Summary</h2>
                            <dl class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(invoice.subtotal) }}</dd>
                                </div>
                                <div v-if="invoice.discount > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Discount</dt>
                                    <dd>-{{ formatCurrency(invoice.discount) }}</dd>
                                </div>
                                <div v-if="invoice.tax > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Tax</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(invoice.tax) }}</dd>
                                </div>
                                <div v-if="invoice.shipping > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Shipping</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(invoice.shipping) }}</dd>
                                </div>
                                <div v-if="invoice.service_fee > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Service Fee</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(invoice.service_fee) }}</dd>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-medium dark:border-gray-700">
                                    <dt class="text-gray-900 dark:text-white">Total</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(invoice.total) }}</dd>
                                </div>
                                <div v-if="invoice.total_paid > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Amount Paid</dt>
                                    <dd>-{{ formatCurrency(invoice.total_paid) }}</dd>
                                </div>
                                <div class="flex justify-between text-base font-bold">
                                    <dt class="text-gray-900 dark:text-white">Balance Due</dt>
                                    <dd :class="invoice.balance_due > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">
                                        {{ formatCurrency(invoice.balance_due) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Customer -->
                        <div v-if="invoice.customer" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Customer</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900">
                                    <UserIcon class="size-6 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div>
                                    <Link :href="`/customers/${invoice.customer.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ invoice.customer.full_name }}
                                    </Link>
                                    <p v-if="invoice.customer.email" class="text-sm text-gray-500 dark:text-gray-400">{{ invoice.customer.email }}</p>
                                    <p v-if="invoice.customer.phone_number" class="text-sm text-gray-500 dark:text-gray-400">{{ invoice.customer.phone_number }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Dates</h2>
                            <dl class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Created</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(invoice.created_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="invoice.due_date" class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Due Date</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(invoice.due_date) }}</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
