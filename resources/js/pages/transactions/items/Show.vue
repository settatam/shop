<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { NotesSection } from '@/components/notes';
import { ImageLightbox } from '@/components/images';
import SimilarItemsSection from '@/components/transactions/SimilarItemsSection.vue';
import AiResearchCard from '@/components/transactions/AiResearchCard.vue';
import WebPriceSearchCard from '@/components/transactions/WebPriceSearchCard.vue';
import ShareWithTeamModal from '@/components/transactions/ShareWithTeamModal.vue';
import ItemChatPanel from '@/components/transactions/ItemChatPanel.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import { ArrowLeftIcon, PencilIcon, ArchiveBoxArrowDownIcon, RectangleStackIcon, XMarkIcon, ShareIcon, SparklesIcon } from '@heroicons/vue/20/solid';

interface ItemImage {
    id: number;
    url: string;
    thumbnail_url: string | null;
    alt_text: string | null;
    is_primary: boolean;
}

interface Category {
    id: number;
    name: string;
    full_path: string;
}

interface Customer {
    id: number;
    full_name: string;
    email: string | null;
}

interface User {
    id: number;
    name: string;
}

interface Transaction {
    id: number;
    transaction_number: string;
    status: string;
    type: string;
    final_offer: number | null;
    total_buy_price: number | null;
    created_at: string;
    customer: Customer | null;
    user: User | null;
}

interface TransactionItem {
    id: number;
    transaction_id: number;
    title: string;
    description: string | null;
    sku: string | null;
    quantity: number;
    category_id: number | null;
    category: Category | null;
    price: number | null;
    buy_price: number | null;
    dwt: number | null;
    precious_metal: string | null;
    condition: string | null;
    attributes: Record<string, string> | null;
    is_added_to_inventory: boolean;
    is_added_to_bucket: boolean;
    date_added_to_inventory: string | null;
    product_id: number | null;
    bucket_id: number | null;
    ai_research: Record<string, any> | null;
    ai_research_generated_at: string | null;
    web_search_results: Record<string, any> | null;
    web_search_generated_at: string | null;
    images: ItemImage[];
    created_at: string;
    updated_at: string;
}

interface ActivityDay {
    date: string;
    dateTime: string;
    items: any[];
}

interface MetalOption {
    value: string;
    label: string;
}

interface FieldOption {
    value: string;
    label: string;
}

interface TemplateField {
    id: number;
    name: string;
    label: string;
    type: string;
    options: FieldOption[];
}

interface Note {
    id: number;
    content: string;
    user: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
}

interface Bucket {
    id: number;
    name: string;
}

interface TeamMember {
    id: number;
    name: string;
    email?: string;
}

interface Vendor {
    id: number;
    name: string;
    company_name: string | null;
    display_name?: string;
}

interface Warehouse {
    id: number;
    name: string;
    is_default: boolean;
}

