<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import {
    MagnifyingGlassIcon,
    FolderIcon,
    FolderOpenIcon,
    ChevronRightIcon,
    PlusIcon,
    TrashIcon,
    PencilIcon,
    CheckIcon,
    XMarkIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    SparklesIcon,
} from '@heroicons/vue/24/outline';
import {
    CheckCircleIcon,
    ExclamationCircleIcon,
} from '@heroicons/vue/20/solid';

interface Platform {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
}

interface EbayCategory {
    id: number;
    name: string;
    ebay_category_id: number;
    level: number;
    parent_id: number | null;
    children_count: number;
    has_children: boolean;
}

interface FieldOption {
    label: string;
    value: string;
}

interface PlatformMapping {
    platform: string;
    field_name: string;
    is_required: boolean;
    is_recommended: boolean;
    accepted_values?: string[];
}

interface Field {
    id?: string;
    name: string;
    canonical_name?: string;
    label: string;
    type: string;
    placeholder?: string;
    help_text?: string;
    is_required?: boolean;
    is_searchable?: boolean;
    is_filterable?: boolean;
    show_in_listing?: boolean;
    group_name?: string;
    group_position?: number;
    width_class?: string;
    options?: FieldOption[];
    platform_mappings?: PlatformMapping[];
    source?: string;
}

interface GeneratedData {
    category: {
        name: string;
        slug?: string;
        description?: string;
        ebay_category_id?: number;
        ebay_category_path?: string;
    };
    template: {
        name: string;
        description?: string;
    };
    fields: Field[];
    source?: string;
    ebay_category?: {
        id: number;
        name: string;
        path: string;
    };
}

