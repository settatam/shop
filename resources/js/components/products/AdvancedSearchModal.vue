<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { MagnifyingGlassIcon } from '@heroicons/vue/20/solid';
import { Package, ShoppingCart, ArrowDownToLine } from 'lucide-vue-next';
import axios from 'axios';

interface SearchResultItem {
    id: number;
    title: string;
    sku: string | null;
    brand?: string | null;
    category?: string | null;
    price: number | null;
    status?: string;
    image?: string | null;
    url: string;
    // For bought items
    transaction_id?: number;
    transaction_number?: string | null;
    // For sold items
    order_id?: number;
    invoice_number?: string | null;
    // Common
    customer_name?: string | null;
    date?: string | null;
}

interface SearchResults {
    active: SearchResultItem[];
    bought: SearchResultItem[];
    sold: SearchResultItem[];
}

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const searchQuery = ref('');
const loading = ref(false);
const results = ref<SearchResults>({
    active: [],
    bought: [],
    sold: [],
});
const activeTab = ref<'active' | 'bought' | 'sold'>('active');

// Debounce timer
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

// Watch for search query changes
watch(searchQuery, (newQuery) => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    if (newQuery.length < 2) {
        results.value = { active: [], bought: [], sold: [] };
        return;
    }

    searchTimeout = setTimeout(() => {
        performSearch(newQuery);
    }, 300);
});

// Reset when modal opens
watch(() => props.open, (isOpen) => {
    if (isOpen) {
        searchQuery.value = '';
        results.value = { active: [], bought: [], sold: [] };
        activeTab.value = 'active';
    }
});

async function performSearch(query: string) {
    loading.value = true;
    try {
        const response = await axios.get('/products/advanced-search', {
            params: { query, limit: 15 },
        });
        results.value = response.data;

        // Auto-switch to first tab with results
        if (results.value.active.length > 0) {
            activeTab.value = 'active';
        } else if (results.value.bought.length > 0) {
            activeTab.value = 'bought';
        } else if (results.value.sold.length > 0) {
            activeTab.value = 'sold';
        }
    } catch (error) {
        console.error('Search failed:', error);
    } finally {
        loading.value = false;
    }
}

const totalResults = computed(() => {
    return results.value.active.length + results.value.bought.length + results.value.sold.length;
});

const currentResults = computed(() => {
    return results.value[activeTab.value] || [];
});

