<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import MetalsCalculatorTool from '@/components/transactions/MetalsCalculatorTool.vue';
import AiPhotoAnalysisTool from '@/components/transactions/AiPhotoAnalysisTool.vue';
import WebPriceSearchCard from '@/components/transactions/WebPriceSearchCard.vue';
import { CalculatorIcon, CameraIcon, DollarSign, SearchIcon } from 'lucide-vue-next';

interface SimilarItem {
    id: number;
    title: string;
    category: string | null;
    buy_price: number | null;
    image_url: string | null;
    days_ago: number;
    similarity_score: number;
}

const activeTab = ref<'metals' | 'analyzer' | 'search'>('metals');

// Web search state
const searchQuery = ref('');
const searchCriteria = ref<{ title: string } | null>(null);

function triggerSearch() {
    const query = searchQuery.value.trim();
    if (!query) return;
    // Update criteria to trigger the WebPriceSearchCard
    searchCriteria.value = { title: query };
}

const preciousMetalOptions = [
    { value: 'gold_10k', label: '10K Gold' },
    { value: 'gold_14k', label: '14K Gold' },
    { value: 'gold_18k', label: '18K Gold' },
    { value: 'gold_22k', label: '22K Gold' },
    { value: 'gold_24k', label: '24K Gold' },
    { value: 'silver', label: 'Sterling Silver' },
    { value: 'platinum', label: 'Platinum' },
    { value: 'palladium', label: 'Palladium' },
];

const similarItems = ref<SimilarItem[]>([]);
const loadingSimilar = ref(false);

async function onAnalysisResults(data: { title: string; description?: string; research: Record<string, unknown> }) {
    if (!data.title) return;

    loadingSimilar.value = true;
    similarItems.value = [];

    try {
        const response = await fetch('/transactions/quick-evaluation/similar-items', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ title: data.title }),
        });

        if (response.ok) {
            const result = await response.json();
            similarItems.value = result.items || [];
        }
    } catch {
        // Silently fail — similar items are supplementary
    } finally {
        loadingSimilar.value = false;
    }
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
    }).format(value);
}

function formatDaysAgo(days: number): string {
    if (days === 0) return 'Today';
    if (days === 1) return '1 day ago';
    return `${days} days ago`;
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <!-- Header with tabs -->
        <div class="border-b border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Quick Tools</h3>
                <div class="flex rounded-lg bg-gray-100 p-0.5 dark:bg-gray-700">
                    <button
                        type="button"
                        @click="activeTab = 'metals'"
                        :class="[
                            'inline-flex items-center gap-x-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            activeTab === 'metals'
                                ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-600 dark:text-white'
                                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
                        ]"
                    >
                        <CalculatorIcon class="size-4" />
                        Metals Calculator
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'analyzer'"
                        :class="[
                            'inline-flex items-center gap-x-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            activeTab === 'analyzer'
                                ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-600 dark:text-white'
                                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
                        ]"
                    >
                        <CameraIcon class="size-4" />
                        Product Analyzer
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'search'"
                        :class="[
                            'inline-flex items-center gap-x-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            activeTab === 'search'
                                ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-600 dark:text-white'
                                : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
                        ]"
                    >
                        <SearchIcon class="size-4" />
                        Web Search
                    </button>
                </div>
            </div>
        </div>

        <!-- Tab content -->
        <div class="p-4 sm:p-6">
            <!-- Metals Calculator -->
            <div v-if="activeTab === 'metals'">
                <MetalsCalculatorTool :precious-metals="preciousMetalOptions" hide-add-button />
                <div class="mt-4 flex justify-end">
                    <Link
                        href="/transactions/buy"
                        class="inline-flex items-center gap-x-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                    >
                        <DollarSign class="size-4" />
                        Start a new buy
                    </Link>
                </div>
            </div>

            <!-- Product Analyzer -->
            <div v-if="activeTab === 'analyzer'">
                <AiPhotoAnalysisTool hide-add-button @results="onAnalysisResults" />

                <!-- Similar Past Buys -->
                <div v-if="loadingSimilar" class="mt-6">
                    <h4 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Similar Past Buys</h4>
                    <div class="space-y-3">
                        <div v-for="i in 3" :key="i" class="flex animate-pulse items-center gap-3">
                            <div class="size-12 rounded-lg bg-gray-200 dark:bg-gray-700" />
                            <div class="flex-1 space-y-2">
                                <div class="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700" />
                                <div class="h-3 w-1/2 rounded bg-gray-200 dark:bg-gray-700" />
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else-if="similarItems.length > 0" class="mt-6">
                    <h4 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Similar Past Buys</h4>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        <Link
                            v-for="item in similarItems"
                            :key="item.id"
                            :href="`/transactions/${item.id}`"
                            class="flex items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                        >
                            <img
                                v-if="item.image_url"
                                :src="item.image_url"
                                :alt="item.title"
                                class="size-12 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-600"
                            />
                            <div v-else class="flex size-12 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700">
                                <CameraIcon class="size-5 text-gray-400" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="item.category">{{ item.category }} &middot; </span>
                                    {{ formatDaysAgo(item.days_ago) }}
                                </p>
                            </div>
                            <div v-if="item.buy_price !== null" class="text-right">
                                <p class="text-sm font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(item.buy_price) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">paid</p>
                            </div>
                        </Link>
                    </div>
                </div>

                <div class="mt-4 flex justify-end">
                    <Link
                        href="/transactions/buy"
                        class="inline-flex items-center gap-x-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                    >
                        <DollarSign class="size-4" />
                        Start a new buy
                    </Link>
                </div>
            </div>

            <!-- Web Search -->
            <div v-if="activeTab === 'search'">
                <div class="mb-4">
                    <label for="web-search-query" class="sr-only">Search for an item</label>
                    <div class="flex gap-2">
                        <input
                            id="web-search-query"
                            v-model="searchQuery"
                            type="text"
                            placeholder="e.g. 14K Gold Diamond Ring"
                            class="block w-full rounded-md border-0 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-500"
                            @keydown.enter="triggerSearch"
                        />
                        <button
                            type="button"
                            :disabled="!searchQuery.trim()"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 disabled:opacity-50"
                            @click="triggerSearch"
                        >
                            <SearchIcon class="size-4" />
                            Search
                        </button>
                    </div>
                </div>

                <WebPriceSearchCard
                    v-if="searchCriteria"
                    :key="searchCriteria.title"
                    :search-criteria="searchCriteria"
                    auto-search
                    class="shadow-none ring-0"
                />

                <div v-else class="py-8 text-center">
                    <SearchIcon class="mx-auto size-10 text-gray-300 dark:text-gray-600" />
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Enter an item description to search Google Shopping and eBay for comparable prices.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
