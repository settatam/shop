<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import {
    MagnifyingGlassIcon,
    CubeIcon,
    PlusIcon,
} from '@heroicons/vue/24/outline';

interface Product {
    id: number;
    title: string;
    sku?: string;
    description?: string;
    price?: number;
    cost?: number;
    quantity?: number;
    category?: string;
    image?: string;
}

interface Props {
    searchUrl: string;
    placeholder?: string;
    showCreateOption?: boolean;
    disabledProductIds?: number[];
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Search products by name or SKU...',
    showCreateOption: false,
    disabledProductIds: () => [],
});

const emit = defineEmits<{
    select: [product: Product];
    'create-new': [query: string];
}>();

const query = ref('');
const results = ref<Product[]>([]);
const isLoading = ref(false);

const isDisabled = (productId: number): boolean => {
    return props.disabledProductIds.includes(productId);
};

const search = useDebounceFn(async () => {
    if (!query.value || query.value.length < 2) {
        results.value = [];
        return;
    }

    isLoading.value = true;

    try {
        const response = await axios.get(props.searchUrl, {
            params: { query: query.value },
        });
        results.value = response.data.products;
    } catch {
        results.value = [];
    } finally {
        isLoading.value = false;
    }
}, 300);

watch(query, () => {
    search();
});

function selectProduct(product: Product) {
    if (!isDisabled(product.id)) {
        emit('select', product);
    }
}

function handleCreateNew() {
    emit('create-new', query.value.trim());
}
</script>

<template>
    <div class="space-y-3">
        <!-- Search input -->
        <div class="relative">
            <input
                v-model="query"
                type="text"
                :placeholder="placeholder"
                class="w-full rounded-lg border-0 bg-white py-3 pl-12 pr-4 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                <MagnifyingGlassIcon class="size-5 text-gray-400" />
            </div>
        </div>

        <!-- Results -->
        <div class="max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-600">
            <div v-if="isLoading" class="p-4 text-center text-gray-500 dark:text-gray-400">Loading...</div>
            <div v-else-if="!query || query.length < 2" class="p-4 text-center text-gray-500 dark:text-gray-400">
                <MagnifyingGlassIcon class="mx-auto size-8 text-gray-300 dark:text-gray-600" />
                <p class="mt-2">Type to search for products</p>
            </div>
            <div v-else-if="results.length === 0" class="p-4 text-center text-gray-500 dark:text-gray-400">
                <p>No products found for "{{ query }}"</p>
                <button
                    v-if="showCreateOption"
                    type="button"
                    @click="handleCreateNew"
                    class="mt-2 inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                >
                    <PlusIcon class="size-4" />
                    Create "{{ query }}"
                </button>
            </div>
            <div v-else class="divide-y divide-gray-200 dark:divide-gray-600">
                <div
                    v-for="product in results"
                    :key="product.id"
                    class="flex items-center justify-between p-3 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                >
                    <div class="flex items-center gap-3">
                        <div class="flex size-12 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                            <img v-if="product.image" :src="product.image" class="size-12 rounded object-cover" />
                            <CubeIcon v-else class="size-6 text-gray-400" />
                        </div>
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">{{ product.title }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                <span v-if="product.sku">SKU: {{ product.sku }}</span>
                                <span v-if="product.sku && product.price"> | </span>
                                <span v-if="product.price">${{ product.price }}</span>
                                <span v-if="product.quantity !== undefined"> | Qty: {{ product.quantity }}</span>
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="selectProduct(product)"
                        :disabled="isDisabled(product.id)"
                        :class="[
                            'inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium',
                            isDisabled(product.id)
                                ? 'cursor-not-allowed bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500'
                                : 'bg-indigo-600 text-white hover:bg-indigo-500',
                        ]"
                    >
                        {{ isDisabled(product.id) ? 'Added' : 'Add' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