function formatCurrency(amount: number | null): string {
    if (amount === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
}

function closeModal() {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-w-3xl max-h-[80vh] flex flex-col">
            <DialogHeader>
                <DialogTitle>Advanced Product Search</DialogTitle>
            </DialogHeader>

            <!-- Search Input -->
            <div class="relative">
                <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                <Input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search by title, brand, SKU..."
                    class="pl-10"
                    autofocus
                />
            </div>

            <!-- Tabs -->
            <div class="flex gap-2 border-b border-gray-200 dark:border-gray-700">
                <button
                    type="button"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
                    :class="activeTab === 'active'
                        ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                        : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    @click="activeTab = 'active'"
                >
                    <Package class="h-4 w-4" />
                    Active
                    <Badge v-if="results.active.length > 0" variant="secondary" class="ml-1">
                        {{ results.active.length }}
                    </Badge>
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
                    :class="activeTab === 'bought'
                        ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                        : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    @click="activeTab = 'bought'"
                >
                    <ArrowDownToLine class="h-4 w-4" />
                    Bought
                    <Badge v-if="results.bought.length > 0" variant="secondary" class="ml-1">
                        {{ results.bought.length }}
                    </Badge>
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
                    :class="activeTab === 'sold'
                        ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                        : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                    @click="activeTab = 'sold'"
                >
                    <ShoppingCart class="h-4 w-4" />
                    Sold
                    <Badge v-if="results.sold.length > 0" variant="secondary" class="ml-1">
                        {{ results.sold.length }}
                    </Badge>
                </button>
            </div>

            <!-- Results -->
            <div class="flex-1 overflow-y-auto min-h-[300px]">
                <!-- Loading State -->
                <div v-if="loading" class="flex items-center justify-center py-12">
                    <div class="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
                </div>

                <!-- Empty State -->
                <div
                    v-else-if="searchQuery.length >= 2 && totalResults === 0"
                    class="flex flex-col items-center justify-center py-12 text-gray-500 dark:text-gray-400"
                >
                    <MagnifyingGlassIcon class="h-12 w-12 mb-4" />
                    <p class="text-lg font-medium">No results found</p>
                    <p class="text-sm">Try a different search term</p>
                </div>

                <!-- Initial State -->
                <div
                    v-else-if="searchQuery.length < 2"
                    class="flex flex-col items-center justify-center py-12 text-gray-500 dark:text-gray-400"
                >
                    <MagnifyingGlassIcon class="h-12 w-12 mb-4" />
                    <p class="text-sm">Enter at least 2 characters to search</p>
                </div>

                <!-- Results List -->
                <div v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                    <!-- Active Products -->
                    <template v-if="activeTab === 'active'">
                        <Link
                            v-for="item in results.active"
                            :key="`active-${item.id}`"
                            :href="item.url"
                            class="flex items-center gap-4 p-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                            @click="closeModal"
                        >
                            <div class="h-12 w-12 flex-shrink-0 rounded-lg bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                <img
                                    v-if="item.image"
                                    :src="item.image"
                                    :alt="item.title"
                                    class="h-full w-full object-cover"
                                />
                                <div v-else class="flex h-full w-full items-center justify-center">
                                    <Package class="h-6 w-6 text-gray-400" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                    {{ item.title }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="item.sku">SKU: {{ item.sku }}</span>
                                    <span v-if="item.sku && item.brand"> &middot; </span>
                                    <span v-if="item.brand">{{ item.brand }}</span>
                                    <span v-if="(item.sku || item.brand) && item.category"> &middot; </span>
                                    <span v-if="item.category">{{ item.category }}</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span v-if="item.price" class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(item.price) }}
                                </span>
                                <Badge
                                    :variant="item.status === 'active' ? 'default' : 'secondary'"
                                    class="capitalize"
                                >
                                    {{ item.status }}
                                </Badge>
                            </div>
                        </Link>
                    </template>

                    <!-- Bought Items -->
                    <template v-if="activeTab === 'bought'">
                        <Link
                            v-for="item in results.bought"
                            :key="`bought-${item.id}`"
                            :href="item.url"
                            class="flex items-center gap-4 p-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                            @click="closeModal"
                        >
                            <div class="h-12 w-12 flex-shrink-0 rounded-lg bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                <img
                                    v-if="item.image"
                                    :src="item.image"
                                    :alt="item.title"
                                    class="h-full w-full object-cover"
                                />
                                <div v-else class="flex h-full w-full items-center justify-center">
                                    <ArrowDownToLine class="h-6 w-6 text-gray-400" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                    {{ item.title }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="item.transaction_number">{{ item.transaction_number }}</span>
                                    <span v-if="item.transaction_number && item.customer_name"> &middot; </span>
                                    <span v-if="item.customer_name">{{ item.customer_name }}</span>
                                    <span v-if="item.date"> &middot; {{ item.date }}</span>
                                </p>
                            </div>
                            <div class="text-right">
                                <span v-if="item.price" class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(item.price) }}
                                </span>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Buy Price</p>
                            </div>
                        </Link>
                    </template>

                    <!-- Sold Items -->
                    <template v-if="activeTab === 'sold'">
                        <Link
                            v-for="item in results.sold"
                            :key="`sold-${item.id}`"
                            :href="item.url"
                            class="flex items-center gap-4 p-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                            @click="closeModal"
                        >
                            <div class="h-12 w-12 flex-shrink-0 rounded-lg bg-gray-100 dark:bg-gray-800 overflow-hidden flex items-center justify-center">
                                <ShoppingCart class="h-6 w-6 text-gray-400" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                    {{ item.title }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="item.invoice_number">{{ item.invoice_number }}</span>
                                    <span v-else-if="item.order_id">#{{ item.order_id }}</span>
                                    <span v-if="(item.invoice_number || item.order_id) && item.customer_name"> &middot; </span>
                                    <span v-if="item.customer_name">{{ item.customer_name }}</span>
                                    <span v-if="item.date"> &middot; {{ item.date }}</span>
                                </p>
                            </div>
                            <div class="text-right">
                                <span v-if="item.price" class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(item.price) }}
                                </span>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Sold Price</p>
                            </div>
                        </Link>
                    </template>

                    <!-- Empty Tab State -->
                    <div
                        v-if="currentResults.length === 0 && searchQuery.length >= 2 && !loading"
                        class="flex flex-col items-center justify-center py-12 text-gray-500 dark:text-gray-400"
                    >
                        <p class="text-sm">No {{ activeTab }} items found</p>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
