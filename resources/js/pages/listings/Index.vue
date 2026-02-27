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
    ChevronRightIcon,
    ChevronDownIcon,
    ChevronUpIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface Listing {
    id: number;
    sales_channel_id: number | null;
    marketplace_id: number | null;
    channel_name: string;
    channel_type: string;
    channel_code: string | null;
    platform: string | null;
    status: string;
    listing_url: string | null;
    external_listing_id: string | null;
    platform_price: number | null;
    platform_quantity: number | null;
    quantity_override: number | null;
    last_synced_at: string | null;
    last_error: string | null;
}

interface ProductWithListings {
    id: number;
    title: string;
    handle: string | null;
    image: string | null;
    listings: Listing[];
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

interface ListingsData {
    data: ProductWithListings[];
    meta: Pagination;
}

interface Props {
    listings?: ListingsData;
    marketplaces: Array<{ id: number; name: string; platform: string; platform_label: string }>;
    filters: Filters;
}

const props = defineProps<Props>();

// Computed for easier access to deferred data
const productsData = computed(() => props.listings?.data ?? []);
const pagination = computed(() => props.listings?.meta ?? { current_page: 1, last_page: 1, per_page: 25, total: 0 });
const platforms = computed(() => props.marketplaces?.map(m => ({ value: m.platform, label: m.platform_label })) ?? []);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Listings', href: '/listings' },
];

// Local state
const search = ref(props.filters?.search || '');
const selectedPlatform = ref(props.filters?.platform || '');
const selectedStatus = ref(props.filters?.status || '');
const selectedListings = ref<number[]>([]);
const syncing = ref<number[]>([]);
const bulkSyncing = ref(false);
const expandedProducts = ref<number[]>([]);

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
    { value: 'listed', label: 'Listed' },
    { value: 'draft', label: 'Draft' },
    { value: 'pending', label: 'Pending' },
    { value: 'error', label: 'Error' },
];

// Get all listing IDs
const allListingIds = computed(() => {
    return productsData.value.flatMap(p => p.listings.map(l => l.id));
});

const allSelected = computed(() => {
    return allListingIds.value.length > 0 && selectedListings.value.length === allListingIds.value.length;
});

const someSelected = computed(() => {
    return selectedListings.value.length > 0 && selectedListings.value.length < allListingIds.value.length;
});

// Search debounce
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
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
        case 'listed':
            return 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400';
        case 'pending':
        case 'draft':
            return 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400';
        case 'error':
        case 'failed':
            return 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400';
        default:
            return 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400';
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'active':
        case 'listed':
            return CheckCircleIcon;
        case 'pending':
        case 'draft':
            return ClockIcon;
        case 'error':
        case 'failed':
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
        selectedListings.value = [...allListingIds.value];
    }
}

function toggleSelectListing(listingId: number) {
    const isSelected = selectedListings.value.includes(listingId);
    if (isSelected) {
        selectedListings.value = selectedListings.value.filter(id => id !== listingId);
    } else {
        selectedListings.value = [...selectedListings.value, listingId];
    }
}

function toggleSelectProduct(product: ProductWithListings, checked: boolean) {
    const productListingIds = product.listings.map(l => l.id);

    if (checked) {
        // Select all listings for this product
        const existingIds = new Set(selectedListings.value);
        const newIds = productListingIds.filter(id => !existingIds.has(id));
        selectedListings.value = [...selectedListings.value, ...newIds];
    } else {
        // Deselect all listings for this product
        const productIdsSet = new Set(productListingIds);
        selectedListings.value = selectedListings.value.filter(id => !productIdsSet.has(id));
    }
}

function isProductSelected(product: ProductWithListings): boolean {
    return product.listings.every(l => selectedListings.value.includes(l.id));
}

function isProductPartiallySelected(product: ProductWithListings): boolean {
    const selected = product.listings.filter(l => selectedListings.value.includes(l.id));
    return selected.length > 0 && selected.length < product.listings.length;
}

function toggleExpand(productId: number) {
    const index = expandedProducts.value.indexOf(productId);
    if (index === -1) {
        expandedProducts.value.push(productId);
    } else {
        expandedProducts.value.splice(index, 1);
    }
}

function isExpanded(productId: number): boolean {
    return expandedProducts.value.includes(productId);
}

