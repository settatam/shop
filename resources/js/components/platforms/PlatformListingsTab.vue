<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    EllipsisVerticalIcon,
    PlusIcon,
    ArrowPathIcon,
    PencilIcon,
    TrashIcon,
    ArrowTopRightOnSquareIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    ExclamationTriangleIcon,
    ClockIcon,
    RocketLaunchIcon,
    ChevronDownIcon,
    ChevronUpIcon,
} from '@heroicons/vue/20/solid';
import PlatformOverrideModal from './PlatformOverrideModal.vue';
import PublishToPlatformModal from './PublishToPlatformModal.vue';
import axios from 'axios';

interface PlatformListing {
    id: number;
    platform: string;
    platform_label: string;
    platform_product_id: string | null;
    status: 'listed' | 'not_listed' | 'ended' | 'pending' | 'error' | 'archived' | 'active' | 'draft' | 'unlisted';
    listing_url: string | null;
    price: number | null;
    quantity: number | null;
    last_synced_at: string | null;
    error_message: string | null;
    is_local: boolean;
    variant_count?: number;
    marketplace: {
        id: number;
        name: string;
    };
}

interface Override {
    id: number;
    title: string | null;
    description: string | null;
    price: number | null;
    compare_at_price: number | null;
    quantity: number | null;
    category_id: string | null;
    attributes: Record<string, string> | null;
}

interface AvailableMarketplace {
    id: number;
    name: string;
    platform: string;
    platform_label: string;
}

interface Props {
    productId: number;
    productStatus?: string;
    listings?: PlatformListing[];
    overrides?: Record<number, Override>;
    availableMarketplaces?: AvailableMarketplace[];
    loading?: boolean;
    productUpdatedAt?: string;
}

const props = withDefaults(defineProps<Props>(), {
    productStatus: 'active',
    listings: () => [],
    overrides: () => ({}),
    availableMarketplaces: () => [],
    loading: false,
    productUpdatedAt: undefined,
});

// Product must be active to list on platforms
const canListOnPlatforms = computed(() => props.productStatus === 'active');

const emit = defineEmits<{
    (e: 'refresh'): void;
}>();

// Modal state
const showOverrideModal = ref(false);
const showPublishModal = ref(false);
const selectedListing = ref<PlatformListing | null>(null);
const selectedMarketplace = ref<AvailableMarketplace | null>(null);
const syncing = ref<number | null>(null);
const syncingAll = ref(false);
const unpublishing = ref<number | null>(null);
const relisting = ref<number | null>(null);
const publishingAll = ref(false);
const publishAllResults = ref<{
    published: Array<{ marketplace_name: string; platform: string; listing_url: string | null; warnings: string[] }>;
    failed: Array<{ marketplace_name: string; platform: string; errors: string[] }>;
} | null>(null);
const expandedErrors = ref<Set<number>>(new Set());

function toggleErrorExpanded(listingId: number) {
    if (expandedErrors.value.has(listingId)) {
        expandedErrors.value.delete(listingId);
    } else {
        expandedErrors.value.add(listingId);
    }
}

// Check if a listing needs sync (product updated after last sync)
// Local/in-house listings never need syncing — they always reflect the product status
function needsSync(listing: PlatformListing): boolean {
    if (listing.is_local) return false;
    if (!props.productUpdatedAt || !listing.last_synced_at) return false;
    return new Date(props.productUpdatedAt) > new Date(listing.last_synced_at);
}

// Count listings that need sync
const listingsNeedingSync = computed(() => {
    return props.listings.filter(l => !l.is_local && (l.status === 'active' || l.status === 'listed') && needsSync(l)).length;
});

// Platform icon mapping
const platformIcons: Record<string, string> = {
    shopify: '/images/platforms/shopify.svg',
    ebay: '/images/platforms/ebay.svg',
    amazon: '/images/platforms/amazon.svg',
    etsy: '/images/platforms/etsy.svg',
    walmart: '/images/platforms/walmart.svg',
};

const unconnectedMarketplaces = computed(() => {
    const connectedIds = props.listings.map(l => l.marketplace.id);
    return props.availableMarketplaces.filter(m => !connectedIds.includes(m.id));
});

