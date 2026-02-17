<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    PencilIcon,
    TrashIcon,
    BuildingOffice2Icon,
    EnvelopeIcon,
    PhoneIcon,
    GlobeAltIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArrowDownTrayIcon,
    ChevronUpIcon,
    ChevronDownIcon,
    ChevronUpDownIcon,
} from '@heroicons/vue/20/solid';
import { useDebounceFn } from '@vueuse/core';

interface Vendor {
    id: number;
    name: string;
    code: string | null;
    company_name: string | null;
    display_name: string;
    email: string | null;
    phone: string | null;
    contact_name: string | null;
    address_line1: string | null;
    city: string | null;
    state: string | null;
    country: string | null;
    payment_terms: string | null;
    is_active: boolean;
    purchase_orders_count: number;
    product_variants_count: number;
    memo_total: number;
    repair_total: number;
    sales_total: number;
    last_transaction_date: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedVendors {
    data: Vendor[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

interface Filters {
    search: string;
    is_active: boolean | null;
    per_page: number;
    sort: string;
    direction: string;
}

interface Props {
    vendors: PaginatedVendors;
    filters: Filters;
    paymentTerms: string[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Vendors', href: '/vendors' },
];

// Filter state
const searchQuery = ref(props.filters.search || '');
const activeFilter = ref<string>(props.filters.is_active !== null ? String(props.filters.is_active) : '');
const perPage = ref(props.filters.per_page || 15);
const perPageOptions = [15, 25, 50, 100];
const sortField = ref(props.filters.sort || 'company_name');
const sortDirection = ref(props.filters.direction || 'asc');

// Modal state
const showVendorModal = ref(false);
const showDeleteModal = ref(false);
const editingVendor = ref<Vendor | null>(null);
const deleteVendor = ref<Vendor | null>(null);

// Form
const form = useForm({
    name: '',
    code: '',
    company_name: '',
    email: '',
    phone: '',
    website: '',
    address_line1: '',
    address_line2: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
    tax_id: '',
    payment_terms: '',
    lead_time_days: '',
    currency_code: 'USD',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    is_active: true,
    notes: '',
});

const modalTitle = computed(() => editingVendor.value ? 'Edit Vendor' : 'Add Vendor');

// Debounced search
const debouncedSearch = useDebounceFn(() => {
    applyFilters();
}, 300);

watch(searchQuery, () => {
    debouncedSearch();
});

watch(activeFilter, () => {
    applyFilters();
});

watch(perPage, () => {
    applyFilters();
});

const applyFilters = () => {
    router.get('/vendors', {
        search: searchQuery.value || undefined,
        is_active: activeFilter.value !== '' ? activeFilter.value : undefined,
        per_page: perPage.value !== 15 ? perPage.value : undefined,
        sort: sortField.value !== 'company_name' ? sortField.value : undefined,
        direction: sortDirection.value !== 'asc' ? sortDirection.value : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['vendors'],
    });
};

const handleSort = (field: string) => {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
    applyFilters();
};

const exportVendors = () => {
    const params = new URLSearchParams();
    if (searchQuery.value) params.append('search', searchQuery.value);
    if (activeFilter.value !== '') params.append('is_active', activeFilter.value);

    const url = '/vendors/export' + (params.toString() ? '?' + params.toString() : '');
    window.location.href = url;
};

const clearFilters = () => {
    searchQuery.value = '';
    activeFilter.value = '';
    perPage.value = 15;
    router.get('/vendors', {}, {
        preserveState: true,
        preserveScroll: true,
    });
};

function openCreateModal() {
    editingVendor.value = null;
    form.reset();
    form.is_active = true;
    form.currency_code = 'USD';
    showVendorModal.value = true;
}

function openEditModal(vendor: Vendor) {
    editingVendor.value = vendor;
    form.reset();

    // Load full vendor data
    fetch(`/api/v1/vendors/${vendor.id}`, {
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find(row => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] || ''
            ),
        },
        credentials: 'include',
    })
        .then(res => {
            if (!res.ok) {
                throw new Error('Failed to load vendor');
            }
            return res.json();
        })
        .then(data => {
            form.name = data.name || '';
            form.code = data.code || '';
            form.company_name = data.company_name || '';
            form.email = data.email || '';
            form.phone = data.phone || '';
            form.website = data.website || '';
            form.address_line1 = data.address_line1 || '';
            form.address_line2 = data.address_line2 || '';
            form.city = data.city || '';
            form.state = data.state || '';
            form.postal_code = data.postal_code || '';
            form.country = data.country || '';
            form.tax_id = data.tax_id || '';
            form.payment_terms = data.payment_terms || '';
            form.lead_time_days = data.lead_time_days?.toString() || '';
            form.currency_code = data.currency_code || 'USD';
            form.contact_name = data.contact_name || '';
            form.contact_email = data.contact_email || '';
            form.contact_phone = data.contact_phone || '';
            form.is_active = data.is_active ?? true;
            form.notes = data.notes || '';
            showVendorModal.value = true;
        })
        .catch(err => {
            console.error('Error loading vendor:', err);
        });
}

function submitForm() {
    if (editingVendor.value) {
        form.put(`/vendors/${editingVendor.value.id}`, {
            onSuccess: () => {
                showVendorModal.value = false;
                form.reset();
            },
        });
    } else {
        form.post('/vendors', {
            onSuccess: () => {
                showVendorModal.value = false;
                form.reset();
            },
        });
    }
}

function confirmDelete(vendor: Vendor) {
    deleteVendor.value = vendor;
    showDeleteModal.value = true;
}

function handleDelete() {
    if (deleteVendor.value) {
        router.delete(`/vendors/${deleteVendor.value.id}`, {
            onSuccess: () => {
                showDeleteModal.value = false;
                deleteVendor.value = null;
            },
            onError: () => {
                showDeleteModal.value = false;
                deleteVendor.value = null;
            },
            onFinish: () => {
                // Close modal regardless of outcome
                showDeleteModal.value = false;
            },
        });
    }
}

const formatPaymentTerms = (terms: string | null) => {
    if (!terms) return '-';
    return terms.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
};

const formatAddress = (vendor: Vendor) => {
    const parts = [vendor.address_line1, vendor.city, vendor.state].filter(Boolean);
    return parts.length > 0 ? parts.join(', ') : '-';
};

const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};
</script>

<template>
    <Head title="Vendors" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Vendors</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ vendors.total }} vendor{{ vendors.total === 1 ? '' : 's' }} total
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                        @click="exportVendors"
                    >
                        <ArrowDownTrayIcon class="-ml-0.5 size-5" />
                        Export
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        @click="openCreateModal"
                    >
                        <PlusIcon class="-ml-0.5 size-5" />
                        Add Vendor
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-4">
                <!-- Search -->
                <div class="relative flex-1 max-w-md">
                    <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search vendors..."
                        class="block w-full rounded-md border-0 py-1.5 pl-10 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <!-- Status Filter -->
                <select
                    v-model="activeFilter"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>

                <!-- Per Page -->
                <select
                    v-model="perPage"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option v-for="option in perPageOptions" :key="option" :value="option">
                        {{ option }} per page
                    </option>
                </select>

                <!-- Clear Filters -->
                <button
                    v-if="searchQuery || activeFilter !== '' || perPage !== 15"
                    type="button"
                    class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <!-- Vendor List -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                <button
                                    type="button"
                                    class="group inline-flex items-center gap-1"
                                    @click="handleSort('company_name')"
                                >
                                    Company Name
                                    <span class="ml-1 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300">
                                        <ChevronUpIcon v-if="sortField === 'company_name' && sortDirection === 'asc'" class="size-4" />
                                        <ChevronDownIcon v-else-if="sortField === 'company_name' && sortDirection === 'desc'" class="size-4" />
                                        <ChevronUpDownIcon v-else class="size-4" />
                                    </span>
                                </button>
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white lg:table-cell">
                                <button
                                    type="button"
                                    class="group inline-flex items-center gap-1"
                                    @click="handleSort('contact_name')"
                                >
                                    Contact Name
                                    <span class="ml-1 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300">
                                        <ChevronUpIcon v-if="sortField === 'contact_name' && sortDirection === 'asc'" class="size-4" />
                                        <ChevronDownIcon v-else-if="sortField === 'contact_name' && sortDirection === 'desc'" class="size-4" />
                                        <ChevronUpDownIcon v-else class="size-4" />
                                    </span>
                                </button>
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white md:table-cell">
                                Address
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                Memo Total
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                Repair Total
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                Sales Total
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white lg:table-cell">
                                Last Transaction
                            </th>
                            <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="vendor in vendors.data" :key="vendor.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="py-4 pl-4 pr-3 sm:pl-6">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-600">
                                        <BuildingOffice2Icon class="size-5 text-gray-500 dark:text-gray-400" />
                                    </div>
                                    <div class="min-w-0">
                                        <Link
                                            :href="`/vendors/${vendor.id}`"
                                            class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                        >
                                            {{ vendor.company_name || vendor.name }}
                                        </Link>
                                        <div v-if="vendor.company_name && vendor.name !== vendor.company_name" class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ vendor.name }}
                                        </div>
                                        <!-- Mobile info -->
                                        <div class="mt-1 lg:hidden">
                                            <div v-if="vendor.contact_name" class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ vendor.contact_name }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-3 py-4 lg:table-cell">
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ vendor.contact_name || '-' }}
                                </span>
                            </td>
                            <td class="hidden px-3 py-4 md:table-cell">
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ formatAddress(vendor) }}
                                </span>
                            </td>
                            <td class="hidden whitespace-nowrap px-3 py-4 text-right text-sm sm:table-cell">
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(vendor.memo_total || 0) }}
                                </span>
                            </td>
                            <td class="hidden whitespace-nowrap px-3 py-4 text-right text-sm sm:table-cell">
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(vendor.repair_total || 0) }}
                                </span>
                            </td>
                            <td class="hidden whitespace-nowrap px-3 py-4 text-right text-sm sm:table-cell">
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(vendor.sales_total || 0) }}
                                </span>
                            </td>
                            <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 lg:table-cell">
                                {{ formatDate(vendor.last_transaction_date) }}
                            </td>
                            <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                        @click="openEditModal(vendor)"
                                    >
                                        <PencilIcon class="size-4" />
                                    </button>
                                    <button
                                        v-if="vendor.purchase_orders_count === 0"
                                        type="button"
                                        class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                        @click="confirmDelete(vendor)"
                                    >
                                        <TrashIcon class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="vendors.data.length === 0">
                            <td colspan="8" class="py-12 text-center">
                                <BuildingOffice2Icon class="mx-auto size-12 text-gray-400" />
                                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No vendors</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ searchQuery || activeFilter !== '' ? 'No vendors match your filters.' : 'Get started by adding your first vendor.' }}
                                </p>
                                <div v-if="!searchQuery && activeFilter === ''" class="mt-6">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                        @click="openCreateModal"
                                    >
                                        <PlusIcon class="-ml-0.5 size-5" />
                                        Add Vendor
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <nav
                    v-if="vendors.last_page > 1"
                    class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800 sm:px-6"
                >
                    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                Showing
                                <span class="font-medium">{{ (vendors.current_page - 1) * vendors.per_page + 1 }}</span>
                                to
                                <span class="font-medium">{{ Math.min(vendors.current_page * vendors.per_page, vendors.total) }}</span>
                                of
                                <span class="font-medium">{{ vendors.total }}</span>
                                results
                            </p>
                        </div>
                        <div>
                            <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                <template v-for="(link, index) in vendors.links" :key="index">
                                    <Link
                                        v-if="link.url"
                                        :href="link.url"
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-gray-300 focus:z-20 focus:outline-offset-0 dark:ring-gray-600',
                                            link.active
                                                ? 'z-10 bg-indigo-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'
                                                : 'text-gray-900 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700',
                                            index === 0 ? 'rounded-l-md' : '',
                                            index === vendors.links.length - 1 ? 'rounded-r-md' : '',
                                        ]"
                                        v-html="link.label"
                                        preserve-scroll
                                    />
                                    <span
                                        v-else
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-gray-300 dark:ring-gray-600 text-gray-400 dark:text-gray-500',
                                            index === 0 ? 'rounded-l-md' : '',
                                            index === vendors.links.length - 1 ? 'rounded-r-md' : '',
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

        <!-- Vendor Modal -->
        <Teleport to="body">
            <div v-if="showVendorModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showVendorModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6 dark:bg-gray-800">
                            <form @submit.prevent="submitForm">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    {{ modalTitle }}
                                </h3>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 max-h-[60vh] overflow-y-auto pr-2">
                                    <!-- Basic Info -->
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name *</label>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            required
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Code</label>
                                        <input
                                            v-model="form.code"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">{{ form.errors.code }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Company Name</label>
                                        <input
                                            v-model="form.company_name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Contact Info -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                        <input
                                            v-model="form.email"
                                            type="email"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                        <input
                                            v-model="form.phone"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Website</label>
                                        <input
                                            v-model="form.website"
                                            type="url"
                                            placeholder="https://..."
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Address -->
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address Line 1</label>
                                        <input
                                            v-model="form.address_line1"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address Line 2</label>
                                        <input
                                            v-model="form.address_line2"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">City</label>
                                        <input
                                            v-model="form.city"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">State/Province</label>
                                        <input
                                            v-model="form.state"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Postal Code</label>
                                        <input
                                            v-model="form.postal_code"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Country</label>
                                        <input
                                            v-model="form.country"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Business Details -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Terms</label>
                                        <select
                                            v-model="form.payment_terms"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option value="">Select...</option>
                                            <option v-for="term in paymentTerms" :key="term" :value="term">
                                                {{ formatPaymentTerms(term) }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Lead Time (days)</label>
                                        <input
                                            v-model="form.lead_time_days"
                                            type="number"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tax ID</label>
                                        <input
                                            v-model="form.tax_id"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Currency</label>
                                        <input
                                            v-model="form.currency_code"
                                            type="text"
                                            maxlength="3"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Contact Person -->
                                    <div class="sm:col-span-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Primary Contact</h4>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contact Name</label>
                                        <input
                                            v-model="form.contact_name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contact Email</label>
                                        <input
                                            v-model="form.contact_email"
                                            type="email"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contact Phone</label>
                                        <input
                                            v-model="form.contact_phone"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="flex items-center gap-2">
                                            <input
                                                v-model="form.is_active"
                                                type="checkbox"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                            />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Active</span>
                                        </label>
                                    </div>

                                    <!-- Notes -->
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                        <textarea
                                            v-model="form.notes"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <div class="mt-5 sm:mt-6 flex flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        :disabled="form.processing"
                                        class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                    >
                                        {{ form.processing ? 'Saving...' : (editingVendor ? 'Update' : 'Create') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showVendorModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10 dark:bg-red-900">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete Vendor
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Are you sure you want to delete "{{ deleteVendor?.name }}"? This action cannot be undone.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                                <button
                                    type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:w-auto"
                                    @click="handleDelete"
                                >
                                    Delete
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showDeleteModal = false"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