interface Props {
    platforms: Platform[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Templates', href: '/templates' },
    { title: 'Template Generator', href: '/templates-generator' },
];

// Wizard steps
type Step = 'search' | 'select-categories' | 'customize' | 'creating';
const currentStep = ref<Step>('search');

// Search state
const searchQuery = ref('');
const isSearching = ref(false);
const searchResults = ref<EbayCategory[]>([]);
const expandedCategories = ref<Set<number>>(new Set());
const childrenCache = ref<Map<number, EbayCategory[]>>(new Map());
const loadingChildren = ref<Set<number>>(new Set());

// Selection state
const selectedCategories = ref<Map<number, EbayCategory>>(new Map());
const useCustomCategory = ref(false);
const customCategoryName = ref('');

// Template state
const isGenerating = ref(false);
const generatedData = ref<GeneratedData | null>(null);
const editableFields = ref<Field[]>([]);
const templateName = ref('');
const templateDescription = ref('');
const categoryName = ref('');
const categoryDescription = ref('');

// Field editing
const editingFieldIndex = ref<number | null>(null);
const showAddField = ref(false);
const newField = ref<Field>({
    name: '',
    label: '',
    type: 'text',
    is_required: false,
    is_filterable: false,
    is_searchable: false,
    show_in_listing: false,
    options: [],
});

const error = ref<string | null>(null);

// Computed
const hasSelection = computed(() => selectedCategories.value.size > 0 || (useCustomCategory.value && customCategoryName.value.trim()));

const selectedCategoriesList = computed(() => Array.from(selectedCategories.value.values()));

const canProceedToCustomize = computed(() => {
    if (useCustomCategory.value) {
        return customCategoryName.value.trim().length >= 2;
    }
    return selectedCategories.value.size > 0;
});

// Field types for dropdown
const fieldTypes = [
    { value: 'text', label: 'Text' },
    { value: 'textarea', label: 'Long Text' },
    { value: 'number', label: 'Number' },
    { value: 'select', label: 'Dropdown' },
    { value: 'checkbox', label: 'Checkbox' },
    { value: 'radio', label: 'Radio Buttons' },
    { value: 'date', label: 'Date' },
];

// Watch search query for debounced search
let searchTimeout: ReturnType<typeof setTimeout>;
watch(searchQuery, (value) => {
    clearTimeout(searchTimeout);
    if (value.trim().length >= 2) {
        searchTimeout = setTimeout(() => searchCategories(), 300);
    } else if (value.trim().length === 0) {
        loadRootCategories();
    }
});

// Methods
async function searchCategories() {
    if (searchQuery.value.trim().length < 2) return;

    isSearching.value = true;
    error.value = null;

    try {
        const response = await fetch(`/api/taxonomy/ebay/categories?query=${encodeURIComponent(searchQuery.value)}`, {
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok) throw new Error('Failed to search categories');

        searchResults.value = await response.json();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Search failed';
    } finally {
        isSearching.value = false;
    }
}

async function loadRootCategories() {
    isSearching.value = true;
    error.value = null;

    try {
        const response = await fetch('/api/taxonomy/ebay/categories', {
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok) throw new Error('Failed to load categories');

        searchResults.value = await response.json();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load categories';
    } finally {
        isSearching.value = false;
    }
}

async function loadChildren(parentId: number) {
    if (childrenCache.value.has(parentId)) {
        return childrenCache.value.get(parentId)!;
    }

    loadingChildren.value.add(parentId);

    try {
        const response = await fetch(`/api/taxonomy/ebay/categories?parent_id=${parentId}`, {
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok) throw new Error('Failed to load subcategories');

        const children = await response.json();
        childrenCache.value.set(parentId, children);
        return children;
    } catch (e) {
        console.error('Failed to load children:', e);
        return [];
    } finally {
        loadingChildren.value.delete(parentId);
    }
}

async function toggleExpand(category: EbayCategory) {
    if (expandedCategories.value.has(category.id)) {
        expandedCategories.value.delete(category.id);
    } else {
        expandedCategories.value.add(category.id);
        if (!childrenCache.value.has(category.id)) {
            await loadChildren(category.id);
        }
    }
    expandedCategories.value = new Set(expandedCategories.value);
}

function toggleSelect(category: EbayCategory) {
    if (selectedCategories.value.has(category.id)) {
        selectedCategories.value.delete(category.id);
    } else {
        selectedCategories.value.set(category.id, category);
    }
    selectedCategories.value = new Map(selectedCategories.value);

    // Uncheck custom category when selecting eBay categories
    if (selectedCategories.value.size > 0) {
        useCustomCategory.value = false;
    }
}

function toggleCustomCategory() {
    useCustomCategory.value = !useCustomCategory.value;
    if (useCustomCategory.value) {
        // Clear eBay category selections when using custom
        selectedCategories.value.clear();
        selectedCategories.value = new Map(selectedCategories.value);
    }
}

async function proceedToCustomize() {
    if (!canProceedToCustomize.value) return;

    isGenerating.value = true;
    error.value = null;

    try {
        if (useCustomCategory.value) {
            // Create a basic template for custom category
            generatedData.value = {
                category: {
                    name: customCategoryName.value,
                    slug: customCategoryName.value.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
                    description: '',
                },
                template: {
                    name: `${customCategoryName.value} Template`,
                    description: `Template for ${customCategoryName.value}`,
                },
                fields: [
                    { id: crypto.randomUUID(), name: 'brand', label: 'Brand', type: 'text', is_filterable: true, is_searchable: true },
                    { id: crypto.randomUUID(), name: 'condition', label: 'Condition', type: 'select', is_required: true, is_filterable: true, options: [
                        { label: 'New', value: 'new' },
                        { label: 'Used', value: 'used' },
                        { label: 'Refurbished', value: 'refurbished' },
                    ]},
                    { id: crypto.randomUUID(), name: 'color', label: 'Color', type: 'text', is_filterable: true },
                    { id: crypto.randomUUID(), name: 'size', label: 'Size', type: 'text', is_filterable: true },
                ],
                source: 'custom',
            };

            categoryName.value = customCategoryName.value;
            templateName.value = `${customCategoryName.value} Template`;
        } else {
            // Generate from selected eBay categories
            const categoryIds = Array.from(selectedCategories.value.keys());

            // Fetch fields from all selected categories and merge
            const allFields: Field[] = [];
            const seenFieldNames = new Set<string>();

            for (const catId of categoryIds) {
                const response = await fetch(`/templates-generator/preview-ebay`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ebay_category_id: catId }),
                });

                if (!response.ok) continue;

                const data = await response.json();
                if (data.success && data.data?.fields) {
                    for (const field of data.data.fields) {
                        if (!seenFieldNames.has(field.name)) {
                            seenFieldNames.add(field.name);
                            allFields.push({ ...field, id: crypto.randomUUID() });
                        }
                    }
                }
            }

            // Sort fields: required first, then recommended, then alphabetical
            allFields.sort((a, b) => {
                if (a.is_required && !b.is_required) return -1;
                if (!a.is_required && b.is_required) return 1;
                if (a.is_filterable && !b.is_filterable) return -1;
                if (!a.is_filterable && b.is_filterable) return 1;
                return a.label.localeCompare(b.label);
            });

            // Build category name from selections
            const catNames = selectedCategoriesList.value.map(c => c.name);
            const combinedName = catNames.length === 1 ? catNames[0] : catNames.slice(0, 3).join(', ') + (catNames.length > 3 ? '...' : '');

            generatedData.value = {
                category: {
                    name: combinedName,
                    slug: combinedName.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
                    description: `Category for ${catNames.join(', ')}`,
                },
                template: {
                    name: `${combinedName} Template`,
                    description: `Auto-generated template based on eBay item specifics for ${catNames.join(', ')}`,
                },
                fields: allFields,
                source: 'ebay_taxonomy',
            };

            categoryName.value = combinedName;
            templateName.value = `${combinedName} Template`;
        }

        editableFields.value = [...(generatedData.value?.fields || [])];
        categoryDescription.value = generatedData.value?.category.description || '';
        templateDescription.value = generatedData.value?.template.description || '';

        currentStep.value = 'customize';
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to generate template';
    } finally {
        isGenerating.value = false;
    }
}

function removeField(index: number) {
    editableFields.value.splice(index, 1);
}

function startEditField(index: number) {
    editingFieldIndex.value = index;
}

function saveFieldEdit(index: number, field: Field) {
    editableFields.value[index] = { ...field };
    editingFieldIndex.value = null;
}

function cancelFieldEdit() {
    editingFieldIndex.value = null;
}

function openAddField() {
    newField.value = {
        id: crypto.randomUUID(),
        name: '',
        label: '',
        type: 'text',
        is_required: false,
        is_filterable: false,
        is_searchable: false,
        show_in_listing: false,
        options: [],
    };
    showAddField.value = true;
}

function addField() {
    if (!newField.value.label.trim()) return;

    newField.value.name = newField.value.label.toLowerCase().replace(/[^a-z0-9]+/g, '_');
    editableFields.value.push({ ...newField.value });
    showAddField.value = false;
}

function addOption(field: Field) {
    if (!field.options) field.options = [];
    field.options.push({ label: '', value: '' });
}

function removeOption(field: Field, index: number) {
    field.options?.splice(index, 1);
}

function goBack() {
    if (currentStep.value === 'customize') {
        currentStep.value = 'select-categories';
    } else if (currentStep.value === 'select-categories') {
        currentStep.value = 'search';
    }
}

function createTemplate() {
    if (!categoryName.value.trim() || !templateName.value.trim()) return;

    currentStep.value = 'creating';

    const templateData = {
        category: {
            name: categoryName.value,
            slug: categoryName.value.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
            description: categoryDescription.value,
        },
        template: {
            name: templateName.value,
            description: templateDescription.value,
        },
        fields: editableFields.value.map(f => ({
            ...f,
            canonical_name: f.canonical_name || f.name,
        })),
    };

    router.post('/templates-generator', {
        template_data: templateData,
        original_prompt: useCustomCategory.value ? customCategoryName.value : selectedCategoriesList.value.map(c => c.name).join(', '),
    });
}

function getFieldTypeLabel(type: string): string {
    return fieldTypes.find(t => t.value === type)?.label || type;
}

// Load root categories on mount
loadRootCategories();
</script>

<template>
    <Head title="Template Generator" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-[calc(100vh-200px)] p-4 lg:p-8">
            <!-- Step Indicator -->
            <div class="max-w-4xl mx-auto mb-8">
                <div class="flex items-center justify-center gap-4">
                    <div class="flex items-center gap-2">
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium"
                            :class="currentStep === 'search' || currentStep === 'select-categories'
                                ? 'bg-indigo-600 text-white'
                                : 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30'"
                        >1</div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Select Categories</span>
                    </div>
                    <div class="w-12 h-px bg-gray-300 dark:bg-gray-600"></div>
                    <div class="flex items-center gap-2">
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium"
                            :class="currentStep === 'customize'
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                        >2</div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Customize Fields</span>
                    </div>
                    <div class="w-12 h-px bg-gray-300 dark:bg-gray-600"></div>
                    <div class="flex items-center gap-2">
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium"
                            :class="currentStep === 'creating'
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                        >3</div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Create</span>
                    </div>
                </div>
            </div>

