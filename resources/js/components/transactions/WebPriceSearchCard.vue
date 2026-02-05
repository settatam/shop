<script setup lang="ts">
import { ref, computed } from 'vue';
import { MagnifyingGlassIcon, ArrowPathIcon, ArrowTopRightOnSquareIcon, GlobeAltIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

interface PriceListing {
    source: string;
    title: string;
    price: number | null;
    link: string | null;
    image: string | null;
    seller?: string | null;
    sold_date?: string | null;
    condition?: string | null;
}

interface PriceSummary {
    min: number | null;
    max: number | null;
    avg: number | null;
    median: number | null;
    count: number;
}

interface SearchResults {
    listings: PriceListing[];
    summary: PriceSummary;
    searched_at: string;
    query: string;
    error?: string;
}

const props = defineProps<{
    // For Transaction Item page
    transactionId?: number;
    itemId?: number;
    // For Quick Evaluation page
    searchCriteria?: {
        title: string;
        category?: string;
        precious_metal?: string;
        attributes?: Record<string, any>;
    };
    existingResults?: SearchResults | null;
    generatedAt?: string | null;
}>();

const emit = defineEmits<{
    (e: 'results', results: SearchResults): void;
}>();

const results = ref<SearchResults | null>(props.existingResults || null);
const generatedAt = ref<string | null>(props.generatedAt || null);
const loading = ref(false);
const error = ref<string | null>(null);

const hasResults = computed(() => results.value && results.value.listings?.length > 0);

const search = async () => {
    loading.value = true;
    error.value = null;

    try {
        let response;

        if (props.transactionId && props.itemId) {
            // Transaction Item page
            response = await axios.post(`/transactions/${props.transactionId}/items/${props.itemId}/web-search`);
        } else if (props.searchCriteria) {
            // Quick Evaluation page
            response = await axios.post('/transactions/quick-evaluation/web-search', props.searchCriteria);
        } else {
            error.value = 'No search criteria provided.';
            loading.value = false;
            return;
        }

        if (response.data.error) {
            error.value = response.data.error;
        } else {
            results.value = response.data;
            generatedAt.value = response.data.searched_at || new Date().toISOString();
            emit('results', response.data);
        }
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to search. Please try again.';
    } finally {
        loading.value = false;
    }
};

const formatPrice = (price: number | null | undefined) => {
    if (price === null || price === undefined) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
};

const formatDate = (date: string | null) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const getSourceBadgeClass = (source: string) => {
    if (source.includes('eBay')) {
        return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
    }
    if (source.includes('Google')) {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
    }
    return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
};
</script>

<template>
    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="px-4 py-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                    <GlobeAltIcon class="size-5 text-blue-500" />
                    Web Price Search
                </h3>
                <div class="flex items-center gap-2">
                    <span v-if="generatedAt" class="text-xs text-gray-500 dark:text-gray-400">
                        {{ formatDate(generatedAt) }}
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-500 disabled:opacity-50"
                        :disabled="loading"
                        @click="search"
                    >
                        <ArrowPathIcon v-if="hasResults" class="size-3.5" :class="{ 'animate-spin': loading }" />
                        <MagnifyingGlassIcon v-else class="size-3.5" />
                        {{ loading ? 'Searching...' : hasResults ? 'Refresh' : 'Search Prices' }}
                    </button>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="loading && !hasResults" class="space-y-3 animate-pulse">
                <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                <div class="h-12 bg-gray-200 dark:bg-gray-700 rounded"></div>
                <div class="h-12 bg-gray-200 dark:bg-gray-700 rounded"></div>
            </div>

            <!-- Error -->
            <div v-else-if="error" class="rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                <p class="text-sm text-red-700 dark:text-red-400">{{ error }}</p>
            </div>

            <!-- No results yet -->
            <div v-else-if="!hasResults" class="py-8 text-center">
                <GlobeAltIcon class="mx-auto size-10 text-gray-300 dark:text-gray-600" />
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Click "Search Prices" to find comparable listings from Google Shopping and eBay.
                </p>
            </div>

            <!-- Search Results -->
            <div v-else class="space-y-4">
                <!-- Price Summary -->
                <div v-if="results?.summary?.count" class="rounded-md bg-blue-50 p-4 dark:bg-blue-900/20">
                    <div class="text-sm font-medium text-blue-900 dark:text-blue-100 mb-2">
                        Found {{ results.summary.count }} comparable listings
                    </div>
                    <div class="grid grid-cols-4 gap-3 text-center">
                        <div>
                            <p class="text-xs text-blue-600 dark:text-blue-400">Low</p>
                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">{{ formatPrice(results.summary.min) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-blue-600 dark:text-blue-400">Average</p>
                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">{{ formatPrice(results.summary.avg) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-blue-600 dark:text-blue-400">Median</p>
                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">{{ formatPrice(results.summary.median) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-blue-600 dark:text-blue-400">High</p>
                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">{{ formatPrice(results.summary.max) }}</p>
                        </div>
                    </div>
                    <p v-if="results.query" class="mt-2 text-xs text-blue-600 dark:text-blue-400">
                        Search: "{{ results.query }}"
                    </p>
                </div>

                <!-- Listings -->
                <div class="space-y-2 max-h-80 overflow-y-auto">
                    <a
                        v-for="(listing, index) in results?.listings?.slice(0, 15)"
                        :key="index"
                        :href="listing.link || '#'"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex gap-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        :class="{ 'pointer-events-none': !listing.link }"
                    >
                        <div v-if="listing.image" class="shrink-0">
                            <img
                                :src="listing.image"
                                :alt="listing.title"
                                class="size-12 object-cover rounded-md bg-gray-100 dark:bg-gray-700"
                                @error="($event.target as HTMLImageElement).style.display = 'none'"
                            />
                        </div>
                        <div v-else class="shrink-0 size-12 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                            <GlobeAltIcon class="size-5 text-gray-400" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-medium text-gray-900 dark:text-white line-clamp-2">
                                    {{ listing.title }}
                                </p>
                                <ArrowTopRightOnSquareIcon v-if="listing.link" class="size-4 text-gray-400 shrink-0" />
                            </div>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-sm font-semibold text-green-600 dark:text-green-400">
                                    {{ formatPrice(listing.price) }}
                                </span>
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="getSourceBadgeClass(listing.source)"
                                >
                                    {{ listing.source }}
                                </span>
                            </div>
                            <div v-if="listing.seller || listing.sold_date || listing.condition" class="flex items-center gap-2 mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="listing.seller">{{ listing.seller }}</span>
                                <span v-if="listing.sold_date">&middot; Sold {{ listing.sold_date }}</span>
                                <span v-if="listing.condition">&middot; {{ listing.condition }}</span>
                            </div>
                        </div>
                    </a>
                </div>

                <p v-if="results?.listings && results.listings.length > 15" class="text-xs text-gray-500 dark:text-gray-400 text-center">
                    Showing 15 of {{ results.listings.length }} results
                </p>
            </div>
        </div>
    </div>
</template>
