<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    MagnifyingGlassIcon,
    FunnelIcon,
    ArrowDownTrayIcon,
    BanknotesIcon,
    CreditCardIcon,
    BuildingLibraryIcon,
    DocumentCheckIcon,
    XMarkIcon,
    ChevronUpIcon,
    ChevronDownIcon,
    ChevronUpDownIcon,
} from '@heroicons/vue/24/outline';
import { debounce } from 'lodash';

interface Customer {
    id: number;
    full_name: string;
}

interface User {
    id: number;
    name: string;
}

interface Payable {
    id: number;
    memo_number?: string;
    repair_number?: string;
    order_number?: string;
}

interface Invoice {
    id: number;
    invoice_number: string;
}

interface Payment {
    id: number;
    payment_method: string;
    status: string;
    amount: number;
    service_fee_amount: number;
    currency: string;
    reference?: string;
    transaction_id?: string;
    gateway?: string;
    notes?: string;
    paid_at?: string;
    created_at: string;
    payable_type?: string;
    payable_id?: number;
    customer?: Customer;
    user?: User;
    payable?: Payable;
    invoice?: Invoice;
}

interface PaymentMethod {
    value: string;
    label: string;
}

interface Status {
    value: string;
    label: string;
}

interface Totals {
    count: number;
    total_amount: number;
    total_fees: number;
}

interface Filters {
    payment_method?: string;
    status?: string;
    from_date?: string;
    to_date?: string;
    search?: string;
    customer_id?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
    min_amount?: string;
    max_amount?: string;
    platform?: string;
}

interface PlatformOption {
    value: string;
    label: string;
}