            <!-- Step 1: Search & Select Categories -->
            <div v-if="currentStep === 'search' || currentStep === 'select-categories'" class="max-w-4xl mx-auto">
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-indigo-100 dark:bg-indigo-900/30 mb-4">
                        <SparklesIcon class="w-8 h-8 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">
                        What do you want to sell?
                    </h1>
                    <p class="mt-3 text-lg text-gray-600 dark:text-gray-400">
                        Search for product categories or create your own. Select all that apply.
                    </p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left: Search & Browse -->
                    <div class="lg:col-span-2">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg ring-1 ring-black/5 dark:ring-white/10 overflow-hidden">
                            <!-- Search Input -->
                            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                                <div class="relative">
                                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search categories (e.g., shoes, jewelry, electronics...)"
                                        class="w-full pl-10 pr-4 py-2.5 rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                    />
                                </div>
                            </div>

                            <!-- Category List -->
                            <div class="max-h-[500px] overflow-y-auto">
                                <div v-if="isSearching" class="p-8 text-center">
                                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-indigo-600 border-t-transparent"></div>
                                    <p class="mt-2 text-gray-600 dark:text-gray-400">Searching...</p>
                                </div>

                                <div v-else-if="error" class="p-4">
                                    <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 flex items-start gap-2">
                                        <ExclamationCircleIcon class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
                                        <p class="text-sm text-red-700 dark:text-red-400">{{ error }}</p>
                                    </div>
                                </div>

