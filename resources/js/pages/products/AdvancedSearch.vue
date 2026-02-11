<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted } from 'vue';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    MagnifyingGlassIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
} from '@heroicons/vue/20/solid';
import { Package, ShoppingCart, ArrowDownToLine } from 'lucide-vue-next';
import axios from 'axios';

interface Brand {
    id: number;
    name: string;
}

interface TemplateValue {
    field: string | null;
    value: string | null;
}

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
    template_values?: TemplateValue[];
    transaction_id?: number;
    transaction_number?: string | null;
    order_id?: number;
    invoice_number?: string | null;
    customer_name?: string | null;
    date?: string | null;
}

interface PaginatedResults {
    data: SearchResultItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    brands: Brand[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Advanced Search', href: '/products/advanced-search' },
];

const searchQuery = ref('');
const selectedBrand = ref<string>('');
const activeTab = ref<'active' | 'bought' | 'sold'>('active');
const loading = ref(false);
const results = ref<PaginatedResults | null>(null);
const counts = ref<{ active: number; bought: number; sold: number }>({
    active: 0,
    bought: 0,
    sold: 0,
});

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

// Perform search
async function performSearch(page: number = 1) {
    loading.value = true;
    try {
        const response = await axios.get('/products/advanced-search/results', {
            params: {
                query: searchQuery.value || undefined,
                tab: activeTab.value,
                brand_id: selectedBrand.value || undefined,
                page,
                per_page: 25,
            },
        });
        results.value = response.data.results;
        counts.value = response.data.counts;
    } catch (error) {
        console.error('Search failed:', error);
    } finally {
        loading.value = false;
    }
}

// Watch for search query changes with debounce
watch(searchQuery, () => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    searchTimeout = setTimeout(() => {
        performSearch(1);
    }, 300);
});

// Watch for brand filter changes
watch(selectedBrand, () => {
    performSearch(1);
});

// Watch for tab changes
watch(activeTab, () => {
    performSearch(1);
});

// Initial load
onMounted(() => {
    performSearch(1);
});

function goToPage(page: number) {
    if (page >= 1 && page <= (results.value?.last_page || 1)) {
        performSearch(page);
    }
}

