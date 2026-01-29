<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { PlusIcon, TrashIcon, ChevronDownIcon, ChevronUpIcon, PhotoIcon, XMarkIcon, SparklesIcon, VideoCameraIcon, LockClosedIcon } from '@heroicons/vue/20/solid';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import CategorySelector from '@/components/products/CategorySelector.vue';

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level: number;
    template_id: number | null;
    has_sku_format: boolean;
}

interface Brand {
    id: number;
    name: string;
}

interface Warehouse {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
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

interface TemplateBrand {
    id: number;
    name: string;
}

interface Template {
    id: number;
    name: string;
    description: string | null;
}

interface Variant {
    sku: string;
    option1_name: string;
    option1_value: string;
    price: number | string;
    wholesale_price: number | string;
    cost: number | string;
    quantity: number | string;
    warehouse_id: number | string;
}

interface VideoInput {
    url: string;
    title: string;
}

interface UploadedVideo {
    file: File;
    title: string;
    preview: string;
}

interface Props {
    categories: Category[];
    brands: Brand[];
    warehouses: Warehouse[];
}

const props = defineProps<Props>();

// Expose warehouses for the template
const warehouses = computed(() => props.warehouses);

// Get default warehouse ID
const defaultWarehouseId = computed(() => {
    const defaultWarehouse = props.warehouses.find(w => w.is_default);
    return defaultWarehouse?.id ?? props.warehouses[0]?.id ?? '';
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Create Product', href: '/products/create' },
];

// Template state
const template = ref<Template | null>(null);
const templateFields = ref<TemplateField[]>([]);
const templateBrands = ref<TemplateBrand[]>([]);
const loadingTemplate = ref(false);

// Section visibility
const sections = ref({
    basicInfo: true,
    pricingInventory: true,
    attributes: true,
    images: true,
    videos: false,
    internalImages: false,
    shipping: false,
});

function toggleSection(section: keyof typeof sections.value) {
    sections.value[section] = !sections.value[section];
}

// Group template fields by group_name
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

// Track whether product has variants (multiple options like size, color)
const hasVariants = ref(false);

// Condition options
const conditionOptions = [
    { value: 'new', label: 'New' },
    { value: 'like_new', label: 'Like New' },
    { value: 'excellent', label: 'Excellent' },
    { value: 'very_good', label: 'Very Good' },
    { value: 'good', label: 'Good' },
    { value: 'fair', label: 'Fair' },
    { value: 'poor', label: 'Poor' },
];

const form = useForm({
    title: '',
    description: '',
    handle: '',
    category_id: '' as string | number,
    template_id: '' as string | number,
    is_published: false,
    has_variants: false,
    track_quantity: true,
    sell_out_of_stock: false,
    condition: '',
    // Shipping fields
    weight: '' as string | number,
    weight_unit: 'g',
    length: '' as string | number,
    width: '' as string | number,
    height: '' as string | number,
    length_class: 'cm',
    domestic_shipping_cost: '' as string | number,
    international_shipping_cost: '' as string | number,
    variants: [
        { sku: '', option1_name: '', option1_value: '', price: '', wholesale_price: '', cost: '', quantity: '0', warehouse_id: '' as string | number },
    ] as Variant[],
    attributes: {} as Record<number, string>,
    images: [] as File[],
    internal_images: [] as File[],
    videos: [] as VideoInput[],
    video_files: [] as File[],
});

// Uploaded video previews
const uploadedVideos = ref<UploadedVideo[]>([]);
const videoInputRef = ref<HTMLInputElement | null>(null);

// Sync has_variants with local state
watch(hasVariants, (newValue) => {
    form.has_variants = newValue;
    // If switching to no variants mode, keep only first variant and clear options
    if (!newValue) {
        form.variants = [form.variants[0] || { sku: '', option1_name: '', option1_value: '', price: '', wholesale_price: '', cost: '', quantity: '0', warehouse_id: defaultWarehouseId.value }];
        form.variants[0].option1_name = '';
        form.variants[0].option1_value = '';
    }
});

// Image upload handling
const imageInputRef = ref<HTMLInputElement | null>(null);
const imagePreviews = ref<string[]>([]);

// Internal image upload handling
const internalImageInputRef = ref<HTMLInputElement | null>(null);
const internalImagePreviews = ref<string[]>([]);

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
        form.images.push(file);
        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreviews.value.push(e.target?.result as string);
        };
        reader.readAsDataURL(file);
    }
}

