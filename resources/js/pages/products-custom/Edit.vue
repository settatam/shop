<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import {
    ChevronDownIcon,
    ChevronUpIcon,
    PlusIcon,
    TrashIcon,
    PhotoIcon,
    ArrowLeftIcon,
    XMarkIcon,
    StarIcon,
    TagIcon,
} from '@heroicons/vue/20/solid';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import CategorySelector from '@/components/products/CategorySelector.vue';

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

interface Template {
    id: number;
    name: string;
    description: string | null;
}

interface Brand {
    id: number;
    name: string;
}

interface TemplateBrand {
    id: number;
    name: string;
}

interface Warehouse {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
}

interface Vendor {
    id: number;
    name: string;
    company_name: string | null;
}

interface Tag {
    id: number;
    name: string;
    color: string;
}

interface Image {
    id: number;
    url: string;
    alt: string | null;
    is_primary: boolean;
}

interface Variant {
    id?: number;
    sku: string;
    barcode: string | null;
    price: number | string;
    wholesale_price: number | string | null;
    cost: number | string | null;
    quantity: number | string;
    weight: number | string | null;
    weight_unit: string;
    option1_name: string | null;
    option1_value: string | null;
    option2_name: string | null;
    option2_value: string | null;
    option3_name: string | null;
    option3_value: string | null;
    is_active: boolean;
    warehouse_id: number | string | null;
}

interface Product {
    id: number;
    title: string;
    description: string | null;
    handle: string;
    sku: string | null;
    upc: string | null;
    is_published: boolean;
    is_draft: boolean;
    has_variants: boolean;
    track_quantity: boolean;
    sell_out_of_stock: boolean;
    category_id: number | null;
    vendor_id: number | null;
    template_id: number | null;
    tag_ids: number[];
    compare_at_price: number | null;
    weight: number | null;
    weight_unit: string;
    length: number | null;
    width: number | null;
    height: number | null;
    length_class: string;
    minimum_order: number;
    total_quantity: number;
    seo_page_title: string | null;
    seo_description: string | null;
    created_at: string;
    updated_at: string;
    variants: Variant[];
    images: Image[];
}

interface Props {
    product: Product;
    categories: Category[];
    brands: Brand[];
    warehouses: Warehouse[];
    vendors: Vendor[];
    availableTags: Tag[];
    variantInventory: Record<number, { warehouse_id: number | null; quantity: number }>;
    template: Template | null;
    templateFields: TemplateField[];
    templateBrands: TemplateBrand[];
    attributeValues: Record<number, string>;
}

const props = defineProps<Props>();

// Track whether product has variants
const hasVariants = ref(props.product.has_variants);

// Template state
const template = ref<Template | null>(props.template);
const templateFields = ref<TemplateField[]>(props.templateFields);
const templateBrands = ref<TemplateBrand[]>(props.templateBrands || []);
const loadingTemplate = ref(false);

// Warehouses
const warehouses = computed(() => props.warehouses);

// Default warehouse ID
const defaultWarehouseId = computed(() => {
    const defaultWarehouse = props.warehouses.find(w => w.is_default);
    return defaultWarehouse?.id ?? props.warehouses[0]?.id ?? '';
});

// Group template fields
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: props.product.title, href: `/products/${props.product.id}` },
    { title: 'Edit', href: `/products/${props.product.id}/edit` },
];

// Collapsible sections
const sections = ref({
    productInfo: true,
    vendor: true,
    tags: true,
    attributes: true,
    pricing: true,
    media: true,
    variants: true,
    inventory: true,
    shipping: false,
    seo: false,
});

function toggleSection(section: keyof typeof sections.value) {
    sections.value[section] = !sections.value[section];
}