function formatCurrency(amount: number | null): string {
    if (amount === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
}

const paginationInfo = computed(() => {
    if (!results.value) return '';
    const from = (results.value.current_page - 1) * results.value.per_page + 1;
    const to = Math.min(results.value.current_page * results.value.per_page, results.value.total);
    return `Showing ${from} to ${to} of ${results.value.total} results`;
});
</script>

<template>
    <Head title="Advanced Search" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 lg:p-8 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Advanced Product Search</h1>
                <Link
                    href="/products"
                    class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                >
                    Back to Products
                </Link>
            </div>

            <!-- Search and Filters -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow ring-1 ring-black/5 dark:ring-white/10 p-4">
                <div class="flex flex-col sm:flex-row gap-4">
                    <!-- Search Input -->
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <Input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by title, SKU, brand, or template values..."
                            class="pl-10"
                        />
                    </div>

                    <!-- Brand Filter -->
                    <select
                        v-model="selectedBrand"
                        class="rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    >
                        <option value="">All Brands</option>
                        <option v-for="brand in brands" :key="brand.id" :value="brand.id">
                            {{ brand.name }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="flex gap-4">
                    <button
                        type="button"
                        class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors"
                        :class="activeTab === 'active'
                            ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        @click="activeTab = 'active'"
                    >
                        <Package class="h-5 w-5" />
                        Active Products
                        <Badge variant="secondary">{{ counts.active.toLocaleString() }}</Badge>
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors"
                        :class="activeTab === 'bought'
                            ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        @click="activeTab = 'bought'"
                    >
                        <ArrowDownToLine class="h-5 w-5" />
                        Bought Items
                        <Badge variant="secondary">{{ counts.bought.toLocaleString() }}</Badge>
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors"
                        :class="activeTab === 'sold'
                            ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        @click="activeTab = 'sold'"
                    >
                        <ShoppingCart class="h-5 w-5" />
                        Sold Items
                        <Badge variant="secondary">{{ counts.sold.toLocaleString() }}</Badge>
                    </button>
                </nav>
            </div>

            <!-- Results -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow ring-1 ring-black/5 dark:ring-white/10">
                <!-- Loading State -->
                <div v-if="loading" class="flex items-center justify-center py-24">
                    <div class="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
                </div>

                <!-- Empty State -->
                <div
                    v-else-if="results && results.data.length === 0"
                    class="flex flex-col items-center justify-center py-24 text-gray-500 dark:text-gray-400"
                >
                    <MagnifyingGlassIcon class="h-12 w-12 mb-4" />
                    <p class="text-lg font-medium">No results found</p>
                    <p class="text-sm">Try adjusting your search or filters</p>
                </div>

                <!-- Results List -->
                <div v-else-if="results && results.data.length > 0">
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <Link
                            v-for="item in results.data"
                            :key="`${activeTab}-${item.id}`"
                            :href="item.url"
                            class="flex items-center gap-4 p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        >
                            <!-- Image -->
                            <div class="h-16 w-16 flex-shrink-0 rounded-lg bg-gray-100 dark:bg-gray-700 overflow-hidden">
                                <img
                                    v-if="item.image"
                                    :src="item.image"
                                    :alt="item.title"
                                    class="h-full w-full object-cover"
                                />
                                <div v-else class="flex h-full w-full items-center justify-center">
                                    <Package v-if="activeTab === 'active'" class="h-6 w-6 text-gray-400" />
                                    <ArrowDownToLine v-else-if="activeTab === 'bought'" class="h-6 w-6 text-gray-400" />
                                    <ShoppingCart v-else class="h-6 w-6 text-gray-400" />
                                </div>
                            </div>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                    {{ item.title }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    <span v-if="item.sku">SKU: {{ item.sku }}</span>
                                    <span v-if="item.sku && item.brand"> &middot; </span>
                                    <span v-if="item.brand">{{ item.brand }}</span>
                                    <span v-if="(item.sku || item.brand) && item.category"> &middot; </span>
                                    <span v-if="item.category">{{ item.category }}</span>
                                </p>
                                <!-- Template Values -->
                                <div v-if="item.template_values && item.template_values.length > 0" class="mt-1 flex flex-wrap gap-1">
                                    <Badge
                                        v-for="(tv, idx) in item.template_values.slice(0, 3)"
                                        :key="idx"
                                        variant="outline"
                                        class="text-xs"
                                    >
                                        {{ tv.field }}: {{ tv.value }}
                                    </Badge>
                                    <Badge v-if="item.template_values.length > 3" variant="outline" class="text-xs">
                                        +{{ item.template_values.length - 3 }} more
                                    </Badge>
                                </div>
                                <!-- Transaction/Order Info -->
                                <p v-if="activeTab === 'bought'" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    {{ item.transaction_number }}
                                    <span v-if="item.customer_name"> &middot; {{ item.customer_name }}</span>
                                    <span v-if="item.date"> &middot; {{ item.date }}</span>
                                </p>
                                <p v-if="activeTab === 'sold'" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    {{ item.invoice_number || `#${item.order_id}` }}
                                    <span v-if="item.customer_name"> &middot; {{ item.customer_name }}</span>
                                    <span v-if="item.date"> &middot; {{ item.date }}</span>
                                </p>
                            </div>

                            <!-- Price & Status -->
                            <div class="flex items-center gap-4 text-right">
                                <div>
                                    <span v-if="item.price" class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(item.price) }}
                                    </span>
                                    <p v-if="activeTab === 'bought'" class="text-xs text-gray-500 dark:text-gray-400">Buy Price</p>
                                    <p v-if="activeTab === 'sold'" class="text-xs text-gray-500 dark:text-gray-400">Sold Price</p>
                                </div>
                                <Badge
                                    v-if="activeTab === 'active' && item.status"
                                    :variant="item.status === 'active' ? 'default' : 'secondary'"
                                    class="capitalize"
                                >
                                    {{ item.status }}
                                </Badge>
                            </div>
                        </Link>
                    </div>

                    <!-- Pagination -->
                    <div class="flex items-center justify-between border-t border-gray-200 dark:border-gray-700 px-4 py-3">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ paginationInfo }}
                        </p>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="results.current_page === 1"
                                @click="goToPage(results.current_page - 1)"
                            >
                                <ChevronLeftIcon class="h-4 w-4" />
                                Previous
                            </Button>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                Page {{ results.current_page }} of {{ results.last_page }}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="results.current_page === results.last_page"
                                @click="goToPage(results.current_page + 1)"
                            >
                                Next
                                <ChevronRightIcon class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
