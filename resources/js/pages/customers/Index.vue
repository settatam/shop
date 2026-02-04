<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { MagnifyingGlassIcon, PencilSquareIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import { UserIcon, EnvelopeIcon, PhoneIcon, BuildingOfficeIcon, IdentificationIcon } from '@heroicons/vue/24/outline';
import { useDebounceFn } from '@vueuse/core';
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';

interface LeadSource {
    id: number;
    name: string;
    slug: string;
}

interface IdDocument {
    id: number;
    url: string;
    type: string;
}

interface Customer {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone_number: string | null;
    company_name: string | null;
    created_at: string;
    lead_source: LeadSource | null;
    id_front: IdDocument | null;
    items_purchased_count: number;
    items_sold_count: number;
    orders_sum_total: number | null;
    transactions_sum_final_offer: number | null;
    last_transaction_date: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedCustomers {
    data: Customer[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

interface Filters {
    search: string;
    lead_source_id: string;
    from_date: string;
    to_date: string;
}

interface Props {
    customers: PaginatedCustomers;
    leadSources: LeadSource[];
    filters: Filters;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
];

// Local filter state
const searchQuery = ref(props.filters.search || '');
const selectedLeadSource = ref(props.filters.lead_source_id || '');
const fromDate = ref(props.filters.from_date || '');
const toDate = ref(props.filters.to_date || '');

// Debounced search
const debouncedSearch = useDebounceFn(() => {
    applyFilters();
}, 300);

watch(searchQuery, () => {
    debouncedSearch();
});

watch(selectedLeadSource, () => {
    applyFilters();
});

const applyFilters = () => {
    router.get('/customers', {
        search: searchQuery.value || undefined,
        lead_source_id: selectedLeadSource.value || undefined,
        from_date: fromDate.value || undefined,
        to_date: toDate.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        only: ['customers'],
    });
};

const clearFilters = () => {
    searchQuery.value = '';
    selectedLeadSource.value = '';
    fromDate.value = '';
    toDate.value = '';
    router.get('/customers', {}, {
        preserveState: true,
        preserveScroll: true,
    });
};

// Formatting helpers
const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const formatCurrency = (amount: number | null) => {
    if (amount === null || amount === undefined) return '$0.00';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
};

// Lightbox state
const showLightbox = ref(false);
const lightboxImage = ref<{ url: string; name: string } | null>(null);

const openLightbox = (customer: Customer) => {
    if (customer.id_front) {
        lightboxImage.value = {
            url: customer.id_front.url,
            name: customer.full_name || 'Customer',
        };
        showLightbox.value = true;
    }
};

const closeLightbox = () => {
    showLightbox.value = false;
    lightboxImage.value = null;
};
</script>

<template>
    <Head title="Customers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Customers</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ customers.total }} customer{{ customers.total === 1 ? '' : 's' }} total
                    </p>
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
                        placeholder="Search customers..."
                        class="block w-full rounded-md border-0 py-1.5 pl-10 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <!-- Lead Source Filter -->
                <select
                    v-model="selectedLeadSource"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Lead Sources</option>
                    <option v-for="source in leadSources" :key="source.id" :value="source.id">
                        {{ source.name }}
                    </option>
                </select>

                <!-- Date Range Filter -->
                <div class="flex items-center gap-2">
                    <input
                        v-model="fromDate"
                        type="date"
                        class="rounded-md border-0 bg-white py-1.5 px-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        @change="applyFilters"
                    />
                    <span class="text-gray-500 dark:text-gray-400">to</span>
                    <input
                        v-model="toDate"
                        type="date"
                        class="rounded-md border-0 bg-white py-1.5 px-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        @change="applyFilters"
                    />
                </div>

                <!-- Clear Filters -->
                <button
                    v-if="searchQuery || selectedLeadSource || fromDate || toDate"
                    type="button"
                    class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <!-- Customer List -->
            <div class="overflow-x-auto bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th scope="col" class="w-64 py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                Name
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-center text-sm font-semibold text-gray-900 dark:text-white">
                                ID
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white lg:table-cell">
                                Email
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white md:table-cell">
                                Phone
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white lg:table-cell">
                                Lead Source
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-center text-sm font-semibold text-gray-900 dark:text-white xl:table-cell">
                                Purchased
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-center text-sm font-semibold text-gray-900 dark:text-white xl:table-cell">
                                Sold
                            </th>
                            <th scope="col" class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white xl:table-cell">
                                Total $
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Last Activity
                            </th>
                            <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="customer in customers.data" :key="customer.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <!-- Name -->
                            <td class="w-64 min-w-64 py-4 pl-4 pr-3 sm:pl-6">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-600">
                                        <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                    </div>
                                    <div class="min-w-0">
                                        <Link
                                            :href="`/customers/${customer.id}`"
                                            class="font-medium text-indigo-600 hover:text-indigo-800 hover:underline dark:text-indigo-400 dark:hover:text-indigo-300"
                                        >
                                            {{ customer.full_name || 'Unnamed Customer' }}
                                        </Link>
                                        <div v-if="customer.company_name" class="mt-0.5 flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                                            <BuildingOfficeIcon class="size-3.5" />
                                            {{ customer.company_name }}
                                        </div>
                                        <!-- Mobile contact info -->
                                        <div class="mt-1 lg:hidden">
                                            <div v-if="customer.email" class="flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                                                <EnvelopeIcon class="size-3.5" />
                                                {{ customer.email }}
                                            </div>
                                            <div v-if="customer.phone_number" class="flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                                                <PhoneIcon class="size-3.5" />
                                                {{ customer.phone_number }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <!-- Picture of ID -->
                            <td class="px-3 py-4 text-center">
                                <button
                                    v-if="customer.id_front"
                                    type="button"
                                    class="flex justify-center focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded"
                                    @click="openLightbox(customer)"
                                >
                                    <img
                                        :src="customer.id_front.url"
                                        :alt="`${customer.full_name} ID`"
                                        class="size-10 rounded object-cover ring-1 ring-gray-200 hover:ring-indigo-400 transition-all cursor-pointer dark:ring-gray-600 dark:hover:ring-indigo-500"
                                    />
                                </button>
                                <div v-else class="flex justify-center">
                                    <div class="flex size-10 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                        <IdentificationIcon class="size-5 text-gray-400" />
                                    </div>
                                </div>
                            </td>
                            <!-- Email -->
                            <td class="hidden whitespace-nowrap px-3 py-4 lg:table-cell">
                                <span v-if="customer.email" class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ customer.email }}
                                </span>
                                <span v-else class="text-sm text-gray-400 dark:text-gray-500">-</span>
                            </td>
                            <!-- Phone -->
                            <td class="hidden whitespace-nowrap px-3 py-4 md:table-cell">
                                <span v-if="customer.phone_number" class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ customer.phone_number }}
                                </span>
                                <span v-else class="text-sm text-gray-400 dark:text-gray-500">-</span>
                            </td>
                            <!-- Lead Source -->
                            <td class="hidden px-3 py-4 lg:table-cell">
                                <span
                                    v-if="customer.lead_source"
                                    class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-gray-300"
                                >
                                    {{ customer.lead_source.name }}
                                </span>
                                <span v-else class="text-sm text-gray-400 dark:text-gray-500">-</span>
                            </td>
                            <!-- Items Purchased (sold to them) -->
                            <td class="hidden px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400 xl:table-cell">
                                {{ customer.items_purchased_count || 0 }}
                            </td>
                            <!-- Items Sold (bought from them) -->
                            <td class="hidden px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400 xl:table-cell">
                                {{ customer.items_sold_count || 0 }}
                            </td>
                            <!-- Total $ -->
                            <td class="hidden px-3 py-4 text-right text-sm xl:table-cell">
                                <div class="space-y-0.5">
                                    <div class="text-green-600 dark:text-green-400">
                                        +{{ formatCurrency(customer.orders_sum_total) }}
                                    </div>
                                    <div class="text-red-600 dark:text-red-400">
                                        -{{ formatCurrency(customer.transactions_sum_final_offer) }}
                                    </div>
                                </div>
                            </td>
                            <!-- Last Activity -->
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ formatDate(customer.last_transaction_date) }}
                            </td>
                            <!-- Edit button -->
                            <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <Link
                                    :href="`/customers/${customer.id}`"
                                    class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                >
                                    <PencilSquareIcon class="size-4" />
                                    Edit
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="customers.data.length === 0">
                            <td colspan="10" class="py-12 text-center">
                                <UserIcon class="mx-auto size-12 text-gray-400" />
                                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No customers</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ searchQuery || selectedLeadSource ? 'No customers match your filters.' : 'No customers have been created yet.' }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <nav
                    v-if="customers.last_page > 1"
                    class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800 sm:px-6"
                >
                    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                Showing
                                <span class="font-medium">{{ (customers.current_page - 1) * customers.per_page + 1 }}</span>
                                to
                                <span class="font-medium">{{ Math.min(customers.current_page * customers.per_page, customers.total) }}</span>
                                of
                                <span class="font-medium">{{ customers.total }}</span>
                                results
                            </p>
                        </div>
                        <div>
                            <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                <template v-for="(link, index) in customers.links" :key="index">
                                    <Link
                                        v-if="link.url"
                                        :href="link.url"
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-gray-300 focus:z-20 focus:outline-offset-0 dark:ring-gray-600',
                                            link.active
                                                ? 'z-10 bg-indigo-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'
                                                : 'text-gray-900 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700',
                                            index === 0 ? 'rounded-l-md' : '',
                                            index === customers.links.length - 1 ? 'rounded-r-md' : '',
                                        ]"
                                        v-html="link.label"
                                        preserve-scroll
                                    />
                                    <span
                                        v-else
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-gray-300 dark:ring-gray-600 text-gray-400 dark:text-gray-500',
                                            index === 0 ? 'rounded-l-md' : '',
                                            index === customers.links.length - 1 ? 'rounded-r-md' : '',
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

        <!-- ID Lightbox Dialog -->
        <Dialog :open="showLightbox" @update:open="closeLightbox">
            <DialogContent class="max-w-3xl p-0 overflow-hidden bg-black/90">
                <DialogTitle class="sr-only">{{ lightboxImage?.name }} - ID Photo</DialogTitle>
                <div class="relative">
                    <button
                        type="button"
                        class="absolute right-2 top-2 z-10 rounded-full bg-black/50 p-2 text-white hover:bg-black/70 focus:outline-none focus:ring-2 focus:ring-white"
                        @click="closeLightbox"
                    >
                        <XMarkIcon class="size-5" />
                    </button>
                    <img
                        v-if="lightboxImage"
                        :src="lightboxImage.url"
                        :alt="`${lightboxImage.name} ID`"
                        class="max-h-[80vh] w-full object-contain"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
