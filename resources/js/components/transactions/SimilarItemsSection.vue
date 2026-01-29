<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

interface SimilarItem {
    id: number;
    title: string;
    sku: string | null;
    price: number | null;
    cost: number | null;
    image_url: string | null;
    similarity_score: number;
    match_reasons: string[];
}

const props = defineProps<{
    transactionId: number;
    itemId: number;
}>();

const expanded = ref(false);
const loading = ref(false);
const items = ref<SimilarItem[]>([]);
const loaded = ref(false);

const toggle = async () => {
    expanded.value = !expanded.value;
    if (expanded.value && !loaded.value) {
        await fetchSimilar();
    }
};

const fetchSimilar = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/transactions/${props.transactionId}/items/${props.itemId}/similar`);
        items.value = response.data.items;
        loaded.value = true;
    } catch {
        items.value = [];
    } finally {
        loading.value = false;
    }
};

const formatPrice = (price: number | null) => {
    if (price === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
};
</script>

<template>
    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <button
            type="button"
            class="flex w-full items-center justify-between px-4 py-5 sm:px-6"
            @click="toggle"
        >
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Similar Items</h3>
            <component :is="expanded ? ChevronUpIcon : ChevronDownIcon" class="size-5 text-gray-400" />
        </button>

        <div v-if="expanded" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <!-- Loading -->
            <div v-if="loading" class="flex items-center justify-center py-8">
                <div class="animate-pulse space-y-3 w-full">
                    <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                    <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                    <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="items.length === 0" class="py-8 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">No similar items found in your inventory.</p>
            </div>

            <!-- Items grid -->
            <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Link
                    v-for="item in items"
                    :key="item.id"
                    :href="`/products/${item.id}`"
                    class="flex gap-3 rounded-lg border border-gray-200 p-3 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/50"
                >
                    <div class="h-14 w-14 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-700">
                        <img
                            v-if="item.image_url"
                            :src="item.image_url"
                            :alt="item.title"
                            class="h-full w-full object-cover"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center text-gray-400 text-xs">
                            N/A
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                        <p v-if="item.sku" class="text-xs text-gray-500 dark:text-gray-400">{{ item.sku }}</p>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ formatPrice(item.price) }}</span>
                            <span
                                class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                :class="item.similarity_score >= 50 ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'"
                            >
                                {{ item.similarity_score }}% match
                            </span>
                        </div>
                    </div>
                </Link>
            </div>
        </div>
    </div>
</template>
