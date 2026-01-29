<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import CategoryTreeItem from '@/components/CategoryTreeItem.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    PlusIcon,
    FolderIcon,
} from '@heroicons/vue/20/solid';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';

interface Category {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    parent_id: number | null;
    template_id: number | null;
    template_name: string | null;
    sort_order: number;
    level: number;
    products_count: number;
    children: Category[];
}

interface Template {
    id: number;
    name: string;
}

interface Props {
    categories: Category[];
    templates: Template[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Categories', href: '/categories' },
];

// Track expanded categories
const expandedIds = ref<Set<number>>(new Set());

// Modal state
const showModal = ref(false);
const editingCategory = ref<Category | null>(null);

// Form for creating/editing
const form = useForm({
    name: '',
    slug: '',
    description: '',
    parent_id: null as number | null,
    template_id: null as number | null,
});

// Flatten categories for parent dropdown
const flatCategories = computed(() => {
    const result: { id: number; name: string; level: number }[] = [];

    function flatten(cats: Category[], level: number = 0) {
        for (const cat of cats) {
            result.push({ id: cat.id, name: cat.name, level });
            if (cat.children.length > 0) {
                flatten(cat.children, level + 1);
            }
        }
    }

    flatten(props.categories);
    return result;
});

function toggleExpanded(id: number) {
    if (expandedIds.value.has(id)) {
        expandedIds.value.delete(id);
    } else {
        expandedIds.value.add(id);
    }
    // Force reactivity
    expandedIds.value = new Set(expandedIds.value);
}

function openCreateModal(parentId: number | null = null) {
    editingCategory.value = null;
    form.reset();
    form.parent_id = parentId;
    showModal.value = true;
}

function openEditModal(category: Category) {
    editingCategory.value = category;
    form.name = category.name;
    form.slug = category.slug;
    form.description = category.description || '';
    form.parent_id = category.parent_id;
    form.template_id = category.template_id;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingCategory.value = null;
    form.reset();
}

function generateSlug(name: string): string {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-');
}

function onNameChange() {
    if (!editingCategory.value && !form.slug) {
        form.slug = generateSlug(form.name);
    }
}

function submit() {
    if (editingCategory.value) {
        form.put(`/categories/${editingCategory.value.id}`, {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post('/categories', {
            onSuccess: () => closeModal(),
        });
    }
}

function deleteCategory(category: Category) {
    if (category.children.length > 0) {
        alert('Cannot delete a category that has subcategories. Delete the subcategories first.');
        return;
    }

    if (category.products_count > 0) {
        alert(`Cannot delete "${category.name}" because it has ${category.products_count} product(s) assigned.`);
        return;
    }

    if (confirm(`Are you sure you want to delete "${category.name}"?`)) {
        router.delete(`/categories/${category.id}`);
    }
}

// Expand all root categories by default
props.categories.forEach(cat => {
    if (cat.children.length > 0) {
        expandedIds.value.add(cat.id);
    }
});

// Get available parents for the current form (exclude self and descendants)
const availableParents = computed(() => {
    if (!editingCategory.value) {
        return flatCategories.value;
    }

    // Filter out the current category and its descendants
    const excludeIds = new Set<number>();

    function collectDescendants(cat: Category) {
        excludeIds.add(cat.id);
        cat.children.forEach(collectDescendants);
    }

    // Find and collect the editing category and its descendants
    function findCategory(cats: Category[]): Category | null {
        for (const cat of cats) {
            if (cat.id === editingCategory.value?.id) {
                return cat;
            }
            const found = findCategory(cat.children);
            if (found) return found;
        }
        return null;
    }

    const found = findCategory(props.categories);
    if (found) {
        collectDescendants(found);
    }

    return flatCategories.value.filter(cat => !excludeIds.has(cat.id));
});
</script>

<template>
    <Head title="Categories" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 lg:p-8">
            <!-- Header -->
            <div class="sm:flex sm:items-center sm:justify-between mb-8">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Categories</h1>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-400">
                        Organize your products into a hierarchical category structure.
                    </p>
                </div>
                <div class="mt-4 sm:mt-0">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        @click="openCreateModal()"
                    >
                        <PlusIcon class="-ml-0.5 size-5" />
                        New Category
                    </button>
                </div>
            </div>

            <!-- Category Tree -->
            <div v-if="categories.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <div class="px-4 py-5 sm:p-6">
                    <div class="space-y-1">
                        <CategoryTreeItem
                            v-for="category in categories"
                            :key="category.id"
                            :category="category"
                            :expanded-ids="expandedIds"
                            :depth="0"
                            @toggle="toggleExpanded"
                            @edit="openEditModal"
                            @delete="deleteCategory"
                            @add-child="openCreateModal"
                        />
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-12 bg-white rounded-lg shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <FolderIcon class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No categories</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Get started by creating your first category.
                </p>
                <div class="mt-6">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        @click="openCreateModal()"
                    >
                        <PlusIcon class="-ml-0.5 size-5" />
                        New Category
                    </button>
                </div>
            </div>

            <!-- Create/Edit Modal -->
            <TransitionRoot as="template" :show="showModal">
                <Dialog class="relative z-50" @close="closeModal">
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0"
                        enter-to="opacity-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100"
                        leave-to="opacity-0"
                    >
                        <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                    </TransitionChild>

                    <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                            <TransitionChild
                                as="template"
                                enter="ease-out duration-300"
                                enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                                enter-to="opacity-100 translate-y-0 sm:scale-100"
                                leave="ease-in duration-200"
                                leave-from="opacity-100 translate-y-0 sm:scale-100"
                                leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                            >
                                <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                    <form @submit.prevent="submit">
                                        <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                            {{ editingCategory ? 'Edit Category' : 'New Category' }}
                                        </DialogTitle>

                                        <div class="space-y-4">
                                            <!-- Name -->
                                            <div>
                                                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Name <span class="text-red-500">*</span>
                                                </label>
                                                <input
                                                    id="name"
                                                    v-model="form.name"
                                                    type="text"
                                                    required
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    @blur="onNameChange"
                                                />
                                                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                                            </div>

                                            <!-- Slug -->
                                            <div>
                                                <label for="slug" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Slug
                                                </label>
                                                <input
                                                    id="slug"
                                                    v-model="form.slug"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 font-mono dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>

                                            <!-- Parent -->
                                            <div>
                                                <label for="parent_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Parent Category
                                                </label>
                                                <select
                                                    id="parent_id"
                                                    v-model="form.parent_id"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option :value="null">— No parent (root category) —</option>
                                                    <option
                                                        v-for="cat in availableParents"
                                                        :key="cat.id"
                                                        :value="cat.id"
                                                    >
                                                        {{ '\u2014'.repeat(cat.level) }} {{ cat.name }}
                                                    </option>
                                                </select>
                                                <p v-if="form.errors.parent_id" class="mt-1 text-sm text-red-600">{{ form.errors.parent_id }}</p>
                                            </div>

                                            <!-- Template -->
                                            <div>
                                                <label for="template_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Product Template
                                                </label>
                                                <select
                                                    id="template_id"
                                                    v-model="form.template_id"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option :value="null">— No template (inherit from parent) —</option>
                                                    <option v-for="template in templates" :key="template.id" :value="template.id">
                                                        {{ template.name }}
                                                    </option>
                                                </select>
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    Products in this category will use this template's custom fields.
                                                </p>
                                            </div>

                                            <!-- Description -->
                                            <div>
                                                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Description
                                                </label>
                                                <textarea
                                                    id="description"
                                                    v-model="form.description"
                                                    rows="2"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>

                                        <div class="mt-6 flex justify-end gap-3">
                                            <button
                                                type="button"
                                                class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                                @click="closeModal"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                type="submit"
                                                :disabled="form.processing"
                                                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                            >
                                                {{ form.processing ? 'Saving...' : (editingCategory ? 'Save Changes' : 'Create Category') }}
                                            </button>
                                        </div>
                                    </form>
                                </DialogPanel>
                            </TransitionChild>
                        </div>
                    </div>
                </Dialog>
            </TransitionRoot>
        </div>
    </AppLayout>
</template>
