<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    ArrowLeftIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArrowPathIcon,
    ChevronDownIcon,
    ExclamationTriangleIcon,
    ClockIcon,
    ArchiveBoxIcon,
    LinkIcon,
} from '@heroicons/vue/20/solid';
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
    status_label: string;
    external_listing_id: string | null;
    listing_url: string | null;
    platform_price: number | null;
    platform_quantity: number | null;
    platform_data: {
        title?: string;
        description?: string;
    } | null;
    published_at: string | null;
    last_synced_at: string | null;
    last_error: string | null;
    created_at: string;
    updated_at: string;
}

interface StatusOption {
    value: string;
    label: string;
    disabled: boolean;
}

interface ActivityItem {
    id: number;
    activity_slug: string;
    activity: {
        name: string;
        category: string;
        description: string;
    } | null;
    description: string;
    properties: Record<string, unknown> | null;
    user: {
        id: number;
        name: string;
    } | null;
    created_at: string;
}

interface Props {
    listing: Listing;
    product: Product;
    channel: Channel;
    statusOptions: StatusOption[];
    activities: ActivityItem[];
}

const props = defineProps<Props>();

// Form state for properties tab
const form = ref({
    title: props.listing?.platform_data?.title || '',
    description: props.listing?.platform_data?.description || '',
    price: props.listing?.platform_price ?? props.product.default_price ?? null,
    quantity: props.listing?.platform_quantity ?? props.product.default_quantity ?? null,
});

const saving = ref(false);
const changingStatus = ref(false);
const activeTab = ref('overview');

// Computed
const statusVariant = computed(() => {
    switch (props.listing.status) {
        case 'listed': return 'success';
        case 'not_listed': return 'secondary';
        case 'ended': return 'warning';
        case 'error': return 'destructive';
        case 'pending': return 'outline';
        case 'archived': return 'secondary';
        default: return 'secondary';
    }
});

const statusIcon = computed(() => {
    switch (props.listing.status) {
        case 'listed': return CheckCircleIcon;
        case 'not_listed': return XCircleIcon;
        case 'ended': return XCircleIcon;
        case 'error': return ExclamationTriangleIcon;
        case 'pending': return ClockIcon;
        case 'archived': return ArchiveBoxIcon;
        default: return ClockIcon;
    }
});

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

function formatRelativeTime(date: string): string {
    const now = new Date();
    const then = new Date(date);
    const diffMs = now.getTime() - then.getTime();
    const diffMins = Math.floor(diffMs / (1000 * 60));
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return formatDate(date);
}

async function changeStatus(newStatus: string) {
    if (changingStatus.value) return;

    changingStatus.value = true;
    try {
        await axios.patch(`/listings/${props.listing.id}/status`, {
            status: newStatus,
        });
        router.reload();
    } catch (error: unknown) {
        const axiosError = error as { response?: { data?: { message?: string } } };
        console.error('Failed to change status:', axiosError.response?.data?.message || error);
        alert(axiosError.response?.data?.message || 'Failed to change status');
    } finally {
        changingStatus.value = false;
    }
}