                                <div v-else-if="searchResults.length === 0" class="p-8 text-center text-gray-500 dark:text-gray-400">
                                    <FolderIcon class="w-12 h-12 mx-auto mb-2 opacity-50" />
                                    <p>No categories found. Try a different search or create a custom category.</p>
                                </div>

                                <div v-else class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <template v-for="category in searchResults" :key="category.id">
                                        <!-- Category Item -->
                                        <div class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                            <!-- Checkbox -->
                                            <input
                                                type="checkbox"
                                                :checked="selectedCategories.has(category.id)"
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                                @change="toggleSelect(category)"
                                            />

                                            <!-- Expand Button -->
                                            <button
                                                v-if="category.has_children"
                                                type="button"
                                                class="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-600"
                                                @click="toggleExpand(category)"
                                            >
                                                <ChevronRightIcon
                                                    class="w-4 h-4 text-gray-400 transition-transform"
                                                    :class="{ 'rotate-90': expandedCategories.has(category.id) }"
                                                />
                                            </button>
                                            <span v-else class="w-6"></span>

                                            <!-- Folder Icon -->
                                            <component
                                                :is="expandedCategories.has(category.id) ? FolderOpenIcon : FolderIcon"
                                                class="w-5 h-5 text-gray-400"
                                            />

                                            <!-- Name -->
                                            <span
                                                class="flex-1 text-sm cursor-pointer"
                                                :class="selectedCategories.has(category.id)
                                                    ? 'text-indigo-600 dark:text-indigo-400 font-medium'
                                                    : 'text-gray-700 dark:text-gray-300'"
                                                @click="toggleSelect(category)"
                                            >
                                                {{ category.name }}
                                            </span>

