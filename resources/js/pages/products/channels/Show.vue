<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    ArrowLeftIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArrowPathIcon,
    BuildingStorefrontIcon,
} from '@heroicons/vue/20/solid';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import axios from 'axios';

interface Image {
    id: number;
    url: string;
    alt: string | null;
    is_primary: boolean;
}

interface Product {
    id: number;
    title: string;
    description: string | null;
    handle: string | null;
    status: string;
    category: string | null;
    brand: string | null;
    default_price: number | null;
    default_quantity: number | null;
    images: Image[];
}

interface Channel {
    id: number;
    name: string;
    code: string;
    type: string;
    type_label: string;
    is_local: boolean;
    color: string | null;
}

interface Listing {
    id: number;
    status: string;
    should_list: boolean;
    platform_price: number | null;
    platform_quantity: number | null;
    platform_data: {
        title?: string;
        description?: string;
    } | null;
    published_at: string | null;
    last_synced_at: string | null;
}

interface ChannelOption {
    id: number;
    name: string;
    type: string;
    is_local: boolean;
    is_listed: boolean;
}

interface Props {
    product: Product;
    channel: Channel;
    listing: Listing | null;
    allChannels: ChannelOption[];
}

const props = defineProps<Props>();

// Form state
const form = ref({
    title: props.listing?.platform_data?.title || '',
    description: props.listing?.platform_data?.description || '',
    price: props.listing?.platform_price ?? props.product.default_price ?? null,
    quantity: props.listing?.platform_quantity ?? props.product.default_quantity ?? null,
});

const saving = ref(false);
const publishing = ref(false);
const syncing = ref(false);
const togglingNotForSale = ref(false);
const togglingShouldList = ref(false);

// Computed
const isListed = computed(() => props.listing?.status === 'active');
const isNotForSale = computed(() => props.listing?.status === 'not_for_sale');
const isExcluded = computed(() => props.listing?.should_list === false);
const isDraft = computed(() => !props.listing || props.listing.status === 'draft');

const effectiveTitle = computed(() => form.value.title || props.product.title);
const effectiveDescription = computed(() => form.value.description || props.product.description);

const statusLabel = computed(() => {
    if (!props.listing) return 'Not Listed';
    switch (props.listing.status) {
        case 'active': return 'Listed';
        case 'not_for_sale': return 'Not For Sale';
        case 'draft': return 'Draft';
        case 'pending': return 'Pending';
        case 'error': return 'Error';
        default: return props.listing.status;
    }
});

const statusVariant = computed(() => {
    if (!props.listing) return 'secondary';
    switch (props.listing.status) {
        case 'active': return 'success';
        case 'not_for_sale': return 'warning';
        case 'error': return 'destructive';
        default: return 'secondary';
    }
});

// Get primary image
const primaryImage = computed(() => {
    return props.product.images.find(img => img.is_primary) || props.product.images[0];
});

