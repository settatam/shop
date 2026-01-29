<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/vue/20/solid';

interface Product {
    id: number;
    title: string;
    category_id: number | null;
    category_name: string | null;
    brand_id: number | null;
    brand_name: string | null;
    is_published: boolean;
    template_name: string | null;
}

interface Category {
    id: number;
    name: string;
}

interface Brand {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    products: Product[];
    categories: Category[];
    brands: Brand[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    success: [];
}>();

const processing = ref(false);

// Track which fields to update
const updateTitle = ref(false);
const updateCategory = ref(false);
const updateBrand = ref(false);
const updateStatus = ref(false);

// Form values
const title = ref('');
const categoryId = ref<number | null>(null);
const brandId = ref<number | null>(null);
const isPublished = ref<boolean | null>(null);

// Group products by template
const productsByTemplate = computed(() => {
    const groups = new Map<string, Product[]>();
    props.products.forEach(product => {
        const key = product.template_name || 'No Template';
        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key)!.push(product);
    });
    return groups;
});

// Track expanded groups
const expandedGroups = ref<Set<string>>(new Set());

function toggleGroup(groupName: string) {
    if (expandedGroups.value.has(groupName)) {
        expandedGroups.value.delete(groupName);
    } else {
        expandedGroups.value.add(groupName);
    }
}

// Watch for open changes to reset form
watch(() => props.open, (isOpen) => {
    if (isOpen) {
        // Reset form state
        updateTitle.value = false;
        updateCategory.value = false;
        updateBrand.value = false;
        updateStatus.value = false;
        title.value = '';
        categoryId.value = null;
        brandId.value = null;
        isPublished.value = null;
        // Expand all groups by default
        expandedGroups.value = new Set(productsByTemplate.value.keys());
    }
});

// Check if any field is selected for update
const hasFieldsToUpdate = computed(() => {
    return updateTitle.value || updateCategory.value || updateBrand.value || updateStatus.value;
});

function handleSubmit() {
    if (!hasFieldsToUpdate.value) return;

    const data: Record<string, unknown> = {
        ids: props.products.map(p => p.id),
    };

    if (updateTitle.value && title.value) {
        data.title = title.value;
    }
    if (updateCategory.value) {
        data.category_id = categoryId.value;
    }
    if (updateBrand.value) {
        data.brand_id = brandId.value;
    }
    if (updateStatus.value && isPublished.value !== null) {
        data.is_published = isPublished.value;
    }

    processing.value = true;

    router.post('/products/bulk-update', data, {
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
        <SheetContent side="right" class="w-full sm:max-w-lg overflow-y-auto">
            <SheetHeader>
                <SheetTitle>Edit {{ products.length }} Products</SheetTitle>
                <SheetDescription>
                    Update common fields for the selected products. Check a field to enable editing.
                </SheetDescription>
            </SheetHeader>

            <div class="py-6 space-y-6">
                <!-- Products grouped by template -->
                <div class="space-y-2">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Selected Products</h4>
                    <div class="border rounded-lg divide-y dark:border-gray-700 dark:divide-gray-700">
                        <div
                            v-for="[templateName, templateProducts] in productsByTemplate"
                            :key="templateName"
                            class="overflow-hidden"
                        >
                            <!-- Group header -->
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-2 text-sm font-medium text-gray-900 bg-gray-50 hover:bg-gray-100 dark:bg-gray-800 dark:text-white dark:hover:bg-gray-700"
                                @click="toggleGroup(templateName)"
                            >
                                <span>{{ templateName }} ({{ templateProducts.length }})</span>
                                <ChevronDownIcon v-if="expandedGroups.has(templateName)" class="h-5 w-5" />
                                <ChevronRightIcon v-else class="h-5 w-5" />
                            </button>
                            <!-- Group products -->
                            <div v-if="expandedGroups.has(templateName)" class="px-4 py-2 space-y-1 bg-white dark:bg-gray-900">
                                <div
                                    v-for="product in templateProducts"
                                    :key="product.id"
                                    class="text-sm text-gray-600 dark:text-gray-400 truncate"
                                >
                                    {{ product.title }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit fields -->
                <div class="space-y-4">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Fields to Update</h4>

                    <!-- Title -->
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="update-title"
                                :checked="updateTitle"
                                @update:checked="updateTitle = $event"
                            />
                            <Label for="update-title" class="text-sm font-medium">Title</Label>
                        </div>
                        <Input
                            v-if="updateTitle"
                            v-model="title"
                            placeholder="Enter new title for all selected products"
                            class="mt-1"
                        />
                        <p v-if="updateTitle" class="text-xs text-gray-500">
                            All selected products will have the same title.
                        </p>
                    </div>

                    <!-- Category -->
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="update-category"
                                :checked="updateCategory"
                                @update:checked="updateCategory = $event"
                            />
                            <Label for="update-category" class="text-sm font-medium">Category</Label>
                        </div>
                        <select
                            v-if="updateCategory"
                            v-model="categoryId"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        >
                            <option :value="null">No category</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">
                                {{ category.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Brand -->
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="update-brand"
                                :checked="updateBrand"
                                @update:checked="updateBrand = $event"
                            />
                            <Label for="update-brand" class="text-sm font-medium">Brand</Label>
                        </div>
                        <select
                            v-if="updateBrand"
                            v-model="brandId"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        >
                            <option :value="null">No brand</option>
                            <option v-for="brand in brands" :key="brand.id" :value="brand.id">
                                {{ brand.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="update-status"
                                :checked="updateStatus"
                                @update:checked="updateStatus = $event"
                            />
                            <Label for="update-status" class="text-sm font-medium">Status</Label>
                        </div>
                        <div v-if="updateStatus" class="mt-2 flex gap-4">
                            <label class="inline-flex items-center">
                                <input
                                    type="radio"
                                    :checked="isPublished === true"
                                    class="rounded-full border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                    @change="isPublished = true"
                                />
                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Published</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input
                                    type="radio"
                                    :checked="isPublished === false"
                                    class="rounded-full border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                    @change="isPublished = false"
                                />
                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Draft</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <SheetFooter class="border-t pt-4 dark:border-gray-700">
                <Button variant="outline" @click="handleClose" :disabled="processing">
                    Cancel
                </Button>
                <Button
                    :disabled="!hasFieldsToUpdate || processing"
                    @click="handleSubmit"
                >
                    {{ processing ? 'Updating...' : 'Update Products' }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