                                            <!-- Children Count -->
                                            <span v-if="category.children_count > 0" class="text-xs text-gray-400">
                                                {{ category.children_count }} subcategories
                                            </span>
                                        </div>

                                        <!-- Children (if expanded) -->
                                        <div v-if="expandedCategories.has(category.id)" class="bg-gray-50 dark:bg-gray-900/50">
                                            <div v-if="loadingChildren.has(category.id)" class="px-8 py-4 text-center">
                                                <div class="inline-block animate-spin rounded-full h-5 w-5 border-2 border-indigo-600 border-t-transparent"></div>
                                            </div>
                                            <template v-else-if="childrenCache.get(category.id)?.length">
                                                <div
                                                    v-for="child in childrenCache.get(category.id)"
                                                    :key="child.id"
                                                    class="flex items-center gap-3 px-4 py-2 pl-12 hover:bg-gray-100 dark:hover:bg-gray-700/50"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        :checked="selectedCategories.has(child.id)"
                                                        class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                                        @change="toggleSelect(child)"
                                                    />
                                                    <FolderIcon class="w-4 h-4 text-gray-400" />
                                                    <span
                                                        class="flex-1 text-sm cursor-pointer"
                                                        :class="selectedCategories.has(child.id)
                                                            ? 'text-indigo-600 dark:text-indigo-400 font-medium'
                                                            : 'text-gray-600 dark:text-gray-400'"
                                                        @click="toggleSelect(child)"
                                                    >
                                                        {{ child.name }}
                                                    </span>
                                                    <span v-if="child.children_count > 0" class="text-xs text-gray-400">
                                                        {{ child.children_count }}
                                                    </span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Custom Category Option -->
                            <div class="p-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                                <div class="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        :checked="useCustomCategory"
                                        class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                        @change="toggleCustomCategory"
                                    />
                                    <div class="flex-1">
                                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer" @click="toggleCustomCategory">
                                            Create a custom category
                                        </label>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            Can't find what you need? Create your own category from scratch.
                                        </p>
                                        <input
                                            v-if="useCustomCategory"
                                            v-model="customCategoryName"
                                            type="text"
                                            placeholder="Enter category name (e.g., Vintage Comics)"
                                            class="mt-2 w-full px-3 py-2 rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Selection Summary -->
                    <div class="lg:col-span-1">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg ring-1 ring-black/5 dark:ring-white/10 p-4 sticky top-4">
                            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">
                                Selected Categories
                            </h3>

                            <div v-if="!hasSelection" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                <FolderIcon class="w-10 h-10 mx-auto mb-2 opacity-50" />
                                <p class="text-sm">No categories selected yet</p>
                            </div>

                            <div v-else>
                                <div v-if="useCustomCategory" class="mb-4">
                                    <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-indigo-50 dark:bg-indigo-900/30">
                                        <PlusIcon class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                                        <span class="text-sm font-medium text-indigo-700 dark:text-indigo-300">
                                            {{ customCategoryName || 'Custom Category' }}
                                        </span>
                                    </div>
                                </div>