// Methods
function formatPrice(price: number | null): string {
    if (price === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
}

function formatDate(date: string | null): string {
    if (!date) return 'Never';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function save() {
    saving.value = true;
    try {
        await axios.put(
            `/products/${props.product.id}/channels/${props.channel.id}`,
            form.value
        );
        router.reload();
    } catch (error) {
        console.error('Failed to save:', error);
    } finally {
        saving.value = false;
    }
}

async function publish() {
    publishing.value = true;
    try {
        // Save first
        await axios.put(
            `/products/${props.product.id}/channels/${props.channel.id}`,
            form.value
        );

        // Then publish
        await axios.post(`/products/${props.product.id}/channels/${props.channel.id}/publish`);

        router.reload();
    } catch (error) {
        console.error('Failed to publish:', error);
    } finally {
        publishing.value = false;
    }
}

async function unpublish() {
    if (!confirm(`Are you sure you want to unlist this product from ${props.channel.name}?`)) {
        return;
    }

    try {
        await axios.delete(`/products/${props.product.id}/channels/${props.channel.id}`);
        router.reload();
    } catch (error) {
        console.error('Failed to unpublish:', error);
    }
}

async function toggleShouldList() {
    togglingShouldList.value = true;
    try {
        await axios.post(`/products/${props.product.id}/channels/${props.channel.id}/toggle-should-list`);
        router.reload();
    } catch (error) {
        console.error('Toggle should_list failed:', error);
    } finally {
        togglingShouldList.value = false;
    }
}

async function toggleNotForSale() {
    togglingNotForSale.value = true;
    try {
        await axios.post(`/products/${props.product.id}/channels/${props.channel.id}/toggle-not-for-sale`);
        router.reload();
    } catch (error) {
        console.error('Failed to toggle not for sale:', error);
    } finally {
        togglingNotForSale.value = false;
    }
}

async function sync() {
    syncing.value = true;
    try {
        await axios.post(`/products/${props.product.id}/channels/${props.channel.id}/sync`);
        router.reload();
    } catch (error) {
        console.error('Failed to sync:', error);
    } finally {
        syncing.value = false;
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="`${product.title} - ${channel.name}`" />

        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Link
                        :href="`/products/${product.id}`"
                        class="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        <ArrowLeftIcon class="h-4 w-4" />
                        Back to Product
                    </Link>

                    <div class="flex items-center gap-3">
                        <div
                            class="h-10 w-10 rounded flex items-center justify-center"
                            :style="{ backgroundColor: channel.color || '#6366f1' }"
                        >
                            <BuildingStorefrontIcon class="h-6 w-6 text-white" />
                        </div>
                        <div>
                            <h1 class="text-lg font-semibold text-gray-900 dark:text-white">
                                {{ channel.name }}
                            </h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ product.title }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Status Badge -->
                    <Badge v-if="isExcluded" variant="destructive" class="flex items-center gap-1">
                        Excluded
                    </Badge>
                    <Badge v-else :variant="statusVariant" class="flex items-center gap-1">
                        <CheckCircleIcon v-if="isListed" class="h-3.5 w-3.5" />
                        <XCircleIcon v-else-if="isNotForSale" class="h-3.5 w-3.5" />
                        {{ statusLabel }}
                    </Badge>

                    <!-- Actions -->
                    <Button variant="outline" @click="save" :disabled="saving">
                        {{ saving ? 'Saving...' : 'Save' }}
                    </Button>

                    <Button
                        variant="outline"
                        @click="toggleShouldList"
                        :disabled="togglingShouldList"
                    >
                        {{ isExcluded ? 'Include on Channel' : 'Exclude from Channel' }}
                    </Button>

                    <template v-if="!isExcluded && isListed">
                        <Button variant="outline" @click="sync" :disabled="syncing">
                            <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': syncing }" />
                            Sync
                        </Button>
                        <Button variant="destructive" @click="unpublish">
                            Unlist
                        </Button>
                    </template>
                    <template v-else-if="!isExcluded">
                        <Button @click="publish" :disabled="publishing">
                            {{ publishing ? 'Listing...' : `List on ${channel.name}` }}
                        </Button>
                    </template>
                </div>
            </div>

            <!-- Excluded Warning -->
            <div v-if="isExcluded" class="mb-6 rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20">
                <div class="flex items-start gap-3">
                    <XCircleIcon class="mt-0.5 h-5 w-5 shrink-0 text-yellow-500" />
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-300">Product Excluded</h3>
                        <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-400">
                            This product is excluded from {{ channel.name }}. Click "Include on Channel" to enable listing.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Product Preview Card -->
            <div class="mb-6 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
                <div class="flex items-start gap-4">
                    <div v-if="primaryImage" class="h-20 w-20 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-700 flex-shrink-0">
                        <img :src="primaryImage.url" :alt="primaryImage.alt || product.title" class="h-full w-full object-cover" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-base font-medium text-gray-900 dark:text-white truncate">{{ product.title }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            {{ product.category || 'No category' }}
                            <span v-if="product.brand"> &middot; {{ product.brand }}</span>
                        </p>
                        <div class="flex items-center gap-4 mt-2 text-sm">
                            <span class="text-gray-600 dark:text-gray-300">
                                {{ formatPrice(product.default_price) }}
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                Qty: {{ product.default_quantity ?? 0 }}
                            </span>
                            <Badge :variant="product.status === 'active' ? 'success' : 'secondary'">
                                {{ product.status }}
                            </Badge>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Form -->
            <div class="space-y-6">
                <!-- Basic Info -->
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Channel-Specific Details</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Override the product details for this channel. Leave blank to use the default product values.
                    </p>

                    <div class="space-y-4">
                        <!-- Title -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <Label for="title">Title</Label>
                                <Badge v-if="form.title" variant="outline" class="text-xs">
                                    Overridden
                                </Badge>
                                <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                    Using product title
                                </span>
                            </div>
                            <Input
                                id="title"
                                v-model="form.title"
                                :placeholder="product.title"
                            />
                        </div>

                        <!-- Description -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <Label for="description">Description</Label>
                                <Badge v-if="form.description" variant="outline" class="text-xs">
                                    Overridden
                                </Badge>
                                <span v-else-if="product.description" class="text-xs text-gray-500 dark:text-gray-400">
                                    Using product description
                                </span>
                            </div>
                            <RichTextEditor
                                v-model="form.description"
                                :placeholder="product.description || 'Enter description...'"
                            />
                        </div>

                        <!-- Pricing -->
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label for="price">Price</Label>
                                    <Badge v-if="form.price !== null && form.price !== product.default_price" variant="outline" class="text-xs">
                                        Overridden
                                    </Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                        Using product price
                                    </span>
                                </div>
                                <Input
                                    id="price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    v-model.number="form.price"
                                    :placeholder="String(product.default_price ?? 0)"
                                />
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label for="quantity">Quantity</Label>
                                    <Badge v-if="form.quantity !== null && form.quantity !== product.default_quantity" variant="outline" class="text-xs">
                                        Overridden
                                    </Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                        Using product quantity
                                    </span>
                                </div>
                                <Input
                                    id="quantity"
                                    type="number"
                                    min="0"
                                    v-model.number="form.quantity"
                                    :placeholder="String(product.default_quantity ?? 0)"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Listing Status -->
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Listing Status</h2>

                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                Not For Sale
                            </p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Mark this product as not for sale on {{ channel.name }}.
                                The product will remain in your catalog but won't be available for purchase.
                            </p>
                        </div>
                        <Button
                            :variant="isNotForSale ? 'default' : 'outline'"
                            @click="toggleNotForSale"
                            :disabled="togglingNotForSale"
                        >
                            {{ isNotForSale ? 'Mark as For Sale' : 'Mark as Not For Sale' }}
                        </Button>
                    </div>

                    <!-- Listing info -->
                    <div v-if="listing" class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                        <dl class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                                <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ statusLabel }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Published</dt>
                                <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ formatDate(listing.published_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Last Synced</dt>
                                <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ formatDate(listing.last_synced_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Channel Price</dt>
                                <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ formatPrice(listing.platform_price) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Other Channels -->
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Other Channels</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Quick links to manage this product on other channels.
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <template v-for="ch in allChannels" :key="ch.id">
                            <Link
                                v-if="ch.id !== channel.id"
                                :href="`/products/${product.id}/channels/${ch.id}`"
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm border transition-colors"
                                :class="ch.is_listed
                                    ? 'border-green-200 bg-green-50 text-green-700 hover:bg-green-100 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400'
                                    : 'border-gray-200 bg-gray-50 text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400'"
                            >
                                <CheckCircleIcon v-if="ch.is_listed" class="h-4 w-4" />
                                {{ ch.name }}
                            </Link>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
