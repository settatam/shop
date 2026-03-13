<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeftOnRectangleIcon } from '@heroicons/vue/24/outline';
import { Toaster } from 'vue-sonner';

const page = usePage();
const storeName = (page.props.currentStore as { name: string } | null)?.name ?? 'POS';
</script>

<template>
    <div class="flex h-screen flex-col bg-gray-50 dark:bg-gray-900">
        <!-- Slim top bar -->
        <header class="flex h-12 shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-800">
            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ storeName }} — POS</span>
            <Link
                href="/orders"
                class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            >
                <ArrowLeftOnRectangleIcon class="size-4" />
                Exit POS
            </Link>
        </header>

        <!-- Full viewport content -->
        <main class="min-h-0 flex-1 overflow-auto">
            <slot />
        </main>

        <Toaster position="top-right" :duration="4000" />
    </div>
</template>