                                <div v-else class="space-y-2 max-h-64 overflow-y-auto">
                                    <div
                                        v-for="cat in selectedCategoriesList"
                                        :key="cat.id"
                                        class="flex items-center gap-2 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700"
                                    >
                                        <CheckCircleIcon class="w-4 h-4 text-green-500 shrink-0" />
                                        <span class="text-sm text-gray-700 dark:text-gray-300 flex-1 truncate">
                                            {{ cat.name }}
                                        </span>
                                        <button
                                            type="button"
                                            class="text-gray-400 hover:text-red-500"
                                            @click="toggleSelect(cat)"
                                        >
                                            <XMarkIcon class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    :disabled="!canProceedToCustomize || isGenerating"
                                    class="mt-4 w-full inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    @click="proceedToCustomize"
                                >
                                    <template v-if="isGenerating">
                                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                        </svg>
                                        Generating...
                                    </template>
                                    <template v-else>
                                        Continue
                                        <ChevronRightIcon class="w-4 h-4" />
                                    </template>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Customize Fields -->
            <div v-else-if="currentStep === 'customize'" class="max-w-5xl mx-auto">
                <!-- Header -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <button
                            type="button"
                            class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 mb-2"
                            @click="goBack"
                        >
                            &larr; Back to categories
                        </button>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                            Customize Your Template
                        </h1>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">
                            Review and customize the fields for your product template.
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="!categoryName.trim() || !templateName.trim()"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                        @click="createTemplate"
                    >
                        <CheckIcon class="w-5 h-5" />
                        Create Template
                    </button>
                </div>

                <!-- Template & Category Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow ring-1 ring-black/5 dark:ring-white/10 p-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Category Name <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="categoryName"
                            type="text"
                            class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        />
                        <textarea
                            v-model="categoryDescription"
                            placeholder="Category description (optional)"
                            rows="2"
                            class="mt-2 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        />
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow ring-1 ring-black/5 dark:ring-white/10 p-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Template Name <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="templateName"
                            type="text"
                            class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        />
                        <textarea
                            v-model="templateDescription"
                            placeholder="Template description (optional)"
                            rows="2"
                            class="mt-2 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        />
                    </div>
                </div>

                <!-- Fields List -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow ring-1 ring-black/5 dark:ring-white/10 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Template Fields ({{ editableFields.length }})
                            </h2>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                Add, edit, or remove fields as needed.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400 dark:hover:bg-indigo-900/50"
                            @click="openAddField"
                        >
                            <PlusIcon class="w-4 h-4" />
                            Add Field
                        </button>
                    </div>

                    <!-- Add Field Form -->
                    <div v-if="showAddField" class="px-6 py-4 bg-indigo-50 dark:bg-indigo-900/20 border-b border-indigo-100 dark:border-indigo-800">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Label</label>
                                <input
                                    v-model="newField.label"
                                    type="text"
                                    placeholder="e.g., Brand"
                                    class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
                                <select
                                    v-model="newField.type"
                                    class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                >
                                    <option v-for="t in fieldTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                                </select>
                            </div>
                            <div class="flex items-end gap-4">
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="newField.is_required" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                                    Required
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="newField.is_filterable" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                                    Filterable
                                </label>
                            </div>
                            <div class="flex items-end gap-2">
                                <button
                                    type="button"
                                    class="flex-1 px-3 py-2 text-sm font-medium rounded bg-indigo-600 text-white hover:bg-indigo-500"
                                    @click="addField"
                                >
                                    Add
                                </button>
                                <button
                                    type="button"
                                    class="px-3 py-2 text-sm font-medium rounded bg-gray-200 text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300"
                                    @click="showAddField = false"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Fields -->
                    <div v-if="editableFields.length === 0" class="p-8 text-center text-gray-500 dark:text-gray-400">
                        <p>No fields yet. Click "Add Field" to create your first field.</p>
                    </div>
                    <div v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                        <div
                            v-for="(field, index) in editableFields"
                            :key="field.id || index"
                            class="px-6 py-4 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                        >
                            <!-- View Mode -->
                            <div v-if="editingFieldIndex !== index" class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3">
                                        <h3 class="font-medium text-gray-900 dark:text-white">
                                            {{ field.label }}
                                        </h3>
                                        <span v-if="field.is_required" class="text-xs text-red-600 dark:text-red-400">
                                            Required
                                        </span>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                            {{ getFieldTypeLabel(field.type) }}
                                        </span>
                                        <span v-if="field.source === 'ebay_taxonomy'" class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                            eBay
                                        </span>
                                    </div>
                                    <div v-if="field.options?.length" class="mt-2 flex flex-wrap gap-1">
                                        <span
                                            v-for="(opt, optIndex) in field.options.slice(0, 5)"
                                            :key="optIndex"
                                            class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400"
                                        >
                                            {{ opt.label }}
                                        </span>
                                        <span v-if="field.options.length > 5" class="text-xs text-gray-400">
                                            +{{ field.options.length - 5 }} more
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span v-if="field.is_filterable" class="text-xs text-gray-400">Filterable</span>
                                    <span v-if="field.is_searchable" class="text-xs text-gray-400">Searchable</span>
                                    <button
                                        type="button"
                                        class="p-1.5 text-gray-400 hover:text-indigo-600 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                                        @click="startEditField(index)"
                                    >
                                        <PencilIcon class="w-4 h-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="p-1.5 text-gray-400 hover:text-red-600 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                                        @click="removeField(index)"
                                    >
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>