// Form with vendor_id (required) and tag_ids
const form = useForm({
    title: props.product.title,
    description: props.product.description || '',
    handle: props.product.handle,
    sku: props.product.sku || '',
    upc: props.product.upc || '',
    category_id: props.product.category_id || '',
    vendor_id: props.product.vendor_id || '',
    tag_ids: props.product.tag_ids || [],
    template_id: props.product.template_id || '',
    is_published: props.product.is_published,
    has_variants: props.product.has_variants,
    track_quantity: props.product.track_quantity,
    sell_out_of_stock: props.product.sell_out_of_stock,
    compare_at_price: props.product.compare_at_price || '',
    weight: props.product.weight || '',
    weight_unit: props.product.weight_unit || 'lb',
    length: props.product.length || '',
    width: props.product.width || '',
    height: props.product.height || '',
    length_class: props.product.length_class || 'in',
    minimum_order: props.product.minimum_order || 1,
    seo_page_title: props.product.seo_page_title || '',
    seo_description: props.product.seo_description || '',
    variants: props.product.variants.map(v => ({
        id: v.id,
        sku: v.sku,
        barcode: v.barcode || '',
        price: v.price,
        wholesale_price: v.wholesale_price || '',
        cost: v.cost || '',
        quantity: v.quantity,
        weight: v.weight || '',
        weight_unit: v.weight_unit || 'lb',
        option1_name: v.option1_name || '',
        option1_value: v.option1_value || '',
        option2_name: v.option2_name || '',
        option2_value: v.option2_value || '',
        option3_name: v.option3_name || '',
        option3_value: v.option3_value || '',
        is_active: v.is_active !== false,
        warehouse_id: v.id ? (props.variantInventory[v.id]?.warehouse_id ?? null) : null,
    })) as Variant[],
    attributes: Object.fromEntries(
        templateFields.value.map(field => [
            field.id,
            props.attributeValues[field.id] ?? field.default_value ?? ''
        ])
    ) as Record<number, string>,
    images: [] as File[],
});

// Tag input state
const tagSearchQuery = ref('');
const showTagDropdown = ref(false);

const filteredTags = computed(() => {
    if (!tagSearchQuery.value) {
        return props.availableTags.filter(tag => !form.tag_ids.includes(tag.id));
    }
    return props.availableTags.filter(
        tag => !form.tag_ids.includes(tag.id) &&
            tag.name.toLowerCase().includes(tagSearchQuery.value.toLowerCase())
    );
});

const selectedTags = computed(() => {
    return props.availableTags.filter(tag => form.tag_ids.includes(tag.id));
});

function addTag(tagId: number) {
    if (!form.tag_ids.includes(tagId)) {
        form.tag_ids.push(tagId);
    }
    tagSearchQuery.value = '';
    showTagDropdown.value = false;
}

function removeTag(tagId: number) {
    const index = form.tag_ids.indexOf(tagId);
    if (index > -1) {
        form.tag_ids.splice(index, 1);
    }
}

// Sync has_variants with local state
watch(hasVariants, (newValue) => {
    form.has_variants = newValue;
    if (!newValue && form.variants.length > 1) {
        form.variants = [form.variants[0]];
        form.variants[0].option1_name = '';
        form.variants[0].option1_value = '';
        form.variants[0].option2_name = '';
        form.variants[0].option2_value = '';
        form.variants[0].option3_name = '';
        form.variants[0].option3_value = '';
    }
});

// Watch for category changes to load new template fields
watch(() => form.category_id, async (newCategoryId, oldCategoryId) => {
    if (newCategoryId === oldCategoryId) return;

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
        form.template_id = data.template?.id || '';

        const newAttributes: Record<number, string> = {};
        for (const field of templateFields.value) {
            newAttributes[field.id] = form.attributes[field.id] ?? field.default_value ?? '';
        }
        form.attributes = newAttributes;
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

// Image upload handling
const imageInputRef = ref<HTMLInputElement | null>(null);
const newImagePreviews = ref<string[]>([]);

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
            newImagePreviews.value.push(e.target?.result as string);
        };
        reader.readAsDataURL(file);
    }
}

function removeNewImage(index: number) {
    form.images.splice(index, 1);
    newImagePreviews.value.splice(index, 1);
}

function deleteExistingImage(imageId: number) {
    if (confirm('Are you sure you want to delete this image?')) {
        router.delete(`/products/${props.product.id}/images/${imageId}`, {
            preserveScroll: true,
        });
    }
}

function setPrimaryImage(imageId: number) {
    router.post(`/products/${props.product.id}/images/${imageId}/primary`, {}, {
        preserveScroll: true,
    });
}

// Computed values
const totalQuantity = computed(() => {
    return form.variants.reduce((sum, v) => sum + Number(v.quantity || 0), 0);
});

const seoTitleLength = computed(() => (form.seo_page_title || '').length);
const seoDescriptionLength = computed(() => (form.seo_description || '').length);

const hasMultipleVariants = computed(() => form.variants.length > 1);

