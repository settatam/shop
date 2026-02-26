<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { home } from '@/routes';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="min-h-screen bg-white dark:bg-gray-900 lg:flex">
        <!-- Left side - Form -->
        <div class="flex flex-1 flex-col justify-center px-4 py-12 sm:px-6 lg:flex-none lg:px-20 xl:px-24">
            <div class="mx-auto w-full max-w-sm lg:w-96">
                <div>
<!--                    <Link :href="home()" class="inline-block">-->
<!--                        <AppLogo class="h-10 w-auto" />-->
<!--                    </Link>-->
<!--                    <h2 class="mt-8 text-2xl font-bold tracking-tight text-gray-900 dark:text-white">-->
<!--                        {{ title }}-->
<!--                    </h2>-->
<!--                    <p v-if="description" class="mt-2 text-sm text-gray-600 dark:text-gray-400">-->
<!--                        {{ description }}-->
<!--                    </p>-->
                </div>

                <div class="mt-10">
                    <slot />
                </div>
            </div>
        </div>

        <!-- Right side - Image -->
        <div class="relative hidden w-0 flex-1 lg:block">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-600 to-purple-700">
                <div class="flex h-full items-center justify-center p-12">
                    <div class="max-w-lg text-center">
                        <svg class="mx-auto h-24 w-24 text-white/80" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <h3 class="mt-8 text-2xl font-semibold text-white">
                            Welcome to Shopmata
                        </h3>
<!--                        <p class="mt-4 text-lg text-indigo-100">-->
<!--                            Streamline your jewelry business with powerful inventory tracking, consignment management, repairs, and point of sale.-->
<!--                        </p>-->
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