function removeImage(index: number) {
    form.images.splice(index, 1);
    imagePreviews.value.splice(index, 1);
}

// Internal images handling
function handleInternalImageSelect(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        addInternalImages(Array.from(target.files));
    }
}

function handleInternalImageDrop(event: DragEvent) {
    event.preventDefault();
    if (event.dataTransfer?.files) {
        addInternalImages(Array.from(event.dataTransfer.files));
    }
}

function addInternalImages(files: File[]) {
    const imageFiles = files.filter(file => file.type.startsWith('image/'));
    for (const file of imageFiles) {
        form.internal_images.push(file);
        const reader = new FileReader();
        reader.onload = (e) => {
            internalImagePreviews.value.push(e.target?.result as string);
        };
        reader.readAsDataURL(file);
    }
}

function removeInternalImage(index: number) {
    form.internal_images.splice(index, 1);
    internalImagePreviews.value.splice(index, 1);
}

// Video URL handling
function addVideo() {
    form.videos.push({ url: '', title: '' });
}

function removeVideo(index: number) {
    form.videos.splice(index, 1);
}

// Video file upload handling
function handleVideoSelect(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        addVideoFiles(Array.from(target.files));
    }
}

function handleVideoDrop(event: DragEvent) {
    event.preventDefault();
    if (event.dataTransfer?.files) {
        addVideoFiles(Array.from(event.dataTransfer.files));
    }
}

function addVideoFiles(files: File[]) {
    const videoFiles = files.filter(file => file.type.startsWith('video/'));
    for (const file of videoFiles) {
        form.video_files.push(file);
        const preview = URL.createObjectURL(file);
        uploadedVideos.value.push({
            file,
            title: file.name.replace(/\.[^/.]+$/, ''), // Remove extension for default title
            preview,
        });
    }
}

function removeUploadedVideo(index: number) {
    // Revoke the object URL to free memory
    URL.revokeObjectURL(uploadedVideos.value[index].preview);
    form.video_files.splice(index, 1);
    uploadedVideos.value.splice(index, 1);
}

function formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Set default warehouse on mount
watch(defaultWarehouseId, (newId) => {
    if (newId && form.variants.length > 0 && !form.variants[0].warehouse_id) {
        form.variants[0].warehouse_id = newId;
    }
}, { immediate: true });