const lowestPrice = computed(() => {
    if (form.variants.length === 0) return 0;
    return Math.min(...form.variants.map(v => Number(v.price) || 0));
});

const lowestCost = computed(() => {
    const costs = form.variants.map(v => Number(v.cost) || 0).filter(c => c > 0);
    return costs.length > 0 ? Math.min(...costs) : 0;
});

const margin = computed(() => {
    if (lowestPrice.value === 0 || lowestCost.value === 0) return 0;
    return ((lowestPrice.value - lowestCost.value) / lowestPrice.value * 100).toFixed(2);
});

const profit = computed(() => {
    return (lowestPrice.value - lowestCost.value).toFixed(2);
});

// Variant management
function addVariant() {
    if (!hasVariants.value) return;
    form.variants.push({
        sku: '',
        barcode: '',
        price: '',
        wholesale_price: '',
        cost: '',
        quantity: '0',
        weight: '',
        weight_unit: 'lb',
        option1_name: '',
        option1_value: '',
        option2_name: '',
        option2_value: '',
        option3_name: '',
        option3_value: '',
        is_active: true,
        warehouse_id: defaultWarehouseId.value,
    });
}

function removeVariant(index: number) {
    if (form.variants.length > 1) {
        form.variants.splice(index, 1);
    }
}

// Vendor is required validation
const vendorError = computed(() => {
    if (!form.vendor_id) {
        return 'Vendor is required';
    }
    return null;
});

