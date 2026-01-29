<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { ChevronRightIcon, XMarkIcon, FolderIcon, FolderOpenIcon, CheckIcon } from '@heroicons/vue/20/solid';

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level: number;
    template_id: number | null;
}

interface Props {
    categories: Category[];
    modelValue: number | string | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: number | string | null];
}>();

// Build a tree structure from flat categories
const categoryTree = computed(() => {
    const map = new Map<number, Category & { children: Category[] }>();
    const roots: (Category & { children: Category[] })[] = [];

    // First pass: create nodes with children arrays
    props.categories.forEach(cat => {
        map.set(cat.id, { ...cat, children: [] });
    });

    // Second pass: build tree
    props.categories.forEach(cat => {
        const node = map.get(cat.id)!;
        if (cat.parent_id === null || cat.parent_id === 0) {
            roots.push(node);
        } else {
            const parent = map.get(cat.parent_id);
            if (parent) {
                parent.children.push(node);
            } else {
                // Parent not found, treat as root
                roots.push(node);
            }
        }
    });

    return roots;
});

// Track the path of selected categories (for drill-down)
const selectionPath = ref<number[]>([]);

// Get current level categories to display
const currentCategories = computed(() => {
    if (selectionPath.value.length === 0) {
        return categoryTree.value;
    }

    // Navigate to the current level
    let current = categoryTree.value;
    for (const id of selectionPath.value) {
        const found = current.find(c => c.id === id);
        if (found && found.children.length > 0) {
            current = found.children;
        } else {
            return [];
        }
    }
    return current;
});

// Get the breadcrumb path for display
const breadcrumbPath = computed(() => {
    const path: Category[] = [];
    let current = categoryTree.value;

    for (const id of selectionPath.value) {
        const found = current.find(c => c.id === id);
        if (found) {
            path.push(found);
            current = (found as any).children || [];
        }
    }

    return path;
});

// Check if a category is a leaf (has no children)
function isLeaf(category: Category & { children?: Category[] }): boolean {
    return !category.children || category.children.length === 0;
}

// Get selected category details
const selectedCategory = computed(() => {
    if (!props.modelValue) return null;
    return props.categories.find(c => c.id === props.modelValue);
});

// Handle category selection
function selectCategory(category: Category & { children?: Category[] }) {
    if (isLeaf(category)) {
        // Leaf node - final selection
        emit('update:modelValue', category.id);
    } else {
        // Non-leaf - drill down
        selectionPath.value = [...selectionPath.value, category.id];
    }
}

// Navigate back in the breadcrumb
function navigateToLevel(index: number) {
    selectionPath.value = selectionPath.value.slice(0, index);
}

// Clear selection
function clearSelection() {
    emit('update:modelValue', null);
    selectionPath.value = [];
}

// Go back one level
function goBack() {
    selectionPath.value = selectionPath.value.slice(0, -1);
}

// Initialize selection path based on current value
watch(() => props.modelValue, (newValue) => {
    if (newValue) {
        // Find the category and build the path to it
        const category = props.categories.find(c => c.id === newValue);
        if (category) {
            const path: number[] = [];
            let current = category;

            // Build path from leaf to root
            while (current.parent_id) {
                const parent = props.categories.find(c => c.id === current.parent_id);
                if (parent) {
                    path.unshift(parent.id);
                    current = parent;
                } else {
                    break;
                }
            }

            selectionPath.value = path;
        }
    }
}, { immediate: true });
</script>

<template>
    <div class="space-y-2">
        <!-- Selected category display -->
        <div v-if="selectedCategory" class="flex items-center gap-2 rounded-md bg-indigo-50 px-3 py-2 dark:bg-indigo-900/30">
            <CheckIcon class="size-4 text-indigo-600 dark:text-indigo-400" />
            <span class="flex-1 text-sm font-medium text-indigo-900 dark:text-indigo-100">
                {{ selectedCategory.full_path }}
            </span>
            <button
                type="button"
                class="rounded p-0.5 text-indigo-600 hover:bg-indigo-100 dark:text-indigo-400 dark:hover:bg-indigo-800"
                @click="clearSelection"
            >
                <XMarkIcon class="size-4" />
            </button>
        </div>

        <!-- Category selector -->
        <div v-else class="rounded-md border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-700">
            <!-- Breadcrumb navigation -->
            <div v-if="selectionPath.length > 0" class="flex items-center gap-1 border-b border-gray-200 px-3 py-2 dark:border-gray-600">
                <button
                    type="button"
                    class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                    @click="navigateToLevel(0)"
                >
                    All
                </button>
                <template v-for="(cat, index) in breadcrumbPath" :key="cat.id">
                    <ChevronRightIcon class="size-4 text-gray-400" />
                    <button
                        type="button"
                        class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                        @click="navigateToLevel(index + 1)"
                    >
                        {{ cat.name }}
                    </button>
                </template>
            </div>

            <!-- Category list -->
            <div class="max-h-64 overflow-y-auto">
                <div v-if="currentCategories.length === 0" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                    No categories available
                </div>
                <button
                    v-for="category in currentCategories"
                    :key="category.id"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-600"
                    @click="selectCategory(category)"
                >
                    <component
                        :is="isLeaf(category) ? FolderIcon : FolderOpenIcon"
                        class="size-5 text-gray-400"
                    />
                    <span class="flex-1 text-sm text-gray-900 dark:text-white">
                        {{ category.name }}
                    </span>
                    <span v-if="!isLeaf(category)" class="flex items-center gap-1 text-xs text-gray-400">
                        <span>{{ (category as any).children?.length || 0 }}</span>
                        <ChevronRightIcon class="size-4" />
                    </span>
                    <span v-else class="text-xs text-green-600 dark:text-green-400">
                        Select
                    </span>
                </button>
            </div>

            <!-- Back button -->
            <div v-if="selectionPath.length > 0" class="border-t border-gray-200 px-3 py-2 dark:border-gray-600">
                <button
                    type="button"
                    class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                    @click="goBack"
                >
                    ← Back
                </button>
            </div>
        </div>

        <!-- Helper text -->
        <p v-if="!selectedCategory" class="text-xs text-gray-500 dark:text-gray-400">
            Navigate through categories and select a leaf category
        </p>
    </div>
</template>
