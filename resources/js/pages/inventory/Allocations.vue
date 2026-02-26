<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MagnifyingGlassIcon,
    ArrowsRightLeftIcon,
    PlusIcon,
    XMarkIcon,
    TruckIcon,
    CheckCircleIcon,
    PaperAirplaneIcon,
    XCircleIcon,
} from '@heroicons/vue/20/solid';
import { ref, watch, computed } from 'vue';
import { useDebounceFn } from '@vueuse/core';

interface Warehouse {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
}

interface DistributionRow {
    variant_id: number;
    product_id: number;
    product_title: string;
    variant_title: string | null;
    sku: string;
    warehouse_quantities: Record<number, number>;
    total_quantity: number;
}

interface TransferItem {
    id: number;
    variant_id: number;
    product_title: string;
    variant_title: string | null;
    sku: string;
    quantity_requested: number;
    quantity_shipped: number;
    quantity_received: number;
}

interface Transfer {
    id: number;
    reference: string;
    from_warehouse: { id: number; name: string } | null;
    to_warehouse: { id: number; name: string } | null;
    status: string;
    total_items: number;
    items: TransferItem[];
    notes: string | null;
    expected_at: string | null;
    shipped_at: string | null;
    received_at: string | null;
    created_by: string | null;
    created_at: string;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    distribution: DistributionRow[];
    warehouses: Warehouse[];
    transfers: PaginatedData<Transfer>;
    stats: {
        total_warehouses: number;
        items_in_transit: number;
        pending_transfers: number;
        total_allocated_value: number;
    };
    filters: {
        search: string;
        status: string;
        transfer_search: string;
    };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Allocations', href: '/inventory/allocations' },
];

// Tab state
const activeTab = ref<'distribution' | 'transfers'>('distribution');

// Distribution filters
const search = ref(props.filters.search);

// Transfer filters
const statusFilter = ref(props.filters.status);
const transferSearch = ref(props.filters.transfer_search);

// Create transfer modal
const showCreateModal = ref(false);
const createForm = ref({
    from_warehouse_id: null as number | null,
    to_warehouse_id: null as number | null,
    notes: '',
    expected_at: '',
    items: [] as { product_variant_id: number; quantity_requested: number; sku: string; product_title: string }[],
});
const createProcessing = ref(false);
const createErrors = ref<Record<string, string>>({});

// Receive modal
const showReceiveModal = ref(false);
const receiveTransfer = ref<Transfer | null>(null);
const receiveQuantities = ref<Record<number, number>>({});
const receiveProcessing = ref(false);

// Variant search for transfer items
const variantSearchQuery = ref('');
const variantSearchResults = ref<DistributionRow[]>([]);
const showVariantDropdown = ref(false);

const debouncedSearch = useDebounceFn(() => {
    applyDistributionFilters();
}, 300);

const debouncedTransferSearch = useDebounceFn(() => {
    applyTransferFilters();
}, 300);

watch(search, () => {
    debouncedSearch();
});

watch(statusFilter, () => {
    applyTransferFilters();
});

watch(transferSearch, () => {
    debouncedTransferSearch();
});

// Filter variant search results from distribution data
watch(variantSearchQuery, (query) => {
    if (query.length < 2) {
        variantSearchResults.value = [];
        showVariantDropdown.value = false;
        return;
    }
    const q = query.toLowerCase();
    variantSearchResults.value = props.distribution.filter(
        (row) =>
            (row.sku && row.sku.toLowerCase().includes(q)) ||
            row.product_title.toLowerCase().includes(q),
    ).slice(0, 10);
    showVariantDropdown.value = variantSearchResults.value.length > 0;
});

