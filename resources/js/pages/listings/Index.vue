<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    DropdownMenuSeparator,
    DropdownMenuLabel,
} from '@/components/ui/dropdown-menu';
import {
    MagnifyingGlassIcon,
    FunnelIcon,
    ArrowPathIcon,
    EllipsisVerticalIcon,
    ArrowTopRightOnSquareIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    ClockIcon,
    XCircleIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface Product {
    id: number;
    title: string;
    sku: string | null;
    image: string | null;
}

interface Marketplace {
    id: number;
    name: string;
    platform: string;
}

interface Listing {
    id: number;
    product: Product;
    marketplace: Marketplace;
    platform: string;
    platform_label: string;
    platform_product_id: string | null;
    status: 'active' | 'inactive' | 'pending' | 'error';
    listing_url: string | null;
    price: number | null;
    quantity: number | null;
    last_synced_at: string | null;
    error_message: string | null;
}

interface Filters {
    platform: string | null;
    status: string | null;
    search: string;
}

interface Pagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    listings: Listing[];
    pagination: Pagination;
    filters: Filters;
    platforms: Array<{ value: string; label: string }>;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Listings', href: '/listings' },
];

// Local state
const search = ref(props.filters.search || '');
const selectedPlatform = ref(props.filters.platform || '');
const selectedStatus = ref(props.filters.status || '');
const selectedListings = ref<number[]>([]);
const syncing = ref<number[]>([]);
const bulkSyncing = ref(false);

// Platform icons
const platformIcons: Record<string, string> = {
    shopify: '/images/platforms/shopify.svg',
    ebay: '/images/platforms/ebay.svg',
    amazon: '/images/platforms/amazon.svg',
    etsy: '/images/platforms/etsy.svg',
    walmart: '/images/platforms/walmart.svg',
};

const statuses = [
    { value: '', label: 'All Statuses' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'pending', label: 'Pending' },
    { value: 'error', label: 'Error' },
];

const allSelected = computed(() => {
    return props.listings.length > 0 && selectedListings.value.length === props.listings.length;
});

const someSelected = computed(() => {
    return selectedListings.value.length > 0 && selectedListings.value.length < props.listings.length;
});

// Search debounce
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(search, (newSearch) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
});

