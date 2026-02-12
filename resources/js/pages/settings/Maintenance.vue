<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    ArrowPathIcon,
    MagnifyingGlassIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface SearchableModel {
    key: string;
    name: string;
    class: string;
}

interface Props {
    searchableModels: SearchableModel[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Maintenance',
        href: '/settings/maintenance',
    },
];

const reindexingAll = ref(false);
const reindexingModel = ref<string | null>(null);
const successMessage = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

async function reindexAll() {
    if (reindexingAll.value) return;

    reindexingAll.value = true;
    successMessage.value = null;
    errorMessage.value = null;

    try {
        const response = await fetch('/settings/maintenance/reindex-search', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
        });

        const data = await response.json();

        if (response.ok) {
            successMessage.value = data.message || 'Search index rebuilt successfully.';
        } else {
            errorMessage.value = data.error || 'Failed to rebuild search index.';
        }
    } catch (error) {
        errorMessage.value = 'Failed to connect to server.';
    } finally {
        reindexingAll.value = false;
    }
}

async function reindexModel(modelKey: string) {
    if (reindexingModel.value) return;

    reindexingModel.value = modelKey;
    successMessage.value = null;
    errorMessage.value = null;

    try {
        const response = await fetch(`/settings/maintenance/reindex-model/${modelKey}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
        });

        const data = await response.json();

        if (response.ok) {
            successMessage.value = data.message || 'Index rebuilt successfully.';
        } else {
            errorMessage.value = data.error || 'Failed to rebuild index.';
        }
    } catch (error) {
        errorMessage.value = 'Failed to connect to server.';
    } finally {
        reindexingModel.value = null;
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Maintenance" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Maintenance"
                    description="System maintenance and administrative tasks"
                />

                <!-- Success/Error Messages -->
                <div
                    v-if="successMessage"
                    class="flex items-center gap-3 rounded-lg bg-green-50 p-4 dark:bg-green-900/20"
                >
                    <CheckCircleIcon class="h-5 w-5 text-green-600 dark:text-green-400" />
                    <p class="text-sm text-green-800 dark:text-green-200">{{ successMessage }}</p>
                </div>

                <div
                    v-if="errorMessage"
                    class="flex items-center gap-3 rounded-lg bg-red-50 p-4 dark:bg-red-900/20"
                >
                    <ExclamationCircleIcon class="h-5 w-5 text-red-600 dark:text-red-400" />
                    <p class="text-sm text-red-800 dark:text-red-200">{{ errorMessage }}</p>
                </div>

                <!-- Search Index Section -->
                <div class="rounded-lg border border-gray-200 dark:border-white/10">
                    <div class="border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-center gap-2">
                            <MagnifyingGlassIcon class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                            <h3 class="text-sm font-medium text-gray-900 dark:text-white">Search Index</h3>
                        </div>
                    </div>

                    <div class="p-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Rebuild the search index when search results are out of sync or missing items.
                            This will flush and reimport all searchable data.
                        </p>

                        <!-- Rebuild All Button -->
                        <div class="mb-6">
                            <Button
                                @click="reindexAll"
                                :disabled="reindexingAll || reindexingModel !== null"
                                class="w-full sm:w-auto"
                            >
                                <ArrowPathIcon
                                    :class="['mr-2 h-4 w-4', reindexingAll ? 'animate-spin' : '']"
                                />
                                {{ reindexingAll ? 'Rebuilding All Indexes...' : 'Rebuild All Search Indexes' }}
                            </Button>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                This may take a few minutes depending on the amount of data.
                            </p>
                        </div>

                        <!-- Individual Model Reindex -->
                        <div class="border-t border-gray-200 pt-4 dark:border-white/10">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                Or rebuild individual indexes:
                            </h4>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                <button
                                    v-for="model in searchableModels"
                                    :key="model.key"
                                    @click="reindexModel(model.key)"
                                    :disabled="reindexingAll || reindexingModel !== null"
                                    :class="[
                                        'flex items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors',
                                        reindexingModel === model.key
                                            ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-600 dark:bg-indigo-900/20 dark:text-indigo-300'
                                            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700',
                                        (reindexingAll || (reindexingModel && reindexingModel !== model.key)) ? 'opacity-50 cursor-not-allowed' : '',
                                    ]"
                                >
                                    <ArrowPathIcon
                                        v-if="reindexingModel === model.key"
                                        class="h-4 w-4 animate-spin"
                                    />
                                    {{ model.name }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
