<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    PlusIcon,
    TrashIcon,
    CurrencyDollarIcon,
    ArchiveBoxIcon,
} from '@heroicons/vue/20/solid';
import { ref } from 'vue';

interface Bucket {
    id: number;
    name: string;
    description: string | null;
    total_value: number;
    items_count: number;
    active_items_count: number;
    sold_items_count: number;
    created_at: string;
}

interface Props {
    buckets: Bucket[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Buckets', href: '/buckets' },
];

const showCreateModal = ref(false);
const showDeleteModal = ref(false);
const deleteBucket = ref<Bucket | null>(null);

const form = useForm({
    name: '',
    description: '',
});

function submitForm() {
    form.post('/buckets', {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });
}

function confirmDelete(bucket: Bucket) {
    deleteBucket.value = bucket;
    showDeleteModal.value = true;
}

function handleDelete() {
    if (deleteBucket.value) {
        router.delete(`/buckets/${deleteBucket.value.id}`, {
            onSuccess: () => {
                showDeleteModal.value = false;
                deleteBucket.value = null;
            },
        });
    }
}

function formatCurrency(amount: number) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}
</script>

<template>
    <Head title="Buckets" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Buckets</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage buckets for junk items without SKUs
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    @click="showCreateModal = true"
                >
                    <PlusIcon class="-ml-0.5 size-5" />
                    Create Bucket
                </button>
            </div>

            <!-- Buckets Grid -->
            <div v-if="buckets.length > 0" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="bucket in buckets"
                    :key="bucket.id"
                    :href="`/buckets/${bucket.id}`"
                    class="relative rounded-lg bg-white shadow ring-1 ring-black/5 hover:ring-indigo-500 hover:shadow-md transition-all dark:bg-gray-800 dark:ring-white/10 dark:hover:ring-indigo-500"
                >
                    <div class="p-5">
                        <!-- Header -->
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ bucket.name }}
                                </h3>
                            </div>
                            <div class="flex items-center gap-1 text-lg font-semibold text-green-600 dark:text-green-400">
                                <CurrencyDollarIcon class="size-5" />
                                {{ formatCurrency(bucket.total_value) }}
                            </div>
                        </div>

                        <!-- Description -->
                        <p v-if="bucket.description" class="mt-2 text-sm text-gray-600 dark:text-gray-400 line-clamp-2">
                            {{ bucket.description }}
                        </p>

                        <!-- Stats -->
                        <div class="mt-4 flex items-center gap-4 text-sm">
                            <div class="flex items-center gap-1 text-gray-500 dark:text-gray-400">
                                <ArchiveBoxIcon class="size-4" />
                                <span class="font-semibold text-gray-900 dark:text-white">{{ bucket.active_items_count }}</span>
                                active
                            </div>
                            <div v-if="bucket.sold_items_count > 0" class="flex items-center gap-1 text-gray-500 dark:text-gray-400">
                                <span class="font-semibold text-gray-900 dark:text-white">{{ bucket.sold_items_count }}</span>
                                sold
                            </div>
                        </div>

                        <!-- Delete button -->
                        <div class="mt-4 flex justify-end border-t border-gray-200 pt-4 dark:border-gray-700">
                            <button
                                v-if="bucket.active_items_count === 0"
                                type="button"
                                class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                @click.prevent="confirmDelete(bucket)"
                            >
                                <TrashIcon class="size-5" />
                            </button>
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-12">
                <ArchiveBoxIcon class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No buckets</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Get started by creating your first bucket for junk items.
                </p>
                <div class="mt-6">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="showCreateModal = true"
                    >
                        <PlusIcon class="-ml-0.5 size-5" />
                        Create Bucket
                    </button>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showCreateModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <form @submit.prevent="submitForm">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    Create Bucket
                                </h3>

                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name *</label>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            required
                                            placeholder="e.g., Junk Watches, Scrap Gold"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                        <textarea
                                            v-model="form.description"
                                            rows="3"
                                            placeholder="Optional description for this bucket"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <div class="mt-5 sm:mt-6 flex flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        :disabled="form.processing"
                                        class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                    >
                                        {{ form.processing ? 'Creating...' : 'Create Bucket' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showCreateModal = false"
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
                                        Delete Bucket
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Are you sure you want to delete "{{ deleteBucket?.name }}"? This action cannot be undone.
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