interface PaginatedPayments {
    data: Payment[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    payments: PaginatedPayments;
    totals: Totals;
    filters: Filters;
    paymentMethods: PaymentMethod[];
    statuses: Status[];
    platforms: PlatformOption[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Payments', href: '/payments' },
];

const search = ref(props.filters.search || '');
const paymentMethod = ref(props.filters.payment_method || '');
const status = ref(props.filters.status || '');
const fromDate = ref(props.filters.from_date || '');
const toDate = ref(props.filters.to_date || '');
const minAmount = ref(props.filters.min_amount || '');
const maxAmount = ref(props.filters.max_amount || '');
const platform = ref(props.filters.platform || '');
const showFilters = ref(true);
const showSummary = ref(false);
const sortField = ref(props.filters.sort || 'created_at');
const sortDirection = ref<'asc' | 'desc'>(props.filters.direction || 'desc');

const hasActiveFilters = computed(() => {
    return paymentMethod.value || status.value || fromDate.value || toDate.value || minAmount.value || maxAmount.value || platform.value;
});

function applyFilters() {
    router.get('/payments', {
        search: search.value || undefined,
        payment_method: paymentMethod.value || undefined,
        status: status.value || undefined,
        from_date: fromDate.value || undefined,
        to_date: toDate.value || undefined,
        min_amount: minAmount.value || undefined,
        max_amount: maxAmount.value || undefined,
        platform: platform.value || undefined,
        sort: sortField.value !== 'created_at' ? sortField.value : undefined,
        direction: sortDirection.value !== 'desc' ? sortDirection.value : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function toggleSort(field: string) {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'desc';
    }
    applyFilters();
}

function getSortIcon(field: string) {
    if (sortField.value !== field) return ChevronUpDownIcon;
    return sortDirection.value === 'asc' ? ChevronUpIcon : ChevronDownIcon;
}

function clearFilters() {
    search.value = '';
    paymentMethod.value = '';
    status.value = '';
    fromDate.value = '';
    toDate.value = '';
    minAmount.value = '';
    maxAmount.value = '';
    platform.value = '';
    sortField.value = 'created_at';
    sortDirection.value = 'desc';
    router.get('/payments', {}, { preserveState: true });
}

const debouncedSearch = debounce(() => {
    applyFilters();
}, 300);

watch(search, debouncedSearch);

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

const methodLabels: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    check: 'Check',
    bank_transfer: 'Bank Transfer',
    store_credit: 'Store Credit',
    layaway: 'Layaway',
    external: 'External',
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

function getPayableLink(payment: Payment): string {
    if (!payment.payable_type) return '#';
    const type = payment.payable_type.toLowerCase();
    if (type.includes('memo')) return `/memos/${payment.payable_id}`;
    if (type.includes('repair')) return `/repairs/${payment.payable_id}`;
    if (type.includes('order')) return `/orders/${payment.payable_id}`;
    return '#';
}

function getPayableLabel(payment: Payment): string {
    if (!payment.payable) return '-';
    return payment.payable.memo_number || payment.payable.repair_number || payment.payable.order_number || `#${payment.payable_id}`;
}
</script>

<template>
    <Head title="Payments" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col space-y-6 p-4">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Payments</h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        View and search all payments across orders, memos, and repairs.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        <ArrowDownTrayIcon class="size-4" />
                        Export
                    </button>
                </div>
            </div>

            <!-- Summary Toggle -->
            <div class="flex items-center">
                <button
                    type="button"
                    @click="showSummary = !showSummary"
                    class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                >
                    <component :is="showSummary ? ChevronUpIcon : ChevronDownIcon" class="size-4" />
                    {{ showSummary ? 'Hide Summary' : 'Show Summary' }}
                </button>
            </div>

            <!-- Summary Cards -->
            <div v-if="showSummary" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Payments</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ totals.count.toLocaleString() }}</p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Amount</p>
                    <p class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(totals.total_amount) }}</p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Fees</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ formatCurrency(totals.total_fees || 0) }}</p>
                </div>
            </div>

            <!-- Search and Filters -->
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-4">
                    <!-- Search -->
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search by reference, transaction ID..."
                            class="w-full rounded-md border-gray-300 py-2 pl-10 pr-4 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        />
                    </div>

                    <!-- Filter Toggle -->
                    <button
                        type="button"
                        @click="showFilters = !showFilters"
                        :class="[
                            'inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium shadow-sm ring-1 ring-inset',
                            hasActiveFilters
                                ? 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-900/50 dark:text-indigo-300 dark:ring-indigo-700'
                                : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700'
                        ]"
                    >
                        <FunnelIcon class="size-4" />
                        Filters
                        <span v-if="hasActiveFilters" class="ml-1 rounded-full bg-indigo-600 px-2 py-0.5 text-xs text-white">
                            Active
                        </span>
                    </button>
                </div>

                <!-- Filter Panel -->
                <div v-if="showFilters" class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <!-- Date Range -->
                        <div class="lg:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Date Range</label>
                            <div class="mt-1 flex gap-2">
                                <input
                                    v-model="fromDate"
                                    type="date"
                                    @change="applyFilters"
                                    placeholder="From"
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                <span class="flex items-center text-gray-400">to</span>
                                <input
                                    v-model="toDate"
                                    type="date"
                                    @change="applyFilters"
                                    placeholder="To"
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Method</label>
                            <select
                                v-model="paymentMethod"
                                @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="">All Methods</option>
                                <option v-for="method in paymentMethods" :key="method.value" :value="method.value">
                                    {{ method.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Platform -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Platform</label>
                            <select
                                v-model="platform"
                                @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="">All Platforms</option>
                                <option v-for="p in platforms" :key="p.value" :value="p.value">
                                    {{ p.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Amount Range -->
                        <div class="lg:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount Range</label>
                            <div class="mt-1 flex gap-2">
                                <div class="relative flex-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">$</span>
                                    <input
                                        v-model="minAmount"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        @change="applyFilters"
                                        placeholder="Min"
                                        class="block w-full rounded-md border-gray-300 pl-7 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                                <span class="flex items-center text-gray-400">to</span>
                                <div class="relative flex-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">$</span>
                                    <input
                                        v-model="maxAmount"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        @change="applyFilters"
                                        placeholder="Max"
                                        class="block w-full rounded-md border-gray-300 pl-7 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                            <select
                                v-model="status"
                                @change="applyFilters"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="">All Statuses</option>
                                <option v-for="s in statuses" :key="s.value" :value="s.value">
                                    {{ s.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div v-if="hasActiveFilters" class="mt-4 flex justify-end">
                        <button
                            type="button"
                            @click="clearFilters"
                            class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                        >
                            <XMarkIcon class="size-4" />
                            Clear Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- Payments Table -->
            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    <button
                                        type="button"
                                        @click="toggleSort('id')"
                                        class="group inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200"
                                    >
                                        Payment
                                        <component :is="getSortIcon('id')" class="size-4" />
                                    </button>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    <button
                                        type="button"
                                        @click="toggleSort('payment_method')"
                                        class="group inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200"
                                    >
                                        Method
                                        <component :is="getSortIcon('payment_method')" class="size-4" />
                                    </button>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Source
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    Customer
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    <button
                                        type="button"
                                        @click="toggleSort('amount')"
                                        class="group inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200"
                                    >
                                        Amount
                                        <component :is="getSortIcon('amount')" class="size-4" />
                                    </button>
                                </th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    <button
                                        type="button"
                                        @click="toggleSort('status')"
                                        class="group inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200"
                                    >
                                        Status
                                        <component :is="getSortIcon('status')" class="size-4" />
                                    </button>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    <button
                                        type="button"
                                        @click="toggleSort('created_at')"
                                        class="group inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200"
                                    >
                                        Date
                                        <component :is="getSortIcon('created_at')" class="size-4" />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr
                                v-for="payment in payments.data"
                                :key="payment.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                            >
                                <td class="whitespace-nowrap px-6 py-4">
                                    <Link :href="`/payments/${payment.id}`" class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                        #{{ payment.id }}
                                    </Link>
                                    <p v-if="payment.reference" class="text-sm text-gray-500 dark:text-gray-400">
                                        Ref: {{ payment.reference }}
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <component :is="methodIcons[payment.payment_method] || BanknotesIcon" class="size-5 text-gray-400" />
                                        <span class="text-sm text-gray-900 dark:text-white">
                                            {{ methodLabels[payment.payment_method] || payment.payment_method }}
                                        </span>
                                    </div>
                                    <p v-if="payment.gateway" class="text-xs text-gray-500 dark:text-gray-400">
                                        via {{ payment.gateway }}
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <Link v-if="payment.payable" :href="getPayableLink(payment)" class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                        {{ getPayableLabel(payment) }}
                                    </Link>
                                    <Link v-else-if="payment.invoice" :href="`/invoices/${payment.invoice.id}`" class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                        {{ payment.invoice.invoice_number }}
                                    </Link>
                                    <span v-else class="text-sm text-gray-500 dark:text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <Link v-if="payment.customer" :href="`/customers/${payment.customer.id}`" class="text-sm text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ payment.customer.full_name }}
                                    </Link>
                                    <span v-else class="text-sm text-gray-500 dark:text-gray-400">-</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(payment.amount) }}</span>
                                    <p v-if="payment.service_fee_amount" class="text-xs text-gray-500 dark:text-gray-400">
                                        +{{ formatCurrency(payment.service_fee_amount) }} fee
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[payment.status]]">
                                        {{ payment.status.replace('_', ' ') }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    {{ payment.paid_at ? formatDate(payment.paid_at) : formatDate(payment.created_at) }}
                                </td>
                            </tr>
                            <tr v-if="payments.data.length === 0">
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <BanknotesIcon class="mx-auto size-12 text-gray-400" />
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No payments found</p>
                                    <p v-if="hasActiveFilters" class="mt-1 text-sm text-gray-400 dark:text-gray-500">
                                        Try adjusting your filters
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="payments.last_page > 1" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800 sm:px-6">
                    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                        <p class="text-sm text-gray-700 dark:text-gray-400">
                            Showing
                            <span class="font-medium">{{ (payments.current_page - 1) * payments.per_page + 1 }}</span>
                            to
                            <span class="font-medium">{{ Math.min(payments.current_page * payments.per_page, payments.total) }}</span>
                            of
                            <span class="font-medium">{{ payments.total }}</span>
                            results
                        </p>
                        <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                            <Link
                                v-for="link in payments.links"
                                :key="link.label"
                                :href="link.url || '#'"
                                :class="[
                                    'relative inline-flex items-center px-4 py-2 text-sm font-medium',
                                    link.active
                                        ? 'z-10 bg-indigo-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'
                                        : 'text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700',
                                    !link.url && 'cursor-not-allowed opacity-50',
                                ]"
                                v-html="link.label"
                            />
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