function getStatusColor(status: string): string {
    switch (status) {
        case 'active':
        case 'listed':
            return 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20';
        case 'pending':
            return 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20';
        case 'error':
            return 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20';
        case 'unlisted':
        case 'ended':
            return 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20';
        case 'draft':
        case 'not_listed':
            return 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20';
        default:
            return 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20';
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'active':
        case 'listed':
            return CheckCircleIcon;
        case 'pending':
            return ClockIcon;
        case 'error':
            return ExclamationCircleIcon;
        case 'unlisted':
        case 'ended':
            return ExclamationTriangleIcon;
        default:
            return ClockIcon;
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

function openOverrideModal(listing: PlatformListing) {
    selectedListing.value = listing;
    showOverrideModal.value = true;
}

function openPublishModal(marketplace?: AvailableMarketplace) {
    // If a specific marketplace is selected, go directly to the platform page
    if (marketplace) {
        router.visit(`/products/${props.productId}/platforms/${marketplace.id}`);
        return;
    }
    // Otherwise show the marketplace selection modal
    selectedMarketplace.value = null;
    showPublishModal.value = true;
}

async function syncListing(listing: PlatformListing) {
    syncing.value = listing.id;
    try {
        await axios.post(`/products/${props.productId}/listings/${listing.marketplace.id}/sync`);
        emit('refresh');
    } catch (error) {
        console.error('Sync failed:', error);
    } finally {
        syncing.value = null;
    }
}

async function syncAllListings() {
    syncingAll.value = true;
    try {
        // Sync all active listings in parallel
        const activeListings = props.listings.filter(l => l.status === 'active' || l.status === 'listed');
        await Promise.all(
            activeListings.map(listing =>
                axios.post(`/products/${props.productId}/listings/${listing.marketplace.id}/sync`)
            )
        );
        emit('refresh');
    } catch (error) {
        console.error('Sync all failed:', error);
    } finally {
        syncingAll.value = false;
    }
}

async function unpublishListing(listing: PlatformListing) {
    if (!confirm(`Are you sure you want to unlist this product from ${listing.platform_label}? You can relist it later.`)) {
        return;
    }

    unpublishing.value = listing.id;
    try {
        await axios.delete(`/products/${props.productId}/listings/${listing.marketplace.id}`);
        emit('refresh');
    } catch (error) {
        console.error('Unlist failed:', error);
    } finally {
        unpublishing.value = null;
    }
}

async function relistListing(listing: PlatformListing) {
    if (!confirm(`Are you sure you want to relist this product on ${listing.platform_label}?`)) {
        return;
    }

    relisting.value = listing.id;
    try {
        await axios.post(`/products/${props.productId}/listings/${listing.marketplace.id}/relist`);
        emit('refresh');
    } catch (error) {
        console.error('Relist failed:', error);
    } finally {
        relisting.value = null;
    }
}

async function publishToAll() {
    publishingAll.value = true;
    publishAllResults.value = null;
    try {
        const response = await axios.post(`/products/${props.productId}/listings/publish-all`);
        publishAllResults.value = {
            published: response.data.published || [],
            failed: response.data.failed || [],
        };
        emit('refresh');
    } catch (error: any) {
        const data = error.response?.data;
        publishAllResults.value = {
            published: [],
            failed: data?.failed || [{ marketplace_name: 'Unknown', platform: '', errors: [data?.message || 'An unexpected error occurred'] }],
        };
    } finally {
        publishingAll.value = false;
    }
}

function dismissPublishAllResults() {
    publishAllResults.value = null;
}

function viewOnPlatform(listing: PlatformListing) {
    if (listing.listing_url) {
        window.open(listing.listing_url, '_blank');
    }
}

function getListingEditUrl(listing: PlatformListing): string {
    if (listing.is_local) {
        return `/products/${props.productId}/channels/${listing.marketplace.id}`;
    }
    return `/products/${props.productId}/platforms/${listing.marketplace.id}`;
}
</script>

<template>
    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="px-4 py-5 sm:p-6">
            <!-- Warning: Product not active -->
            <div
                v-if="!canListOnPlatforms"
                class="mb-4 rounded-md bg-yellow-50 p-4 dark:bg-yellow-900/20"
            >
                <div class="flex">
                    <div class="flex-shrink-0">
                        <ExclamationTriangleIcon class="h-5 w-5 text-yellow-400" aria-hidden="true" />
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                            Product is {{ productStatus }}
                        </h3>
                        <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                            This product must be set to "Active" status before it can be listed on platforms.
                            Any existing listings will be unlisted when the product status changes.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Platform Listings</h3>
                    <Badge
                        v-if="listingsNeedingSync > 0"
                        class="bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400"
                    >
                        {{ listingsNeedingSync }} need{{ listingsNeedingSync === 1 ? 's' : '' }} sync
                    </Badge>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="listings.length > 0"
                        variant="outline"
                        size="sm"
                        :disabled="syncingAll"
                        @click="syncAllListings"
                    >
                        <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': syncingAll }" />
                        {{ syncingAll ? 'Syncing...' : 'Sync All' }}
                    </Button>
                    <Button
                        v-if="unconnectedMarketplaces.length > 1"
                        size="sm"
                        :disabled="publishingAll"
                        @click="publishToAll"
                    >
                        <RocketLaunchIcon class="h-4 w-4 mr-1" :class="{ 'animate-pulse': publishingAll }" />
                        {{ publishingAll ? 'Publishing...' : 'Publish All' }}
                    </Button>
                    <Button
                        v-if="unconnectedMarketplaces.length > 0"
                        variant="outline"
                        size="sm"
                        @click="openPublishModal()"
                    >
                        <PlusIcon class="h-4 w-4 mr-1" />
                        Publish to Platform
                    </Button>
                </div>
            </div>

            <!-- Publish All Results -->
            <div v-if="publishAllResults" class="mb-4 space-y-3">
                <!-- Success results -->
                <div
                    v-if="publishAllResults.published.length > 0"
                    class="rounded-md bg-green-50 p-4 dark:bg-green-900/20"
                >
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-3">
                            <CheckCircleIcon class="h-5 w-5 text-green-500 flex-shrink-0 mt-0.5" />
                            <div>
                                <h3 class="text-sm font-medium text-green-800 dark:text-green-200">
                                    Published to {{ publishAllResults.published.length }} platform{{ publishAllResults.published.length !== 1 ? 's' : '' }}
                                </h3>
                                <ul class="mt-1 text-sm text-green-700 dark:text-green-300 space-y-0.5">
                                    <li v-for="item in publishAllResults.published" :key="item.marketplace_name">
                                        {{ item.marketplace_name }}
                                        <a
                                            v-if="item.listing_url"
                                            :href="item.listing_url"
                                            target="_blank"
                                            class="ml-1 text-green-600 underline hover:text-green-800 dark:text-green-400 dark:hover:text-green-200"
                                        >
                                            View listing
                                            <ArrowTopRightOnSquareIcon class="inline h-3 w-3" />
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="text-green-500 hover:text-green-700 dark:hover:text-green-300"
                            @click="dismissPublishAllResults"
                        >
                            &times;
                        </button>
                    </div>
                </div>

                <!-- Failure results -->
                <div
                    v-if="publishAllResults.failed.length > 0"
                    class="rounded-md bg-red-50 p-4 dark:bg-red-900/20"
                >
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-3">
                            <ExclamationCircleIcon class="h-5 w-5 text-red-500 flex-shrink-0 mt-0.5" />
                            <div>
                                <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                                    Failed to publish to {{ publishAllResults.failed.length }} platform{{ publishAllResults.failed.length !== 1 ? 's' : '' }}
                                </h3>
                                <div class="mt-2 space-y-2">
                                    <div v-for="item in publishAllResults.failed" :key="item.marketplace_name">
                                        <p class="text-sm font-medium text-red-700 dark:text-red-300">{{ item.marketplace_name }}</p>
                                        <ul class="mt-0.5 text-sm text-red-600 dark:text-red-400 list-disc list-inside">
                                            <li v-for="error in item.errors" :key="error">{{ error }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="text-red-500 hover:text-red-700 dark:hover:text-red-300"
                            @click="dismissPublishAllResults"
                        >
                            &times;
                        </button>
                    </div>
                </div>
            </div>

            <!-- Loading State -->
            <div v-if="loading" class="space-y-3">
                <div v-for="i in 2" :key="i" class="flex items-center gap-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                    <Skeleton class="h-10 w-10 rounded" />
                    <div class="flex-1 space-y-2">
                        <Skeleton class="h-4 w-24" />
                        <Skeleton class="h-3 w-32" />
                    </div>
                    <Skeleton class="h-6 w-16" />
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-else-if="listings.length === 0"
                class="text-center py-8"
            >
                <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-4">
                    <ArrowTopRightOnSquareIcon class="h-6 w-6 text-gray-400" />
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    This product is not listed on any platform yet.
                </p>
                <Button
                    v-if="availableMarketplaces.length > 0"
                    variant="default"
                    size="sm"
                    @click="openPublishModal()"
                >
                    <PlusIcon class="h-4 w-4 mr-1" />
                    Publish to Platform
                </Button>
                <p v-else class="text-xs text-gray-400 dark:text-gray-500">
                    Connect a marketplace in settings to start listing products.
                </p>
            </div>

            <!-- Listings -->
            <div v-else class="space-y-3">
                <div
                    v-for="listing in listings"
                    :key="listing.id"
                    class="flex items-center gap-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                >
                    <!-- Platform Icon -->
                    <div class="h-10 w-10 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                        <img
                            v-if="platformIcons[listing.platform]"
                            :src="platformIcons[listing.platform]"
                            :alt="listing.platform_label"
                            class="h-6 w-6"
                        />
                        <span v-else class="text-xs font-medium text-gray-500 uppercase">
                            {{ listing.platform.slice(0, 2) }}
                        </span>
                    </div>

                    <!-- Listing Info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ listing.marketplace.name }}
                            </span>
                            <Badge
                                :class="['text-xs ring-1 ring-inset', getStatusColor(listing.status)]"
                            >
                                <component :is="getStatusIcon(listing.status)" class="h-3 w-3 mr-1" />
                                {{ listing.status }}
                            </Badge>
                            <Badge
                                v-if="needsSync(listing)"
                                class="text-xs bg-yellow-50 text-yellow-700 ring-1 ring-inset ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20"
                                title="Product has been updated since last sync"
                            >
                                <ExclamationTriangleIcon class="h-3 w-3 mr-1" />
                                Out of sync
                            </Badge>
                        </div>
                        <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400 mt-1">
                            <span>{{ formatPrice(listing.price) }}</span>
                            <span v-if="listing.quantity !== null">Qty: {{ listing.quantity }}</span>
                            <span>Synced: {{ formatDate(listing.last_synced_at) }}</span>
                        </div>
                        <a
                            v-if="listing.listing_url && (listing.status === 'active' || listing.status === 'listed')"
                            :href="listing.listing_url"
                            target="_blank"
                            class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 mt-1"
                        >
                            View listing
                            <ArrowTopRightOnSquareIcon class="h-3 w-3" />
                        </a>
                        <div v-if="listing.error_message" class="mt-1">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300"
                                @click.stop="toggleErrorExpanded(listing.id)"
                            >
                                <ExclamationCircleIcon class="h-3.5 w-3.5 flex-shrink-0" />
                                <span v-if="!expandedErrors.has(listing.id)" class="truncate max-w-xs">{{ listing.error_message }}</span>
                                <span v-else>Hide error</span>
                                <component
                                    :is="expandedErrors.has(listing.id) ? ChevronUpIcon : ChevronDownIcon"
                                    class="h-3 w-3 flex-shrink-0"
                                />
                            </button>
                            <p
                                v-if="expandedErrors.has(listing.id)"
                                class="mt-1 text-xs text-red-600 dark:text-red-400 whitespace-pre-wrap"
                            >
                                {{ listing.error_message }}
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <!-- Relist button for unlisted items -->
                        <Button
                            v-if="listing.status === 'unlisted' || listing.status === 'ended'"
                            variant="default"
                            size="sm"
                            :disabled="relisting === listing.id"
                            @click="relistListing(listing)"
                        >
                            <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': relisting === listing.id }" />
                            {{ relisting === listing.id ? 'Relisting...' : 'Relist' }}
                        </Button>

                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="ghost" size="sm">
                                    <EllipsisVerticalIcon class="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    v-if="listing.listing_url && (listing.status === 'active' || listing.status === 'listed')"
                                    @click="viewOnPlatform(listing)"
                                >
                                    <ArrowTopRightOnSquareIcon class="h-4 w-4 mr-2" />
                                    View on Platform
                                </DropdownMenuItem>
                                <DropdownMenuItem @click="router.visit(getListingEditUrl(listing))">
                                    <PencilIcon class="h-4 w-4 mr-2" />
                                    Edit Listing Details
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="listing.status === 'active' || listing.status === 'listed'"
                                    @click="syncListing(listing)"
                                    :disabled="syncing === listing.id"
                                >
                                    <ArrowPathIcon class="h-4 w-4 mr-2" :class="{ 'animate-spin': syncing === listing.id }" />
                                    {{ syncing === listing.id ? 'Syncing...' : 'Sync Now' }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="listing.status === 'unlisted' || listing.status === 'ended'"
                                    class="text-green-600 dark:text-green-400"
                                    @click="relistListing(listing)"
                                    :disabled="relisting === listing.id"
                                >
                                    <ArrowPathIcon class="h-4 w-4 mr-2" :class="{ 'animate-spin': relisting === listing.id }" />
                                    {{ relisting === listing.id ? 'Relisting...' : 'Relist' }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="listing.status === 'active' || listing.status === 'listed'"
                                    class="text-orange-600 dark:text-orange-400"
                                    @click="unpublishListing(listing)"
                                    :disabled="unpublishing === listing.id"
                                >
                                    <ExclamationTriangleIcon class="h-4 w-4 mr-2" />
                                    {{ unpublishing === listing.id ? 'Unlisting...' : 'Unlist' }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <PlatformOverrideModal
        v-model:open="showOverrideModal"
        :product-id="productId"
        :listing="selectedListing"
        :override="selectedListing ? overrides[selectedListing.marketplace.id] : undefined"
        @saved="emit('refresh'); showOverrideModal = false"
    />

    <PublishToPlatformModal
        v-model:open="showPublishModal"
        :product-id="productId"
        :available-marketplaces="unconnectedMarketplaces"
        :initial-marketplace="selectedMarketplace"
        @published="emit('refresh'); showPublishModal = false"
    />
</template>
