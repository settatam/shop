<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { XMarkIcon, PhotoIcon, ChevronRightIcon, FolderIcon, FolderOpenIcon, CheckIcon } from '@heroicons/vue/24/outline';

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level: number;
    template_id: number | null;
}

interface FieldOption {
    label: string;
    value: string;
}

interface TemplateField {
    id: number;
    name: string;
    label: string;
    type: 'text' | 'textarea' | 'number' | 'select' | 'checkbox' | 'radio' | 'date' | 'brand';
    placeholder: string | null;
    help_text: string | null;
    default_value: string | null;
    is_required: boolean;
    group_name: string | null;
    group_position: number;
    width_class: 'full' | 'half' | 'third' | 'quarter';
    options: FieldOption[];
}

interface TransactionItem {
    id: string;
    title: string;
    description?: string;
    category_id?: number;
    price?: number;
    buy_price: number;
    precious_metal?: string;
    dwt?: number;
    attributes: Record<number, string>;
    images: File[];
}

interface ExistingImage {
    id: number;
    url: string;
    thumbnail_url: string | null;
}

interface Props {
    open: boolean;
    categories: Category[];
    editingItem?: TransactionItem | null;
    existingImages?: ExistingImage[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    close: [];
    save: [item: TransactionItem, deletedImageIds: number[]];
}>();

// Track existing images to delete
const imagesToDelete = ref<number[]>([]);

// Filter out deleted images from display
const visibleExistingImages = computed(() => {
    if (!props.existingImages) return [];
    return props.existingImages.filter(img => !imagesToDelete.value.includes(img.id));
});

function markImageForDeletion(imageId: number) {
    imagesToDelete.value.push(imageId);
}

// --- Category tree logic ---
const categoryTree = computed(() => {
    const map = new Map<number, Category & { children: (Category & { children: any[] })[] }>();
    const roots: (Category & { children: any[] })[] = [];

    props.categories.forEach(cat => {
        map.set(cat.id, { ...cat, children: [] });
    });

    props.categories.forEach(cat => {
        const node = map.get(cat.id)!;
        if (cat.parent_id === null || cat.parent_id === 0) {
            roots.push(node);
        } else {
            const parent = map.get(cat.parent_id);
            if (parent) {
                parent.children.push(node);
            } else {
                roots.push(node);
            }
        }
    });

    return roots;
});

const selectionPath = ref<number[]>([]);

const currentCategories = computed(() => {
    if (selectionPath.value.length === 0) {
        return categoryTree.value;
    }
    let current = categoryTree.value as any[];
    for (const id of selectionPath.value) {
        const found = current.find((c: any) => c.id === id);
        if (found && found.children.length > 0) {
            current = found.children;
        } else {
            return [];
        }
    }
    return current;
});

const breadcrumbPath = computed(() => {
    const path: Category[] = [];
    let current = categoryTree.value as any[];
    for (const id of selectionPath.value) {
        const found = current.find((c: any) => c.id === id);
        if (found) {
            path.push(found);
            current = found.children || [];
        }
    }
    return path;
});

function isCategoryLeaf(category: any): boolean {
    return !category.children || category.children.length === 0;
}

function selectCategory(category: any) {
    if (isCategoryLeaf(category)) {
        form.value.category_id = category.id;
    } else {
        selectionPath.value = [...selectionPath.value, category.id];
    }
}

function navigateToLevel(index: number) {
    selectionPath.value = selectionPath.value.slice(0, index);
}

function clearCategory() {
    form.value.category_id = undefined;
    selectionPath.value = [];
    templateFields.value = [];
    form.value.attributes = {};
}

// --- Template fields ---
const templateFields = ref<TemplateField[]>([]);
const loadingTemplate = ref(false);

const groupedTemplateFields = computed(() => {
    const groups: Record<string, TemplateField[]> = {};
    const standalone: TemplateField[] = [];

    for (const field of templateFields.value) {
        if (field.group_name) {
            if (!groups[field.group_name]) {
                groups[field.group_name] = [];
            }
            groups[field.group_name].push(field);
            groups[field.group_name].sort((a, b) => a.group_position - b.group_position);
        } else {
            standalone.push(field);
        }
    }

    return { groups, standalone };
});

async function loadTemplateFields(categoryId: number) {
    loadingTemplate.value = true;
    try {
        const response = await fetch(`/api/v1/categories/${categoryId}/template`, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            templateFields.value = [];
            return;
        }

        const data = await response.json();
        templateFields.value = (data.fields || []).map((f: any) => ({
            ...f,
            options: f.options || [],
        }));

        // Initialize attributes with defaults
        for (const field of templateFields.value) {
            if (!(field.id in form.value.attributes)) {
                form.value.attributes[field.id] = field.default_value || '';
            }
        }
    } catch {
        templateFields.value = [];
    } finally {
        loadingTemplate.value = false;
    }
}

// --- Precious Metal Options ---
const preciousMetalOptions = [
    { value: 'gold_10k', label: '10K Gold' },
    { value: 'gold_14k', label: '14K Gold' },
    { value: 'gold_18k', label: '18K Gold' },
    { value: 'gold_22k', label: '22K Gold' },
    { value: 'gold_24k', label: '24K Gold' },
    { value: 'silver', label: 'Sterling Silver' },
    { value: 'platinum', label: 'Platinum' },
    { value: 'palladium', label: 'Palladium' },
];

// --- Spot price ---
const spotPrice = ref<number | null>(null);
const loadingSpotPrice = ref(false);
let spotPriceTimeout: ReturnType<typeof setTimeout> | null = null;

// Find precious metal and DWT fields in template (for syncing)
const preciousMetalField = computed(() =>
    templateFields.value.find(f => f.name === 'precious_metal' || f.name === 'metal_type')
);
const dwtField = computed(() =>
    templateFields.value.find(f => f.name === 'dwt' || f.name === 'weight_dwt')
);

// Sync direct fields to template attributes
function syncMetalToTemplate() {
    if (preciousMetalField.value && form.value.precious_metal) {
        form.value.attributes[preciousMetalField.value.id] = form.value.precious_metal;
    }
    if (dwtField.value && form.value.dwt) {
        form.value.attributes[dwtField.value.id] = String(form.value.dwt);
    }
}

// Sync template attributes to direct fields
function syncTemplateToMetal() {
    if (preciousMetalField.value) {
        const val = form.value.attributes[preciousMetalField.value.id];
        if (val && val !== form.value.precious_metal) {
            form.value.precious_metal = val;
        }
    }
    if (dwtField.value) {
        const val = form.value.attributes[dwtField.value.id];
        if (val && parseFloat(val) !== form.value.dwt) {
            form.value.dwt = parseFloat(val) || undefined;
        }
    }
}

function calculateSpotPrice() {
    const metal = form.value.precious_metal;
    const dwt = form.value.dwt;

    if (!metal || !dwt || dwt <= 0) {
        spotPrice.value = null;
        return;
    }

    if (spotPriceTimeout) clearTimeout(spotPriceTimeout);
    spotPriceTimeout = setTimeout(async () => {
        loadingSpotPrice.value = true;
        try {
            const response = await fetch(`/api/v1/metal-prices/calculate?precious_metal=${encodeURIComponent(metal)}&dwt=${dwt}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            if (response.ok) {
                const data = await response.json();
                spotPrice.value = data.spot_price;
            } else {
                spotPrice.value = null;
            }
        } catch {
            spotPrice.value = null;
        } finally {
            loadingSpotPrice.value = false;
        }
    }, 300);
}

function fillSpotPrice() {
    if (spotPrice.value !== null) {
        form.value.price = spotPrice.value;
    }
}

// --- Image upload ---
const imageInputRef = ref<HTMLInputElement | null>(null);
const imagePreviews = ref<string[]>([]);

function handleImageSelect(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        addImages(Array.from(target.files));
    }
}

function handleImageDrop(event: DragEvent) {
    event.preventDefault();
    if (event.dataTransfer?.files) {
        addImages(Array.from(event.dataTransfer.files));
    }
}

function addImages(files: File[]) {
    const imageFiles = files.filter(file => file.type.startsWith('image/'));
    for (const file of imageFiles) {
        form.value.images.push(file);
        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreviews.value.push(e.target?.result as string);
        };
        reader.readAsDataURL(file);
    }
}

function removeImage(index: number) {
    form.value.images.splice(index, 1);
    imagePreviews.value.splice(index, 1);
}

// --- Form ---
function makeEmptyForm(): TransactionItem {
    return {
        id: crypto.randomUUID(),
        title: '',
        description: '',
        category_id: undefined,
        price: undefined,
        buy_price: 0,
        precious_metal: undefined,
        dwt: undefined,
        attributes: {},
        images: [],
    };
}

const form = ref<TransactionItem>(makeEmptyForm());

watch(() => form.value.category_id, (newId) => {
    if (newId) {
        loadTemplateFields(newId);
    } else {
        templateFields.value = [];
    }
});

// Watch precious metal/dwt changes for spot price calculation
watch([() => form.value.precious_metal, () => form.value.dwt], () => {
    calculateSpotPrice();
    syncMetalToTemplate();
});

// Watch attribute changes to sync back to direct fields
watch(() => form.value.attributes, () => {
    syncTemplateToMetal();
}, { deep: true });

const isEditing = computed(() => !!props.editingItem);

const selectedCategory = computed(() => {
    if (!form.value.category_id) return null;
    return props.categories.find(c => c.id === form.value.category_id);
});

watch(() => props.open, (isOpen) => {
    if (isOpen && props.editingItem) {
        form.value = { ...props.editingItem, images: props.editingItem.images || [] };
        imagesToDelete.value = [];
        // Build selection path for editing
        if (props.editingItem.category_id) {
            const path: number[] = [];
            let cat = props.categories.find(c => c.id === props.editingItem!.category_id);
            while (cat?.parent_id) {
                const parent = props.categories.find(c => c.id === cat!.parent_id);
                if (parent) {
                    path.unshift(parent.id);
                    cat = parent;
                } else {
                    break;
                }
            }
            selectionPath.value = path;
        }
    } else if (isOpen) {
        form.value = makeEmptyForm();
        selectionPath.value = [];
        templateFields.value = [];
        imagePreviews.value = [];
        imagesToDelete.value = [];
        spotPrice.value = null;
    }
});

const isValid = computed(() => {
    return form.value.title.trim().length > 0 && form.value.buy_price >= 0;
});

function handleSave() {
    if (!isValid.value) return;
    emit('save', { ...form.value }, [...imagesToDelete.value]);
    emit('close');
}

function handleClose() {
    emit('close');
}

// CSS class helper
const inputClass = 'mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600';
</script>

<template>
    <TransitionRoot as="template" :show="open">
        <Dialog as="div" class="relative z-50" @close="handleClose">
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

            <div class="fixed inset-0 z-10 overflow-y-auto">
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
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6 dark:bg-gray-800">
                            <div class="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none dark:bg-gray-800"
                                    @click="handleClose"
                                >
                                    <span class="sr-only">Close</span>
                                    <XMarkIcon class="size-6" aria-hidden="true" />
                                </button>
                            </div>

                            <div class="w-full">
                                <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ isEditing ? 'Edit Item' : 'Add Item' }}
                                </DialogTitle>

                                <div class="mt-6 space-y-6">
                                    <!-- Category Selection -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Category
                                        </label>

                                        <!-- Selected category display -->
                                        <div v-if="selectedCategory" class="flex items-center gap-2 rounded-md bg-indigo-50 px-3 py-2 dark:bg-indigo-900/30">
                                            <CheckIcon class="size-4 text-indigo-600 dark:text-indigo-400" />
                                            <span class="flex-1 text-sm font-medium text-indigo-900 dark:text-indigo-100">
                                                {{ selectedCategory.full_path }}
                                            </span>
                                            <button
                                                type="button"
                                                class="rounded p-0.5 text-indigo-600 hover:bg-indigo-100 dark:text-indigo-400 dark:hover:bg-indigo-800"
                                                @click="clearCategory"
                                            >
                                                <XMarkIcon class="size-4" />
                                            </button>
                                        </div>

                                        <!-- Category tree browser -->
                                        <div v-else class="rounded-md border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-700">
                                            <!-- Breadcrumb -->
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
                                            <div class="max-h-48 overflow-y-auto">
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
                                                        :is="isCategoryLeaf(category) ? FolderIcon : FolderOpenIcon"
                                                        class="size-5 text-gray-400"
                                                    />
                                                    <span class="flex-1 text-sm text-gray-900 dark:text-white">
                                                        {{ category.name }}
                                                    </span>
                                                    <span v-if="!isCategoryLeaf(category)" class="flex items-center gap-1 text-xs text-gray-400">
                                                        <span>{{ category.children?.length || 0 }}</span>
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
                                                    @click="selectionPath = selectionPath.slice(0, -1)"
                                                >
                                                    &larr; Back
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Base Fields -->
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div class="sm:col-span-2">
                                            <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Title <span class="text-red-500">*</span>
                                            </label>
                                            <input
                                                id="title"
                                                v-model="form.title"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="14K Gold Ring"
                                            />
                                        </div>

                                        <div class="sm:col-span-2">
                                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Description
                                            </label>
                                            <textarea
                                                id="description"
                                                v-model="form.description"
                                                rows="3"
                                                :class="inputClass"
                                                placeholder="Detailed description of the item..."
                                            />
                                        </div>
                                    </div>

                                    <!-- Precious Metal Quick Entry -->
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                                        <h4 class="text-sm font-medium text-amber-800 dark:text-amber-200 mb-3 flex items-center gap-2">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                            </svg>
                                            Metal Value Calculator
                                        </h4>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label for="precious_metal" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                                                    Metal Type
                                                </label>
                                                <select
                                                    id="precious_metal"
                                                    v-model="form.precious_metal"
                                                    class="block w-full rounded-md border-0 py-1.5 pl-3 pr-8 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-amber-500 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option value="">Select metal...</option>
                                                    <option v-for="opt in preciousMetalOptions" :key="opt.value" :value="opt.value">
                                                        {{ opt.label }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div>
                                                <label for="dwt" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                                                    Weight (DWT)
                                                </label>
                                                <input
                                                    id="dwt"
                                                    v-model.number="form.dwt"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="0.00"
                                                    class="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-amber-500 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                        <!-- Spot Price Result -->
                                        <div v-if="loadingSpotPrice" class="mt-3 flex items-center gap-2 text-sm text-gray-500">
                                            <svg class="animate-spin size-4" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                            </svg>
                                            Calculating...
                                        </div>
                                        <div v-else-if="spotPrice !== null" class="mt-3 flex items-center justify-between rounded-md bg-white px-3 py-2 dark:bg-gray-800">
                                            <div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">Spot Value:</span>
                                                <span class="ml-2 text-lg font-semibold text-green-600 dark:text-green-400">${{ spotPrice.toFixed(2) }}</span>
                                            </div>
                                            <button
                                                type="button"
                                                class="rounded-md bg-green-600 px-3 py-1 text-xs font-medium text-white hover:bg-green-500"
                                                @click="fillSpotPrice"
                                            >
                                                Use as Value
                                            </button>
                                        </div>
                                        <p v-else-if="form.precious_metal && form.dwt" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                            Unable to calculate spot price. Please ensure metal prices are up to date.
                                        </p>
                                    </div>

                                    <!-- Template Fields -->
                                    <div v-if="loadingTemplate" class="flex items-center justify-center gap-2 py-4 text-gray-500 dark:text-gray-400">
                                        <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                        </svg>
                                        <span>Loading template fields...</span>
                                    </div>

                                    <div v-else-if="templateFields.length > 0" class="space-y-4">
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white">Template Fields</h4>

                                        <!-- Grouped Fields -->
                                        <div v-for="(fields, groupName) in groupedTemplateFields.groups" :key="groupName" class="space-y-2">
                                            <div class="flex gap-2">
                                                <div
                                                    v-for="field in fields"
                                                    :key="field.id"
                                                    :class="[
                                                        field.width_class === 'full' ? 'flex-1' : '',
                                                        field.width_class === 'half' ? 'w-1/2' : '',
                                                        field.width_class === 'third' ? 'w-1/3' : '',
                                                        field.width_class === 'quarter' ? 'w-1/4' : '',
                                                        field.group_position > 1 ? 'w-auto shrink-0' : 'flex-1',
                                                    ]"
                                                >
                                                    <label :for="`attr_${field.id}`" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        {{ field.label }}
                                                        <span v-if="field.is_required" class="text-red-500">*</span>
                                                    </label>

                                                    <input
                                                        v-if="field.type === 'text'"
                                                        :id="`attr_${field.id}`"
                                                        v-model="form.attributes[field.id]"
                                                        type="text"
                                                        :placeholder="field.placeholder || ''"
                                                        :required="field.is_required"
                                                        :class="inputClass"
                                                    />
                                                    <input
                                                        v-else-if="field.type === 'number'"
                                                        :id="`attr_${field.id}`"
                                                        v-model="form.attributes[field.id]"
                                                        type="number"
                                                        step="any"
                                                        :placeholder="field.placeholder || ''"
                                                        :required="field.is_required"
                                                        :class="inputClass"
                                                    />
                                                    <select
                                                        v-else-if="field.type === 'select'"
                                                        :id="`attr_${field.id}`"
                                                        v-model="form.attributes[field.id]"
                                                        :required="field.is_required"
                                                        :class="inputClass"
                                                    >
                                                        <option value="">{{ field.placeholder || 'Select...' }}</option>
                                                        <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                                                            {{ opt.label }}
                                                        </option>
                                                    </select>
                                                    <input
                                                        v-else-if="field.type === 'date'"
                                                        :id="`attr_${field.id}`"
                                                        v-model="form.attributes[field.id]"
                                                        type="date"
                                                        :required="field.is_required"
                                                        :class="inputClass"
                                                    />

                                                    <p v-if="field.help_text && field.group_position === 1" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                        {{ field.help_text }}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Standalone Fields -->
                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div
                                                v-for="field in groupedTemplateFields.standalone"
                                                :key="field.id"
                                                :class="[
                                                    field.width_class === 'full' ? 'sm:col-span-2' : '',
                                                ]"
                                            >
                                                <label :for="`attr_${field.id}`" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    {{ field.label }}
                                                    <span v-if="field.is_required" class="text-red-500">*</span>
                                                </label>

                                                <input
                                                    v-if="field.type === 'text'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    type="text"
                                                    :placeholder="field.placeholder || ''"
                                                    :required="field.is_required"
                                                    :class="inputClass"
                                                />
                                                <input
                                                    v-else-if="field.type === 'number'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    type="number"
                                                    step="any"
                                                    :placeholder="field.placeholder || ''"
                                                    :required="field.is_required"
                                                    :class="inputClass"
                                                />
                                                <textarea
                                                    v-else-if="field.type === 'textarea'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    :placeholder="field.placeholder || ''"
                                                    :required="field.is_required"
                                                    rows="3"
                                                    :class="inputClass"
                                                />
                                                <select
                                                    v-else-if="field.type === 'select'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    :required="field.is_required"
                                                    :class="inputClass"
                                                >
                                                    <option value="">{{ field.placeholder || 'Select...' }}</option>
                                                    <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                                                        {{ opt.label }}
                                                    </option>
                                                </select>
                                                <div v-else-if="field.type === 'checkbox'" class="mt-2 space-y-2">
                                                    <label
                                                        v-for="opt in field.options"
                                                        :key="opt.value"
                                                        class="flex items-center gap-2"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            :value="opt.value"
                                                            :checked="(form.attributes[field.id] || '').split(',').includes(opt.value)"
                                                            @change="(e: Event) => {
                                                                const target = e.target as HTMLInputElement;
                                                                const current = (form.attributes[field.id] || '').split(',').filter(Boolean);
                                                                if (target.checked) {
                                                                    current.push(opt.value);
                                                                } else {
                                                                    const idx = current.indexOf(opt.value);
                                                                    if (idx > -1) current.splice(idx, 1);
                                                                }
                                                                form.attributes[field.id] = current.join(',');
                                                            }"
                                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                                        />
                                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ opt.label }}</span>
                                                    </label>
                                                </div>
                                                <div v-else-if="field.type === 'radio'" class="mt-2 space-y-2">
                                                    <label
                                                        v-for="opt in field.options"
                                                        :key="opt.value"
                                                        class="flex items-center gap-2"
                                                    >
                                                        <input
                                                            type="radio"
                                                            :name="`attr_${field.id}`"
                                                            :value="opt.value"
                                                            v-model="form.attributes[field.id]"
                                                            class="size-4 border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                                        />
                                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ opt.label }}</span>
                                                    </label>
                                                </div>
                                                <input
                                                    v-else-if="field.type === 'date'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    type="date"
                                                    :required="field.is_required"
                                                    :class="inputClass"
                                                />

                                                <p v-if="field.help_text" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    {{ field.help_text }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pricing -->
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <label for="buy_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Buy Price <span class="text-red-500">*</span>
                                            </label>
                                            <div class="relative mt-1">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                    <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
                                                </div>
                                                <input
                                                    id="buy_price"
                                                    v-model.number="form.buy_price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    required
                                                    class="block w-full rounded-md border-0 py-2 pl-7 pr-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="0.00"
                                                />
                                            </div>
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                The amount you're paying for this item
                                            </p>
                                        </div>

                                        <div>
                                            <label for="price" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Estimated Value
                                            </label>
                                            <div class="relative mt-1">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                    <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
                                                </div>
                                                <input
                                                    id="price"
                                                    v-model.number="form.price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-md border-0 py-2 pl-7 pr-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="0.00"
                                                />
                                            </div>
                                            <!-- Spot price hint -->
                                            <div v-if="spotPrice !== null" class="mt-1">
                                                <button
                                                    type="button"
                                                    class="text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                    @click="fillSpotPrice"
                                                >
                                                    Spot value: ${{ spotPrice.toFixed(2) }} — click to fill
                                                </button>
                                            </div>
                                            <div v-else-if="loadingSpotPrice" class="mt-1 text-xs text-gray-400">
                                                Calculating spot price...
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Image Upload -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            Images
                                        </label>

                                        <!-- Existing images (when editing) -->
                                        <div v-if="visibleExistingImages.length > 0" class="mb-3 flex flex-wrap gap-3">
                                            <div
                                                v-for="img in visibleExistingImages"
                                                :key="`existing-${img.id}`"
                                                class="relative h-20 w-20 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200 dark:bg-gray-700 dark:ring-gray-600"
                                            >
                                                <img :src="img.thumbnail_url || img.url" class="h-full w-full object-cover" />
                                                <button
                                                    type="button"
                                                    class="absolute right-0.5 top-0.5 rounded-full bg-red-600 p-0.5 text-white hover:bg-red-700"
                                                    @click="markImageForDeletion(img.id)"
                                                >
                                                    <XMarkIcon class="size-3" />
                                                </button>
                                                <span class="absolute bottom-0 left-0 right-0 bg-black/50 text-[10px] text-white text-center py-0.5">
                                                    Saved
                                                </span>
                                            </div>
                                        </div>

                                        <!-- New image previews -->
                                        <div v-if="imagePreviews.length > 0" class="mb-3 flex flex-wrap gap-3">
                                            <div
                                                v-for="(preview, index) in imagePreviews"
                                                :key="index"
                                                class="relative h-20 w-20 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200 dark:bg-gray-700 dark:ring-gray-600"
                                            >
                                                <img :src="preview" class="h-full w-full object-cover" />
                                                <button
                                                    type="button"
                                                    class="absolute right-0.5 top-0.5 rounded-full bg-red-600 p-0.5 text-white hover:bg-red-700"
                                                    @click="removeImage(index)"
                                                >
                                                    <XMarkIcon class="size-3" />
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Upload area -->
                                        <div
                                            class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 p-6 dark:border-gray-600 hover:border-indigo-400 dark:hover:border-indigo-500 cursor-pointer transition-colors"
                                            @click="imageInputRef?.click()"
                                            @dragover.prevent
                                            @drop="handleImageDrop"
                                        >
                                            <PhotoIcon class="size-8 text-gray-400 dark:text-gray-500" />
                                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                                Drag & drop or <span class="text-indigo-600 dark:text-indigo-400">click to upload</span>
                                            </p>
                                        </div>
                                        <input
                                            ref="imageInputRef"
                                            type="file"
                                            accept="image/*"
                                            multiple
                                            class="hidden"
                                            @change="handleImageSelect"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 flex justify-end gap-3">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="handleClose"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="!isValid"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="handleSave"
                                >
                                    {{ isEditing ? 'Save Changes' : 'Add Item' }}
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