function applyDistributionFilters() {
    router.get(
        '/inventory/allocations',
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
            transfer_search: transferSearch.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function applyTransferFilters() {
    router.get(
        '/inventory/allocations',
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
            transfer_search: transferSearch.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function formatCurrency(value: number | null) {
    if (value === null || value === undefined) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
}

function statusLabel(status: string) {
    const labels: Record<string, string> = {
        draft: 'Draft',
        pending: 'Pending',
        in_transit: 'In Transit',
        received: 'Received',
        cancelled: 'Cancelled',
    };
    return labels[status] || status;
}

function statusClass(status: string) {
    const classes: Record<string, string> = {
        draft: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        in_transit: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        received: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        cancelled: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    };
    return classes[status] || '';
}

// Create transfer modal
function openCreateModal(prefilledVariant?: DistributionRow) {
    createForm.value = {
        from_warehouse_id: null,
        to_warehouse_id: null,
        notes: '',
        expected_at: '',
        items: [],
    };
    createErrors.value = {};

    if (prefilledVariant) {
        createForm.value.items.push({
            product_variant_id: prefilledVariant.variant_id,
            quantity_requested: 1,
            sku: prefilledVariant.sku,
            product_title: prefilledVariant.product_title,
        });
    }

    showCreateModal.value = true;
}

function addVariantToTransfer(row: DistributionRow) {
    if (createForm.value.items.some((i) => i.product_variant_id === row.variant_id)) {
        return;
    }
    createForm.value.items.push({
        product_variant_id: row.variant_id,
        quantity_requested: 1,
        sku: row.sku,
        product_title: row.product_title,
    });
    variantSearchQuery.value = '';
    showVariantDropdown.value = false;
}

function removeTransferItem(index: number) {
    createForm.value.items.splice(index, 1);
}

const canSubmitCreate = computed(() => {
    return (
        createForm.value.from_warehouse_id &&
        createForm.value.to_warehouse_id &&
        createForm.value.from_warehouse_id !== createForm.value.to_warehouse_id &&
        createForm.value.items.length > 0 &&
        createForm.value.items.every((i) => i.quantity_requested > 0)
    );
});

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] || '',
    );
}

function submitCreateTransfer(asDraft: boolean) {
    if (createProcessing.value || !canSubmitCreate.value) return;

    createProcessing.value = true;
    createErrors.value = {};

    const payload = {
        from_warehouse_id: createForm.value.from_warehouse_id,
        to_warehouse_id: createForm.value.to_warehouse_id,
        notes: createForm.value.notes || null,
        expected_at: createForm.value.expected_at || null,
        items: createForm.value.items.map((i) => ({
            product_variant_id: i.product_variant_id,
            quantity_requested: i.quantity_requested,
        })),
    };

    fetch('/api/v1/inventory-transfers', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'include',
        body: JSON.stringify(payload),
    })
        .then(async (response) => {
            if (!response.ok) {
                const data = await response.json();
                createErrors.value = data.errors || { general: data.message || 'An error occurred' };
                return;
            }

            const transfer = await response.json();

            if (!asDraft) {
                // Submit the transfer immediately
                await fetch(`/api/v1/inventory-transfers/${transfer.id}/submit`, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'include',
                });
            }

            showCreateModal.value = false;
            router.reload();
        })
        .catch(() => {
            createErrors.value = { general: 'An unexpected error occurred' };
        })
        .finally(() => {
            createProcessing.value = false;
        });
}

// Transfer actions
function submitTransfer(transfer: Transfer) {
    fetch(`/api/v1/inventory-transfers/${transfer.id}/submit`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        credentials: 'include',
    }).then(() => router.reload());
}

function shipTransfer(transfer: Transfer) {
    fetch(`/api/v1/inventory-transfers/${transfer.id}/ship`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        credentials: 'include',
    }).then(() => router.reload());
}

function cancelTransfer(transfer: Transfer) {
    if (!confirm('Are you sure you want to cancel this transfer?')) return;

    fetch(`/api/v1/inventory-transfers/${transfer.id}/cancel`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        credentials: 'include',
    }).then(() => router.reload());
}

function openReceiveModal(transfer: Transfer) {
    receiveTransfer.value = transfer;
    receiveQuantities.value = {};
    transfer.items.forEach((item) => {
        receiveQuantities.value[item.id] = item.quantity_shipped;
    });
    showReceiveModal.value = true;
}

function submitReceive() {
    if (receiveProcessing.value || !receiveTransfer.value) return;

    receiveProcessing.value = true;

    fetch(`/api/v1/inventory-transfers/${receiveTransfer.value.id}/receive`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'include',
        body: JSON.stringify({ quantities: receiveQuantities.value }),
    })
        .then(() => {
            showReceiveModal.value = false;
            receiveTransfer.value = null;
            router.reload();
        })
        .finally(() => {
            receiveProcessing.value = false;
        });
}
</script>

