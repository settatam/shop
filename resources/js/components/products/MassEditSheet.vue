<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';

interface Product {
    id: number;
    title: string;
    category_id: number | null;
    category_name: string | null;
    brand_id: number | null;
    brand_name: string | null;
    vendor_id: number | null;
    vendor_name: string | null;
    price: number | null;
    wholesale_price: number | null;
    cost: number | null;
    status: string | null;
    is_published: boolean;
    template_name: string | null;
}

interface Category {
    id: number;
    name: string;
    parent_id: number | null;
}

interface Brand {
    id: number;
    name: string;
}

interface Vendor {
    id: number;
    name: string;
}

interface EditableProduct {
    id: number;
    title: string;
    price: number | null;
    wholesale_price: number | null;
    cost: number | null;
    category_id: number | null;
    vendor_id: number | null;
    status: string | null;
    isDirty: boolean;
}

interface Props {
    open: boolean;
    products: Product[];
    categories: Category[];
    brands: Brand[];
    vendors: Vendor[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    success: [];
}>();

const processing = ref(false);
const editableProducts = ref<EditableProduct[]>([]);

// Initialize editable products when sheet opens
watch(() => props.open, (isOpen) => {
    if (isOpen && props.products.length > 0) {
        editableProducts.value = props.products.map(p => ({
            id: p.id,
            title: p.title || '',
            price: p.price ?? null,
            wholesale_price: p.wholesale_price ?? null,
            cost: p.cost ?? null,
            category_id: p.category_id ?? null,
            vendor_id: p.vendor_id ?? null,
            status: p.status ?? 'draft',
            isDirty: false,
        }));
    }
});

// Mark product as dirty when edited
function markDirty(index: number) {
    editableProducts.value[index].isDirty = true;
}

// Check if any products have been modified
const hasChanges = computed(() => {
    return editableProducts.value.some(p => p.isDirty);
});

// Count of modified products
const dirtyCount = computed(() => {
    return editableProducts.value.filter(p => p.isDirty).length;
});

// Build category tree structure
interface CategoryNode {
    id: number;
    name: string;
    parent_id: number | null;
    depth: number;
    isLeaf: boolean;
}

const categoryTree = computed((): CategoryNode[] => {
    // Find all category IDs that are parents (have children)
    const parentIds = new Set(
        props.categories
            .filter(c => c.parent_id !== null)
            .map(c => c.parent_id as number)
    );

    // Build a map for quick lookup
    const categoryMap = new Map(props.categories.map(c => [c.id, c]));

    // Calculate depth for each category
    const getDepth = (category: Category): number => {
        let depth = 0;
        let current = category;
        while (current.parent_id !== null) {
            depth++;
            const parent = categoryMap.get(current.parent_id);
            if (!parent) break;
            current = parent;
        }
        return depth;
    };

    // Sort categories to show hierarchy (parents before children, alphabetically within levels)
    const sortedCategories: CategoryNode[] = [];

    const addCategoryWithChildren = (parentId: number | null, depth: number) => {
        const children = props.categories
            .filter(c => c.parent_id === parentId)
            .sort((a, b) => a.name.localeCompare(b.name));

        for (const category of children) {
            sortedCategories.push({
                id: category.id,
                name: category.name,
                parent_id: category.parent_id,
                depth,
                isLeaf: !parentIds.has(category.id),
            });
            addCategoryWithChildren(category.id, depth + 1);
        }
    };

    addCategoryWithChildren(null, 0);
    return sortedCategories;
});

function handleSubmit() {
    if (!hasChanges.value) return;

    // Only send products that were modified
    const changedProducts = editableProducts.value
        .filter(p => p.isDirty)
        .map(p => ({
            id: p.id,
            title: p.title,
            price: p.price,
            wholesale_price: p.wholesale_price,
            cost: p.cost,
            category_id: p.category_id,
            vendor_id: p.vendor_id,
            status: p.status,
        }));

    processing.value = true;

    router.post('/products/bulk-inline-update', {
        products: changedProducts,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:open', false);
            emit('success');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function handleClose() {
    emit('update:open', false);
}
</script>

<template>
    <Sheet :open="open" @update:open="(val) => emit('update:open', val)">
        <SheetContent side="right" class="w-full sm:w-[75vw] sm:max-w-none overflow-y-auto">
            <SheetHeader>
                <SheetTitle>Edit {{ products.length }} Products</SheetTitle>
                <SheetDescription>
                    Edit product details inline. Changes are highlighted and saved when you click Update.
                </SheetDescription>
            </SheetHeader>

            <div class="py-4">
                <!-- Inline editing table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-64">
                                    Title
                                </th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">
                                    Price
                                </th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">
                                    Wholesale
                                </th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">
                                    Cost
                                </th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-36">
                                    Vendor
                                </th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-36">
                                    Category
                                </th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-28">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 dark:bg-gray-900 dark:divide-gray-700">
                            <tr
                                v-for="(product, index) in editableProducts"
                                :key="product.id"
                                :class="{ 'bg-yellow-50 dark:bg-yellow-900/20': product.isDirty }"
                            >
                                <!-- Title -->
                                <td class="px-2 py-1">
                                    <input
                                        v-model="product.title"
                                        type="text"
                                        class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                                        @input="markDirty(index)"
                                    />
                                </td>
                                <!-- Price -->
                                <td class="px-2 py-1">
                                    <input
                                        v-model.number="product.price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                                        @input="markDirty(index)"
                                    />
                                </td>
                                <!-- Wholesale Price -->
                                <td class="px-2 py-1">
                                    <input
                                        v-model.number="product.wholesale_price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                                        @input="markDirty(index)"
                                    />
                                </td>
                                <!-- Cost -->
                                <td class="px-2 py-1">
                                    <input
                                        v-model.number="product.cost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                                        @input="markDirty(index)"
                                    />
                                </td>
                                <!-- Vendor -->
                                <td class="px-2 py-1">
                                    <select
                                        v-model="product.vendor_id"
                                        class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                                        @change="markDirty(index)"
                                    >
                                        <option :value="null">--</option>
                                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">
                                            {{ vendor.name }}
                                        </option>
                                    </select>
                                </td>
                                <!-- Category -->
                                <td class="px-2 py-1">
                                    <select
                                        v-model="product.category_id"
                                        class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                                        @change="markDirty(index)"
                                    >
                                        <option :value="null">--</option>
                                        <option
                                            v-for="category in categoryTree"
                                            :key="category.id"
                                            :value="category.id"
                                            :disabled="!category.isLeaf"
                                            :class="{ 'text-gray-400 dark:text-gray-500': !category.isLeaf }"
                                        >
                                            {{ '\u00A0\u00A0'.repeat(category.depth) }}{{ category.isLeaf ? '' : '📁 ' }}{{ category.name }}
                                        </option>
                                    </select>
                                </td>
                                <!-- Status -->
                                <td class="px-2 py-1">
                                    <select
                                        v-model="product.status"
                                        class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                                        @change="markDirty(index)"
                                    >
                                        <option value="draft">Draft</option>
                                        <option value="active">Active</option>
                                        <option value="archive">Archive</option>
                                        <option value="sold">Sold</option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Change indicator -->
                <div v-if="hasChanges" class="mt-4 text-sm text-amber-600 dark:text-amber-400">
                    {{ dirtyCount }} product(s) modified
                </div>
            </div>

            <SheetFooter class="border-t pt-4 dark:border-gray-700">
                <Button variant="outline" @click="handleClose" :disabled="processing">
                    Cancel
                </Button>
                <Button
                    :disabled="!hasChanges || processing"
                    @click="handleSubmit"
                >
                    {{ processing ? 'Updating...' : `Update ${dirtyCount} Product(s)` }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
