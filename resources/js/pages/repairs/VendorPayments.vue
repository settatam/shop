<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    MagnifyingGlassIcon,
    FunnelIcon,
    ArrowDownTrayIcon,
    PaperClipIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
} from '@heroicons/vue/24/outline';

interface User {
    id: number;
    name: string;
}

interface Vendor {
    id: number;
    name: string;
    display_name?: string;
}

interface Repair {
    id: number;
    repair_number: string;
}

interface VendorPayment {
    id: number;
    repair_id: number;
    check_number?: string;
    amount: number;
    vendor_invoice_amount?: number;
    reason?: string;
    payment_date?: string;
    has_attachment: boolean;
    attachment_name?: string;
    created_at: string;
    repair?: Repair;
    vendor?: Vendor;
    user?: User;
}

interface VendorOption {
    value: number;
    label: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedPayments {
    data: VendorPayment[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Filters {
    vendor_id?: number | string;
    date_from?: string;
    date_to?: string;
    repair_number?: string;
    sort?: string;
    direction?: string;
}

interface Props {
    payments: PaginatedPayments;
    vendors: VendorOption[];
    filters: Filters;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Repairs', href: '/repairs' },
    { title: 'Vendor Payments', href: '/repair-vendor-payments' },
];

const localFilters = ref<Filters>({
    vendor_id: props.filters.vendor_id || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
    repair_number: props.filters.repair_number || '',
    sort: props.filters.sort || 'payment_date',
    direction: props.filters.direction || 'desc',
});

const showFilters = ref(false);

const debouncedSearch = useDebounceFn(() => {
    applyFilters();
}, 300);

watch(() => localFilters.value.repair_number, () => {
    debouncedSearch();
});

function applyFilters() {
    const params: Record<string, string | number> = {};

    if (localFilters.value.vendor_id) params.vendor_id = localFilters.value.vendor_id;
    if (localFilters.value.date_from) params.date_from = localFilters.value.date_from;
    if (localFilters.value.date_to) params.date_to = localFilters.value.date_to;
    if (localFilters.value.repair_number) params.repair_number = localFilters.value.repair_number;
    if (localFilters.value.sort) params.sort = localFilters.value.sort;
    if (localFilters.value.direction) params.direction = localFilters.value.direction;

    router.get('/repair-vendor-payments', params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    localFilters.value = {
        vendor_id: '',
        date_from: '',
        date_to: '',
        repair_number: '',
        sort: 'payment_date',
        direction: 'desc',
    };
    applyFilters();
}

function sortBy(field: string) {
    if (localFilters.value.sort === field) {
        localFilters.value.direction = localFilters.value.direction === 'asc' ? 'desc' : 'asc';
    } else {
        localFilters.value.sort = field;
        localFilters.value.direction = 'desc';
    }
    applyFilters();
}

function getSortIcon(field: string): string {
    if (localFilters.value.sort !== field) return '';
    return localFilters.value.direction === 'asc' ? '\u2191' : '\u2193';
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function formatDate(dateString: string | undefined): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function downloadAttachment(payment: VendorPayment) {
    window.location.href = `/repair-vendor-payments/${payment.id}/attachment`;
}

function goToPage(url: string | null) {
    if (url) {
        router.get(url, {}, { preserveState: true, preserveScroll: true });
    }
}

const hasActiveFilters = computed(() => {
    return !!(localFilters.value.vendor_id || localFilters.value.date_from || localFilters.value.date_to || localFilters.value.repair_number);
});

const totalAmount = computed(() => {
    return props.payments.data.reduce((sum, p) => sum + Number(p.amount), 0);
});
</script>

<template>
    <Head title="Vendor Payments" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-7xl">
                <!-- Header -->
                <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Vendor Payments</h1>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Track all payments made to vendors for repair work
                        </p>
                    </div>
                </div>

                <!-- Filters -->
                <div class="mb-6 rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <div class="flex flex-wrap items-center gap-4">
                        <!-- Search by repair number -->
                        <div class="flex-1 min-w-[200px]">
                            <div class="relative">
                                <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                <input
                                    v-model="localFilters.repair_number"
                                    type="text"
                                    placeholder="Search by repair number..."
                                    class="block w-full rounded-md border-0 py-2 pl-10 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium"
                            :class="[
                                showFilters || hasActiveFilters
                                    ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300'
                                    : 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600'
                            ]"
                            @click="showFilters = !showFilters"
                        >
                            <FunnelIcon class="size-4" />
                            Filters
                            <span v-if="hasActiveFilters" class="rounded-full bg-indigo-500 px-1.5 py-0.5 text-xs text-white">!</span>
                        </button>

                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                            @click="clearFilters"
                        >
                            Clear filters
                        </button>
                    </div>

                    <!-- Expanded filters -->
                    <div v-if="showFilters" class="mt-4 grid grid-cols-1 gap-4 border-t border-gray-200 pt-4 sm:grid-cols-3 dark:border-gray-700">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Vendor</label>
                            <select
                                v-model="localFilters.vendor_id"
                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                @change="applyFilters"
                            >
                                <option value="">All Vendors</option>
                                <option v-for="vendor in vendors" :key="vendor.value" :value="vendor.value">
                                    {{ vendor.label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">From Date</label>
                            <input
                                v-model="localFilters.date_from"
                                type="date"
                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                @change="applyFilters"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">To Date</label>
                            <input
                                v-model="localFilters.date_to"
                                type="date"
                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                @change="applyFilters"
                            />
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="mb-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                    <span>
                        Showing {{ payments.data.length }} of {{ payments.total }} payments
                    </span>
                    <span class="font-medium text-gray-900 dark:text-white">
                        Page Total: {{ formatCurrency(totalAmount) }}
                    </span>
                </div>

                <!-- Table -->
                <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 cursor-pointer hover:text-gray-700 dark:hover:text-gray-300"
                                        @click="sortBy('payment_date')"
                                    >
                                        Date {{ getSortIcon('payment_date') }}
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        Repair
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        Vendor
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 cursor-pointer hover:text-gray-700 dark:hover:text-gray-300"
                                        @click="sortBy('check_number')"
                                    >
                                        Check # {{ getSortIcon('check_number') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 cursor-pointer hover:text-gray-700 dark:hover:text-gray-300"
                                        @click="sortBy('amount')"
                                    >
                                        Amount {{ getSortIcon('amount') }}
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        Invoice Amt
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        Attachment
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                <tr v-for="payment in payments.data" :key="payment.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                        {{ formatDate(payment.payment_date) }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <Link
                                            v-if="payment.repair"
                                            :href="`/repairs/${payment.repair.id}`"
                                            class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                        >
                                            {{ payment.repair.repair_number }}
                                        </Link>
                                        <span v-else class="text-sm text-gray-500 dark:text-gray-400">-</span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900 dark:text-white">
                                        {{ payment.vendor?.display_name || payment.vendor?.name || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                        {{ payment.check_number || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(payment.amount) }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-gray-500 dark:text-gray-400">
                                        {{ payment.vendor_invoice_amount ? formatCurrency(payment.vendor_invoice_amount) : '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-center">
                                        <button
                                            v-if="payment.has_attachment"
                                            type="button"
                                            class="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                            @click="downloadAttachment(payment)"
                                        >
                                            <ArrowDownTrayIcon class="size-4" />
                                        </button>
                                        <span v-else class="text-gray-300 dark:text-gray-600">-</span>
                                    </td>
                                </tr>
                                <tr v-if="payments.data.length === 0">
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                        No vendor payments found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="payments.last_page > 1" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800 sm:px-6">
                        <div class="flex flex-1 justify-between sm:hidden">
                            <button
                                type="button"
                                :disabled="!payments.prev_page_url"
                                class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                @click="goToPage(payments.prev_page_url)"
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                :disabled="!payments.next_page_url"
                                class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                @click="goToPage(payments.next_page_url)"
                            >
                                Next
                            </button>
                        </div>
                        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700 dark:text-gray-300">
                                    Page <span class="font-medium">{{ payments.current_page }}</span> of
                                    <span class="font-medium">{{ payments.last_page }}</span>
                                </p>
                            </div>
                            <div>
                                <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                    <button
                                        type="button"
                                        :disabled="!payments.prev_page_url"
                                        class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:ring-gray-600 dark:hover:bg-gray-700"
                                        @click="goToPage(payments.prev_page_url)"
                                    >
                                        <ChevronLeftIcon class="size-5" />
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="!payments.next_page_url"
                                        class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:ring-gray-600 dark:hover:bg-gray-700"
                                        @click="goToPage(payments.next_page_url)"
                                    >
                                        <ChevronRightIcon class="size-5" />
                                    </button>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
