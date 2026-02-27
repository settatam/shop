<script setup lang="ts">
import { ref, computed } from 'vue';
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

interface KnowledgeBaseEntry {
    id: number;
    type: string;
    title: string;
    content: string;
    is_active: boolean;
    sort_order: number;
    type_label: string;
}

interface TypeOption {
    value: string;
    label: string;
}

interface Props {
    entries: KnowledgeBaseEntry[];
    types: TypeOption[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Knowledge Base',
        href: '/settings/knowledge-base',
    },
];

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);

const selectedEntry = ref<KnowledgeBaseEntry | null>(null);
const formData = ref({
    type: 'faq',
    title: '',
    content: '',
    is_active: true,
});
const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

const groupedEntries = computed(() => {
    const groups: Record<string, KnowledgeBaseEntry[]> = {};
    for (const entry of props.entries) {
        const label = entry.type_label || entry.type;
        if (!groups[label]) {
            groups[label] = [];
        }
        groups[label].push(entry);
    }
    return groups;
});

function openCreateModal() {
    formErrors.value = {};
    formData.value = {
        type: 'faq',
        title: '',
        content: '',
        is_active: true,
    };
    showCreateModal.value = true;
}

function openEditModal(entry: KnowledgeBaseEntry) {
    selectedEntry.value = entry;
    formData.value = {
        type: entry.type,
        title: entry.title,
        content: entry.content,
        is_active: entry.is_active,
    };
    formErrors.value = {};
    showEditModal.value = true;
}

function openDeleteModal(entry: KnowledgeBaseEntry) {
    selectedEntry.value = entry;
    showDeleteModal.value = true;
}

function closeModals() {
    showCreateModal.value = false;
    showEditModal.value = false;
    showDeleteModal.value = false;
    selectedEntry.value = null;
    formErrors.value = {};
}

function createEntry() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.post('/settings/knowledge-base', formData.value, {
        preserveScroll: true,
        onSuccess: () => closeModals(),
        onError: (errors) => { formErrors.value = errors; },
        onFinish: () => { isSubmitting.value = false; },
    });
}

function updateEntry() {
    if (!selectedEntry.value || isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.put(`/settings/knowledge-base/${selectedEntry.value.id}`, formData.value, {
        preserveScroll: true,
        onSuccess: () => closeModals(),
        onError: (errors) => { formErrors.value = errors; },
        onFinish: () => { isSubmitting.value = false; },
    });
}

function deleteEntry() {
    if (!selectedEntry.value || isSubmitting.value) return;

    isSubmitting.value = true;

    router.delete(`/settings/knowledge-base/${selectedEntry.value.id}`, {
        preserveScroll: true,
        onSuccess: () => closeModals(),
        onFinish: () => { isSubmitting.value = false; },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Knowledge Base" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Knowledge Base"
                        description="Configure information your AI assistant uses to answer customer questions"
                    />
                    <Button @click="openCreateModal()" size="sm">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Add Entry
                    </Button>
                </div>

                <!-- Grouped entries by type -->
                <div v-for="(groupEntries, groupLabel) in groupedEntries" :key="groupLabel" class="space-y-3">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ groupLabel }}
                    </h3>

                    <div
                        v-for="entry in groupEntries"
                        :key="entry.id"
                        class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ entry.title }}
                                    </h4>
                                    <span
                                        v-if="!entry.is_active"
                                        class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20"
                                    >
                                        Inactive
                                    </span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ entry.content }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button variant="ghost" size="sm" @click="openEditModal(entry)" title="Edit entry">
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openDeleteModal(entry)"
                                    title="Delete entry"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="entries.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No knowledge base entries yet. Add information about your return policy, shipping, FAQs, and more to help the AI assistant answer customer questions.
                </p>
            </div>
        </SettingsLayout>

        <!-- Create Entry Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Add Knowledge Base Entry
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Add information your AI assistant can use to answer customer questions.
                                </p>

                                <div class="mt-6 space-y-4">
                                    <div>
                                        <Label for="create-type">Category</Label>
                                        <select
                                            id="create-type"
                                            v-model="formData.type"
                                            class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option v-for="t in types" :key="t.value" :value="t.value">
                                                {{ t.label }}
                                            </option>
                                        </select>
                                        <p v-if="formErrors.type" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formErrors.type }}</p>
                                    </div>

                                    <div>
                                        <Label for="create-title">Title</Label>
                                        <Input
                                            id="create-title"
                                            v-model="formData.title"
                                            type="text"
                                            placeholder="e.g., 30-Day Return Policy"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.title" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formErrors.title }}</p>
                                    </div>

                                    <div>
                                        <Label for="create-content">Content</Label>
                                        <textarea
                                            id="create-content"
                                            v-model="formData.content"
                                            rows="5"
                                            placeholder="Write the information your assistant should know..."
                                            class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                        <p v-if="formErrors.content" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formErrors.content }}</p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox id="create-active" v-model="formData.is_active" />
                                        <Label for="create-active" class="!mb-0">Active</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button
                                    @click="createEntry"
                                    :disabled="!formData.title || !formData.content || isSubmitting"
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

        <!-- Edit Entry Modal -->
        <Teleport to="body">
            <div v-if="showEditModal && selectedEntry" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Edit Knowledge Base Entry
                                </h3>

                                <div class="mt-6 space-y-4">
                                    <div>
                                        <Label for="edit-type">Category</Label>
                                        <select
                                            id="edit-type"
                                            v-model="formData.type"
                                            class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option v-for="t in types" :key="t.value" :value="t.value">
                                                {{ t.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <Label for="edit-title">Title</Label>
                                        <Input
                                            id="edit-title"
                                            v-model="formData.title"
                                            type="text"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.title" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formErrors.title }}</p>
                                    </div>

                                    <div>
                                        <Label for="edit-content">Content</Label>
                                        <textarea
                                            id="edit-content"
                                            v-model="formData.content"
                                            rows="5"
                                            class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                        <p v-if="formErrors.content" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formErrors.content }}</p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox id="edit-active" v-model="formData.is_active" />
                                        <Label for="edit-active" class="!mb-0">Active</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button
                                    @click="updateEntry"
                                    :disabled="!formData.title || !formData.content || isSubmitting"
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

        <!-- Delete Entry Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedEntry" class="relative z-50">
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
                                        Delete entry
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete <span class="font-medium">{{ selectedEntry.title }}</span>? This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    @click="deleteEntry"
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