async function saveProperties() {
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
</script>

<template>
    <AppLayout>
        <Head :title="`${product.title} - ${channel.name}`" />

        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
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
                </div>

                <div class="flex items-center gap-3">
                    <!-- Status Dropdown -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                class="flex items-center gap-2"
                                :disabled="changingStatus"
                            >
                                <component :is="statusIcon" class="h-4 w-4" />
                                <span>{{ listing.status_label }}</span>
                                <ChevronDownIcon class="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                v-for="option in statusOptions"
                                :key="option.value"
                                :disabled="option.disabled || option.value === listing.status"
                                @click="changeStatus(option.value)"
                                class="flex items-center gap-2"
                            >
                                <CheckCircleIcon
                                    v-if="option.value === listing.status"
                                    class="h-4 w-4 text-green-500"
                                />
                                <span
                                    v-else
                                    class="w-4"
                                />
                                {{ option.label }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <!-- Product & Channel Header Card -->
            <div class="mb-6 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <div class="flex items-start gap-6">
                    <!-- Product Image -->
                    <div v-if="primaryImage" class="h-24 w-24 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-700 flex-shrink-0">
                        <img :src="primaryImage.url" :alt="primaryImage.alt || product.title" class="h-full w-full object-cover" />
                    </div>
                    <div v-else class="h-24 w-24 rounded-lg bg-gray-100 dark:bg-gray-700 flex-shrink-0 flex items-center justify-center">
                        <span class="text-gray-400 text-2xl">?</span>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h1 class="text-xl font-semibold text-gray-900 dark:text-white truncate">
                                    {{ product.title }}
                                </h1>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    {{ product.category || 'No category' }}
                                    <span v-if="product.brand"> &middot; {{ product.brand }}</span>
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                <Badge :variant="statusVariant" class="flex items-center gap-1.5">
                                    <component :is="statusIcon" class="h-3.5 w-3.5" />
                                    {{ listing.status_label }}
                                </Badge>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 mt-4">
                            <div
                                class="flex items-center gap-2 px-3 py-1.5 rounded-full text-sm"
                                :style="{ backgroundColor: (channel.color || '#6366f1') + '20' }"
                            >
                                <span class="font-medium" :style="{ color: channel.color || '#6366f1' }">
                                    {{ channel.name }}
                                </span>
                            </div>

                            <span class="text-sm text-gray-600 dark:text-gray-300">
                                {{ formatPrice(listing.platform_price) }}
                            </span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                Qty: {{ listing.platform_quantity ?? 0 }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <Tabs v-model="activeTab" default-value="overview" class="space-y-6">
                <TabsList class="grid w-full grid-cols-3">
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="properties">Properties</TabsTrigger>
                    <TabsTrigger value="activity">Activity</TabsTrigger>
                </TabsList>

                <!-- Overview Tab -->
                <TabsContent value="overview" class="space-y-6">
                    <!-- Listing Details -->
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Listing Details</h2>

                        <dl class="grid grid-cols-2 gap-x-8 gap-y-4">
                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white flex items-center gap-2">
                                    <Badge :variant="statusVariant" class="flex items-center gap-1">
                                        <component :is="statusIcon" class="h-3 w-3" />
                                        {{ listing.status_label }}
                                    </Badge>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Platform</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ channel.name }} ({{ channel.type_label }})
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">External ID</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ listing.external_listing_id || '-' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Listing URL</dt>
                                <dd class="mt-1 text-sm font-medium">
                                    <a
                                        v-if="listing.listing_url"
                                        :href="listing.listing_url"
                                        target="_blank"
                                        class="text-blue-600 hover:text-blue-700 dark:text-blue-400 flex items-center gap-1"
                                    >
                                        <LinkIcon class="h-3.5 w-3.5" />
                                        View on Platform
                                    </a>
                                    <span v-else class="text-gray-500 dark:text-gray-400">-</span>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Price</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ formatPrice(listing.platform_price) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Quantity</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ listing.platform_quantity ?? 0 }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Published At</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ formatDate(listing.published_at) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Last Synced</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ formatDate(listing.last_synced_at) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Created</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ formatDate(listing.created_at) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Updated</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ formatDate(listing.updated_at) }}
                                </dd>
                            </div>
                        </dl>

                        <!-- Error Alert -->
                        <div
                            v-if="listing.last_error"
                            class="mt-6 p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800"
                        >
                            <div class="flex items-start gap-3">
                                <ExclamationTriangleIcon class="h-5 w-5 text-red-500 flex-shrink-0" />
                                <div>
                                    <h3 class="text-sm font-medium text-red-800 dark:text-red-300">Last Error</h3>
                                    <p class="mt-1 text-sm text-red-700 dark:text-red-400">
                                        {{ listing.last_error }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </TabsContent>

                <!-- Properties Tab -->
                <TabsContent value="properties" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Channel-Specific Properties</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    Override product details for this channel. Leave blank to use defaults.
                                </p>
                            </div>
                            <Button @click="saveProperties" :disabled="saving">
                                {{ saving ? 'Saving...' : 'Save Changes' }}
                            </Button>
                        </div>

                        <div class="space-y-4">
                            <!-- Title -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label for="title">Title</Label>
                                    <Badge v-if="form.title" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Using product title</span>
                                </div>
                                <Input id="title" v-model="form.title" :placeholder="product.title" />
                            </div>

                            <!-- Description -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label for="description">Description</Label>
                                    <Badge v-if="form.description" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Using product description</span>
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
                                        <Badge
                                            v-if="form.price !== null && form.price !== product.default_price"
                                            variant="outline"
                                            class="text-xs"
                                        >Overridden</Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">Using product price</span>
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
                                        <Badge
                                            v-if="form.quantity !== null && form.quantity !== product.default_quantity"
                                            variant="outline"
                                            class="text-xs"
                                        >Overridden</Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">Using product quantity</span>
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
                </TabsContent>

                <!-- Activity Tab -->
                <TabsContent value="activity" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Activity Log</h2>

                        <div v-if="activities.length === 0" class="py-8 text-center">
                            <ClockIcon class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" />
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                No activity recorded yet.
                            </p>
                        </div>

                        <div v-else class="space-y-4">
                            <div
                                v-for="activity in activities"
                                :key="activity.id"
                                class="flex items-start gap-3 border-b border-gray-100 dark:border-gray-700 pb-4 last:border-b-0 last:pb-0"
                            >
                                <div class="h-8 w-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                    <ArrowPathIcon class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-900 dark:text-white">
                                        <span v-if="activity.user" class="font-medium">{{ activity.user.name }}</span>
                                        <span v-else class="font-medium text-gray-500">System</span>
                                        <span class="ml-1 text-gray-600 dark:text-gray-300">
                                            {{ activity.description }}
                                        </span>
                                    </p>

                                    <!-- Properties -->
                                    <div
                                        v-if="activity.properties && (activity.properties.old_status || activity.properties.new_status)"
                                        class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        <span v-if="activity.properties.old_status" class="text-red-500 line-through">
                                            {{ activity.properties.old_status }}
                                        </span>
                                        <span v-if="activity.properties.old_status && activity.properties.new_status" class="mx-1">&rarr;</span>
                                        <span v-if="activity.properties.new_status" class="text-green-500">
                                            {{ activity.properties.new_status }}
                                        </span>
                                    </div>

                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                        {{ formatRelativeTime(activity.created_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
