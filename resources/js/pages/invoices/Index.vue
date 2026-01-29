<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Widget from '@/components/widgets/Widget.vue';
import type { BreadcrumbItemType } from '@/types';

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Invoices', href: '/invoices' },
];

// Filters that will be passed to the widget
const filters = ref({
    page: 1,
    per_page: 15,
});

// Reference to the widget for programmatic control
const widgetRef = ref<InstanceType<typeof Widget> | null>(null);

function handleStatusChange(status: string) {
    if (widgetRef.value) {
        widgetRef.value.updateFilter({ status, page: 1 });
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6">
            <!-- Page header -->
            <div class="sm:flex sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Invoices</h1>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        View and manage all invoices for orders, repairs, and memos.
                    </p>
                </div>
                <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
                    <button
                        type="button"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        Export
                    </button>
                </div>
            </div>

            <!-- Status filter tabs -->
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex gap-x-8" aria-label="Tabs">
                    <button
                        type="button"
                        class="whitespace-nowrap border-b-2 border-indigo-500 px-1 py-4 text-sm font-medium text-indigo-600 dark:text-indigo-400"
                        @click="handleStatusChange('')"
                    >
                        All
                    </button>
                    <button
                        type="button"
                        class="whitespace-nowrap border-b-2 border-transparent px-1 py-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        @click="handleStatusChange('pending')"
                    >
                        Pending
                    </button>
                    <button
                        type="button"
                        class="whitespace-nowrap border-b-2 border-transparent px-1 py-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        @click="handleStatusChange('partial')"
                    >
                        Partial
                    </button>
                    <button
                        type="button"
                        class="whitespace-nowrap border-b-2 border-transparent px-1 py-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        @click="handleStatusChange('paid')"
                    >
                        Paid
                    </button>
                    <button
                        type="button"
                        class="whitespace-nowrap border-b-2 border-transparent px-1 py-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        @click="handleStatusChange('overdue')"
                    >
                        Overdue
                    </button>
                </nav>
            </div>

            <!-- Invoices table widget -->
            <Widget
                ref="widgetRef"
                type="Invoices\InvoicesTable"
                :filter="filters"
            />
        </div>
    </AppLayout>
</template>
