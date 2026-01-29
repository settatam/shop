<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import {
    PlusIcon,
    PencilIcon,
    DocumentDuplicateIcon,
    TrashIcon,
    CheckCircleIcon,
    XCircleIcon,
    MagnifyingGlassIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';
import { SparklesIcon } from '@heroicons/vue/24/outline';

interface Template {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    fields_count: number;
    categories_count: number;
    created_at: string;
    updated_at: string;
}

interface Filters {
    search: string;
}

interface Props {
    templates: Template[];
    filters: Filters;
}

const props = defineProps<Props>();

const search = ref(props.filters.search || '');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Templates', href: '/templates' },
];

const performSearch = useDebounceFn(() => {
    router.get('/templates', { search: search.value || undefined }, {
        preserveState: true,
        preserveScroll: true,
    });
}, 300);

watch(search, () => {
    performSearch();
});

function clearSearch() {
    search.value = '';
}

function deleteTemplate(template: Template) {
    if (confirm(`Are you sure you want to delete "${template.name}"? This will remove the template from all assigned categories.`)) {
        router.delete(`/templates/${template.id}`);
    }
}

function duplicateTemplate(template: Template) {
    router.post(`/templates/${template.id}/duplicate`);
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
</script>

<template>
    <Head title="Product Templates" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 lg:p-8">
            <!-- Header -->
            <div class="sm:flex sm:items-center sm:justify-between mb-8">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Product Templates</h1>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-400">
                        Manage templates that define custom attributes for different product types.
                    </p>
                </div>
                <div class="mt-4 sm:mt-0 flex items-center gap-3">
                    <Link
                        href="/templates-generator"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-gradient-to-r from-purple-600 to-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:from-purple-500 hover:to-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        <SparklesIcon class="-ml-0.5 size-5" />
                        Generate with AI
                    </Link>
                    <Link
                        href="/templates/create"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <PlusIcon class="-ml-0.5 size-5" />
                        Create Manual
                    </Link>
                </div>
            </div>

            <!-- Search -->
            <div class="mb-6">
                <div class="relative max-w-md">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <MagnifyingGlassIcon class="size-5 text-gray-400" />
                    </div>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search templates..."
                        class="block w-full rounded-md border-0 py-2 pl-10 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                    />
                    <button
                        v-if="search"
                        type="button"
                        class="absolute inset-y-0 right-0 flex items-center pr-3"
                        @click="clearSearch"
                    >
                        <XMarkIcon class="size-5 text-gray-400 hover:text-gray-500" />
                    </button>
                </div>
            </div>

            <!-- Templates List -->
            <div v-if="templates.length > 0" class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                Template
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Fields
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Categories
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Status
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Updated
                            </th>
                            <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="template in templates" :key="template.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="py-4 pl-4 pr-3 sm:pl-6">
                                <div>
                                    <Link
                                        :href="`/templates/${template.id}`"
                                        class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                    >
                                        {{ template.name }}
                                    </Link>
                                    <p v-if="template.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400 truncate max-w-xs">
                                        {{ template.description }}
                                    </p>
                                </div>
                            </td>
                            <td class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ template.fields_count }} field{{ template.fields_count !== 1 ? 's' : '' }}
                            </td>
                            <td class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ template.categories_count }} categor{{ template.categories_count !== 1 ? 'ies' : 'y' }}
                            </td>
                            <td class="px-3 py-4 text-sm">
                                <span
                                    v-if="template.is_active"
                                    class="inline-flex items-center gap-x-1.5 rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20"
                                >
                                    <CheckCircleIcon class="size-3.5" />
                                    Active
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-x-1.5 rounded-full bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20"
                                >
                                    <XCircleIcon class="size-3.5" />
                                    Inactive
                                </span>
                            </td>
                            <td class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ formatDate(template.updated_at) }}
                            </td>
                            <td class="py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <div class="flex items-center justify-end gap-2">
                                    <Link
                                        :href="`/templates/${template.id}/edit`"
                                        class="rounded p-1 text-gray-400 hover:text-indigo-600 hover:bg-gray-100 dark:hover:text-indigo-400 dark:hover:bg-gray-700"
                                        title="Edit"
                                    >
                                        <PencilIcon class="size-5" />
                                    </Link>
                                    <button
                                        type="button"
                                        class="rounded p-1 text-gray-400 hover:text-indigo-600 hover:bg-gray-100 dark:hover:text-indigo-400 dark:hover:bg-gray-700"
                                        title="Duplicate"
                                        @click="duplicateTemplate(template)"
                                    >
                                        <DocumentDuplicateIcon class="size-5" />
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded p-1 text-gray-400 hover:text-red-600 hover:bg-gray-100 dark:hover:text-red-400 dark:hover:bg-gray-700"
                                        title="Delete"
                                        @click="deleteTemplate(template)"
                                    >
                                        <TrashIcon class="size-5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-12 bg-white rounded-lg shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <template v-if="search">
                    <MagnifyingGlassIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No templates found</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        No templates match your search "{{ search }}".
                    </p>
                    <div class="mt-6">
                        <button
                            type="button"
                            class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            @click="clearSearch"
                        >
                            Clear Search
                        </button>
                    </div>
                </template>
                <template v-else>
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                    </svg>
                    <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No templates</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Get started by creating a new product template.
                    </p>
                    <div class="mt-6">
                        <Link
                            href="/templates/create"
                            class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            <PlusIcon class="-ml-0.5 size-5" />
                            New Template
                        </Link>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
