<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import {
    PlusIcon,
    DocumentTextIcon,
    BuildingOffice2Icon,
    BuildingStorefrontIcon,
    ClockIcon,
    ArrowDownTrayIcon,
} from '@heroicons/vue/20/solid';
import TableFilters, { type FilterDefinition, type FilterValues } from '@/components/widgets/TableFilters.vue';

interface Vendor {
    id: number;
    name: string;
    code: string | null;
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

interface PurchaseOrder {
    id: number;
    po_number: string;
    status: string;
    vendor: Vendor | null;
    warehouse: Warehouse | null;
    created_by: User | null;
    total: number;
    items_count: number;
    order_date: string | null;
    expected_date: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedPurchaseOrders {
    data: PurchaseOrder[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

interface Filters {
    search: string;
    status: string | null;
    vendor_id: number | null;
}

interface Props {
    purchaseOrders: PaginatedPurchaseOrders;
    vendors: Vendor[];
    warehouses: Warehouse[];
    statuses: string[];
    filters: Filters;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchase Orders', href: '/purchase-orders' },
];

// Filter state
const searchQuery = ref(props.filters.search || '');
const filterValues = ref<FilterValues>({
    status: props.filters.status || null,
    vendor_id: props.filters.vendor_id ? String(props.filters.vendor_id) : null,
});

// Filter definitions
const filterDefinitions = computed<FilterDefinition[]>(() => [
    {
        key: 'status',
        label: 'Status',
        type: 'radio',
        options: props.statuses.map(status => ({
            value: status,
            label: formatStatus(status),
        })),
    },
    {
        key: 'vendor_id',
        label: 'Vendor',
        type: 'radio',
        searchable: true,
        options: props.vendors.map(vendor => ({
            value: String(vendor.id),
            label: vendor.name,
        })),
    },
]);

// Apply filters when filter values change
watch(filterValues, (newValues) => {
    applyFilters(newValues);
}, { deep: true });

const applyFilters = (values?: FilterValues) => {
    const currentValues = values || filterValues.value;
    router.get('/purchase-orders', {
        search: searchQuery.value || undefined,
        status: currentValues.status || undefined,
        vendor_id: currentValues.vendor_id || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['purchaseOrders'],
    });
};

function handleSearch(value: string) {
    searchQuery.value = value;
    applyFilters();
}

function handleFilterChange(key: string, value: string | number | (string | number)[] | null) {
    filterValues.value = { ...filterValues.value, [key]: value };
}

const hasFilters = () => searchQuery.value || filterValues.value.status || filterValues.value.vendor_id;

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

// ===== TOTALS =====
const totals = computed(() => ({
    total: props.purchaseOrders.data.reduce((sum, po) => sum + (po.total || 0), 0),
    items: props.purchaseOrders.data.reduce((sum, po) => sum + (po.items_count || 0), 0),
}));

// ===== EXPORT =====
const isExporting = ref(false);

const getExportFilename = () => {
    const parts = ['purchase-orders'];

    // Add status to filename
    if (filterValues.value.status) {
        parts.push(String(filterValues.value.status));
    }

    // Add vendor name to filename
    if (filterValues.value.vendor_id) {
        const vendor = props.vendors.find(v => v.id === Number(filterValues.value.vendor_id));
        if (vendor) {
            // Sanitize vendor name for filename
            const vendorName = vendor.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
            parts.push(vendorName);
        }
    }

    // Add search term if present
    if (searchQuery.value) {
        const searchTerm = searchQuery.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
        if (searchTerm) {
            parts.push(`search-${searchTerm}`);
        }
    }

    // Add date
    const timestamp = new Date().toISOString().split('T')[0];
    parts.push(timestamp);

    return parts.join('-') + '.csv';
};

const exportToCsv = () => {
    if (props.purchaseOrders.data.length === 0) return;

    isExporting.value = true;

    try {
        const headers = ['PO #', 'Vendor', 'Warehouse', 'Status', 'Items', 'Total', 'Order Date', 'Expected Date', 'Created At', 'Created By'];

        const rows = props.purchaseOrders.data.map(po => [
            po.po_number,
            po.vendor?.name || '',
            po.warehouse?.name || '',
            formatStatus(po.status),
            po.items_count,
            po.total.toFixed(2),
            po.order_date || '',
            po.expected_date || '',
            po.created_at,
            po.created_by?.name || '',
        ]);

        // Add totals row
        rows.push([
            'TOTALS',
            '',
            '',
            '',
            String(totals.value.items),
            totals.value.total.toFixed(2),
            '',
            '',
            '',
            '',
        ]);

        const csvContent = [
            headers.map(h => `"${h}"`).join(','),
            ...rows.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')),
        ].join('\n');

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);

        link.setAttribute('href', url);
        link.setAttribute('download', getExportFilename());
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } finally {
        isExporting.value = false;
    }
};
</script>

<template>
    <Head title="Purchase Orders" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Purchase Orders</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ purchaseOrders.total }} order{{ purchaseOrders.total === 1 ? '' : 's' }} total
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Export Button -->
                    <button
                        v-if="purchaseOrders.data.length > 0"
                        type="button"
                        :disabled="isExporting"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                        @click="exportToCsv"
                    >
                        <ArrowDownTrayIcon class="-ml-0.5 size-5" />
                        {{ isExporting ? 'Exporting...' : 'Export' }}
                    </button>
                    <Link
                        href="/purchase-orders/create"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        <PlusIcon class="-ml-0.5 size-5" />
                        Create PO
                    </Link>
                </div>
            </div>