function expandAll() {
    expandedProducts.value = productsData.value.map(p => p.id);
}

function collapseAll() {
    expandedProducts.value = [];
}

const allExpanded = computed(() => {
    return productsData.value.length > 0 && expandedProducts.value.length === productsData.value.length;
});

async function syncListing(productId: number, listing: Listing) {
    // Only sync if we have a marketplace (external platform)
    if (!listing.marketplace_id) {
        console.warn('Cannot sync local channel listing');
        return;
    }

    syncing.value.push(listing.id);
    try {
        await axios.post(`/products/${productId}/listings/${listing.marketplace_id}/sync`);
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

const bulkActionLoading = ref(false);

async function bulkAction(action: 'list' | 'end' | 'archive') {
    if (selectedListings.value.length === 0) return;

    bulkActionLoading.value = true;
    try {
        await axios.post('/listings/bulk-status', {
            listing_ids: [...selectedListings.value],
            action,
        });
        router.reload({ only: ['listings'] });
        selectedListings.value = [];
    } catch (error) {
        console.error('Bulk action failed:', error);
    } finally {
        bulkActionLoading.value = false;
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

// Expand all by default on initial load
watch(productsData, (products) => {
    if (products.length > 0 && expandedProducts.value.length === 0) {
        expandedProducts.value = products.map(p => p.id);
    }
}, { immediate: true });
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
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="allExpanded ? collapseAll() : expandAll()"
                    >
                        <ChevronDownIcon v-if="!allExpanded" class="h-4 w-4 mr-1" />
                        <ChevronUpIcon v-else class="h-4 w-4 mr-1" />
                        {{ allExpanded ? 'Collapse All' : 'Expand All' }}
                    </Button>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex items-center gap-4 mb-6">
                <div class="relative flex-1 max-w-md">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                    <Input
                        v-model="search"
                        type="text"
                        placeholder="Search by product title..."
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
                        variant="default"
                        size="sm"
                        @click="bulkAction('list')"
                        :disabled="bulkActionLoading"
                        class="bg-green-600 hover:bg-green-700"
                    >
                        <CheckCircleIcon class="h-4 w-4 mr-1" />
                        List
                    </Button>

                    <Button
                        variant="outline"
                        size="sm"
                        @click="bulkAction('end')"
                        :disabled="bulkActionLoading"
                    >
                        <XCircleIcon class="h-4 w-4 mr-1" />
                        End
                    </Button>

                    <Button
                        variant="outline"
                        size="sm"
                        @click="bulkAction('archive')"
                        :disabled="bulkActionLoading"
                        class="text-red-600 hover:text-red-700 border-red-300 hover:border-red-400"
                    >
                        Archive
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
                                    :model-value="allSelected"
                                    :indeterminate="someSelected"
                                    @update:model-value="toggleSelectAll"
                                />
                            </th>
                            <th class="w-8 px-2 py-3"></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Product / Platform
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Status
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Price
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Qty
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
                        <!-- Loading State -->
                        <tr v-if="!listings">
                            <td colspan="8" class="px-4 py-8">
                                <div class="flex flex-col gap-4">
                                    <div v-for="i in 5" :key="i" class="animate-pulse">
                                        <div class="flex items-center gap-4 mb-2">
                                            <div class="w-5 h-5 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                            <div class="w-4 h-4 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                            <div class="h-10 w-10 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                            <div class="flex-1">
                                                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3 mb-2"></div>
                                            </div>
                                        </div>
                                        <div class="ml-16 pl-4 border-l-2 border-gray-100 dark:border-gray-700 space-y-2">
                                            <div class="flex items-center gap-4">
                                                <div class="h-6 w-6 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                                <div class="h-4 w-20 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                                <div class="h-4 w-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>

                        <template v-else>
                            <template v-for="product in productsData" :key="product.id">
                                <!-- Product Row -->
                                <tr class="bg-gray-50/50 dark:bg-gray-800/50">
                                    <td class="px-4 py-3">
                                        <Checkbox
                                            :model-value="isProductSelected(product)"
                                            :indeterminate="isProductPartiallySelected(product)"
                                            @update:model-value="(checked: boolean) => toggleSelectProduct(product, checked)"
                                        />
                                    </td>
                                    <td class="px-2 py-3">
                                        <button
                                            @click="toggleExpand(product.id)"
                                            class="p-1 hover:bg-gray-200 dark:hover:bg-gray-700 rounded transition-colors"
                                        >
                                            <ChevronRightIcon
                                                class="h-4 w-4 text-gray-500 transition-transform"
                                                :class="{ 'rotate-90': isExpanded(product.id) }"
                                            />
                                        </button>
                                    </td>
                                    <td class="px-4 py-3" colspan="6">
                                        <Link
                                            :href="`/products/${product.id}`"
                                            class="flex items-center gap-3 hover:text-indigo-600 dark:hover:text-indigo-400"
                                        >
                                            <div class="h-10 w-10 rounded bg-gray-100 dark:bg-gray-700 overflow-hidden flex-shrink-0">
                                                <img
                                                    v-if="product.image"
                                                    :src="product.image"
                                                    :alt="product.title"
                                                    class="h-full w-full object-cover"
                                                />
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                    {{ product.title }}
                                                </p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ product.listings.length }} listing{{ product.listings.length === 1 ? '' : 's' }}
                                                </p>
                                            </div>
                                        </Link>
                                    </td>
                                </tr>

                                <!-- Listing Rows (nested under product) -->
                                <template v-if="isExpanded(product.id)">
                                    <tr
                                        v-for="listing in product.listings"
                                        :key="listing.id"
                                        class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                    >
                                        <td class="px-4 py-3">
                                            <Checkbox
                                                :model-value="selectedListings.includes(listing.id)"
                                                @update:model-value="() => toggleSelectListing(listing.id)"
                                            />
                                        </td>
                                        <td class="px-2 py-3"></td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3 pl-6">
                                                <div class="w-px h-6 bg-gray-200 dark:bg-gray-700 -ml-3"></div>
                                                <div class="h-7 w-7 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                                    <img
                                                        v-if="listing.platform && platformIcons[listing.platform]"
                                                        :src="platformIcons[listing.platform]"
                                                        :alt="listing.channel_name"
                                                        class="h-4 w-4"
                                                    />
                                                    <span v-else class="text-xs font-medium text-gray-500">
                                                        {{ listing.channel_type === 'local' ? 'L' : listing.channel_type === 'pos' ? 'P' : '?' }}
                                                    </span>
                                                </div>
                                                <div>
                                                    <p class="text-sm text-gray-900 dark:text-white">
                                                        {{ listing.channel_name }}
                                                    </p>
                                                    <p v-if="listing.external_listing_id" class="text-xs text-gray-500 dark:text-gray-400">
                                                        ID: {{ listing.external_listing_id }}
                                                    </p>
                                                    <p v-else-if="listing.channel_type === 'local'" class="text-xs text-gray-500 dark:text-gray-400">
                                                        Local Channel
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <Badge :class="['text-xs ring-1 ring-inset', getStatusColor(listing.status)]">
                                                <component :is="getStatusIcon(listing.status)" class="h-3 w-3 mr-1" />
                                                {{ listing.status }}
                                            </Badge>
                                            <p v-if="listing.last_error" class="text-xs text-red-600 dark:text-red-400 mt-1 max-w-xs truncate">
                                                {{ listing.last_error }}
                                            </p>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                            {{ formatPrice(listing.platform_price) }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                            {{ listing.platform_quantity ?? '-' }}
                                            <span v-if="listing.quantity_override !== null" class="ml-1 text-xs text-amber-600 dark:text-amber-400" :title="`Capped at ${listing.quantity_override}`">
                                                (cap: {{ listing.quantity_override }})
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                            {{ formatDate(listing.last_synced_at) }}
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <Button
                                                    v-if="listing.listing_url"
                                                    variant="ghost"
                                                    size="sm"
                                                    @click="viewOnPlatform(listing)"
                                                    title="View on platform"
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
                                                            <Link :href="`/listings/${listing.id}`">
                                                                View Listing Details
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            @click="syncListing(product.id, listing)"
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
                                </template>
                            </template>

                            <!-- Empty State -->
                            <tr v-if="productsData.length === 0">
                                <td colspan="8" class="px-4 py-12 text-center">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        No listings found matching your filters.
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="pagination.last_page > 1" class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to
                        {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} of
                        {{ pagination.total }} products
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
