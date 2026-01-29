<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    PencilIcon,
    TrashIcon,
    TagIcon,
} from '@heroicons/vue/20/solid';
import { useDebounceFn } from '@vueuse/core';

interface Tag {
    id: number;
    name: string;
    slug: string;
    color: string;
    products_count: number;
    transactions_count: number;
    memos_count: number;
    repairs_count: number;
    total_usage: number;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedTags {
    data: Tag[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

interface Filters {
    search: string;
}

interface Props {
    tags: PaginatedTags;
    filters: Filters;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tags', href: '/tags' },
];

// Filter state
const searchQuery = ref(props.filters.search || '');

// Modal state
const showTagModal = ref(false);
const showDeleteModal = ref(false);
const editingTag = ref<Tag | null>(null);
const deleteTag = ref<Tag | null>(null);

// Preset colors
const presetColors = [
    '#ef4444', // red
    '#f97316', // orange
    '#f59e0b', // amber
    '#eab308', // yellow
    '#84cc16', // lime
    '#22c55e', // green
    '#14b8a6', // teal
    '#06b6d4', // cyan
    '#3b82f6', // blue
    '#6366f1', // indigo
    '#8b5cf6', // violet
    '#a855f7', // purple
    '#d946ef', // fuchsia
    '#ec4899', // pink
    '#6b7280', // gray
];

// Form
const form = useForm({
    name: '',
    color: '#6b7280',
});

// Search
const performSearch = useDebounceFn(() => {
    router.get('/tags', {
        search: searchQuery.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch(searchQuery, () => {
    performSearch();
});

function openCreateModal() {
    editingTag.value = null;
    form.reset();
    form.color = '#6b7280';
    showTagModal.value = true;
}

function openEditModal(tag: Tag) {
    editingTag.value = tag;
    form.name = tag.name;
    form.color = tag.color;
    showTagModal.value = true;
}

function submitForm() {
    if (editingTag.value) {
        form.put(`/tags/${editingTag.value.id}`, {
            onSuccess: () => {
                showTagModal.value = false;
                form.reset();
            },
        });
    } else {
        form.post('/tags', {
            onSuccess: () => {
                showTagModal.value = false;
                form.reset();
            },
        });
    }
}

function confirmDelete(tag: Tag) {
    deleteTag.value = tag;
    showDeleteModal.value = true;
}

function handleDelete() {
    if (deleteTag.value) {
        router.delete(`/tags/${deleteTag.value.id}`, {
            onSuccess: () => {
                showDeleteModal.value = false;
                deleteTag.value = null;
            },
        });
    }
}
</script>

<template>
    <Head title="Tags" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Tags</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage tags for products, transactions, memos, and repairs
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    @click="openCreateModal"
                >
                    <PlusIcon class="-ml-0.5 size-5" />
                    Create Tag
                </button>
            </div>

            <!-- Search -->
            <div class="mb-4">
                <div class="relative max-w-md">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-gray-400" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search tags..."
                        class="block w-full rounded-md border-0 py-2 pl-10 pr-4 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-800 dark:text-white dark:ring-gray-700"
                    />
                </div>
            </div>

            <!-- Tags Table -->
            <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-4 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Tag</th>
                            <th class="px-4 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Products</th>
                            <th class="px-4 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Transactions</th>
                            <th class="px-4 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Memos</th>
                            <th class="px-4 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Repairs</th>
                            <th class="px-4 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Total Usage</th>
                            <th class="relative px-4 py-3.5">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="tag in tags.data" :key="tag.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="whitespace-nowrap px-4 py-4">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                        :style="{ backgroundColor: tag.color + '20', color: tag.color }"
                                    >
                                        <span class="size-2 rounded-full" :style="{ backgroundColor: tag.color }"></span>
                                        {{ tag.name }}
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ tag.products_count }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ tag.transactions_count }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ tag.memos_count }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ tag.repairs_count }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-gray-900 dark:text-white">
                                {{ tag.total_usage }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-right text-sm">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                        @click="openEditModal(tag)"
                                    >
                                        <PencilIcon class="size-5" />
                                    </button>
                                    <button
                                        v-if="tag.total_usage === 0"
                                        type="button"
                                        class="text-gray-400 hover:text-red-600 dark:hover:text-red-400"
                                        @click="confirmDelete(tag)"
                                    >
                                        <TrashIcon class="size-5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="tags.data.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center">
                                <TagIcon class="mx-auto h-12 w-12 text-gray-400" />
                                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No tags</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Get started by creating your first tag.
                                </p>
                                <div class="mt-6">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                        @click="openCreateModal"
                                    >
                                        <PlusIcon class="-ml-0.5 size-5" />
                                        Create Tag
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="tags.last_page > 1" class="mt-4 flex items-center justify-between">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Showing {{ (tags.current_page - 1) * tags.per_page + 1 }} to
                    {{ Math.min(tags.current_page * tags.per_page, tags.total) }} of {{ tags.total }} results
                </p>
                <nav class="flex gap-1">
                    <button
                        v-for="link in tags.links"
                        :key="link.label"
                        :disabled="!link.url"
                        class="px-3 py-1 text-sm rounded-md"
                        :class="{
                            'bg-indigo-600 text-white': link.active,
                            'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700': !link.active && link.url,
                            'text-gray-400 cursor-not-allowed': !link.url,
                        }"
                        @click="link.url && router.get(link.url)"
                        v-html="link.label"
                    />
                </nav>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Teleport to="body">
            <div v-if="showTagModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showTagModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <form @submit.prevent="submitForm">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    {{ editingTag ? 'Edit Tag' : 'Create Tag' }}
                                </h3>

                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name *</label>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            required
                                            placeholder="e.g., Featured, Sale, New Arrival"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Color</label>
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="size-10 rounded-lg border-2 border-gray-300 dark:border-gray-600"
                                                :style="{ backgroundColor: form.color }"
                                            />
                                            <input
                                                v-model="form.color"
                                                type="color"
                                                class="h-10 w-16 cursor-pointer rounded border-0 bg-transparent"
                                            />
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button
                                                v-for="color in presetColors"
                                                :key="color"
                                                type="button"
                                                class="size-6 rounded-full border-2 transition-transform hover:scale-110"
                                                :class="form.color === color ? 'border-gray-900 dark:border-white' : 'border-transparent'"
                                                :style="{ backgroundColor: color }"
                                                @click="form.color = color"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-5 sm:mt-6 flex flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        :disabled="form.processing"
                                        class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                    >
                                        {{ form.processing ? 'Saving...' : (editingTag ? 'Update Tag' : 'Create Tag') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showTagModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10 dark:bg-red-900">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete Tag
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Are you sure you want to delete "{{ deleteTag?.name }}"? This action cannot be undone.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                                <button
                                    type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:w-auto"
                                    @click="handleDelete"
                                >
                                    Delete
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showDeleteModal = false"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
