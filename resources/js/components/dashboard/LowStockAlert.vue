<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExclamationTriangleIcon, CubeIcon } from '@heroicons/vue/24/outline';

interface Product {
    id: number;
    title: string;
    handle: string;
    quantity: number;
    image: string | null;
}

interface Props {
    products: Product[];
}

const props = defineProps<Props>();

function getQuantityColor(quantity: number): string {
    if (quantity === 0) return 'text-red-600 dark:text-red-400';
    if (quantity <= 5) return 'text-orange-600 dark:text-orange-400';
    return 'text-yellow-600 dark:text-yellow-400';
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <div class="flex items-center gap-x-2">
                <ExclamationTriangleIcon class="h-5 w-5 text-yellow-500" />
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Low Stock Alert</h3>
            </div>
            <Link href="/inventory" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
                View all
            </Link>
        </div>

        <!-- Empty state -->
        <div v-if="products.length === 0" class="px-4 py-12 text-center sm:px-6">
            <CubeIcon class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" />
            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">All stocked up</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                No products are running low on inventory.
            </p>
        </div>

        <!-- Products list -->
        <ul v-else role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
            <li v-for="product in products" :key="product.id">
                <Link :href="`/products/${product.id}`" class="flex items-center gap-x-4 px-4 py-4 hover:bg-gray-50 sm:px-6 dark:hover:bg-gray-700/50">
                    <div class="h-10 w-10 flex-shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700">
                        <img
                            v-if="product.image"
                            :src="product.image"
                            :alt="product.title"
                            class="h-full w-full object-cover"
                        />
                        <CubeIcon v-else class="h-full w-full p-2 text-gray-400" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white">
                            {{ product.title }}
                        </p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ product.handle }}
                        </p>
                    </div>
                    <div class="flex items-center gap-x-2">
                        <span
                            :class="[
                                getQuantityColor(product.quantity),
                                'text-sm font-semibold',
                            ]"
                        >
                            {{ product.quantity }} left
                        </span>
                    </div>
                </Link>
            </li>
        </ul>
    </div>
</template>
