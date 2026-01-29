<script setup lang="ts">
import { computed } from 'vue';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/20/solid';

interface PaginationData {
    total: number;
    per_page: number;
    current_page: number;
    from: number;
    to: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    pagination: PaginationData;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    pageChange: [page: number];
}>();

const lastPage = computed(() => Math.ceil(props.pagination.total / props.pagination.per_page));

const canGoPrevious = computed(() => props.pagination.current_page > 1);
const canGoNext = computed(() => props.pagination.current_page < lastPage.value);

// Generate visible page numbers
const visiblePages = computed(() => {
    const current = props.pagination.current_page;
    const total = lastPage.value;
    const delta = 2;
    const pages: (number | string)[] = [];

    // Always show first page
    pages.push(1);

    // Calculate range around current page
    const start = Math.max(2, current - delta);
    const end = Math.min(total - 1, current + delta);

    // Add ellipsis after first page if needed
    if (start > 2) {
        pages.push('...');
    }

    // Add pages in range
    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    // Add ellipsis before last page if needed
    if (end < total - 1) {
        pages.push('...');
    }

    // Always show last page (if more than 1 page)
    if (total > 1) {
        pages.push(total);
    }

    return pages;
});

function goToPage(page: number | string) {
    if (typeof page === 'number') {
        emit('pageChange', page);
    }
}
</script>

<template>
    <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6 dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-1 justify-between sm:hidden">
            <!-- Mobile pagination -->
            <button
                :disabled="!canGoPrevious"
                :class="[
                    'relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium dark:border-gray-600 dark:bg-gray-700',
                    canGoPrevious
                        ? 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600'
                        : 'cursor-not-allowed text-gray-400 dark:text-gray-500',
                ]"
                @click="goToPage(pagination.current_page - 1)"
            >
                Previous
            </button>
            <button
                :disabled="!canGoNext"
                :class="[
                    'relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium dark:border-gray-600 dark:bg-gray-700',
                    canGoNext
                        ? 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600'
                        : 'cursor-not-allowed text-gray-400 dark:text-gray-500',
                ]"
                @click="goToPage(pagination.current_page + 1)"
            >
                Next
            </button>
        </div>

        <!-- Desktop pagination -->
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Showing
                    <span class="font-medium">{{ pagination.from }}</span>
                    to
                    <span class="font-medium">{{ pagination.to }}</span>
                    of
                    <span class="font-medium">{{ pagination.total }}</span>
                    results
                </p>
            </div>
            <div>
                <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                    <!-- Previous button -->
                    <button
                        :disabled="!canGoPrevious"
                        :class="[
                            'relative inline-flex items-center rounded-l-md px-2 py-2 ring-1 ring-inset ring-gray-300 dark:ring-gray-600',
                            canGoPrevious
                                ? 'text-gray-400 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 dark:text-gray-400 dark:hover:bg-gray-700'
                                : 'cursor-not-allowed text-gray-300 dark:text-gray-600',
                        ]"
                        @click="goToPage(pagination.current_page - 1)"
                    >
                        <span class="sr-only">Previous</span>
                        <ChevronLeftIcon class="size-5" aria-hidden="true" />
                    </button>

                    <!-- Page numbers -->
                    <template v-for="(page, index) in visiblePages" :key="index">
                        <span
                            v-if="page === '...'"
                            class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-600"
                        >
                            ...
                        </span>
                        <button
                            v-else
                            :class="[
                                'relative inline-flex items-center px-4 py-2 text-sm font-semibold ring-1 ring-inset ring-gray-300 focus:z-20 focus:outline-offset-0 dark:ring-gray-600',
                                page === pagination.current_page
                                    ? 'z-10 bg-indigo-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'
                                    : 'text-gray-900 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700',
                            ]"
                            @click="goToPage(page)"
                        >
                            {{ page }}
                        </button>
                    </template>

                    <!-- Next button -->
                    <button
                        :disabled="!canGoNext"
                        :class="[
                            'relative inline-flex items-center rounded-r-md px-2 py-2 ring-1 ring-inset ring-gray-300 dark:ring-gray-600',
                            canGoNext
                                ? 'text-gray-400 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 dark:text-gray-400 dark:hover:bg-gray-700'
                                : 'cursor-not-allowed text-gray-300 dark:text-gray-600',
                        ]"
                        @click="goToPage(pagination.current_page + 1)"
                    >
                        <span class="sr-only">Next</span>
                        <ChevronRightIcon class="size-5" aria-hidden="true" />
                    </button>
                </nav>
            </div>
        </div>
    </div>
</template>