<template>
    <Head title="Inventory Allocation" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Inventory Allocation</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        View stock distribution across warehouses and manage transfers
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    @click="openCreateModal()"
                >
                    <PlusIcon class="size-5" />
                    Create Transfer
                </button>
            </div>

            <!-- Stats -->
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Warehouses</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.total_warehouses }}</p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Items in Transit</p>
                    <p class="mt-1 text-2xl font-semibold" :class="stats.items_in_transit > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-900 dark:text-white'">
                        {{ stats.items_in_transit }}
                    </p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Pending Transfers</p>
                    <p class="mt-1 text-2xl font-semibold" :class="stats.pending_transfers > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white'">
                        {{ stats.pending_transfers }}
                    </p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Allocated Value</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ formatCurrency(stats.total_allocated_value) }}</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="mb-4 border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex gap-6" aria-label="Tabs">
                    <button
                        type="button"
                        class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium"
                        :class="activeTab === 'distribution' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                        @click="activeTab = 'distribution'"
                    >
                        Stock Distribution
                    </button>
                    <button
                        type="button"
                        class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium"
                        :class="activeTab === 'transfers' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                        @click="activeTab = 'transfers'"
                    >
                        Transfers
                        <span
                            v-if="stats.pending_transfers + stats.items_in_transit > 0"
                            class="ml-2 inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400"
                        >
                            {{ stats.pending_transfers + stats.items_in_transit }}
                        </span>
                    </button>
                </nav>
            </div>

            <!-- Tab: Stock Distribution -->
            <div v-if="activeTab === 'distribution'">
                <!-- Search filter -->
                <div class="mb-4 flex flex-wrap items-center gap-4">
                    <div class="relative flex-1 sm:max-w-xs">
                        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search by SKU or product..."
                            class="block w-full rounded-md border-0 bg-white py-1.5 pl-10 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        />
                    </div>
                </div>

                <!-- Distribution table -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="overflow-x-auto">
                        <table v-if="distribution.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        Product / SKU
                                    </th>
                                    <th
                                        v-for="warehouse in warehouses"
                                        :key="warehouse.id"
                                        scope="col"
                                        class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                    >
                                        {{ warehouse.name }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        Total
                                    </th>
                                    <th scope="col" class="relative px-4 py-3">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="row in distribution" :key="row.variant_id">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div>
                                            <Link
                                                :href="`/products/${row.product_id}`"
                                                class="text-sm font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                            >
                                                {{ row.product_title }}
                                            </Link>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ row.sku }}
                                                <span v-if="row.variant_title"> - {{ row.variant_title }}</span>
                                            </p>
                                        </div>
                                    </td>
                                    <td
                                        v-for="warehouse in warehouses"
                                        :key="warehouse.id"
                                        class="whitespace-nowrap px-4 py-3 text-right text-sm"
                                        :class="row.warehouse_quantities[warehouse.id] > 0 ? 'font-medium text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500'"
                                    >
                                        {{ row.warehouse_quantities[warehouse.id] || 0 }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ row.total_quantity }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                            @click="openCreateModal(row)"
                                        >
                                            <ArrowsRightLeftIcon class="size-4" />
                                            Allocate
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Empty state -->
                        <div v-else class="px-6 py-12 text-center">
                            <ArrowsRightLeftIcon class="mx-auto h-12 w-12 text-gray-400" />
                            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No inventory data</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ filters.search ? 'No items match your search.' : 'Add inventory to see stock distribution across warehouses.' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Transfers -->
            <div v-if="activeTab === 'transfers'">
                <!-- Filters -->
                <div class="mb-4 flex flex-wrap items-center gap-4">
                    <div class="flex-1 sm:w-48 sm:flex-none">
                        <select
                            v-model="statusFilter"
                            class="block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        >
                            <option value="">All Statuses</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending</option>
                            <option value="in_transit">In Transit</option>
                            <option value="received">Received</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="relative flex-1 sm:max-w-xs">
                        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <input
                            v-model="transferSearch"
                            type="text"
                            placeholder="Search by reference..."
                            class="block w-full rounded-md border-0 bg-white py-1.5 pl-10 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        />
                    </div>
                </div>

                <!-- Transfers table -->
                <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="overflow-x-auto">
                        <table v-if="transfers.data.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Reference</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">From / To</th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Items</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Created</th>
                                    <th scope="col" class="relative px-4 py-3">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="transfer in transfers.data" :key="transfer.id">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ transfer.reference }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                        {{ transfer.from_warehouse?.name || '-' }}
                                        <span class="mx-1 text-gray-400">&rarr;</span>
                                        {{ transfer.to_warehouse?.name || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                        {{ transfer.total_items }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(transfer.status)">
                                            {{ statusLabel(transfer.status) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                        {{ transfer.created_at }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                        <div class="flex items-center justify-end gap-2">
                                            <!-- Draft actions -->
                                            <template v-if="transfer.status === 'draft'">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                    title="Submit"
                                                    @click="submitTransfer(transfer)"
                                                >
                                                    <PaperAirplaneIcon class="size-4" />
                                                    Submit
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                                    title="Cancel"
                                                    @click="cancelTransfer(transfer)"
                                                >
                                                    <XCircleIcon class="size-4" />
                                                    Cancel
                                                </button>
                                            </template>

                                            <!-- Pending actions -->
                                            <template v-if="transfer.status === 'pending'">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                    title="Ship"
                                                    @click="shipTransfer(transfer)"
                                                >
                                                    <TruckIcon class="size-4" />
                                                    Ship
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                                    title="Cancel"
                                                    @click="cancelTransfer(transfer)"
                                                >
                                                    <XCircleIcon class="size-4" />
                                                    Cancel
                                                </button>
                                            </template>

                                            <!-- In Transit actions -->
                                            <template v-if="transfer.status === 'in_transit'">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300"
                                                    title="Receive"
                                                    @click="openReceiveModal(transfer)"
                                                >
                                                    <CheckCircleIcon class="size-4" />
                                                    Receive
                                                </button>
                                            </template>

                                            <!-- Received / Cancelled - view only -->
                                            <template v-if="transfer.status === 'received' || transfer.status === 'cancelled'">
                                                <span class="text-xs text-gray-400 dark:text-gray-500">No actions</span>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Empty state -->
                        <div v-else class="px-6 py-12 text-center">
                            <TruckIcon class="mx-auto h-12 w-12 text-gray-400" />
                            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No transfers</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ filters.status || filters.transfer_search ? 'No transfers match your filters.' : 'Create your first transfer to move inventory between warehouses.' }}
                            </p>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="transfers.last_page > 1" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                        <div class="text-sm text-gray-700 dark:text-gray-300">
                            Showing {{ (transfers.current_page - 1) * transfers.per_page + 1 }} to
                            {{ Math.min(transfers.current_page * transfers.per_page, transfers.total) }} of {{ transfers.total }} results
                        </div>
                        <div class="flex gap-1">
                            <template v-for="link in transfers.links" :key="link.label">
                                <Link
                                    v-if="link.url"
                                    :href="link.url"
                                    class="rounded-md px-3 py-1 text-sm"
                                    :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                                    v-html="link.label"
                                />
                                <span v-else class="rounded-md px-3 py-1 text-sm text-gray-400" v-html="link.label" />
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Transfer Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Create Transfer</h3>
                                <button type="button" class="text-gray-400 hover:text-gray-500" @click="showCreateModal = false">
                                    <XMarkIcon class="size-6" />
                                </button>
                            </div>

                            <div v-if="createErrors.general" class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                {{ createErrors.general }}
                            </div>

                            <div class="mt-5 space-y-4">
                                <!-- Warehouses -->
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">From Warehouse</label>
                                        <select
                                            v-model="createForm.from_warehouse_id"
                                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">Select warehouse...</option>
                                            <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
                                        </select>
                                        <p v-if="createErrors.from_warehouse_id" class="mt-1 text-xs text-red-600">{{ createErrors.from_warehouse_id }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">To Warehouse</label>
                                        <select
                                            v-model="createForm.to_warehouse_id"
                                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">Select warehouse...</option>
                                            <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
                                        </select>
                                        <p v-if="createErrors.to_warehouse_id" class="mt-1 text-xs text-red-600">{{ createErrors.to_warehouse_id }}</p>
                                    </div>
                                </div>

                                <!-- Items -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Items</label>
                                    <div class="relative mt-1">
                                        <input
                                            v-model="variantSearchQuery"
                                            type="text"
                                            placeholder="Search by SKU or product name to add..."
                                            class="block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            @focus="showVariantDropdown = variantSearchResults.length > 0"
                                            @blur="setTimeout(() => (showVariantDropdown = false), 200)"
                                        />
                                        <div
                                            v-if="showVariantDropdown"
                                            class="absolute z-10 mt-1 max-h-40 w-full overflow-auto rounded-md bg-white shadow-lg ring-1 ring-black/5 dark:bg-gray-700 dark:ring-white/10"
                                        >
                                            <button
                                                v-for="result in variantSearchResults"
                                                :key="result.variant_id"
                                                type="button"
                                                class="block w-full px-4 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-600"
                                                @mousedown.prevent="addVariantToTransfer(result)"
                                            >
                                                <span class="font-medium text-gray-900 dark:text-white">{{ result.product_title }}</span>
                                                <span class="ml-2 text-gray-500 dark:text-gray-400">{{ result.sku }}</span>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Items list -->
                                    <div v-if="createForm.items.length > 0" class="mt-2 space-y-2">
                                        <div
                                            v-for="(item, index) in createForm.items"
                                            :key="item.product_variant_id"
                                            class="flex items-center gap-3 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-700/50"
                                        >
                                            <div class="flex-1">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ item.product_title }}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ item.sku }}</p>
                                            </div>
                                            <input
                                                v-model.number="item.quantity_requested"
                                                type="number"
                                                min="1"
                                                class="w-20 rounded-md border-0 bg-white py-1 text-center text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-600 dark:text-white dark:ring-gray-500"
                                            />
                                            <button type="button" class="text-red-500 hover:text-red-700" @click="removeTransferItem(index)">
                                                <XMarkIcon class="size-5" />
                                            </button>
                                        </div>
                                    </div>
                                    <p v-else class="mt-2 text-xs text-gray-400 dark:text-gray-500">Search above to add items to this transfer.</p>
                                </div>

                                <!-- Notes -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (optional)</label>
                                    <textarea
                                        v-model="createForm.notes"
                                        rows="2"
                                        class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>

                                <!-- Expected date -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Expected Date (optional)</label>
                                    <input
                                        v-model="createForm.expected_at"
                                        type="date"
                                        class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>

                            <div class="mt-5 flex justify-end gap-3 sm:mt-6">
                                <button
                                    type="button"
                                    class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showCreateModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="createProcessing || !canSubmitCreate"
                                    class="inline-flex justify-center rounded-md bg-gray-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500 disabled:opacity-50"
                                    @click="submitCreateTransfer(true)"
                                >
                                    {{ createProcessing ? 'Saving...' : 'Save as Draft' }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="createProcessing || !canSubmitCreate"
                                    class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                    @click="submitCreateTransfer(false)"
                                >
                                    {{ createProcessing ? 'Saving...' : 'Submit Transfer' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Receive Transfer Modal -->
        <Teleport to="body">
            <div v-if="showReceiveModal && receiveTransfer" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Receive Transfer {{ receiveTransfer.reference }}</h3>
                                <button type="button" class="text-gray-400 hover:text-gray-500" @click="showReceiveModal = false">
                                    <XMarkIcon class="size-6" />
                                </button>
                            </div>

                            <div class="mt-4">
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ receiveTransfer.from_warehouse?.name }}
                                    <span class="mx-1">&rarr;</span>
                                    {{ receiveTransfer.to_warehouse?.name }}
                                </p>
                            </div>

                            <div class="mt-4 space-y-3">
                                <div
                                    v-for="item in receiveTransfer.items"
                                    :key="item.id"
                                    class="flex items-center gap-4 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-700/50"
                                >
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ item.product_title }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ item.sku }} | Shipped: {{ item.quantity_shipped }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <label class="text-xs text-gray-500 dark:text-gray-400">Received</label>
                                        <input
                                            v-model.number="receiveQuantities[item.id]"
                                            type="number"
                                            min="0"
                                            :max="item.quantity_shipped"
                                            class="mt-1 block w-20 rounded-md border-0 bg-white py-1 text-center text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-600 dark:text-white dark:ring-gray-500"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 flex justify-end gap-3 sm:mt-6">
                                <button
                                    type="button"
                                    class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showReceiveModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="receiveProcessing"
                                    class="inline-flex justify-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50"
                                    @click="submitReceive"
                                >
                                    {{ receiveProcessing ? 'Processing...' : 'Confirm Receive' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
