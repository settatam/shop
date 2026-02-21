<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { ImageLightbox } from '@/components/images';
import { PlatformListingsTab } from '@/components/platforms';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PencilIcon, TrashIcon, ArrowLeftIcon, PrinterIcon, ShoppingCartIcon } from '@heroicons/vue/20/solid';

interface Variant {
    id: number;
    sku: string;
    title: string | null;
    price: number;
    cost: number | null;
    quantity: number;
}

interface Image {
    id: number;
    url: string;
    alt: string | null;
    is_primary: boolean;
}

interface Tag {
    id: number;
    name: string;
    color: string;
}

interface ActivityItem {
    id: number;
    activity: string;
    description: string;
    user: { name: string } | null;
    changes: Record<string, { old: string; new: string }> | null;
    time: string;
    created_at: string;
    icon: string;
    color: string;
}

interface ActivityDay {
    date: string;
    dateTime: string;
    items: ActivityItem[];
}

interface Template {
    id: number;
    name: string;
}

interface PlatformListing {
    id: number;
    marketplace_id: number;
    marketplace_name: string;
    platform: string;
    platform_label: string;
    status: string;
    listing_url: string | null;
    external_listing_id: string | null;
    platform_price: number | null;
    platform_quantity: number | null;
    last_synced_at: string | null;
    published_at: string | null;
    last_error: string | null;
}

interface AvailableMarketplace {
    id: number;
    name: string;
    platform: string;
    platform_label: string;
}

interface TemplateFieldValue {
    id: number;
    label: string;
    name: string;
    type: string;
    value: string | null;
}

interface Product {
    id: number;
    title: string;
    description: string | null;
    handle: string;
    status: string;
    is_published: boolean;
    is_draft: boolean;
    has_variants: boolean;
    track_quantity: boolean;
    sell_out_of_stock: boolean;
    charge_taxes: boolean;
    total_quantity: number;
    created_at: string;
    updated_at: string;
    category: { id: number; name: string } | null;
    brand: { id: number; name: string } | null;
    vendor: { id: number; name: string } | null;
    tags: Tag[];
    variants: Variant[];
    images: Image[];
}

interface Props {
    product: Product;
    template?: Template | null;
    templateFields?: TemplateFieldValue[];
    activityLogs?: ActivityDay[];
    platformListings?: PlatformListing[];
    availableMarketplaces?: AvailableMarketplace[];
}

const props = defineProps<Props>();

// Lightbox state
const lightboxOpen = ref(false);
const lightboxIndex = ref(0);

const openLightbox = (index: number) => {
    lightboxIndex.value = index;
    lightboxOpen.value = true;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: props.product.title, href: `/products/${props.product.id}` },
];

