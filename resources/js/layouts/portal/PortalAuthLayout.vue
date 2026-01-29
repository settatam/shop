<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();
const store = computed(() => (page.props as any).currentStore);
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 py-12 dark:bg-gray-900 sm:px-6 lg:px-8">
        <div class="w-full max-w-md">
            <div class="text-center">
                <img
                    v-if="store?.logo_url"
                    :src="store.logo_url"
                    :alt="store?.name"
                    class="mx-auto h-12 w-auto"
                />
                <div
                    v-else-if="store?.name"
                    class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-indigo-600 text-xl font-bold text-white"
                >
                    {{ store.name.charAt(0).toUpperCase() }}
                </div>
                <h2 class="mt-6 text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                    {{ title }}
                </h2>
                <p v-if="description" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ description }}
                </p>
            </div>

            <div class="mt-8 rounded-lg bg-white p-8 shadow dark:bg-gray-800">
                <slot />
            </div>
        </div>
    </div>
</template>