                            <!-- Edit Mode -->
                            <div v-else class="space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Label</label>
                                        <input
                                            v-model="field.label"
                                            type="text"
                                            class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
                                        <select
                                            v-model="field.type"
                                            class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                        >
                                            <option v-for="t in fieldTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Placeholder</label>
                                        <input
                                            v-model="field.placeholder"
                                            type="text"
                                            class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                        />
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-4">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input v-model="field.is_required" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                                        Required
                                    </label>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input v-model="field.is_filterable" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                                        Filterable
                                    </label>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input v-model="field.is_searchable" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                                        Searchable
                                    </label>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input v-model="field.show_in_listing" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                                        Show in Listing
                                    </label>
                                </div>

                                <!-- Options for Select/Radio -->
                                <div v-if="field.type === 'select' || field.type === 'radio'">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Options</label>
                                        <button
                                            type="button"
                                            class="text-xs text-indigo-600 hover:text-indigo-500"
                                            @click="addOption(field)"
                                        >
                                            + Add Option
                                        </button>
                                    </div>
                                    <div class="space-y-2 max-h-40 overflow-y-auto">
                                        <div
                                            v-for="(opt, optIndex) in field.options"
                                            :key="optIndex"
                                            class="flex items-center gap-2"
                                        >
                                            <input
                                                v-model="opt.label"
                                                type="text"
                                                placeholder="Label"
                                                class="flex-1 text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                @blur="opt.value = opt.label.toLowerCase().replace(/[^a-z0-9]+/g, '_')"
                                            />
                                            <button
                                                type="button"
                                                class="text-gray-400 hover:text-red-500"
                                                @click="removeOption(field, optIndex)"
                                            >
                                                <XMarkIcon class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-end gap-2">
                                    <button
                                        type="button"
                                        class="px-3 py-1.5 text-sm font-medium rounded bg-gray-200 text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300"
                                        @click="cancelFieldEdit"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        class="px-3 py-1.5 text-sm font-medium rounded bg-indigo-600 text-white hover:bg-indigo-500"
                                        @click="saveFieldEdit(index, field)"
                                    >
                                        Save
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Actions -->
                <div class="mt-6 flex items-center justify-between">
                    <button
                        type="button"
                        class="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                        @click="goBack"
                    >
                        &larr; Back
                    </button>
                    <button
                        type="button"
                        :disabled="!categoryName.trim() || !templateName.trim()"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                        @click="createTemplate"
                    >
                        <CheckIcon class="w-5 h-5" />
                        Create Template & Category
                    </button>
                </div>
            </div>

            <!-- Step 3: Creating -->
            <div v-else-if="currentStep === 'creating'" class="max-w-xl mx-auto text-center py-20">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-indigo-100 dark:bg-indigo-900/30 mb-4">
                    <svg class="animate-spin h-8 w-8 text-indigo-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                </div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Creating your template...</h2>
                <p class="mt-2 text-gray-600 dark:text-gray-400">
                    Setting up your category and template fields.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
