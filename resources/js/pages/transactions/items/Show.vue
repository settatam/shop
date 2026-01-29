<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import SimilarItemsSection from '@/components/transactions/SimilarItemsSection.vue';
import AiResearchCard from '@/components/transactions/AiResearchCard.vue';
import ItemChatPanel from '@/components/transactions/ItemChatPanel.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeftIcon, PencilIcon, ArchiveBoxArrowDownIcon } from '@heroicons/vue/20/solid';

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
    category_id: number | null;
    category: Category | null;
    price: number | null;
    buy_price: number | null;
    dwt: number | null;
    precious_metal: string | null;
    condition: string | null;
    attributes: Record<string, string> | null;
    is_added_to_inventory: boolean;
    date_added_to_inventory: string | null;
    product_id: number | null;
    ai_research: Record<string, any> | null;
    ai_research_generated_at: string | null;
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

interface Props {
    transaction: Transaction;
    item: TransactionItem;
    preciousMetals: MetalOption[];
    conditions: MetalOption[];
    templateFields: TemplateField[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transactions', href: '/transactions' },
    { title: props.transaction.transaction_number, href: `/transactions/${props.transaction.id}` },
    { title: props.item.title || 'Item', href: `/transactions/${props.transaction.id}/items/${props.item.id}` },
];

const selectedImage = ref<ItemImage | null>(props.item.images[0] || null);
const movingToInventory = ref(false);

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

const moveToInventory = () => {
    if (!confirm('Move this item to inventory? A new product will be created as a draft.')) return;
    movingToInventory.value = true;
    router.post(`/transactions/${props.transaction.id}/items/${props.item.id}/move-to-inventory`, {}, {
        onFinish: () => { movingToInventory.value = false; },
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
                        v-if="!item.is_added_to_inventory"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50"
                        :disabled="movingToInventory"
                        @click="moveToInventory"
                    >
                        <ArchiveBoxArrowDownIcon class="-ml-0.5 size-5" />
                        {{ movingToInventory ? 'Moving...' : 'Move to Inventory' }}
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
                            <div v-if="selectedImage" class="mb-4 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700">
                                <img
                                    :src="selectedImage.url"
                                    :alt="selectedImage.alt_text || item.title"
                                    class="mx-auto max-h-96 object-contain"
                                />
                            </div>
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

                    <!-- Chat -->
                    <ItemChatPanel :transaction-id="transaction.id" :item-id="item.id" />
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
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Details</h3>
                            <dl class="space-y-3">
                                <div v-if="item.category">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Category</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ item.category.full_path || item.category.name }}</dd>
                                </div>
                                <div v-if="item.precious_metal">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Metal</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ getMetalLabel(item.precious_metal) }}</dd>
                                </div>
                                <div v-if="item.condition">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Condition</dt>
                                    <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ getConditionLabel(item.condition) }}</dd>
                                </div>
                                <!-- Template Fields -->
                                <template v-for="field in templateFields" :key="field.id">
                                    <div v-if="getAttributeDisplayValue(field)">
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ field.label }}</dt>
                                        <dd class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ getAttributeDisplayValue(field) }}</dd>
                                    </div>
                                </template>
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
            </div>
        </div>
    </AppLayout>
</template>
