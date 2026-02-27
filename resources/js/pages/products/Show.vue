<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { ImageLightbox } from '@/components/images';
import { PlatformListingsTab } from '@/components/platforms';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { PencilIcon, TrashIcon, ArrowLeftIcon, PrinterIcon, ShoppingCartIcon, ArrowsRightLeftIcon, XMarkIcon, CheckCircleIcon } from '@heroicons/vue/20/solid';

interface Variant {
    id: number;
    sku: string;
    barcode: string | null;
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

interface Warehouse {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
}

interface DistributionRow {
    variant_id: number;
    variant_title: string | null;
    sku: string;
    warehouse_quantities: Record<number, number>;
    total_quantity: number;
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
    price_code: string | null;
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
    warehouses?: Warehouse[];
    inventoryDistribution?: DistributionRow[];
    barcodeAttributes?: string[];
    templateFieldValues?: Record<string, string | null>;
}

const props = defineProps<Props>();

// Flash message
const page = usePage();
const flashSuccess = ref<string | null>(null);

onMounted(() => {
    const flash = page.props.flash as { success?: string } | undefined;
    if (flash?.success) {
        flashSuccess.value = flash.success;
        setTimeout(() => {
            flashSuccess.value = null;
        }, 8000);
    }
});

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

const getAttributeValue = (attr: string, variant: Variant): string => {
    switch (attr.toLowerCase()) {
        case 'price_code':
            return props.product.price_code || '';
        case 'category':
            return props.product.category?.name || '';
        case 'price':
            return formatPrice(variant.price);
        case 'sku':
            return variant.sku || '';
        case 'barcode':
            return variant.barcode || variant.sku || '';
        default: {
            const snakeAttr = attr.toLowerCase().replace(/\s+/g, '_');
            return props.templateFieldValues?.[attr] || props.templateFieldValues?.[snakeAttr] || '';
        }
    }
};

const formatAttributeName = (attr: string): string => {
    return attr
        .replace(/_/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/\b\w/g, c => c.toUpperCase());
};

const deleteProduct = () => {
    if (confirm(`Are you sure you want to delete "${props.product.title}"?`)) {
        router.delete(`/products/${props.product.id}`);
    }
};

const refreshListings = () => {
    router.reload({ only: ['platformListings', 'availableMarketplaces'] });
};

// Transfer modal state
const showTransferModal = ref(false);
const transferForm = ref({
    from_warehouse_id: null as number | null,
    to_warehouse_id: null as number | null,
    notes: '',
    expected_at: '',
    items: [] as { product_variant_id: number; quantity_requested: number; sku: string; variant_title: string | null }[],
});
const transferErrors = ref<Record<string, string>>({});
const transferProcessing = ref(false);

const canSubmitTransfer = computed(() => {
    return (
        transferForm.value.from_warehouse_id &&
        transferForm.value.to_warehouse_id &&
        transferForm.value.from_warehouse_id !== transferForm.value.to_warehouse_id &&
        transferForm.value.items.length > 0 &&
        transferForm.value.items.every((i) => i.quantity_requested > 0)
    );
});

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] || '',
    );
}

function openTransferModal(row?: DistributionRow) {
    transferForm.value = {
        from_warehouse_id: null,
        to_warehouse_id: null,
        notes: '',
        expected_at: '',
        items: [],
    };
    transferErrors.value = {};

    if (row) {
        transferForm.value.items.push({
            product_variant_id: row.variant_id,
            quantity_requested: 1,
            sku: row.sku,
            variant_title: row.variant_title,
        });
    }

    showTransferModal.value = true;
}

function addVariantToTransfer(row: DistributionRow) {
    if (transferForm.value.items.some((i) => i.product_variant_id === row.variant_id)) {
        return;
    }
    transferForm.value.items.push({
        product_variant_id: row.variant_id,
        quantity_requested: 1,
        sku: row.sku,
        variant_title: row.variant_title,
    });
}

function removeTransferItem(index: number) {
    transferForm.value.items.splice(index, 1);
}