interface Props {
    transaction: Transaction;
    item: TransactionItem;
    preciousMetals: MetalOption[];
    conditions: MetalOption[];
    templateFields: TemplateField[];
    notes: Note[];
    buckets: Bucket[];
    teamMembers: TeamMember[];
    vendors: Vendor[];
    warehouses: Warehouse[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transactions', href: '/transactions' },
    { title: props.transaction.transaction_number, href: `/transactions/${props.transaction.id}` },
    { title: props.item.title || 'Item', href: `/transactions/${props.transaction.id}/items/${props.item.id}` },
];

const selectedImage = ref<ItemImage | null>(props.item.images[0] || null);
const lightboxOpen = ref(false);
const lightboxIndex = ref(0);
const movingToInventory = ref(false);

const openLightbox = (index: number) => {
    lightboxIndex.value = index;
    lightboxOpen.value = true;
};

// Move to Inventory modal state
const showInventoryModal = ref(false);
const selectedVendorId = ref<number | null>(null);
const selectedWarehouseId = ref<number | null>(props.warehouses.find(w => w.is_default)?.id ?? null);
const inventoryQuantity = ref<number>(props.item.quantity || 1);

// Move to Bucket modal state
const showBucketModal = ref(false);

// Share with team modal state
const showShareModal = ref(false);
const selectedBucketId = ref<number | null>(null);
const bucketValue = ref<string>(String(props.item.buy_price ?? props.item.price ?? 0));
const movingToBucket = ref(false);

const formatPrice = (price: number | null) => {
    if (price === null || price === undefined) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
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

const getMetalLabel = (value: string | null) => {
    if (!value) return null;
    return props.preciousMetals.find(m => m.value === value)?.label || value;
};

const getConditionLabel = (value: string | null) => {
    if (!value) return null;
    return props.conditions.find(c => c.value === value)?.label || value;
};

const getAttributeDisplayValue = (field: TemplateField) => {
    const value = props.item.attributes?.[field.id];
    if (!value) return null;

    // For select/radio/checkbox, look up the label
    if (['select', 'radio', 'checkbox'].includes(field.type)) {
        const option = field.options.find(o => o.value === value);
        return option?.label || value;
    }

    return value;
};

const margin = (() => {
    if (!props.item.price || !props.item.buy_price || props.item.buy_price === 0) return null;
    return ((props.item.price - props.item.buy_price) / props.item.buy_price * 100).toFixed(1);
})();

const openInventoryModal = () => {
    selectedVendorId.value = null;
    selectedWarehouseId.value = props.warehouses.find(w => w.is_default)?.id ?? props.warehouses[0]?.id ?? null;
    inventoryQuantity.value = props.item.quantity || 1;
    showInventoryModal.value = true;
};

const closeInventoryModal = () => {
    showInventoryModal.value = false;
};

const moveToInventory = () => {
    if (!selectedVendorId.value) {
        alert('Please select a vendor.');
        return;
    }
    if (!selectedWarehouseId.value) {
        alert('Please select a warehouse.');
        return;
    }
    if (inventoryQuantity.value < 1) {
        alert('Quantity must be at least 1.');
        return;
    }
    movingToInventory.value = true;
    router.post(`/transactions/${props.transaction.id}/items/${props.item.id}/move-to-inventory`, {
        vendor_id: selectedVendorId.value,
        warehouse_id: selectedWarehouseId.value,
        quantity: inventoryQuantity.value,
        status: 'active',
    }, {
        onSuccess: () => {
            showInventoryModal.value = false;
        },
        onFinish: () => {
            movingToInventory.value = false;
        },
    });
};

const openBucketModal = () => {
    bucketValue.value = String(props.item.buy_price ?? props.item.price ?? 0);
    selectedBucketId.value = null;
    showBucketModal.value = true;
};

const closeBucketModal = () => {
    showBucketModal.value = false;
};

const moveToBucket = () => {
    if (!selectedBucketId.value) {
        alert('Please select a bucket.');
        return;
    }
    movingToBucket.value = true;
    router.post(`/transactions/${props.transaction.id}/items/${props.item.id}/move-to-bucket`, {
        bucket_id: selectedBucketId.value,
        value: parseFloat(bucketValue.value) || 0,
    }, {
        onSuccess: () => {
            showBucketModal.value = false;
        },
        onFinish: () => {
            movingToBucket.value = false;
        },
    });
};

// AI Auto-populate state
const autoPopulatingFields = ref(false);
const autoPopulateError = ref<string | null>(null);
const autoPopulateResult = ref<{
    identified: boolean;
    confidence: string;
    product_info: Record<string, string>;
    fields: Record<string, string>;
    notes?: string;
} | null>(null);
const applyingChanges = ref(false);

const autoPopulateFields = async () => {
    if (!props.item.category_id) {
        autoPopulateError.value = 'Please select a category first. The category determines which template fields to populate.';
        return;
    }

    autoPopulatingFields.value = true;
    autoPopulateError.value = null;
    autoPopulateResult.value = null;

    try {
        const response = await axios.post(`/transactions/${props.transaction.id}/items/${props.item.id}/auto-populate-fields`);
        autoPopulateResult.value = response.data;
    } catch (error: any) {
        autoPopulateError.value = error.response?.data?.error || 'Failed to auto-populate fields. Please try again.';
    } finally {
        autoPopulatingFields.value = false;
    }
};

const applyAutoPopulatedFields = () => {
    if (!autoPopulateResult.value?.fields) return;

    applyingChanges.value = true;

    // Build the attributes object with the AI suggestions
    const newAttributes = { ...props.item.attributes };
    for (const [fieldId, value] of Object.entries(autoPopulateResult.value.fields)) {
        newAttributes[fieldId] = value;
    }

    router.patch(`/transactions/${props.transaction.id}/items/${props.item.id}`, {
        attributes: newAttributes,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            autoPopulateResult.value = null;
        },
        onFinish: () => {
            applyingChanges.value = false;
        },
    });
};
</script>

<template>
    <Head :title="item.title || 'Transaction Item'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-4">
                    <Link
                        :href="`/transactions/${transaction.id}`"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ item.title || 'Untitled Item' }}</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Transaction {{ transaction.transaction_number }}
                            <span v-if="item.sku"> &middot; SKU: {{ item.sku }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button
                        v-if="!item.is_added_to_inventory && !item.is_added_to_bucket && vendors.length > 0"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50"
                        :disabled="movingToInventory"
                        @click="openInventoryModal"
                    >
                        <ArchiveBoxArrowDownIcon class="-ml-0.5 size-5" />
                        Move to Inventory
                    </button>
                    <button
                        v-if="!item.is_added_to_inventory && !item.is_added_to_bucket && buckets.length > 0"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500"
                        @click="openBucketModal"
                    >
                        <RectangleStackIcon class="-ml-0.5 size-5" />
                        Move to Bucket
                    </button>
                    <button
                        v-if="teamMembers.length > 0"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-gray-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500"
                        @click="showShareModal = true"
                    >
                        <ShareIcon class="-ml-0.5 size-5" />
                        Share
                    </button>
                    <Link
                        :href="`/transactions/${transaction.id}/items/${item.id}/edit`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        <PencilIcon class="-ml-0.5 size-5" />
                        Edit
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Images -->
                    <div v-if="item.images.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Images</h3>
                            <!-- Main image -->
                            <button
                                v-if="selectedImage"
                                type="button"
                                class="mb-4 w-full overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700 cursor-zoom-in"
                                @click="openLightbox(item.images.findIndex(img => img.id === selectedImage?.id))"
                            >
                                <img
                                    :src="selectedImage.url"
                                    :alt="selectedImage.alt_text || item.title"
                                    class="mx-auto max-h-96 object-contain"
                                />
                            </button>
                            <!-- Thumbnails -->
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="image in item.images"
                                    :key="image.id"
                                    type="button"
                                    class="relative h-16 w-16 overflow-hidden rounded-lg ring-2 transition"
                                    :class="selectedImage?.id === image.id ? 'ring-indigo-500' : 'ring-transparent hover:ring-gray-300 dark:hover:ring-gray-600'"
                                    @click="selectedImage = image"
                                >
                                    <img
                                        :src="image.thumbnail_url || image.url"
                                        :alt="image.alt_text || ''"
                                        class="h-full w-full object-cover"
                                    />
                                    <span
                                        v-if="image.is_primary"
                                        class="absolute bottom-0 left-0 right-0 bg-indigo-600 px-1 text-center text-[10px] text-white"
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
                            <p v-if="item.description" class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{{ item.description }}</p>
                            <p v-else class="text-sm text-gray-400 dark:text-gray-500 italic">No description provided</p>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Pricing</h3>
                            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Estimated Value</dt>
                                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ formatPrice(item.price) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Buy Price</dt>
                                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ formatPrice(item.buy_price) }}</dd>
                                </div>
                                <div v-if="margin !== null">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Margin</dt>
                                    <dd class="mt-1 text-sm font-medium" :class="parseFloat(margin) >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                        {{ margin }}%
                                    </dd>
                                </div>
                                <div v-if="item.dwt">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Weight (DWT)</dt>
                                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ item.dwt }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Similar Items -->
                    <SimilarItemsSection :transaction-id="transaction.id" :item-id="item.id" />

                    <!-- AI Research -->
                    <AiResearchCard
                        :transaction-id="transaction.id"
                        :item-id="item.id"
                        :existing-research="item.ai_research"
                        :generated-at="item.ai_research_generated_at"
                    />

                    <!-- Web Price Search -->
                    <WebPriceSearchCard
                        :transaction-id="transaction.id"
                        :item-id="item.id"
                        :existing-results="item.web_search_results"
                        :generated-at="item.web_search_generated_at"
                    />

                    <!-- Chat -->
                    <ItemChatPanel :transaction-id="transaction.id" :item-id="item.id" />

                    <!-- Notes -->
                    <NotesSection
                        :notes="notes"
                        notable-type="App\\Models\\TransactionItem"
                        :notable-id="item.id"
                    />

                    <!-- Activity Timeline -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Activity</h3>
                            <ActivityTimeline v-if="activityLogs && activityLogs.length > 0" :days="activityLogs" />
                            <div v-else class="flex items-center justify-center py-8">
                                <div class="animate-pulse space-y-3 w-full">
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-2/3"></div>
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
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="item.is_added_to_inventory
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400'"
                                    >
                                        {{ item.is_added_to_inventory ? 'In Inventory' : 'Not in Inventory' }}
                                    </span>
                                </div>
                                <div v-if="item.product_id">
                                    <Link
                                        :href="`/products/${item.product_id}`"
                                        class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    >
                                        View Product #{{ item.product_id }}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Info -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Transaction</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Number</dt>
                                    <dd class="mt-0.5">
                                        <Link
                                            :href="`/transactions/${transaction.id}`"
                                            class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                        >
                                            {{ transaction.transaction_number }}
                                        </Link>
                                    </dd>
                                </div>
                                <div v-if="transaction.customer">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Customer</dt>
                                    <dd class="mt-0.5">
                                        <Link
                                            :href="`/customers/${transaction.customer.id}`"
                                            class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                        >
                                            {{ transaction.customer.full_name }}
                                        </Link>
                                    </dd>
                                </div>
                                <div v-if="transaction.user">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Created By</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ transaction.user.name }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Details -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Details</h3>
                                <button
                                    v-if="item.category_id && templateFields.length > 0"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-gradient-to-r from-purple-600 to-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:from-purple-500 hover:to-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    :disabled="autoPopulatingFields"
                                    @click="autoPopulateFields"
                                >
                                    <SparklesIcon class="size-3.5" :class="{ 'animate-pulse': autoPopulatingFields }" />
                                    {{ autoPopulatingFields ? 'Analyzing...' : 'Auto-fill with AI' }}
                                </button>
                            </div>

                            <!-- AI Auto-populate Error -->
                            <div
                                v-if="autoPopulateError"
                                class="mb-4 rounded-md bg-red-50 p-3 dark:bg-red-900/20"
                            >
                                <p class="text-sm text-red-700 dark:text-red-400">{{ autoPopulateError }}</p>
                            </div>

                            <!-- AI Auto-populate Results -->
                            <div
                                v-if="autoPopulateResult"
                                class="mb-4 rounded-md border border-purple-200 bg-purple-50 p-3 dark:border-purple-800 dark:bg-purple-900/20"
                            >
                                <div class="flex items-start gap-2">
                                    <SparklesIcon class="size-4 text-purple-600 dark:text-purple-400 mt-0.5 shrink-0" />
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-purple-900 dark:text-purple-200">
                                            {{ autoPopulateResult.identified ? 'Product Identified' : 'Could not identify product' }}
                                            <span
                                                class="ml-1.5 inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium"
                                                :class="{
                                                    'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': autoPopulateResult.confidence === 'high',
                                                    'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400': autoPopulateResult.confidence === 'medium',
                                                    'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300': autoPopulateResult.confidence === 'low',
                                                }"
                                            >
                                                {{ autoPopulateResult.confidence }} confidence
                                            </span>
                                        </p>
                                        <div v-if="autoPopulateResult.product_info && Object.keys(autoPopulateResult.product_info).length" class="mt-1.5">
                                            <p class="text-xs text-purple-700 dark:text-purple-300">
                                                <span v-if="autoPopulateResult.product_info.brand">{{ autoPopulateResult.product_info.brand }}</span>
                                                <span v-if="autoPopulateResult.product_info.model"> {{ autoPopulateResult.product_info.model }}</span>
                                                <span v-if="autoPopulateResult.product_info.reference_number"> ({{ autoPopulateResult.product_info.reference_number }})</span>
                                            </p>
                                        </div>
                                        <p v-if="autoPopulateResult.notes" class="mt-1 text-xs text-purple-600 dark:text-purple-400">
                                            {{ autoPopulateResult.notes }}
                                        </p>
                                        <p v-if="autoPopulateResult.fields && Object.keys(autoPopulateResult.fields).length" class="mt-1 text-xs text-purple-600 dark:text-purple-400">
                                            {{ Object.keys(autoPopulateResult.fields).length }} field(s) ready to apply
                                        </p>
                                        <div class="mt-2 flex gap-2">
                                            <button
                                                v-if="autoPopulateResult.fields && Object.keys(autoPopulateResult.fields).length"
                                                type="button"
                                                class="inline-flex items-center rounded-md bg-purple-600 px-2 py-1 text-xs font-semibold text-white shadow-sm hover:bg-purple-500 disabled:opacity-50"
                                                :disabled="applyingChanges"
                                                @click="applyAutoPopulatedFields"
                                            >
                                                {{ applyingChanges ? 'Applying...' : 'Apply Changes' }}
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md bg-white px-2 py-1 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600"
                                                @click="autoPopulateResult = null"
                                            >
                                                Dismiss
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Category</dt>
                                    <dd class="mt-0.5 text-sm" :class="item.category ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500 italic'">
                                        {{ item.category ? (item.category.full_path || item.category.name) : 'Not set' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Metal</dt>
                                    <dd class="mt-0.5 text-sm" :class="item.precious_metal ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500 italic'">
                                        {{ item.precious_metal ? getMetalLabel(item.precious_metal) : 'Not set' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Condition</dt>
                                    <dd class="mt-0.5 text-sm" :class="item.condition ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500 italic'">
                                        {{ item.condition ? getConditionLabel(item.condition) : 'Not set' }}
                                    </dd>
                                </div>
                                <!-- Template Fields -->
                                <div v-for="field in templateFields" :key="field.id">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ field.label }}</dt>
                                    <dd class="mt-0.5 text-sm" :class="getAttributeDisplayValue(field) ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500 italic'">
                                        {{ getAttributeDisplayValue(field) || 'Not set' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Created</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ formatDate(item.created_at) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Updated</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ formatDate(item.updated_at) }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Move to Inventory Modal -->
        <div
            v-if="showInventoryModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            aria-labelledby="inventory-modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <!-- Backdrop -->
                <div
                    class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75"
                    @click="closeInventoryModal"
                ></div>

                <!-- Modal panel -->
                <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                    <div class="absolute right-0 top-0 pr-4 pt-4">
                        <button
                            type="button"
                            class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none dark:bg-gray-800 dark:text-gray-500 dark:hover:text-gray-400"
                            @click="closeInventoryModal"
                        >
                            <span class="sr-only">Close</span>
                            <XMarkIcon class="size-6" />
                        </button>
                    </div>

                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-green-100 sm:mx-0 sm:size-10 dark:bg-green-900/30">
                            <ArchiveBoxArrowDownIcon class="size-6 text-green-600 dark:text-green-400" />
                        </div>
                        <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left flex-1">
                            <h3 id="inventory-modal-title" class="text-base font-semibold text-gray-900 dark:text-white">
                                Move to Inventory
                            </h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                A new product will be created and listed on the In Store channel.
                            </p>
                            <div class="mt-4 space-y-4">
                                <!-- Vendor selection -->
                                <div>
                                    <label for="vendor-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Vendor <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        id="vendor-select"
                                        v-model="selectedVendorId"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option :value="null" disabled>Choose a vendor...</option>
                                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">
                                            {{ vendor.company_name || vendor.name }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Warehouse selection -->
                                <div>
                                    <label for="warehouse-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Warehouse <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        id="warehouse-select"
                                        v-model="selectedWarehouseId"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option :value="null" disabled>Choose a warehouse...</option>
                                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                            {{ warehouse.name }}{{ warehouse.is_default ? ' (Default)' : '' }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Quantity input -->
                                <div>
                                    <label for="inventory-quantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Quantity <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="inventory-quantity"
                                        v-model.number="inventoryQuantity"
                                        type="number"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Item cannot be active without a quantity of at least 1.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                        <button
                            type="button"
                            class="inline-flex w-full justify-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 sm:w-auto disabled:opacity-50"
                            :disabled="movingToInventory || !selectedVendorId || !selectedWarehouseId || inventoryQuantity < 1"
                            @click="moveToInventory"
                        >
                            {{ movingToInventory ? 'Creating Product...' : 'Move to Inventory' }}
                        </button>
                        <button
                            type="button"
                            class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                            @click="closeInventoryModal"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Move to Bucket Modal -->
        <div
            v-if="showBucketModal"
            class="fixed inset-0 z-50 overflow-y-auto"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <!-- Backdrop -->
                <div
                    class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75"
                    @click="closeBucketModal"
                ></div>

                <!-- Modal panel -->
                <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                    <div class="absolute right-0 top-0 pr-4 pt-4">
                        <button
                            type="button"
                            class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none dark:bg-gray-800 dark:text-gray-500 dark:hover:text-gray-400"
                            @click="closeBucketModal"
                        >
                            <span class="sr-only">Close</span>
                            <XMarkIcon class="size-6" />
                        </button>
                    </div>

                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-amber-100 sm:mx-0 sm:size-10 dark:bg-amber-900/30">
                            <RectangleStackIcon class="size-6 text-amber-600 dark:text-amber-400" />
                        </div>
                        <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left flex-1">
                            <h3 id="modal-title" class="text-base font-semibold text-gray-900 dark:text-white">
                                Move to Bucket
                            </h3>
                            <div class="mt-4 space-y-4">
                                <!-- Bucket selection -->
                                <div>
                                    <label for="bucket-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Select Bucket
                                    </label>
                                    <select
                                        id="bucket-select"
                                        v-model="selectedBucketId"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option :value="null" disabled>Choose a bucket...</option>
                                        <option v-for="bucket in buckets" :key="bucket.id" :value="bucket.id">
                                            {{ bucket.name }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Value input -->
                                <div>
                                    <label for="bucket-value" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Value
                                    </label>
                                    <div class="relative mt-1 rounded-md shadow-sm">
                                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                            <span class="text-gray-500 sm:text-sm dark:text-gray-400">$</span>
                                        </div>
                                        <input
                                            id="bucket-value"
                                            v-model="bucketValue"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="block w-full rounded-md border-0 py-2 pl-7 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            placeholder="0.00"
                                        />
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        This value will be added to the bucket total.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                        <button
                            type="button"
                            class="inline-flex w-full justify-center rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 sm:w-auto disabled:opacity-50"
                            :disabled="movingToBucket || !selectedBucketId"
                            @click="moveToBucket"
                        >
                            {{ movingToBucket ? 'Moving...' : 'Move to Bucket' }}
                        </button>
                        <button
                            type="button"
                            class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                            @click="closeBucketModal"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Image Lightbox -->
        <ImageLightbox
            v-model="lightboxOpen"
            :images="item.images"
            :initial-index="lightboxIndex"
        />

        <!-- Share with Team Modal -->
        <ShareWithTeamModal
            v-model:open="showShareModal"
            :team-members="teamMembers"
            :share-url="`/transactions/${transaction.id}/items/${item.id}/share`"
        />
    </AppLayout>
</template>