function submit() {
    // Validate vendor is selected
    if (!form.vendor_id) {
        form.setError('vendor_id', 'Vendor is required');
        return;
    }

    form.put(`/products/${props.product.id}`, {
        forceFormData: true,
    });
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function deleteProduct() {
    if (confirm(`Are you sure you want to delete "${props.product.title}"?`)) {
        router.delete(`/products/${props.product.id}`);
    }
}
</script>

<template>
    <Head :title="`Edit ${product.title}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Header -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="`/products/${product.id}`"
                            class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                        >
                            <ArrowLeftIcon class="size-5" />
                        </Link>
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Edit Product</h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ product.handle }}
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-red-50 dark:bg-gray-800 dark:ring-gray-600 dark:hover:bg-red-900/20"
                            @click="deleteProduct"
                        >
                            <TrashIcon class="size-5" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                            @click="router.visit(`/products/${product.id}`)"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing || !form.vendor_id"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Product Information -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('productInfo')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Product Information</h3>
                                <ChevronDownIcon v-if="!sections.productInfo" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>
                            <div v-show="sections.productInfo" class="border-t border-gray-100 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Title</label>
                                        <input
                                            v-model="form.title"
                                            type="text"
                                            required
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                        <RichTextEditor
                                            v-model="form.description"
                                            placeholder="Enter product description..."
                                            class="mt-1"
                                        />
                                    </div>

                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">SKU</label>
                                            <input
                                                v-model="form.sku"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">UPC / Barcode</label>
                                            <input
                                                v-model="form.upc"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Vendor Section (Required for Custom Products) -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('vendor')"
                            >
                                <div class="flex items-center gap-2">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Vendor</h3>
                                    <span class="text-xs text-red-600 dark:text-red-400">Required</span>
                                </div>
                                <ChevronDownIcon v-if="!sections.vendor" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>
                            <div v-show="sections.vendor" class="border-t border-gray-100 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Select Vendor <span class="text-red-600">*</span>
                                    </label>
                                    <select
                                        v-model="form.vendor_id"
                                        required
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        :class="{ 'ring-red-500 focus:ring-red-500': form.errors.vendor_id || (!form.vendor_id && form.wasRecentlySuccessful === false) }"
                                    >
                                        <option value="">Select a vendor...</option>
                                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">
                                            {{ vendor.name }}
                                            <template v-if="vendor.company_name"> ({{ vendor.company_name }})</template>
                                        </option>
                                    </select>
                                    <p v-if="form.errors.vendor_id" class="mt-1 text-sm text-red-600">{{ form.errors.vendor_id }}</p>
                                    <p v-else-if="!form.vendor_id" class="mt-1 text-sm text-amber-600 dark:text-amber-400">
                                        Please select a vendor to continue
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Tags Section -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('tags')"
                            >
                                <div class="flex items-center gap-2">
                                    <TagIcon class="size-5 text-gray-400" />
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Tags</h3>
                                    <span v-if="selectedTags.length > 0" class="text-sm text-gray-500 dark:text-gray-400">
                                        ({{ selectedTags.length }})
                                    </span>
                                </div>
                                <ChevronDownIcon v-if="!sections.tags" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>
                            <div v-show="sections.tags" class="border-t border-gray-100 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <!-- Selected Tags -->
                                <div v-if="selectedTags.length > 0" class="flex flex-wrap gap-2 mb-4">
                                    <span
                                        v-for="tag in selectedTags"
                                        :key="tag.id"
                                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :style="{
                                            backgroundColor: tag.color + '20',
                                            color: tag.color,
                                            border: `1px solid ${tag.color}40`,
                                        }"
                                    >
                                        {{ tag.name }}
                                        <button
                                            type="button"
                                            class="hover:opacity-75"
                                            @click="removeTag(tag.id)"
                                        >
                                            <XMarkIcon class="size-3.5" />
                                        </button>
                                    </span>
                                </div>

                                <!-- Tag Search/Add -->
                                <div class="relative">
                                    <input
                                        v-model="tagSearchQuery"
                                        type="text"
                                        placeholder="Search or add tags..."
                                        class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        @focus="showTagDropdown = true"
                                        @blur="setTimeout(() => showTagDropdown = false, 200)"
                                    />

                                    <!-- Tags Dropdown -->
                                    <div
                                        v-if="showTagDropdown && filteredTags.length > 0"
                                        class="absolute z-10 mt-1 max-h-48 w-full overflow-auto rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 dark:bg-gray-700"
                                    >
                                        <button
                                            v-for="tag in filteredTags"
                                            :key="tag.id"
                                            type="button"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-600"
                                            @click="addTag(tag.id)"
                                        >
                                            <span
                                                class="size-3 rounded-full"
                                                :style="{ backgroundColor: tag.color }"
                                            ></span>
                                            <span class="text-gray-900 dark:text-white">{{ tag.name }}</span>
                                        </button>
                                    </div>

                                    <div
                                        v-else-if="showTagDropdown && tagSearchQuery && filteredTags.length === 0"
                                        class="absolute z-10 mt-1 w-full rounded-md bg-white py-2 px-3 shadow-lg ring-1 ring-black/5 dark:bg-gray-700"
                                    >
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            No matching tags found
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Media -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('media')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Media</h3>
                                <ChevronDownIcon v-if="!sections.media" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>
                            <div v-show="sections.media" class="border-t border-gray-100 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <!-- Existing Images -->
                                <div v-if="product.images.length > 0" class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Current Images</h4>
                                    <div class="flex flex-wrap gap-4">
                                        <div
                                            v-for="image in product.images"
                                            :key="image.id"
                                            class="relative group h-24 w-24 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700"
                                        >
                                            <img
                                                :src="image.url"
                                                :alt="image.alt || product.title"
                                                class="h-full w-full object-cover"
                                            />
                                            <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                                                <button
                                                    v-if="!image.is_primary"
                                                    type="button"
                                                    class="p-1 rounded bg-white text-gray-700 hover:bg-gray-100"
                                                    title="Set as primary"
                                                    @click="setPrimaryImage(image.id)"
                                                >
                                                    <StarIcon class="size-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="p-1 rounded bg-white text-red-600 hover:bg-red-50"
                                                    title="Delete"
                                                    @click="deleteExistingImage(image.id)"
                                                >
                                                    <TrashIcon class="size-4" />
                                                </button>
                                            </div>
                                            <span
                                                v-if="image.is_primary"
                                                class="absolute bottom-1 left-1 rounded bg-indigo-600 px-1 py-0.5 text-[10px] font-medium text-white"
                                            >
                                                Primary
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- New Images -->
                                <div v-if="newImagePreviews.length > 0" class="mb-4">
                                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">New Images</h4>
                                    <div class="flex flex-wrap gap-4">
                                        <div
                                            v-for="(preview, index) in newImagePreviews"
                                            :key="index"
                                            class="relative group h-24 w-24 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700"
                                        >
                                            <img :src="preview" class="h-full w-full object-cover" />
                                            <button
                                                type="button"
                                                class="absolute top-1 right-1 p-1 rounded-full bg-red-600 text-white opacity-0 group-hover:opacity-100 transition-opacity"
                                                @click="removeNewImage(index)"
                                            >
                                                <XMarkIcon class="size-3" />
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Upload Area -->
                                <div
                                    class="flex justify-center rounded-lg border border-dashed border-gray-300 px-6 py-10 dark:border-gray-600"
                                    @dragover.prevent
                                    @drop="handleImageDrop"
                                >
                                    <div class="text-center">
                                        <PhotoIcon class="mx-auto size-12 text-gray-300 dark:text-gray-600" />
                                        <div class="mt-4 flex text-sm/6 text-gray-600 dark:text-gray-400">
                                            <label class="relative cursor-pointer rounded-md font-semibold text-indigo-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-indigo-600 focus-within:ring-offset-2 hover:text-indigo-500 dark:text-indigo-400">
                                                <span>Upload files</span>
                                                <input
                                                    ref="imageInputRef"
                                                    type="file"
                                                    accept="image/*"
                                                    multiple
                                                    class="sr-only"
                                                    @change="handleImageSelect"
                                                />
                                            </label>
                                            <p class="pl-1">or drag and drop</p>
                                        </div>
                                        <p class="text-xs/5 text-gray-600 dark:text-gray-400">PNG, JPG, GIF up to 10MB</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('pricing')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Pricing</h3>
                                <ChevronDownIcon v-if="!sections.pricing" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>
                            <div v-show="sections.pricing" class="border-t border-gray-100 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-4">
                                    <div class="grid grid-cols-2 gap-4 mb-4" v-if="form.variants.length > 0">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Price</label>
                                            <div class="mt-1 relative">
                                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500">$</span>
                                                <input
                                                    v-model="form.variants[0].price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-md border-0 py-1.5 pl-7 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cost</label>
                                            <div class="mt-1 relative">
                                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500">$</span>
                                                <input
                                                    v-model="form.variants[0].cost"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-md border-0 py-1.5 pl-7 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Compare at Price</label>
                                            <div class="mt-1 relative">
                                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500">$</span>
                                                <input
                                                    v-model="form.compare_at_price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-md border-0 py-1.5 pl-7 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                        <div v-if="form.variants.length > 0">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Wholesale Price</label>
                                            <div class="mt-1 relative">
                                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500">$</span>
                                                <input
                                                    v-model="form.variants[0].wholesale_price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-md border-0 py-1.5 pl-7 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Profit Summary -->
                                    <div v-if="lowestPrice > 0" class="mt-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-700/50">
                                        <div class="grid grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Profit:</span>
                                                <span class="ml-2 font-medium text-gray-900 dark:text-white">
                                                    {{ formatCurrency(Number(profit)) }}
                                                </span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Margin:</span>
                                                <span class="ml-2 font-medium text-gray-900 dark:text-white">{{ margin }}%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inventory -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('inventory')"
                            >
                                <div class="flex items-center gap-2">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Inventory</h3>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">({{ totalQuantity }} total)</span>
                                </div>
                                <ChevronDownIcon v-if="!sections.inventory" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>
                            <div v-show="sections.inventory" class="border-t border-gray-100 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-4">
                                    <div class="flex items-center gap-6">
                                        <label class="flex items-center gap-2">
                                            <input
                                                v-model="form.track_quantity"
                                                type="checkbox"
                                                class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                            />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Track quantity</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input
                                                v-model="form.sell_out_of_stock"
                                                type="checkbox"
                                                class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                            />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Continue selling when out of stock</span>
                                        </label>
                                    </div>

                                    <div v-if="form.variants.length > 0" class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Quantity</label>
                                            <input
                                                v-model="form.variants[0].quantity"
                                                type="number"
                                                min="0"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Warehouse</label>
                                            <select
                                                v-model="form.variants[0].warehouse_id"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option value="">Select warehouse...</option>
                                                <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                                    {{ warehouse.name }}
                                                    <template v-if="warehouse.code">({{ warehouse.code }})</template>
                                                </option>
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
                                    <label class="flex items-center gap-2">
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
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                        <CategorySelector
                                            v-model="form.category_id"
                                            :categories="categories"
                                            class="mt-1"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Dates</h3>
                                <dl class="space-y-3 text-sm">
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">Created</dt>
                                        <dd class="font-medium text-gray-900 dark:text-white">
                                            {{ formatDate(product.created_at) }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">Last Updated</dt>
                                        <dd class="font-medium text-gray-900 dark:text-white">
                                            {{ formatDate(product.updated_at) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
