<script setup lang="ts">
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { Skeleton } from '@/components/ui/skeleton';

interface InventoryLevel {
    warehouse_name: string;
    warehouse_code: string;
    quantity: number;
    available_quantity: number;
}

interface ProductPreview {
    id: number;
    title: string;
    image_url: string | null;
    price: number;
    status: 'Published' | 'Draft';
    category_name: string | null;
    brand_name: string | null;
    total_quantity: number;
    inventory_levels: InventoryLevel[];
}

interface Props {
    productId: number;
}

const props = defineProps<Props>();

const product = ref<ProductPreview | null>(null);
const loading = ref(true);
const error = ref(false);

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

async function loadProduct() {
    if (product.value) return;

    loading.value = true;
    error.value = false;

    try {
        const response = await axios.get(`/api/v1/products/${props.productId}/preview`);
        product.value = response.data;
    } catch (e) {
        console.error('Failed to load product preview:', e);
        error.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    loadProduct();
});
</script>

<template>
    <div class="space-y-3">
        <!-- Loading skeleton -->
        <template v-if="loading">
            <Skeleton class="h-32 w-full rounded-md" />
            <div class="space-y-2">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-6 w-1/3" />
                <Skeleton class="h-3 w-1/2" />
            </div>
            <div class="space-y-1 pt-3 border-t border-gray-200 dark:border-gray-700">
                <Skeleton class="h-3 w-20" />
                <Skeleton class="h-4 w-full" />
                <Skeleton class="h-4 w-full" />
            </div>
        </template>

        <!-- Error state -->
        <div v-else-if="error" class="text-center py-4 text-gray-500 dark:text-gray-400">
            <p class="text-sm">Failed to load preview</p>
        </div>

        <!-- Product preview content -->
        <template v-else-if="product">
            <!-- Image -->
            <div class="h-32 w-full overflow-hidden rounded-md bg-gray-100 dark:bg-gray-700">
                <img
                    v-if="product.image_url"
                    :src="product.image_url"
                    :alt="product.title"
                    class="h-full w-full object-cover"
                />
                <div v-else class="flex h-full items-center justify-center text-gray-400">
                    <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
            </div>

            <!-- Title & Status -->
            <div class="flex items-start justify-between gap-2">
                <h4 class="font-medium text-gray-900 dark:text-white line-clamp-2">
                    {{ product.title }}
                </h4>
                <span
                    :class="[
                        'shrink-0 rounded-full px-2 py-0.5 text-xs font-medium',
                        product.status === 'Published'
                            ? 'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-400'
                            : 'bg-yellow-50 text-yellow-800 dark:bg-yellow-500/10 dark:text-yellow-400'
                    ]"
                >
                    {{ product.status }}
                </span>
            </div>

            <!-- Price -->
            <div class="text-lg font-semibold text-gray-900 dark:text-white">
                {{ formatCurrency(product.price) }}
            </div>

            <!-- Category & Brand -->
            <div v-if="product.category_name || product.brand_name" class="text-sm text-gray-500 dark:text-gray-400">
                <span v-if="product.category_name">{{ product.category_name }}</span>
                <span v-if="product.category_name && product.brand_name"> · </span>
                <span v-if="product.brand_name">{{ product.brand_name }}</span>
            </div>

            <!-- Inventory by Warehouse -->
            <div class="border-t border-gray-200 pt-3 dark:border-gray-700">
                <h5 class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                    Inventory
                </h5>
                <div v-if="product.inventory_levels.length > 0" class="space-y-1">
                    <div
                        v-for="level in product.inventory_levels"
                        :key="level.warehouse_code"
                        class="flex items-center justify-between text-sm"
                    >
                        <span class="text-gray-600 dark:text-gray-300">
                            {{ level.warehouse_name }}
                        </span>
                        <span
                            :class="[
                                'font-medium',
                                level.available_quantity > 0
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-red-600 dark:text-red-400'
                            ]"
                        >
                            {{ level.available_quantity }} / {{ level.quantity }}
                        </span>
                    </div>
                </div>
                <div v-else class="text-sm text-gray-400">
                    No inventory records
                </div>
            </div>
        </template>
    </div>
</template>
