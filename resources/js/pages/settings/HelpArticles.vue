<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface HelpArticle {
    id: number;
    category: string;
    title: string;
    slug: string;
    content: string;
    excerpt: string | null;
    sort_order: number;
    is_published: boolean;
}

interface Props {
    articles: HelpArticle[];
    categories: string[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Help Articles', href: '/settings/help-articles' },
];

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);

const selectedArticle = ref<HelpArticle | null>(null);
const formData = ref({
    category: 'Getting Started',
    title: '',
    content: '',
    excerpt: '',
    is_published: true,
});
const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

const groupedArticles = computed(() => {
    const groups: Record<string, HelpArticle[]> = {};
    for (const article of props.articles) {
        if (!groups[article.category]) {
            groups[article.category] = [];
        }
        groups[article.category].push(article);
    }
    return groups;
});

function openCreateModal() {
    formErrors.value = {};
    formData.value = {
        category: 'Getting Started',
        title: '',
        content: '',
        excerpt: '',
        is_published: true,
    };
    showCreateModal.value = true;
}

function openEditModal(article: HelpArticle) {
    selectedArticle.value = article;
    formData.value = {
        category: article.category,
        title: article.title,
        content: article.content,
        excerpt: article.excerpt || '',
        is_published: article.is_published,
    };
    formErrors.value = {};
    showEditModal.value = true;
}

function openDeleteModal(article: HelpArticle) {
    selectedArticle.value = article;
    showDeleteModal.value = true;
}

function closeModals() {
    showCreateModal.value = false;
    showEditModal.value = false;
    showDeleteModal.value = false;
    selectedArticle.value = null;
    formErrors.value = {};
}

