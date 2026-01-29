<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { NotesSection } from '@/components/notes';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    ArrowLeftIcon,
    BanknotesIcon,
    CreditCardIcon,
    BuildingLibraryIcon,
    DocumentCheckIcon,
    UserIcon,
    CalendarIcon,
    DocumentTextIcon,
    ClipboardDocumentIcon,
} from '@heroicons/vue/24/outline';

interface Customer {
    id: number;
    full_name: string;
    email?: string;
    phone_number?: string;
}

interface User {
    id: number;
    name: string;
}

interface PayableItem {
    id: number;
    title?: string;
    sku?: string;
    price?: number;
    customer_cost?: number;
    quantity?: number;
}

interface Payable {
    id: number;
    memo_number?: string;
    repair_number?: string;
    order_number?: string;
    items?: PayableItem[];
}

interface Invoice {
    id: number;
    invoice_number: string;
    status: string;
    total: number;
}

interface TerminalCheckout {
    id: number;
    status: string;
    terminal_id?: string;
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

interface Payment {
    id: number;
    payment_method: string;
    status: string;
    amount: number;
    service_fee_value?: number;
    service_fee_unit?: string;
    service_fee_amount?: number;
    currency: string;
    reference?: string;
    transaction_id?: string;
    gateway?: string;
    gateway_payment_id?: string;
    gateway_response?: Record<string, any>;
    notes?: string;
    metadata?: Record<string, any>;
    paid_at?: string;
    created_at: string;
    payable_type?: string;
    payable_id?: number;
    customer?: Customer;
    user?: User;
    payable?: Payable;
    invoice?: Invoice;
    terminal_checkout?: TerminalCheckout;
}

interface Props {
    payment: Payment;
    noteEntries: Note[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Payments', href: '/payments' },
    { title: `Payment #${props.payment.id}`, href: `/payments/${props.payment.id}` },
];

const methodLabels: Record<string, string> = {
    cash: 'Cash',
    card: 'Credit/Debit Card',
    check: 'Check',
    bank_transfer: 'Bank Transfer / ACH / Wire',
    store_credit: 'Store Credit',
    layaway: 'Layaway',
    external: 'External Payment',
};

const methodIcons: Record<string, any> = {
    cash: BanknotesIcon,
    card: CreditCardIcon,
    check: DocumentCheckIcon,
    bank_transfer: BuildingLibraryIcon,
    store_credit: BanknotesIcon,
    layaway: BanknotesIcon,
    external: BanknotesIcon,
};

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    refunded: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    partially_refunded: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
};

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

const payableType = computed(() => {
    const type = props.payment.payable_type;
    if (!type) return null;
    if (type.includes('Memo')) return 'Memo';
    if (type.includes('Repair')) return 'Repair';
    if (type.includes('Order')) return 'Order';
    return 'Unknown';
});

const payableNumber = computed(() => {
    const p = props.payment.payable;
    if (!p) return '';
    return p.memo_number || p.repair_number || p.order_number || `#${p.id}`;
});

const payableLink = computed(() => {
    const type = payableType.value?.toLowerCase();
    const id = props.payment.payable_id;
    if (type === 'memo') return `/memos/${id}`;
    if (type === 'repair') return `/repairs/${id}`;
    if (type === 'order') return `/orders/${id}`;
    return '#';
});

const totalWithFee = computed(() => {
    return props.payment.amount + (props.payment.service_fee_amount || 0);
});

const MethodIcon = computed(() => methodIcons[props.payment.payment_method] || BanknotesIcon);
</script>

<template>
    <Head :title="`Payment #${payment.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-4xl">
                <!-- Header -->
                <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link href="/payments" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Payment #{{ payment.id }}</h1>
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[payment.status]]">
                                    {{ payment.status.replace('_', ' ') }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ payment.paid_at ? formatDate(payment.paid_at) : formatDate(payment.created_at) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main content -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Payment Details -->
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Payment Details</h2>
                            </div>
                            <div class="p-6">
                                <dl class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Payment Method</dt>
                                        <dd class="mt-1 flex items-center gap-2">
                                            <component :is="MethodIcon" class="size-5 text-gray-400" />
                                            <span class="font-medium text-gray-900 dark:text-white">
                                                {{ methodLabels[payment.payment_method] || payment.payment_method }}
                                            </span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                                        <dd class="mt-1">
                                            <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[payment.status]]">
                                                {{ payment.status.replace('_', ' ') }}
                                            </span>
                                        </dd>
                                    </div>
                                    <div v-if="payment.gateway">
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Payment Gateway</dt>
                                        <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ payment.gateway }}</dd>
                                    </div>
                                    <div v-if="payment.reference">
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Reference</dt>
                                        <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ payment.reference }}</dd>
                                    </div>
                                    <div v-if="payment.transaction_id">
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Transaction ID</dt>
                                        <dd class="mt-1 font-mono text-sm text-gray-900 dark:text-white">{{ payment.transaction_id }}</dd>
                                    </div>
                                    <div v-if="payment.gateway_payment_id">
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Gateway Payment ID</dt>
                                        <dd class="mt-1 font-mono text-sm text-gray-900 dark:text-white">{{ payment.gateway_payment_id }}</dd>
                                    </div>
                                </dl>

                                <div v-if="payment.notes" class="mt-6 border-t border-gray-200 pt-6 dark:border-gray-700">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Notes</dt>
                                    <dd class="mt-1 whitespace-pre-wrap text-gray-900 dark:text-white">{{ payment.notes }}</dd>
                                </div>
                            </div>
                        </div>

                        <!-- Gateway Response (for debugging) -->
                        <div v-if="payment.gateway_response && Object.keys(payment.gateway_response).length > 0" class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Gateway Response</h2>
                            </div>
                            <div class="p-6">
                                <pre class="overflow-x-auto rounded bg-gray-100 p-4 text-xs text-gray-800 dark:bg-gray-700 dark:text-gray-200">{{ JSON.stringify(payment.gateway_response, null, 2) }}</pre>
                            </div>
                        </div>

                        <!-- Source Items -->
                        <div v-if="payment.payable?.items && payment.payable.items.length > 0" class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Items</h2>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div v-for="item in payment.payable.items" :key="item.id" class="flex items-center justify-between p-4">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ item.title || 'Item' }}</p>
                                        <p v-if="item.sku" class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.sku }}</p>
                                    </div>
                                    <span class="font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(item.price || item.customer_cost || 0) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <NotesSection
                            :notes="noteEntries"
                            notable-type="payment"
                            :notable-id="payment.id"
                        />
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6 lg:col-span-1">
                        <!-- Amount Summary -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Amount</h2>
                            <dl class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Payment Amount</dt>
                                    <dd class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(payment.amount) }}</dd>
                                </div>
                                <div v-if="payment.service_fee_amount" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">
                                        Service Fee
                                        <span v-if="payment.service_fee_unit === 'percent'" class="text-xs">
                                            ({{ payment.service_fee_value }}%)
                                        </span>
                                    </dt>
                                    <dd class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(payment.service_fee_amount) }}</dd>
                                </div>
                                <div v-if="payment.service_fee_amount" class="flex justify-between border-t border-gray-200 pt-3 text-base font-bold dark:border-gray-700">
                                    <dt class="text-gray-900 dark:text-white">Total</dt>
                                    <dd class="text-green-600 dark:text-green-400">{{ formatCurrency(totalWithFee) }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Source -->
                        <div v-if="payment.payable || payment.invoice" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Source</h2>
                            <div class="space-y-4">
                                <div v-if="payment.payable" class="flex items-start gap-3">
                                    <DocumentTextIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ payableType }}</dt>
                                        <dd>
                                            <Link :href="payableLink" class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                {{ payableNumber }}
                                            </Link>
                                        </dd>
                                    </div>
                                </div>
                                <div v-if="payment.invoice" class="flex items-start gap-3">
                                    <ClipboardDocumentIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Invoice</dt>
                                        <dd>
                                            <Link :href="`/invoices/${payment.invoice.id}`" class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                {{ payment.invoice.invoice_number }}
                                            </Link>
                                        </dd>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Customer -->
                        <div v-if="payment.customer" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Customer</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900">
                                    <UserIcon class="size-6 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div>
                                    <Link :href="`/customers/${payment.customer.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ payment.customer.full_name }}
                                    </Link>
                                    <p v-if="payment.customer.email" class="text-sm text-gray-500 dark:text-gray-400">{{ payment.customer.email }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Processed By -->
                        <div v-if="payment.user" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Processed By</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                </div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ payment.user.name }}</p>
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
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(payment.created_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="payment.paid_at" class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-green-500" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Paid</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(payment.paid_at) }}</dd>
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