            <!-- Filters -->
            <TableFilters
                v-model="filterValues"
                :filters="filterDefinitions"
                :searchable="true"
                :search-value="searchQuery"
                search-placeholder="Search by PO# or vendor..."
                @search="handleSearch"
                @change="handleFilterChange"
            />

            <!-- Purchase Order List -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                PO #
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white lg:table-cell">
                                Vendor
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white md:table-cell">
                                Warehouse
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Status
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                Total
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white xl:table-cell">
                                Created
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="po in purchaseOrders.data" :key="po.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="py-4 pl-4 pr-3 sm:pl-6">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                        <DocumentTextIcon class="size-5 text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <div class="min-w-0">
                                        <Link
                                            :href="`/purchase-orders/${po.id}`"
                                            class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                        >
                                            {{ po.po_number }}
                                        </Link>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ po.items_count }} item{{ po.items_count === 1 ? '' : 's' }}
                                        </div>
                                        <!-- Mobile info -->
                                        <div class="mt-1 lg:hidden">
                                            <div v-if="po.vendor" class="flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                                                <BuildingOffice2Icon class="size-3.5" />
                                                {{ po.vendor.name }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-3 py-4 lg:table-cell">
                                <div v-if="po.vendor" class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400">
                                    <BuildingOffice2Icon class="size-4" />
                                    {{ po.vendor.name }}
                                </div>
                                <span v-else class="text-sm text-gray-400 dark:text-gray-500">
                                    -
                                </span>
                            </td>
                            <td class="hidden px-3 py-4 md:table-cell">
                                <div v-if="po.warehouse" class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400">
                                    <BuildingStorefrontIcon class="size-4" />
                                    {{ po.warehouse.name }}
                                </div>
                                <span v-else class="text-sm text-gray-400 dark:text-gray-500">
                                    -
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <span
                                    :class="[
                                        'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
                                        getStatusColor(po.status),
                                    ]"
                                >
                                    {{ formatStatus(po.status) }}
                                </span>
                            </td>
                            <td class="hidden whitespace-nowrap px-3 py-4 text-right text-sm font-medium text-gray-900 dark:text-white sm:table-cell">
                                {{ formatCurrency(po.total) }}
                            </td>
                            <td class="hidden whitespace-nowrap px-3 py-4 xl:table-cell">
                                <div class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                                    <ClockIcon class="size-4" />
                                    {{ po.created_at }}
                                </div>
                                <div v-if="po.created_by" class="text-sm text-gray-400 dark:text-gray-500">
                                    by {{ po.created_by.name }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="purchaseOrders.data.length === 0">
                            <td colspan="6" class="py-12 text-center">
                                <DocumentTextIcon class="mx-auto size-12 text-gray-400" />
                                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No purchase orders</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ hasFilters() ? 'No purchase orders match your filters.' : 'Get started by creating your first purchase order.' }}
                                </p>
                                <div v-if="!hasFilters()" class="mt-6">
                                    <Link
                                        href="/purchase-orders/create"
                                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    >
                                        <PlusIcon class="-ml-0.5 size-5" />
                                        Create PO
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    <!-- Totals Footer -->
                    <tfoot v-if="purchaseOrders.data.length > 0" class="bg-gray-100 dark:bg-gray-700 border-t-2 border-gray-300 dark:border-gray-600">
                        <tr>
                            <td class="py-3.5 pl-4 pr-3 sm:pl-6 text-sm font-semibold text-gray-900 dark:text-white">
                                Totals ({{ purchaseOrders.data.length }} orders)
                            </td>
                            <td class="hidden px-3 py-3.5 lg:table-cell"></td>
                            <td class="hidden px-3 py-3.5 md:table-cell"></td>
                            <td class="px-3 py-3.5"></td>
                            <td class="hidden whitespace-nowrap px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                {{ formatCurrency(totals.total) }}
                            </td>
                            <td class="hidden px-3 py-3.5 xl:table-cell"></td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Pagination -->
                <nav
                    v-if="purchaseOrders.last_page > 1"
                    class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800 sm:px-6"
                >
                    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                Showing
                                <span class="font-medium">{{ (purchaseOrders.current_page - 1) * purchaseOrders.per_page + 1 }}</span>
                                to
                                <span class="font-medium">{{ Math.min(purchaseOrders.current_page * purchaseOrders.per_page, purchaseOrders.total) }}</span>
                                of
                                <span class="font-medium">{{ purchaseOrders.total }}</span>
                                results
                            </p>
                        </div>
                        <div>
                            <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                <template v-for="(link, index) in purchaseOrders.links" :key="index">
                                    <Link
                                        v-if="link.url"
                                        :href="link.url"
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-gray-300 focus:z-20 focus:outline-offset-0 dark:ring-gray-600',
                                            link.active
                                                ? 'z-10 bg-indigo-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'
                                                : 'text-gray-900 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700',
                                            index === 0 ? 'rounded-l-md' : '',
                                            index === purchaseOrders.links.length - 1 ? 'rounded-r-md' : '',
                                        ]"
                                        v-html="link.label"
                                        preserve-scroll
                                    />
                                    <span
                                        v-else
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-gray-300 dark:ring-gray-600 text-gray-400 dark:text-gray-500',
                                            index === 0 ? 'rounded-l-md' : '',
                                            index === purchaseOrders.links.length - 1 ? 'rounded-r-md' : '',
                                        ]"
                                        v-html="link.label"
                                    />
                                </template>
                            </nav>
                        </div>
                    </div>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