function createArticle() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.post('/settings/help-articles', formData.value, {
        preserveScroll: true,
        onSuccess: () => closeModals(),
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function updateArticle() {
    if (!selectedArticle.value || isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.put(`/settings/help-articles/${selectedArticle.value.id}`, formData.value, {
        preserveScroll: true,
        onSuccess: () => closeModals(),
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function deleteArticle() {
    if (!selectedArticle.value || isSubmitting.value) return;

    isSubmitting.value = true;

    router.delete(`/settings/help-articles/${selectedArticle.value.id}`, {
        preserveScroll: true,
        onSuccess: () => closeModals(),
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Help Articles" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Help Articles"
                        description="Manage help content that staff see in the Help Center"
                    />
                    <Button size="sm" @click="openCreateModal()">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Add Article
                    </Button>
                </div>

                <!-- Grouped articles by category -->
                <div v-for="(groupArticles, groupLabel) in groupedArticles" :key="groupLabel" class="space-y-3">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ groupLabel }}
                    </h3>

                    <div
                        v-for="article in groupArticles"
                        :key="article.id"
                        class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ article.title }}
                                    </h4>
                                    <span
                                        v-if="!article.is_published"
                                        class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20"
                                    >
                                        Draft
                                    </span>
                                </div>
                                <p v-if="article.excerpt" class="mt-1 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ article.excerpt }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button variant="ghost" size="sm" title="Edit article" @click="openEditModal(article)">
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    title="Delete article"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    @click="openDeleteModal(article)"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="articles.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No help articles yet. Add articles to help your staff learn how to use the app.
                </p>
            </div>
        </SettingsLayout>

        <!-- Create Article Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="closeModals" />

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative w-full transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:max-w-2xl dark:bg-gray-800">
                            <div class="px-4 pt-5 pb-4 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Add Help Article
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Create a new help article for the staff Help Center.
                                </p>

                                <div class="mt-6 space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label for="create-category">Category</Label>
                                            <select
                                                id="create-category"
                                                v-model="formData.category"
                                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            >
                                                <option v-for="cat in categories" :key="cat" :value="cat">
                                                    {{ cat }}
                                                </option>
                                            </select>
                                            <p v-if="formErrors.category" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ formErrors.category }}
                                            </p>
                                        </div>
                                        <div>
                                            <Label for="create-title">Title</Label>
                                            <Input
                                                id="create-title"
                                                v-model="formData.title"
                                                type="text"
                                                placeholder="e.g., How to create an in-store buy"
                                                class="mt-1"
                                            />
                                            <p v-if="formErrors.title" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ formErrors.title }}
                                            </p>
                                        </div>
                                    </div>

                                    <div>
                                        <Label for="create-excerpt">Short Summary</Label>
                                        <Input
                                            id="create-excerpt"
                                            v-model="formData.excerpt"
                                            type="text"
                                            placeholder="Brief description shown in search results"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.excerpt" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.excerpt }}
                                        </p>
                                    </div>

                                    <div>
                                        <Label>Content</Label>
                                        <div class="mt-1">
                                            <RichTextEditor
                                                v-model="formData.content"
                                                placeholder="Write the help article content..."
                                            />
                                        </div>
                                        <p v-if="formErrors.content" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.content }}
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox id="create-published" v-model="formData.is_published" />
                                        <Label for="create-published" class="!mb-0">Published</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 dark:bg-white/5">
                                <Button
                                    :disabled="!formData.title || !formData.content || isSubmitting"
                                    class="w-full sm:ml-3 sm:w-auto"
                                    @click="createArticle"
                                >
                                    {{ isSubmitting ? 'Creating...' : 'Create' }}
                                </Button>
                                <Button variant="outline" class="mt-3 w-full sm:mt-0 sm:w-auto" @click="closeModals">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Edit Article Modal -->
        <Teleport to="body">
            <div v-if="showEditModal && selectedArticle" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="closeModals" />

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative w-full transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:max-w-2xl dark:bg-gray-800">
                            <div class="px-4 pt-5 pb-4 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Edit Help Article
                                </h3>

                                <div class="mt-6 space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label for="edit-category">Category</Label>
                                            <select
                                                id="edit-category"
                                                v-model="formData.category"
                                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            >
                                                <option v-for="cat in categories" :key="cat" :value="cat">
                                                    {{ cat }}
                                                </option>
                                            </select>
                                        </div>
                                        <div>
                                            <Label for="edit-title">Title</Label>
                                            <Input id="edit-title" v-model="formData.title" type="text" class="mt-1" />
                                            <p v-if="formErrors.title" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ formErrors.title }}
                                            </p>
                                        </div>
                                    </div>

                                    <div>
                                        <Label for="edit-excerpt">Short Summary</Label>
                                        <Input
                                            id="edit-excerpt"
                                            v-model="formData.excerpt"
                                            type="text"
                                            placeholder="Brief description shown in search results"
                                            class="mt-1"
                                        />
                                    </div>

                                    <div>
                                        <Label>Content</Label>
                                        <div class="mt-1">
                                            <RichTextEditor
                                                v-model="formData.content"
                                                placeholder="Write the help article content..."
                                            />
                                        </div>
                                        <p v-if="formErrors.content" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.content }}
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox id="edit-published" v-model="formData.is_published" />
                                        <Label for="edit-published" class="!mb-0">Published</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 dark:bg-white/5">
                                <Button
                                    :disabled="!formData.title || !formData.content || isSubmitting"
                                    class="w-full sm:ml-3 sm:w-auto"
                                    @click="updateArticle"
                                >
                                    {{ isSubmitting ? 'Saving...' : 'Save changes' }}
                                </Button>
                                <Button variant="outline" class="mt-3 w-full sm:mt-0 sm:w-auto" @click="closeModals">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Article Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedArticle" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="closeModals" />

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pt-5 pb-4 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6 dark:bg-gray-800">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete article
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete
                                        <span class="font-medium">{{ selectedArticle.title }}</span>? This action
                                        cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    :disabled="isSubmitting"
                                    class="sm:col-start-2"
                                    @click="deleteArticle"
                                >
                                    {{ isSubmitting ? 'Deleting...' : 'Delete' }}
                                </Button>
                                <Button variant="outline" class="mt-3 sm:col-start-1 sm:mt-0" @click="closeModals">
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