// Watch for category changes to load template fields
watch(() => form.category_id, async (newCategoryId) => {
    if (!newCategoryId) {
        template.value = null;
        templateFields.value = [];
        templateBrands.value = [];
        form.template_id = '';
        form.attributes = {};
        return;
    }

    loadingTemplate.value = true;
    try {
        const response = await fetch(`/categories/${newCategoryId}/template-fields`, {
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Failed to load template');
        }

        const data = await response.json();
        template.value = data.template;
        templateFields.value = data.fields || [];
        templateBrands.value = data.brands || [];

        // Store template_id on the form
        form.template_id = data.template?.id || '';

        // Initialize attributes with default values
        form.attributes = {};
        for (const field of templateFields.value) {
            form.attributes[field.id] = field.default_value || '';
        }

        // Auto-generate SKU if category has SKU format and SKU field is empty
        const category = props.categories.find(c => c.id == newCategoryId);
        if (category?.has_sku_format && !form.variants[0].sku) {
            generateSku(0);
        }
    } catch (e) {
        console.error('Failed to load template fields:', e);
        template.value = null;
        templateFields.value = [];
        templateBrands.value = [];
        form.template_id = '';
    } finally {
        loadingTemplate.value = false;
    }
});

function addVariant() {
    if (!hasVariants.value) return;
    form.variants.push({ sku: '', option1_name: '', option1_value: '', price: '', wholesale_price: '', cost: '', quantity: '0', warehouse_id: defaultWarehouseId.value });
}

function removeVariant(index: number) {
    if (form.variants.length > 1) {
        form.variants.splice(index, 1);
    }
}

// SKU Generation
const generatingSku = ref(false);

// Check if selected category has SKU format
const categoryHasSkuFormat = computed(() => {
    if (!form.category_id) return false;
    const category = props.categories.find(c => c.id == form.category_id);
    return category?.has_sku_format ?? false;
});

async function generateSku(variantIndex: number = 0) {
    if (!form.category_id || generatingSku.value) return;

    generatingSku.value = true;
    try {
        const response = await fetch('/products/generate-sku', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                category_id: form.category_id,
            }),
        });

        const data = await response.json();

        if (response.ok && data.sku) {
            form.variants[variantIndex].sku = data.sku;
        } else if (data.error) {
            console.error('SKU generation error:', data.error);
        }
    } catch (e) {
        console.error('Failed to generate SKU:', e);
    } finally {
        generatingSku.value = false;
    }
}

