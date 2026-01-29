<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { PencilIcon, TrashIcon, ArrowLeftIcon } from '@heroicons/vue/20/solid';

interface Tag {
    id: number;
    name: string;
    color: string;
}

interface Vendor {
    id: number;
    name: string;
    company_name: string | null;
}

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

interface Product {
    id: number;
    title: string;
    description: string | null;
    handle: string;
    is_published: boolean;
    is_draft: boolean;
    has_variants: boolean;
    track_quantity: boolean;
    sell_out_of_stock: boolean;
    total_quantity: number;
    created_at: string;
    updated_at: string;
    category: { id: number; name: string } | null;
    brand: { id: number; name: string } | null;
    vendor: Vendor | null;
    tags: Tag[];
    variants: Variant[];
    images: Image[];
}

interface Props {
    product: Product;
}

const props = defineProps<Props>();

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
                    </div>
                </div>
                <div class="flex gap-3">
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
                                <div
                                    v-for="image in product.images"
                                    :key="image.id"
                                    class="relative h-32 w-32 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700"
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
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Description</h3>
                            <p v-if="product.description" class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">
                                {{ product.description }}
                            </p>
                            <p v-else class="text-sm text-gray-400 dark:text-gray-500 italic">
                                No description provided
                            </p>
                        </div>
                    </div>

                    <!-- Variants -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
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
                                            'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
                                            product.is_published
                                                ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20'
                                                : 'bg-yellow-50 text-yellow-800 ring-1 ring-inset ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20',
                                        ]"
                                    >
                                        {{ product.is_published ? 'Published' : 'Draft' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vendor (Custom Feature) -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Vendor</h3>
                            <div v-if="product.vendor" class="space-y-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ product.vendor.name }}
                                </p>
                                <p v-if="product.vendor.company_name" class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ product.vendor.company_name }}
                                </p>
                            </div>
                            <p v-else class="text-sm text-gray-400 dark:text-gray-500 italic">
                                No vendor assigned
                            </p>
                        </div>
                    </div>

                    <!-- Tags -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Tags</h3>
                            <div v-if="product.tags.length > 0" class="flex flex-wrap gap-2">
                                <span
                                    v-for="tag in product.tags"
                                    :key="tag.id"
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :style="{
                                        backgroundColor: tag.color + '20',
                                        color: tag.color,
                                        border: `1px solid ${tag.color}40`,
                                    }"
                                >
                                    {{ tag.name }}
                                </span>
                            </div>
                            <p v-else class="text-sm text-gray-400 dark:text-gray-500 italic">
                                No tags added
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
                </div>
            </div>
        </div>
    </AppLayout>
</template>
