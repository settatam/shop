<script setup lang="ts">
import { ref } from 'vue';
import { SparklesIcon, ArrowPathIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

const props = defineProps<{
    transactionId: number;
    itemId: number;
    existingResearch: Record<string, any> | null;
    generatedAt: string | null;
}>();

const research = ref<Record<string, any> | null>(props.existingResearch);
const generatedAt = ref<string | null>(props.generatedAt);
const loading = ref(false);
const error = ref<string | null>(null);

const generate = async () => {
    loading.value = true;
    error.value = null;
    try {
        const response = await axios.post(`/transactions/${props.transactionId}/items/${props.itemId}/ai-research`);
        if (response.data.research?.error) {
            error.value = response.data.research.error;
        } else {
            research.value = response.data.research;
            generatedAt.value = new Date().toISOString();
        }
    } catch {
        error.value = 'Failed to generate research. Please try again.';
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
</script>

<template>
    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="px-4 py-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                    <SparklesIcon class="size-5 text-purple-500" />
                    AI Research
                </h3>
                <div class="flex items-center gap-2">
                    <span v-if="generatedAt" class="text-xs text-gray-500 dark:text-gray-400">
                        {{ formatDate(generatedAt) }}
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md bg-purple-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-purple-500 disabled:opacity-50"
                        :disabled="loading"
                        @click="generate"
                    >
                        <ArrowPathIcon v-if="research" class="size-3.5" :class="{ 'animate-spin': loading }" />
                        <SparklesIcon v-else class="size-3.5" />
                        {{ loading ? 'Analyzing...' : research ? 'Regenerate' : 'Generate Research' }}
                    </button>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="loading && !research" class="space-y-4 animate-pulse">
                <div class="h-20 bg-gray-200 dark:bg-gray-700 rounded"></div>
                <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                <div class="h-24 bg-gray-200 dark:bg-gray-700 rounded"></div>
            </div>

            <!-- Error -->
            <div v-else-if="error" class="rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                <p class="text-sm text-red-700 dark:text-red-400">{{ error }}</p>
            </div>

            <!-- No research yet -->
            <div v-else-if="!research" class="py-8 text-center">
                <SparklesIcon class="mx-auto size-10 text-gray-300 dark:text-gray-600" />
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Click "Generate Research" to get an AI-powered market analysis.</p>
            </div>

            <!-- Research results -->
            <div v-else class="space-y-5">
                <!-- Market Value -->
                <div v-if="research.market_value" class="rounded-md bg-gray-50 p-4 dark:bg-gray-700/50">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Market Value</h4>
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Low</p>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ formatPrice(research.market_value.min) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Average</p>
                            <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">{{ formatPrice(research.market_value.avg) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">High</p>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ formatPrice(research.market_value.max) }}</p>
                        </div>
                    </div>
                    <div v-if="research.market_value.confidence" class="mt-3">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-500 dark:text-gray-400">Confidence:</span>
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="{
                                    'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': research.market_value.confidence === 'high',
                                    'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400': research.market_value.confidence === 'medium',
                                    'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400': research.market_value.confidence === 'low',
                                }"
                            >
                                {{ research.market_value.confidence }}
                            </span>
                        </div>
                    </div>
                    <p v-if="research.market_value.reasoning" class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                        {{ research.market_value.reasoning }}
                    </p>
                </div>

                <!-- Pricing Recommendation -->
                <div v-if="research.pricing_recommendation">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Pricing Recommendation</h4>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-md bg-green-50 p-3 dark:bg-green-900/20">
                            <p class="text-xs text-green-700 dark:text-green-400">Suggested Retail</p>
                            <p class="text-sm font-semibold text-green-800 dark:text-green-300">{{ formatPrice(research.pricing_recommendation.suggested_retail) }}</p>
                        </div>
                        <div class="rounded-md bg-blue-50 p-3 dark:bg-blue-900/20">
                            <p class="text-xs text-blue-700 dark:text-blue-400">Suggested Wholesale</p>
                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-300">{{ formatPrice(research.pricing_recommendation.suggested_wholesale) }}</p>
                        </div>
                    </div>
                    <p v-if="research.pricing_recommendation.notes" class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                        {{ research.pricing_recommendation.notes }}
                    </p>
                </div>

                <!-- Item Analysis -->
                <div v-if="research.item_analysis">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Item Analysis</h4>
                    <p v-if="research.item_analysis.description" class="text-sm text-gray-600 dark:text-gray-300 mb-2">
                        {{ research.item_analysis.description }}
                    </p>
                    <div v-if="research.item_analysis.notable_features?.length > 0" class="mt-2">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Notable Features:</p>
                        <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-300 space-y-0.5">
                            <li v-for="(feature, i) in research.item_analysis.notable_features" :key="i">{{ feature }}</li>
                        </ul>
                    </div>
                    <p v-if="research.item_analysis.condition_notes" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Condition: {{ research.item_analysis.condition_notes }}
                    </p>
                </div>

                <!-- Raw/fallback -->
                <div v-if="research.raw_analysis" class="rounded-md bg-gray-50 p-4 dark:bg-gray-700/50">
                    <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{{ research.raw_analysis }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