function submit() {
    form.post('/products', {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="Create Product" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Header -->
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Create Product</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ form.category_id ? 'Fill in the product details' : 'Start by selecting a product category' }}
                        </p>
                    </div>
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                            @click="router.visit('/products')"
                        >
                            Cancel
                        </button>
                        <button
                            v-if="form.category_id"
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Creating...' : 'Create Product' }}
                        </button>
                    </div>
                </div>

                <!-- Step 1: Category Selection (shown when no category selected) -->
                <div v-if="!form.category_id" class="max-w-2xl">
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Select Product Category</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                The category determines the product type and which fields will be available.
                            </p>
                            <CategorySelector
                                v-model="form.category_id"
                                :categories="categories"
                            />
                        </div>
                    </div>
                </div>

                <!-- Step 2: Product Form (shown after category selected) -->
                <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Basic Info -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('basicInfo')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Basic Information</h3>
                                <ChevronDownIcon v-if="!sections.basicInfo" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.basicInfo" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-4">
                                    <div>
                                        <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Title <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="title"
                                            v-model="form.title"
                                            type="text"
                                            required
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                                    </div>

                                    <div>
                                        <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Description
                                        </label>
                                        <RichTextEditor
                                            v-model="form.description"
                                            placeholder="Enter product description..."
                                            class="mt-1"
                                        />
                                        <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</p>
                                    </div>

                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label for="handle" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Handle (URL slug)
                                            </label>
                                            <input
                                                id="handle"
                                                v-model="form.handle"
                                                type="text"
                                                placeholder="leave-blank-to-auto-generate"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <p v-if="form.errors.handle" class="mt-1 text-sm text-red-600">{{ form.errors.handle }}</p>
                                        </div>

                                        <div>
                                            <label for="condition" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Condition
                                            </label>
                                            <select
                                                id="condition"
                                                v-model="form.condition"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option value="">Select condition...</option>
                                                <option v-for="opt in conditionOptions" :key="opt.value" :value="opt.value">
                                                    {{ opt.label }}
                                                </option>
                                            </select>
                                            <p v-if="form.errors.condition" class="mt-1 text-sm text-red-600">{{ form.errors.condition }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing & Inventory (moved above templates) -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('pricingInventory')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Pricing & Inventory</h3>
                                <ChevronDownIcon v-if="!sections.pricingInventory" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.pricingInventory" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <!-- Has Variants Toggle -->
                                <div class="mb-6 flex items-center justify-between rounded-lg bg-gray-50 p-4 dark:bg-gray-700/50">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">This product has variants</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Enable if this product comes in different sizes, colors, or options</p>
                                    </div>
                                    <button
                                        type="button"
                                        :class="[
                                            hasVariants ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-600',
                                            'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2'
                                        ]"
                                        @click="hasVariants = !hasVariants"
                                    >
                                        <span
                                            :class="[
                                                hasVariants ? 'translate-x-5' : 'translate-x-0',
                                                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out'
                                            ]"
                                        />
                                    </button>
                                </div>

                                <!-- Single product (no variants) -->
                                <div v-if="!hasVariants" class="space-y-4">
                                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-6">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                SKU <span class="text-red-500">*</span>
                                            </label>
                                            <div class="mt-1 flex gap-2">
                                                <input
                                                    v-model="form.variants[0].sku"
                                                    type="text"
                                                    required
                                                    class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                                <button
                                                    v-if="categoryHasSkuFormat"
                                                    type="button"
                                                    :disabled="generatingSku"
                                                    class="shrink-0 rounded-md bg-indigo-50 px-2 py-1.5 text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-900/50 dark:text-indigo-400 dark:hover:bg-indigo-900 disabled:opacity-50"
                                                    title="Generate SKU"
                                                    @click="generateSku(0)"
                                                >
                                                    <SparklesIcon class="size-5" :class="{ 'animate-pulse': generatingSku }" />
                                                </button>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Price <span class="text-red-500">*</span>
                                            </label>
                                            <div class="mt-1 flex rounded-md shadow-sm">
                                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-2 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
                                                <input
                                                    v-model="form.variants[0].price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    required
                                                    class="block w-full rounded-none rounded-r-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Wholesale
                                            </label>
                                            <div class="mt-1 flex rounded-md shadow-sm">
                                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-2 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
                                                <input
                                                    v-model="form.variants[0].wholesale_price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-none rounded-r-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Cost
                                            </label>
                                            <div class="mt-1 flex rounded-md shadow-sm">
                                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-2 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
                                                <input
                                                    v-model="form.variants[0].cost"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-none rounded-r-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Quantity <span class="text-red-500">*</span>
                                            </label>
                                            <input
                                                v-model="form.variants[0].quantity"
                                                type="number"
                                                min="0"
                                                required
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Warehouse
                                            </label>
                                            <select
                                                v-model="form.variants[0].warehouse_id"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                                    {{ warehouse.name }}{{ warehouse.is_default ? ' (Default)' : '' }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Multiple variants -->
                                <div v-else>
                                    <div class="flex justify-end mb-4">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-50 px-2.5 py-1.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-900/50 dark:text-indigo-400 dark:hover:bg-indigo-900"
                                            @click="addVariant"
                                        >
                                            <PlusIcon class="-ml-0.5 size-4" />
                                            Add Variant
                                        </button>
                                    </div>

                                    <div class="space-y-4">
                                        <div
                                            v-for="(variant, index) in form.variants"
                                            :key="index"
                                            class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                                        >
                                            <div class="flex items-center justify-between mb-3">
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Variant {{ index + 1 }}
                                                </span>
                                                <button
                                                    v-if="form.variants.length > 1"
                                                    type="button"
                                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                                    @click="removeVariant(index)"
                                                >
                                                    <TrashIcon class="size-5" />
                                                </button>
                                            </div>

                                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-8">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        SKU <span class="text-red-500">*</span>
                                                    </label>
                                                    <div class="mt-1 flex gap-1">
                                                        <input
                                                            v-model="variant.sku"
                                                            type="text"
                                                            required
                                                            class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                        <button
                                                            v-if="categoryHasSkuFormat"
                                                            type="button"
                                                            :disabled="generatingSku"
                                                            class="shrink-0 rounded-md bg-indigo-50 px-1.5 py-1.5 text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-900/50 dark:text-indigo-400 dark:hover:bg-indigo-900 disabled:opacity-50"
                                                            title="Generate SKU"
                                                            @click="generateSku(index)"
                                                        >
                                                            <SparklesIcon class="size-4" :class="{ 'animate-pulse': generatingSku }" />
                                                        </button>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Option Name
                                                    </label>
                                                    <input
                                                        v-model="variant.option1_name"
                                                        type="text"
                                                        placeholder="e.g. Size"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Option Value
                                                    </label>
                                                    <input
                                                        v-model="variant.option1_value"
                                                        type="text"
                                                        placeholder="e.g. Medium"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Price <span class="text-red-500">*</span>
                                                    </label>
                                                    <input
                                                        v-model="variant.price"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        required
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Wholesale
                                                    </label>
                                                    <input
                                                        v-model="variant.wholesale_price"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Cost
                                                    </label>
                                                    <input
                                                        v-model="variant.cost"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Quantity <span class="text-red-500">*</span>
                                                    </label>
                                                    <input
                                                        v-model="variant.quantity"
                                                        type="number"
                                                        min="0"
                                                        required
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Warehouse
                                                    </label>
                                                    <select
                                                        v-model="variant.warehouse_id"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    >
                                                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                                            {{ warehouse.name }}{{ warehouse.is_default ? ' (Default)' : '' }}
                                                        </option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <p v-if="form.errors.variants" class="mt-2 text-sm text-red-600">{{ form.errors.variants }}</p>
                            </div>
                        </div>

                        <!-- Template Attributes Section -->
                        <div v-if="template && templateFields.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('attributes')"
                            >
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ template.name }}</h3>
                                    <p v-if="template.description" class="text-sm text-gray-500 dark:text-gray-400">{{ template.description }}</p>
                                </div>
                                <ChevronDownIcon v-if="!sections.attributes" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.attributes" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-6">
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
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />

                                                <input
                                                    v-else-if="field.type === 'number'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    type="number"
                                                    step="any"
                                                    :placeholder="field.placeholder || ''"
                                                    :required="field.is_required"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />

                                                <select
                                                    v-else-if="field.type === 'select'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    :required="field.is_required"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option value="">{{ field.placeholder || 'Select...' }}</option>
                                                    <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                                                        {{ opt.label }}
                                                    </option>
                                                </select>

                                                <select
                                                    v-else-if="field.type === 'brand'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    :required="field.is_required"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option value="">{{ field.placeholder || 'Select brand...' }}</option>
                                                    <option v-for="brand in templateBrands" :key="brand.id" :value="brand.id.toString()">
                                                        {{ brand.name }}
                                                    </option>
                                                </select>

                                                <input
                                                    v-else-if="field.type === 'date'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    type="date"
                                                    :required="field.is_required"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
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
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />

                                            <input
                                                v-else-if="field.type === 'number'"
                                                :id="`attr_${field.id}`"
                                                v-model="form.attributes[field.id]"
                                                type="number"
                                                step="any"
                                                :placeholder="field.placeholder || ''"
                                                :required="field.is_required"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />

                                            <textarea
                                                v-else-if="field.type === 'textarea'"
                                                :id="`attr_${field.id}`"
                                                v-model="form.attributes[field.id]"
                                                :placeholder="field.placeholder || ''"
                                                :required="field.is_required"
                                                rows="3"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />

                                            <select
                                                v-else-if="field.type === 'select'"
                                                :id="`attr_${field.id}`"
                                                v-model="form.attributes[field.id]"
                                                :required="field.is_required"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
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

                                            <select
                                                v-else-if="field.type === 'brand'"
                                                :id="`attr_${field.id}`"
                                                v-model="form.attributes[field.id]"
                                                :required="field.is_required"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option value="">{{ field.placeholder || 'Select brand...' }}</option>
                                                <option v-for="brand in templateBrands" :key="brand.id" :value="brand.id.toString()">
                                                    {{ brand.name }}
                                                </option>
                                            </select>

                                            <input
                                                v-else-if="field.type === 'date'"
                                                :id="`attr_${field.id}`"
                                                v-model="form.attributes[field.id]"
                                                type="date"
                                                :required="field.is_required"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />

                                            <p v-if="field.help_text" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ field.help_text }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Loading Template State -->
                        <div v-else-if="loadingTemplate" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 p-6">
                            <div class="flex items-center justify-center gap-2 text-gray-500 dark:text-gray-400">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                <span>Loading template fields...</span>
                            </div>
                        </div>

                        <!-- Images Section -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('images')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Images</h3>
                                <ChevronDownIcon v-if="!sections.images" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.images" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <!-- Image previews -->
                                <div v-if="imagePreviews.length > 0" class="mb-4 flex flex-wrap gap-4">
                                    <div
                                        v-for="(preview, index) in imagePreviews"
                                        :key="index"
                                        class="relative h-24 w-24 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200 dark:bg-gray-700 dark:ring-gray-600"
                                    >
                                        <img
                                            :src="preview"
                                            class="h-full w-full object-cover"
                                        />
                                        <button
                                            type="button"
                                            class="absolute right-1 top-1 rounded-full bg-red-600 p-0.5 text-white hover:bg-red-700"
                                            @click="removeImage(index)"
                                        >
                                            <XMarkIcon class="size-4" />
                                        </button>
                                        <span
                                            v-if="index === 0"
                                            class="absolute bottom-0 left-0 right-0 bg-indigo-600 px-1 py-0.5 text-center text-xs font-medium text-white"
                                        >
                                            Primary
                                        </span>
                                    </div>
                                </div>

                                <!-- Upload area -->
                                <div
                                    class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 p-8 dark:border-gray-600 hover:border-indigo-400 dark:hover:border-indigo-500 cursor-pointer transition-colors"
                                    @click="imageInputRef?.click()"
                                    @dragover.prevent
                                    @drop="handleImageDrop"
                                >
                                    <PhotoIcon class="size-12 text-gray-400 dark:text-gray-500" />
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Drag & drop images or <span class="text-indigo-600 dark:text-indigo-400">click to upload</span>
                                    </p>
                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                        PNG, JPG, WEBP up to 10MB each
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

                        <!-- Videos Section -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('videos')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Videos</h3>
                                <ChevronDownIcon v-if="!sections.videos" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.videos" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-6">
                                    <!-- Uploaded Videos -->
                                    <div v-if="uploadedVideos.length > 0">
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">Uploaded Videos</h4>
                                        <div class="space-y-3">
                                            <div
                                                v-for="(video, index) in uploadedVideos"
                                                :key="'uploaded-' + index"
                                                class="flex gap-4 items-start rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                                            >
                                                <div class="relative h-20 w-32 shrink-0 overflow-hidden rounded bg-gray-100 dark:bg-gray-700">
                                                    <video
                                                        :src="video.preview"
                                                        class="h-full w-full object-cover"
                                                        muted
                                                    />
                                                    <div class="absolute inset-0 flex items-center justify-center bg-black/30">
                                                        <VideoCameraIcon class="size-8 text-white" />
                                                    </div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <input
                                                        v-model="video.title"
                                                        type="text"
                                                        placeholder="Video title"
                                                        class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate">
                                                        {{ video.file.name }} ({{ formatFileSize(video.file.size) }})
                                                    </p>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                                    @click="removeUploadedVideo(index)"
                                                >
                                                    <TrashIcon class="size-5" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Video Upload Area -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">Upload Video</h4>
                                        <div
                                            class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 p-6 dark:border-gray-600 hover:border-indigo-400 dark:hover:border-indigo-500 cursor-pointer transition-colors"
                                            @click="videoInputRef?.click()"
                                            @dragover.prevent
                                            @drop="handleVideoDrop"
                                        >
                                            <VideoCameraIcon class="size-10 text-gray-400 dark:text-gray-500" />
                                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                                Drag & drop videos or <span class="text-indigo-600 dark:text-indigo-400">click to upload</span>
                                            </p>
                                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                                MP4, MOV, AVI, WebM up to 100MB each
                                            </p>
                                        </div>
                                        <input
                                            ref="videoInputRef"
                                            type="file"
                                            accept="video/*"
                                            multiple
                                            class="hidden"
                                            @change="handleVideoSelect"
                                        />
                                    </div>

                                    <!-- External Video URLs -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">External Videos</h4>
                                        <div class="space-y-3">
                                            <div
                                                v-for="(video, index) in form.videos"
                                                :key="'external-' + index"
                                                class="flex gap-4 items-start"
                                            >
                                                <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                    <div>
                                                        <input
                                                            v-model="video.url"
                                                            type="url"
                                                            placeholder="https://youtube.com/watch?v=..."
                                                            class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                    </div>
                                                    <div>
                                                        <input
                                                            v-model="video.title"
                                                            type="text"
                                                            placeholder="Video title (optional)"
                                                            class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                                    @click="removeVideo(index)"
                                                >
                                                    <TrashIcon class="size-5" />
                                                </button>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            class="mt-3 inline-flex items-center gap-x-1.5 rounded-md bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                            @click="addVideo"
                                        >
                                            <PlusIcon class="-ml-0.5 size-4" />
                                            Add External URL
                                        </button>

                                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                            Supports YouTube and Vimeo links
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Internal Images Section -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('internalImages')"
                            >
                                <div class="flex items-center gap-2">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Internal Images</h3>
                                    <LockClosedIcon class="size-4 text-gray-400" title="For internal use only" />
                                </div>
                                <ChevronDownIcon v-if="!sections.internalImages" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.internalImages" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <p class="mb-4 text-sm text-amber-600 dark:text-amber-400">
                                    These images are for internal use only and will not be shown to customers.
                                </p>

                                <!-- Internal image previews -->
                                <div v-if="internalImagePreviews.length > 0" class="mb-4 flex flex-wrap gap-4">
                                    <div
                                        v-for="(preview, index) in internalImagePreviews"
                                        :key="index"
                                        class="relative h-24 w-24 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-amber-300 dark:bg-gray-700 dark:ring-amber-600"
                                    >
                                        <img
                                            :src="preview"
                                            class="h-full w-full object-cover"
                                        />
                                        <button
                                            type="button"
                                            class="absolute right-1 top-1 rounded-full bg-red-600 p-0.5 text-white hover:bg-red-700"
                                            @click="removeInternalImage(index)"
                                        >
                                            <XMarkIcon class="size-4" />
                                        </button>
                                        <LockClosedIcon class="absolute bottom-1 left-1 size-4 text-amber-600 dark:text-amber-400" />
                                    </div>
                                </div>

                                <!-- Upload area -->
                                <div
                                    class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-amber-300 p-8 dark:border-amber-600 hover:border-amber-400 dark:hover:border-amber-500 cursor-pointer transition-colors"
                                    @click="internalImageInputRef?.click()"
                                    @dragover.prevent
                                    @drop="handleInternalImageDrop"
                                >
                                    <LockClosedIcon class="size-12 text-amber-400 dark:text-amber-500" />
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Drag & drop internal images or <span class="text-amber-600 dark:text-amber-400">click to upload</span>
                                    </p>
                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                        Receipts, certificates, internal documentation
                                    </p>
                                </div>
                                <input
                                    ref="internalImageInputRef"
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    class="hidden"
                                    @change="handleInternalImageSelect"
                                />
                            </div>
                        </div>

                        <!-- Shipping Rates Section -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('shipping')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Shipping</h3>
                                <ChevronDownIcon v-if="!sections.shipping" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.shipping" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-6">
                                    <!-- Shipping Costs -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">Shipping Costs</h4>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label for="domestic_shipping_cost" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Domestic Shipping
                                                </label>
                                                <div class="mt-1 flex rounded-md shadow-sm">
                                                    <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-2 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
                                                    <input
                                                        id="domestic_shipping_cost"
                                                        v-model="form.domestic_shipping_cost"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        placeholder="0.00"
                                                        class="block w-full rounded-none rounded-r-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>

                                            <div>
                                                <label for="international_shipping_cost" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    International Shipping
                                                </label>
                                                <div class="mt-1 flex rounded-md shadow-sm">
                                                    <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-2 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
                                                    <input
                                                        id="international_shipping_cost"
                                                        v-model="form.international_shipping_cost"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        placeholder="0.00"
                                                        class="block w-full rounded-none rounded-r-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Weight -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">Weight</h4>
                                        <div class="flex gap-2">
                                            <div class="flex-1">
                                                <input
                                                    id="weight"
                                                    v-model="form.weight"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="0.00"
                                                    class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <select
                                                v-model="form.weight_unit"
                                                class="rounded-md border-0 bg-white py-1.5 pl-3 pr-8 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option value="g">g</option>
                                                <option value="kg">kg</option>
                                                <option value="oz">oz</option>
                                                <option value="lb">lb</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Dimensions -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">Dimensions</h4>
                                        <div class="flex gap-2 items-end">
                                            <div class="flex-1">
                                                <label for="length" class="block text-xs text-gray-500 dark:text-gray-400">Length</label>
                                                <input
                                                    id="length"
                                                    v-model="form.length"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="0"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <span class="pb-2 text-gray-400">x</span>
                                            <div class="flex-1">
                                                <label for="width" class="block text-xs text-gray-500 dark:text-gray-400">Width</label>
                                                <input
                                                    id="width"
                                                    v-model="form.width"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="0"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <span class="pb-2 text-gray-400">x</span>
                                            <div class="flex-1">
                                                <label for="height" class="block text-xs text-gray-500 dark:text-gray-400">Height</label>
                                                <input
                                                    id="height"
                                                    v-model="form.height"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="0"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <select
                                                v-model="form.length_class"
                                                class="rounded-md border-0 bg-white py-1.5 pl-3 pr-8 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option value="cm">cm</option>
                                                <option value="in">in</option>
                                                <option value="m">m</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Status -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Status</h3>

                                <div class="space-y-3">
                                    <label class="flex items-center gap-3">
                                        <input
                                            v-model="form.is_published"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Published</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Organization -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Organization</h3>

                                <div class="space-y-4">
                                    <!-- Selected Category Display -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Category
                                        </label>
                                        <div class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-700">
                                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                {{ categories.find(c => c.id == form.category_id)?.full_path || 'Unknown' }}
                                            </p>
                                            <button
                                                type="button"
                                                class="mt-1 text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                @click="form.category_id = ''"
                                            >
                                                Change category
                                            </button>
                                        </div>
                                        <p v-if="form.errors.category_id" class="mt-1 text-sm text-red-600">{{ form.errors.category_id }}</p>
                                    </div>

                                    <!-- Template Info -->
                                    <div v-if="template">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Product Type
                                        </label>
                                        <div class="text-sm text-gray-900 dark:text-white">
                                            {{ template.name }}
                                        </div>
                                    </div>
                                    <div v-else-if="!loadingTemplate && form.category_id">
                                        <p class="text-xs text-amber-600 dark:text-amber-400">
                                            This category has no template assigned.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inventory -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Inventory</h3>

                                <div class="space-y-3">
                                    <label class="flex items-center gap-3">
                                        <input
                                            v-model="form.track_quantity"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Track quantity</span>
                                    </label>

                                    <label class="flex items-center gap-3">
                                        <input
                                            v-model="form.sell_out_of_stock"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Continue selling when out of stock</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