const formatPrice = (price: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(price);
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const deleteProduct = () => {
    if (confirm(`Are you sure you want to delete "${props.product.title}"?`)) {
        router.delete(`/products/${props.product.id}`);
    }
};

const refreshListings = () => {
    router.reload({ only: ['platformListings', 'availableMarketplaces'] });
};
</script>

<template>
    <Head :title="product.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-4">
                    <Link
                        href="/products"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ product.title }}</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ product.handle }}
                        </p>
                        <!-- Tags displayed as badges under title -->
                        <div v-if="product.tags && product.tags.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                            <span
                                v-for="tag in product.tags"
                                :key="tag.id"
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :style="{
                                    backgroundColor: tag.color + '20',
                                    color: tag.color,
                                    border: `1px solid ${tag.color}40`
                                }"
                            >
                                {{ tag.name }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex gap-3">
                    <Link
                        :href="`/orders/create?product_id=${product.id}`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600"
                    >
                        <ShoppingCartIcon class="-ml-0.5 size-5" />
                        Sell
                    </Link>
                    <Link
                        :href="`/products/${product.id}/print-barcode`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        <PrinterIcon class="-ml-0.5 size-5" />
                        Print Barcode
                    </Link>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-red-50 dark:bg-gray-800 dark:ring-gray-600 dark:hover:bg-red-900/20"
                        @click="deleteProduct"
                    >
                        <TrashIcon class="-ml-0.5 size-5" />
                        Delete
                    </button>
                    <Link
                        :href="`/products/${product.id}/edit`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        <PencilIcon class="-ml-0.5 size-5" />
                        Edit
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Product Images -->
                    <div v-if="product.images.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Images</h3>
                            <div class="flex flex-wrap gap-4">
                                <button
                                    v-for="(image, index) in product.images"
                                    :key="image.id"
                                    type="button"
                                    class="relative h-32 w-32 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700 cursor-zoom-in"
                                    @click="openLightbox(index)"
                                >
                                    <img
                                        :src="image.url"
                                        :alt="image.alt || product.title"
                                        class="h-full w-full object-cover"
                                    />
                                    <span
                                        v-if="image.is_primary"
                                        class="absolute bottom-1 left-1 rounded bg-indigo-600 px-1.5 py-0.5 text-xs font-medium text-white"
                                    >
                                        Primary
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Description</h3>
                            <div
                                v-if="product.description"
                                class="prose prose-sm max-w-none text-gray-600 dark:prose-invert dark:text-gray-300"
                                v-html="product.description"
                            />
                            <p v-else class="text-sm text-gray-400 dark:text-gray-500 italic">
                                No description provided
                            </p>
                        </div>
                    </div>

                    <!-- Template Attributes -->
                    <div v-if="template && templateFields && templateFields.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">{{ template.name }}</h3>
                            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div v-for="field in templateFields" :key="field.id">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ field.label }}</dt>
                                    <dd class="mt-1 text-sm font-medium" :class="field.value ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500 italic'">
                                        {{ field.value || 'Not set' }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Pricing (for products without variants) -->
                    <div v-if="!product.has_variants && product.variants.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Pricing & Inventory</h3>
                            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">SKU</dt>
                                    <dd class="mt-1 text-sm font-mono font-medium text-gray-900 dark:text-white">
                                        {{ product.variants[0].sku }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Price</dt>
                                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatPrice(product.variants[0].price) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Cost</dt>
                                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                        {{ product.variants[0].cost ? formatPrice(product.variants[0].cost) : '-' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Quantity</dt>
                                    <dd class="mt-1 text-sm font-medium" :class="product.variants[0].quantity <= 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'">
                                        {{ product.variants[0].quantity }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Variants (only shown when product has variants) -->
                    <div v-if="product.has_variants" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                                Variants ({{ product.variants.length }})
                            </h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">SKU</th>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Title</th>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Price</th>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Cost</th>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="variant in product.variants" :key="variant.id">
                                            <td class="whitespace-nowrap px-3 py-4 text-sm font-mono text-gray-500 dark:text-gray-300">
                                                {{ variant.sku }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                {{ variant.title || '-' }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                {{ formatPrice(variant.price) }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                {{ variant.cost ? formatPrice(variant.cost) : '-' }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                <span :class="{ 'text-red-600 dark:text-red-400': variant.quantity <= 0 }">
                                                    {{ variant.quantity }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Platform Listings -->
                    <PlatformListingsTab
                        :product-id="product.id"
                        :product-status="product.status"
                        :listings="platformListings || []"
                        :available-marketplaces="availableMarketplaces || []"
                        :product-updated-at="product.updated_at"
                        @refresh="refreshListings"
                    />
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Status -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Status</h3>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Status</span>
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium capitalize',
                                            product.status === 'active'
                                                ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20'
                                                : product.status === 'sold'
                                                    ? 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20'
                                                    : product.status === 'archive'
                                                        ? 'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20'
                                                        : product.status === 'in_repair'
                                                            ? 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20'
                                                            : 'bg-yellow-50 text-yellow-800 ring-1 ring-inset ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20',
                                        ]"
                                    >
                                        {{ product.status?.replace('_', ' ') || 'Draft' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vendor -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Vendor</h3>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ product.vendor?.name || '-' }}
                            </p>
                        </div>
                    </div>

                    <!-- Organization -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Organization</h3>
                            <dl class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Category</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ product.category?.name || '-' }}
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Brand</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ product.brand?.name || '-' }}
                                    </dd>
                                </div>
                                <div v-if="product.tags && product.tags.length > 0">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400 mb-2">Tags</dt>
                                    <dd class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="tag in product.tags"
                                            :key="tag.id"
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :style="{
                                                backgroundColor: tag.color + '20',
                                                color: tag.color,
                                                border: `1px solid ${tag.color}40`
                                            }"
                                        >
                                            {{ tag.name }}
                                        </span>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Inventory -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Inventory</h3>
                            <dl class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Total Quantity</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        <span :class="{ 'text-red-600 dark:text-red-400': product.total_quantity <= 0 }">
                                            {{ product.total_quantity }}
                                        </span>
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Track Quantity</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ product.track_quantity ? 'Yes' : 'No' }}
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Sell Out of Stock</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ product.sell_out_of_stock ? 'Yes' : 'No' }}
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Charge Taxes</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ product.charge_taxes ? 'Yes' : 'No' }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Dates -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Dates</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Created</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatDate(product.created_at) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Last Updated</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatDate(product.updated_at) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Activity Log -->
                    <ActivityTimeline
                        :activities="activityLogs"
                        title="Activity Log"
                    />
                </div>
            </div>
        </div>

        <!-- Image Lightbox -->
        <ImageLightbox
            v-model="lightboxOpen"
            :images="product.images"
            :initial-index="lightboxIndex"
        />
    </AppLayout>
</template>