function submitTransfer(asDraft: boolean) {
    if (transferProcessing.value || !canSubmitTransfer.value) {
        return;
    }

    transferProcessing.value = true;
    transferErrors.value = {};

    const payload = {
        from_warehouse_id: transferForm.value.from_warehouse_id,
        to_warehouse_id: transferForm.value.to_warehouse_id,
        notes: transferForm.value.notes || null,
        expected_at: transferForm.value.expected_at || null,
        items: transferForm.value.items.map((i) => ({
            product_variant_id: i.product_variant_id,
            quantity_requested: i.quantity_requested,
        })),
    };

    fetch('/api/v1/inventory-transfers', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'include',
        body: JSON.stringify(payload),
    })
        .then(async (response) => {
            if (!response.ok) {
                const data = await response.json();
                transferErrors.value = data.errors || { general: data.message || 'An error occurred' };
                return;
            }

            const transfer = await response.json();

            if (!asDraft) {
                await fetch(`/api/v1/inventory-transfers/${transfer.id}/submit`, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'include',
                });
            }

            showTransferModal.value = false;
            router.reload();
        })
        .catch(() => {
            transferErrors.value = { general: 'An unexpected error occurred' };
        })
        .finally(() => {
            transferProcessing.value = false;
        });
}
</script>

<template>
    <Head :title="product.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Flash Message -->
            <transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="translate-y-[-8px] opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-[-8px] opacity-0"
            >
                <div v-if="flashSuccess" class="mb-6 rounded-lg bg-green-50 p-4 shadow-sm ring-1 ring-inset ring-green-200 dark:bg-green-900/20 dark:ring-green-800">
                    <div class="flex items-center">
                        <CheckCircleIcon class="size-6 text-green-500 dark:text-green-400" />
                        <p class="ml-3 text-sm font-semibold text-green-800 dark:text-green-300">{{ flashSuccess }}</p>
                        <button
                            type="button"
                            class="ml-auto rounded-md p-1 text-green-500 hover:bg-green-100 hover:text-green-600 dark:text-green-400 dark:hover:bg-green-800/30"
                            @click="flashSuccess = null"
                        >
                            <XMarkIcon class="size-5" />
                        </button>
                    </div>
                </div>
            </transition>

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
                    <!-- Sell button only shown for active products -->
                    <Link
                        v-if="product.status === 'active'"
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

                    <!-- Inventory Allocation -->
                    <div v-if="warehouses && warehouses.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Inventory Allocation</h3>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    @click="openTransferModal()"
                                >
                                    <ArrowsRightLeftIcon class="size-4" />
                                    Allocate
                                </button>
                            </div>
                            <div class="overflow-x-auto">
                                <table v-if="inventoryDistribution && inventoryDistribution.length > 0" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                                        <tr>
                                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                                Variant / SKU
                                            </th>
                                            <th
                                                v-for="wh in warehouses"
                                                :key="wh.id"
                                                scope="col"
                                                class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                                            >
                                                {{ wh.name }}
                                            </th>
                                            <th scope="col" class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                                Total
                                            </th>
                                            <th scope="col" class="relative px-3 py-3">
                                                <span class="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="row in inventoryDistribution" :key="row.variant_id">
                                            <td class="whitespace-nowrap px-3 py-3">
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                        {{ row.sku }}
                                                    </p>
                                                    <p v-if="row.variant_title" class="text-xs text-gray-500 dark:text-gray-400">
                                                        {{ row.variant_title }}
                                                    </p>
                                                </div>
                                            </td>
                                            <td
                                                v-for="wh in warehouses"
                                                :key="wh.id"
                                                class="whitespace-nowrap px-3 py-3 text-right text-sm"
                                                :class="row.warehouse_quantities[wh.id] > 0 ? 'font-medium text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500'"
                                            >
                                                {{ row.warehouse_quantities[wh.id] || 0 }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-3 text-right text-sm font-semibold text-gray-900 dark:text-white">
                                                {{ row.total_quantity }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-3 text-right text-sm">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                    @click="openTransferModal(row)"
                                                >
                                                    <ArrowsRightLeftIcon class="size-4" />
                                                    Allocate
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                                <div v-else class="py-8 text-center">
                                    <ArrowsRightLeftIcon class="mx-auto h-10 w-10 text-gray-400" />
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        No inventory allocation data for this product.
                                    </p>
                                </div>
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

                    <!-- Barcode Label -->
                    <div v-if="barcodeAttributes && barcodeAttributes.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Barcode Label</h3>
                            <dl class="space-y-3">
                                <div v-for="attr in barcodeAttributes" :key="attr" class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ formatAttributeName(attr) }}</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ getAttributeValue(attr, product.variants[0]) || '-' }}
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

        <!-- Create Transfer Modal -->
        <Teleport to="body">
            <div v-if="showTransferModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Create Transfer</h3>
                                <button type="button" class="text-gray-400 hover:text-gray-500" @click="showTransferModal = false">
                                    <XMarkIcon class="size-6" />
                                </button>
                            </div>

                            <div v-if="transferErrors.general" class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                {{ transferErrors.general }}
                            </div>

                            <div class="mt-5 space-y-4">
                                <!-- Warehouses -->
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">From Warehouse</label>
                                        <select
                                            v-model="transferForm.from_warehouse_id"
                                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">Select warehouse...</option>
                                            <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
                                        </select>
                                        <p v-if="transferErrors.from_warehouse_id" class="mt-1 text-xs text-red-600">{{ transferErrors.from_warehouse_id }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">To Warehouse</label>
                                        <select
                                            v-model="transferForm.to_warehouse_id"
                                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">Select warehouse...</option>
                                            <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
                                        </select>
                                        <p v-if="transferErrors.to_warehouse_id" class="mt-1 text-xs text-red-600">{{ transferErrors.to_warehouse_id }}</p>
                                    </div>
                                </div>

                                <!-- Items -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Items</label>

                                    <!-- Add variant buttons from distribution data -->
                                    <div v-if="inventoryDistribution && inventoryDistribution.length > 0" class="mt-1 flex flex-wrap gap-1.5">
                                        <button
                                            v-for="row in inventoryDistribution.filter((r) => !transferForm.items.some((i) => i.product_variant_id === r.variant_id))"
                                            :key="row.variant_id"
                                            type="button"
                                            class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                            @click="addVariantToTransfer(row)"
                                        >
                                            + {{ row.sku }}
                                            <span v-if="row.variant_title" class="ml-1 text-gray-500 dark:text-gray-400">{{ row.variant_title }}</span>
                                        </button>
                                    </div>

                                    <!-- Items list -->
                                    <div v-if="transferForm.items.length > 0" class="mt-2 space-y-2">
                                        <div
                                            v-for="(item, index) in transferForm.items"
                                            :key="item.product_variant_id"
                                            class="flex items-center gap-3 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-700/50"
                                        >
                                            <div class="flex-1">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ item.sku }}</p>
                                                <p v-if="item.variant_title" class="text-xs text-gray-500 dark:text-gray-400">{{ item.variant_title }}</p>
                                            </div>
                                            <input
                                                v-model.number="item.quantity_requested"
                                                type="number"
                                                min="1"
                                                class="w-20 rounded-md border-0 bg-white py-1 text-center text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-600 dark:text-white dark:ring-gray-500"
                                            />
                                            <button type="button" class="text-red-500 hover:text-red-700" @click="removeTransferItem(index)">
                                                <XMarkIcon class="size-5" />
                                            </button>
                                        </div>
                                    </div>
                                    <p v-else class="mt-2 text-xs text-gray-400 dark:text-gray-500">Click a variant above to add it to this transfer.</p>
                                </div>

                                <!-- Notes -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (optional)</label>
                                    <textarea
                                        v-model="transferForm.notes"
                                        rows="2"
                                        class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>

                                <!-- Expected date -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Expected Date (optional)</label>
                                    <input
                                        v-model="transferForm.expected_at"
                                        type="date"
                                        class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>

                            <div class="mt-5 flex justify-end gap-3 sm:mt-6">
                                <button
                                    type="button"
                                    class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showTransferModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="transferProcessing || !canSubmitTransfer"
                                    class="inline-flex justify-center rounded-md bg-gray-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500 disabled:opacity-50"
                                    @click="submitTransfer(true)"
                                >
                                    {{ transferProcessing ? 'Saving...' : 'Save as Draft' }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="transferProcessing || !canSubmitTransfer"
                                    class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                    @click="submitTransfer(false)"
                                >
                                    {{ transferProcessing ? 'Saving...' : 'Submit Transfer' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
