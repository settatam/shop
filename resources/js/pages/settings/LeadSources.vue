<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface LeadSource {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_active: boolean;
    sort_order: number;
    customers_count: number;
}

interface Props {
    leadSources: LeadSource[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Lead Sources',
        href: '/settings/lead-sources',
    },
];

// Modal states
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);

// Form state
const selectedSource = ref<LeadSource | null>(null);
const formData = ref({
    name: '',
    description: '',
    is_active: true,
});
const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

function openCreateModal() {
    formErrors.value = {};
    formData.value = {
        name: '',
        description: '',
        is_active: true,
    };
    showCreateModal.value = true;
}

function openEditModal(source: LeadSource) {
    selectedSource.value = source;
    formData.value = {
        name: source.name,
        description: source.description || '',
        is_active: source.is_active,
    };
    formErrors.value = {};
    showEditModal.value = true;
}

function openDeleteModal(source: LeadSource) {
    selectedSource.value = source;
    showDeleteModal.value = true;
}

function closeModals() {
    showCreateModal.value = false;
    showEditModal.value = false;
    showDeleteModal.value = false;
    selectedSource.value = null;
    formErrors.value = {};
}

function createSource() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.post('/settings/lead-sources', formData.value, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function updateSource() {
    if (!selectedSource.value || isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.put(`/settings/lead-sources/${selectedSource.value.id}`, formData.value, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function deleteSource() {
    if (!selectedSource.value || isSubmitting.value) return;

    isSubmitting.value = true;

    router.delete(`/settings/lead-sources/${selectedSource.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: () => {
            alert('Cannot delete lead source that is assigned to customers.');
            closeModals();
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Lead Sources" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Lead Sources"
                        description="Manage how customers discovered your store"
                    />
                    <Button @click="openCreateModal()" size="sm">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Add Lead Source
                    </Button>
                </div>

                <!-- Lead Sources list -->
                <div class="space-y-3">
                    <div
                        v-for="source in leadSources"
                        :key="source.id"
                        class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ source.name }}
                                    </h3>
                                    <span
                                        v-if="!source.is_active"
                                        class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20"
                                    >
                                        Inactive
                                    </span>
                                </div>
                                <p v-if="source.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ source.description }}
                                </p>
                                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                    {{ source.customers_count }} {{ source.customers_count === 1 ? 'customer' : 'customers' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openEditModal(source)"
                                    title="Edit lead source"
                                >
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openDeleteModal(source)"
                                    :disabled="source.customers_count > 0"
                                    :title="source.customers_count > 0 ? 'Cannot delete - has customers assigned' : 'Delete lead source'"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="leadSources.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No lead sources configured yet.
                </p>
            </div>
        </SettingsLayout>

        <!-- Create Lead Source Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Add Lead Source
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Create a new lead source to track where customers come from.
                                </p>

                                <div class="mt-6 space-y-4">
                                    <div>
                                        <Label for="create-name">Name</Label>
                                        <Input
                                            id="create-name"
                                            v-model="formData.name"
                                            type="text"
                                            placeholder="e.g., Social Media, Referral"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.name }}
                                        </p>
                                    </div>

                                    <div>
                                        <Label for="create-description">Description</Label>
                                        <Input
                                            id="create-description"
                                            v-model="formData.description"
                                            type="text"
                                            placeholder="Optional description..."
                                            class="mt-1"
                                        />
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="create-active"
                                            :checked="formData.is_active"
                                            @update:checked="formData.is_active = $event as boolean"
                                        />
                                        <Label for="create-active" class="!mb-0">Active</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button
                                    @click="createSource"
                                    :disabled="!formData.name || isSubmitting"
                                    class="w-full sm:ml-3 sm:w-auto"
                                >
                                    {{ isSubmitting ? 'Creating...' : 'Create' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 w-full sm:mt-0 sm:w-auto">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Edit Lead Source Modal -->
        <Teleport to="body">
            <div v-if="showEditModal && selectedSource" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Edit Lead Source
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Update the lead source details.
                                </p>

                                <div class="mt-6 space-y-4">
                                    <div>
                                        <Label for="edit-name">Name</Label>
                                        <Input
                                            id="edit-name"
                                            v-model="formData.name"
                                            type="text"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.name }}
                                        </p>
                                    </div>

                                    <div>
                                        <Label for="edit-description">Description</Label>
                                        <Input
                                            id="edit-description"
                                            v-model="formData.description"
                                            type="text"
                                            class="mt-1"
                                        />
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="edit-active"
                                            :checked="formData.is_active"
                                            @update:checked="formData.is_active = $event as boolean"
                                        />
                                        <Label for="edit-active" class="!mb-0">Active</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button
                                    @click="updateSource"
                                    :disabled="!formData.name || isSubmitting"
                                    class="w-full sm:ml-3 sm:w-auto"
                                >
                                    {{ isSubmitting ? 'Saving...' : 'Save changes' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 w-full sm:mt-0 sm:w-auto">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Lead Source Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedSource" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete lead source
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete <span class="font-medium">{{ selectedSource.name }}</span>? This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    @click="deleteSource"
                                    :disabled="isSubmitting"
                                    class="sm:col-start-2"
                                >
                                    {{ isSubmitting ? 'Deleting...' : 'Delete' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 sm:col-start-1 sm:mt-0">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