function applyFilters() {
    router.get('/listings', {
        search: search.value || undefined,
        platform: selectedPlatform.value || undefined,
        status: selectedStatus.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'active':
            return 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400';
        case 'pending':
            return 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400';
        case 'error':
            return 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400';
        default:
            return 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400';
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'active':
            return CheckCircleIcon;
        case 'pending':
            return ClockIcon;
        case 'error':
            return XCircleIcon;
        default:
            return ExclamationCircleIcon;
    }
}

function formatPrice(price: number | null): string {
    if (price === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
}

function formatDate(date: string | null): string {
    if (!date) return 'Never';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function toggleSelectAll() {
    if (allSelected.value) {
        selectedListings.value = [];
    } else {
        selectedListings.value = props.listings.map(l => l.id);
    }
}

function toggleSelect(listingId: number) {
    const index = selectedListings.value.indexOf(listingId);
    if (index === -1) {
        selectedListings.value.push(listingId);
    } else {
        selectedListings.value.splice(index, 1);
    }
}

async function syncListing(listing: Listing) {
    syncing.value.push(listing.id);
    try {
        await axios.post(`/products/${listing.product.id}/listings/${listing.marketplace.id}/sync`);
        router.reload({ only: ['listings'] });
    } catch (error) {
        console.error('Sync failed:', error);
    } finally {
        syncing.value = syncing.value.filter(id => id !== listing.id);
    }
}

async function bulkSync() {
    if (selectedListings.value.length === 0) return;

    bulkSyncing.value = true;
    try {
        await axios.post('/listings/bulk-sync', {
            listing_ids: selectedListings.value,
        });
        router.reload({ only: ['listings'] });
        selectedListings.value = [];
    } catch (error) {
        console.error('Bulk sync failed:', error);
    } finally {
        bulkSyncing.value = false;
    }
}

function viewOnPlatform(listing: Listing) {
    if (listing.listing_url) {
        window.open(listing.listing_url, '_blank');
    }
}

function goToPage(page: number) {
    router.get('/listings', {
        page,
        search: search.value || undefined,
        platform: selectedPlatform.value || undefined,
        status: selectedStatus.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Listings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Platform Listings</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Manage all your product listings across platforms
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex items-center gap-4 mb-6">
                <div class="relative flex-1 max-w-md">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                    <Input
                        v-model="search"
                        type="text"
                        placeholder="Search by product title or SKU..."
                        class="pl-10"
                    />
                </div>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline">
                            <FunnelIcon class="h-4 w-4 mr-2" />
                            Platform
                            <Badge v-if="selectedPlatform" variant="secondary" class="ml-2">
                                {{ platforms.find(p => p.value === selectedPlatform)?.label }}
                            </Badge>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem @click="selectedPlatform = ''; applyFilters()">
                            All Platforms
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-for="platform in platforms"
                            :key="platform.value"
                            @click="selectedPlatform = platform.value; applyFilters()"
                        >
                            {{ platform.label }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline">
                            Status
                            <Badge v-if="selectedStatus" variant="secondary" class="ml-2">
                                {{ statuses.find(s => s.value === selectedStatus)?.label }}
                            </Badge>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem
                            v-for="status in statuses"
                            :key="status.value"
                            @click="selectedStatus = status.value; applyFilters()"
                        >
                            {{ status.label }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- Bulk Actions -->
                <div v-if="selectedListings.length > 0" class="flex items-center gap-2 ml-auto">
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ selectedListings.length }} selected
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="bulkSync"
                        :disabled="bulkSyncing"
                    >
                        <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': bulkSyncing }" />
                        {{ bulkSyncing ? 'Syncing...' : 'Sync Selected' }}
                    </Button>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="w-12 px-4 py-3">
                                <Checkbox
                                    :checked="allSelected"
                                    :indeterminate="someSelected"
                                    @update:checked="toggleSelectAll"
                                />
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Product
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Platform
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Status
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Price
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Quantity
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Last Synced
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr
                            v-for="listing in listings"
                            :key="listing.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        >
                            <td class="px-4 py-4">
                                <Checkbox
                                    :checked="selectedListings.includes(listing.id)"
                                    @update:checked="toggleSelect(listing.id)"
                                />
                            </td>
                            <td class="px-4 py-4">
                                <Link
                                    :href="`/products/${listing.product.id}`"
                                    class="flex items-center gap-3 hover:text-indigo-600 dark:hover:text-indigo-400"
                                >
                                    <div class="h-10 w-10 rounded bg-gray-100 dark:bg-gray-700 overflow-hidden flex-shrink-0">
                                        <img
                                            v-if="listing.product.image"
                                            :src="listing.product.image"
                                            :alt="listing.product.title"
                                            class="h-full w-full object-cover"
                                        />
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ listing.product.title }}
                                        </p>
                                        <p v-if="listing.product.sku" class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ listing.product.sku }}
                                        </p>
                                    </div>
                                </Link>
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="h-6 w-6 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                        <img
                                            v-if="platformIcons[listing.platform]"
                                            :src="platformIcons[listing.platform]"
                                            :alt="listing.platform_label"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <span class="text-sm text-gray-900 dark:text-white">
                                        {{ listing.marketplace.name }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <Badge :class="['text-xs ring-1 ring-inset', getStatusColor(listing.status)]">
                                    <component :is="getStatusIcon(listing.status)" class="h-3 w-3 mr-1" />
                                    {{ listing.status }}
                                </Badge>
                                <p v-if="listing.error_message" class="text-xs text-red-600 dark:text-red-400 mt-1 max-w-xs truncate">
                                    {{ listing.error_message }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-900 dark:text-white">
                                {{ formatPrice(listing.price) }}
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-900 dark:text-white">
                                {{ listing.quantity ?? '-' }}
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ formatDate(listing.last_synced_at) }}
                            </td>
                            <td class="px-4 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <Button
                                        v-if="listing.listing_url"
                                        variant="ghost"
                                        size="sm"
                                        @click="viewOnPlatform(listing)"
                                    >
                                        <ArrowTopRightOnSquareIcon class="h-4 w-4" />
                                    </Button>

                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="sm">
                                                <EllipsisVerticalIcon class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link :href="`/products/${listing.product.id}`">
                                                    View Product
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                @click="syncListing(listing)"
                                                :disabled="syncing.includes(listing.id)"
                                            >
                                                <ArrowPathIcon class="h-4 w-4 mr-2" :class="{ 'animate-spin': syncing.includes(listing.id) }" />
                                                {{ syncing.includes(listing.id) ? 'Syncing...' : 'Sync Now' }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                        </tr>

                        <!-- Empty State -->
                        <tr v-if="listings.length === 0">
                            <td colspan="8" class="px-4 py-12 text-center">
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    No listings found matching your filters.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="pagination.last_page > 1" class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to
                        {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} of
                        {{ pagination.total }} results
                    </p>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="pagination.current_page === 1"
                            @click="goToPage(pagination.current_page - 1)"
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="pagination.current_page === pagination.last_page"
                            @click="goToPage(pagination.current_page + 1)"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
