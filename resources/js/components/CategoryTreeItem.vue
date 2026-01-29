<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronRightIcon,
    Cog6ToothIcon,
    FolderIcon,
    FolderOpenIcon,
    PlusIcon,
    PencilIcon,
    TrashIcon,
} from '@heroicons/vue/20/solid';

interface Category {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    parent_id: number | null;
    template_id: number | null;
    template_name: string | null;
    sku_format: string | null;
    sku_prefix: string | null;
    sort_order: number;
    level: number;
    products_count: number;
    is_leaf: boolean;
    children: Category[];
}

interface Props {
    category: Category;
    expandedIds: Set<number>;
    depth?: number;
}

const props = withDefaults(defineProps<Props>(), {
    depth: 0,
});

const emit = defineEmits<{
    toggle: [id: number];
    edit: [category: Category];
    delete: [category: Category];
    'add-child': [parentId: number];
}>();

const hasChildren = props.category.children.length > 0;
const isExpanded = props.expandedIds.has(props.category.id);
</script>

<template>
    <div>
        <div
            class="group flex items-center gap-2 py-2 px-2 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700/50"
            :style="{ paddingLeft: (depth * 24 + 8) + 'px' }"
        >
            <!-- Expand/Collapse button -->
            <button
                v-if="hasChildren"
                type="button"
                class="p-0.5 rounded hover:bg-gray-200 dark:hover:bg-gray-600"
                @click="emit('toggle', category.id)"
            >
                <ChevronRightIcon
                    class="size-4 text-gray-400 transition-transform duration-200"
                    :class="{ 'rotate-90': isExpanded }"
                />
            </button>
            <span v-else class="w-5" />

            <!-- Folder icon -->
            <component
                :is="hasChildren && isExpanded ? FolderOpenIcon : FolderIcon"
                class="size-5 text-gray-400"
            />

            <!-- Category name -->
            <span class="flex-1 text-sm font-medium text-gray-900 dark:text-white">
                {{ category.name }}
            </span>

            <!-- Template badge -->
            <span
                v-if="category.template_name"
                class="text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded dark:bg-indigo-900/50 dark:text-indigo-400"
            >
                {{ category.template_name }}
            </span>

            <!-- SKU Format badge -->
            <span
                v-if="category.sku_format"
                class="text-xs bg-emerald-50 text-emerald-600 px-2 py-0.5 rounded dark:bg-emerald-900/50 dark:text-emerald-400"
                title="Has SKU format configured"
            >
                SKU: {{ category.sku_prefix || 'Auto' }}
            </span>

            <!-- Products count -->
            <span class="text-xs text-gray-400 dark:text-gray-500">
                {{ category.products_count }} product{{ category.products_count !== 1 ? 's' : '' }}
            </span>

            <!-- Actions -->
            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                <button
                    v-if="category.is_leaf"
                    type="button"
                    class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-gray-100 dark:hover:bg-gray-600"
                    title="SKU & Template Settings"
                    @click="router.visit(`/categories/${category.id}/settings`)"
                >
                    <Cog6ToothIcon class="size-4" />
                </button>
                <button
                    type="button"
                    class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-gray-100 dark:hover:bg-gray-600"
                    title="Add subcategory"
                    @click="emit('add-child', category.id)"
                >
                    <PlusIcon class="size-4" />
                </button>
                <button
                    type="button"
                    class="p-1 rounded text-gray-400 hover:text-indigo-600 hover:bg-gray-100 dark:hover:bg-gray-600"
                    title="Edit"
                    @click="emit('edit', category)"
                >
                    <PencilIcon class="size-4" />
                </button>
                <button
                    type="button"
                    class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-gray-100 dark:hover:bg-gray-600"
                    title="Delete"
                    @click="emit('delete', category)"
                >
                    <TrashIcon class="size-4" />
                </button>
            </div>
        </div>

        <!-- Children -->
        <div v-if="hasChildren && isExpanded">
            <CategoryTreeItem
                v-for="child in category.children"
                :key="child.id"
                :category="child"
                :expanded-ids="expandedIds"
                :depth="depth + 1"
                @toggle="emit('toggle', $event)"
                @edit="emit('edit', $event)"
                @delete="emit('delete', $event)"
                @add-child="emit('add-child', $event)"
            />
        </div>
    </div>
</template>
