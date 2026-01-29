<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    title?: string;
}>();

const page = usePage();
const store = computed(() => (page.props as any).currentStore);
const customer = computed(() => (page.props as any).auth?.customer);

function logout() {
    router.post(`/p/logout`);
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <Head :title="title" />

        <!-- Header -->
        <header class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-6">
                    <Link href="/p/" class="flex items-center gap-2">
                        <img
                            v-if="store?.logo_url"
                            :src="store.logo_url"
                            :alt="store?.name"
                            class="h-8 w-auto"
                        />
                        <span class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ store?.name }}
                        </span>
                    </Link>

                    <nav class="hidden items-center gap-4 sm:flex">
                        <Link
                            href="/p/"
                            class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white"
                        >
                            Transactions
                        </Link>
                        <Link
                            href="/p/account"
                            class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white"
                        >
                            Account
                        </Link>
                    </nav>
                </div>

                <div class="flex items-center gap-4">
                    <span v-if="customer" class="text-sm text-gray-600 dark:text-gray-400">
                        {{ customer.first_name }}
                    </span>
                    <button
                        @click="logout"
                        class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white"
                    >
                        Sign out
                    </button>
                </div>
            </div>
        </header>

        <!-- Main content -->
        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>
    </div>
</template>
