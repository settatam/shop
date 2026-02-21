<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, Link } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import {
    ChevronDownIcon,
    ChevronUpIcon,
    PlusIcon,
    TrashIcon,
    PhotoIcon,
    ArrowLeftIcon,
    XMarkIcon,
    StarIcon,
    PrinterIcon,
    SparklesIcon,
} from '@heroicons/vue/20/solid';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import CategorySelector from '@/components/products/CategorySelector.vue';
import TagInput from '@/components/tags/TagInput.vue';

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level: number;
    template_id: number | null;
    charge_taxes: boolean;
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
    code: string;
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
    status: string;
    is_published: boolean;
    is_draft: boolean;
    has_variants: boolean;
    track_quantity: boolean;
    sell_out_of_stock: boolean;
    charge_taxes: boolean;
    price_code: string | null;
    category_id: number | null;
    vendor_id: number | null;
    template_id: number | null;
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
    tag_ids: number[];
    variants: Variant[];
    images: Image[];
}

interface OrderActivity {
    id: number;
    title: string;
    status: string;
    date: string;
    price: number;
}

interface MemoActivity {
    id: number;
    title: string;
    status: 'on_memo' | 'returned';
    date: string;
    due_date: string | null;
    price: number;
}

interface RepairActivity {
    id: number;
    title: string;
    status: string;
    date: string;
    price: number;
}

interface Activity {
    orders: OrderActivity[];
    memos: MemoActivity[];
    repairs: RepairActivity[];
}

interface FieldRequirement {
    required?: boolean;
    label?: string;
    message?: string;
}

interface Props {
    product: Product;
    categories: Category[];
    brands: Brand[];
    vendors: Vendor[];
    warehouses: Warehouse[];
    variantInventory: Record<number, { warehouse_id: number | null; quantity: number }>;
    template: Template | null;
    templateFields: TemplateField[];
    templateBrands: TemplateBrand[];
    attributeValues: Record<number, string>;
    availableTags: Tag[];
    availableStatuses: Record<string, string>;
    fieldRequirements: Record<string, FieldRequirement>;
    activity: Activity;
}

const props = defineProps<Props>();

// Helper to check if a field is required based on edition
function isFieldRequired(field: string): boolean {
    return props.fieldRequirements?.[field]?.required ?? false;
}

// Helper to get field requirement message
function getFieldRequirementMessage(field: string): string | undefined {
    return props.fieldRequirements?.[field]?.message;
}

// Track whether product has variants (multiple options like size, color)
const hasVariants = ref(props.product.has_variants);

// Template state (can be updated when category changes)
const template = ref<Template | null>(props.template);
const templateFields = ref<TemplateField[]>(props.templateFields);
const templateBrands = ref<TemplateBrand[]>(props.templateBrands || []);
const loadingTemplate = ref(false);

// Expose warehouses for template
const warehouses = computed(() => props.warehouses);

// Get default warehouse ID
const defaultWarehouseId = computed(() => {
    const defaultWarehouse = props.warehouses.find(w => w.is_default);
    return defaultWarehouse?.id ?? props.warehouses[0]?.id ?? '';
});

// Group template fields by group_name for rendering as field sets
const groupedTemplateFields = computed(() => {
    const groups: Record<string, TemplateField[]> = {};
    const standalone: TemplateField[] = [];

    for (const field of templateFields.value) {
        if (field.group_name) {
            if (!groups[field.group_name]) {
                groups[field.group_name] = [];
            }
            groups[field.group_name].push(field);
            // Sort by group_position within each group
            groups[field.group_name].sort((a, b) => a.group_position - b.group_position);
        } else {
            standalone.push(field);
        }
    }

    return { groups, standalone };
});

// Activity
const activity = computed(() => props.activity);
const hasActivity = computed(() => {
    return activity.value.orders.length > 0 ||
           activity.value.memos.length > 0 ||
           activity.value.repairs.length > 0;
});

function getOrderStatusClass(status: string): string {
    switch (status) {
        case 'confirmed':
        case 'completed':
            return 'text-green-600 dark:text-green-400';
        case 'pending':
            return 'text-yellow-600 dark:text-yellow-400';
        case 'cancelled':
            return 'text-red-600 dark:text-red-400';
        default:
            return 'text-gray-600 dark:text-gray-400';
    }
}

function formatOrderStatus(status: string): string {
    return status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
}

function getRepairStatusClass(status: string): string {
    switch (status) {
        case 'completed':
            return 'text-green-600 dark:text-green-400';
        case 'in_progress':
            return 'text-blue-600 dark:text-blue-400';
        case 'pending':
            return 'text-yellow-600 dark:text-yellow-400';
        default:
            return 'text-gray-600 dark:text-gray-400';
    }
}

function formatRepairStatus(status: string): string {
    return status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: props.product.title, href: `/products/${props.product.id}` },
    { title: 'Edit', href: `/products/${props.product.id}/edit` },
];

// Collapsible sections state
const sections = ref({
    productInfo: true,
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

// Form
const form = useForm({
    title: props.product.title,
    description: props.product.description || '',
    handle: props.product.handle,
    sku: props.product.sku || '',
    upc: props.product.upc || '',
    category_id: props.product.category_id || '',
    vendor_id: props.product.vendor_id || '',
    template_id: props.product.template_id || '',
    status: props.product.status || 'draft',
    is_published: props.product.is_published,
    has_variants: props.product.has_variants,
    track_quantity: props.product.track_quantity,
    sell_out_of_stock: props.product.sell_out_of_stock,
    charge_taxes: props.product.charge_taxes,
    price_code: props.product.price_code || '',
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
    tag_ids: props.product.tag_ids || [],
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

// Tag management - writable computed for TagInput component
const selectedTags = computed({
    get: () => {
        return (props.availableTags || []).filter(tag => form.tag_ids.includes(tag.id));
    },
    set: (tags: Tag[]) => {
        form.tag_ids = tags.map(tag => tag.id);
    },
});

// AI Generation state
const generatingTitle = ref(false);
const generatingDescription = ref(false);
const aiError = ref<string | null>(null);

// Generate title with AI
async function generateTitle() {
    if (generatingTitle.value) return;

    generatingTitle.value = true;
    aiError.value = null;

    try {
        const response = await fetch(`/products/${props.product.id}/generate-title`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });

        if (!response.ok) {
            throw new Error('Failed to generate title');
        }

        const reader = response.body?.getReader();
        const decoder = new TextDecoder();

        if (!reader) {
            throw new Error('Stream not available');
        }

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const text = decoder.decode(value);
            const lines = text.split('\n');

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    const data = JSON.parse(line.slice(6));
                    if (data.type === 'complete') {
                        form.title = data.content;
                    } else if (data.type === 'error') {
                        aiError.value = data.message;
                    }
                }
            }
        }
    } catch (error: any) {
        aiError.value = error.message || 'Failed to generate title';
    } finally {
        generatingTitle.value = false;
    }
}

// Generate description with AI
async function generateDescription() {
    if (generatingDescription.value) return;

    generatingDescription.value = true;
    aiError.value = null;

    try {
        const response = await fetch(`/products/${props.product.id}/generate-description`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                tone: 'professional',
                length: 'medium',
            }),
        });

        if (!response.ok) {
            throw new Error('Failed to generate description');
        }

        const reader = response.body?.getReader();
        const decoder = new TextDecoder();

        if (!reader) {
            throw new Error('Stream not available');
        }

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const text = decoder.decode(value);
            const lines = text.split('\n');

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    const data = JSON.parse(line.slice(6));
                    if (data.type === 'complete') {
                        form.description = data.content;
                    } else if (data.type === 'error') {
                        aiError.value = data.message;
                    }
                }
            }
        }
    } catch (error: any) {
        aiError.value = error.message || 'Failed to generate description';
    } finally {
        generatingDescription.value = false;
    }
}

// Sync has_variants with local state
watch(hasVariants, (newValue) => {
    form.has_variants = newValue;
    // If switching to no variants mode, keep only first variant and clear options
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

// Watch for category changes to load new template fields and inherit charge_taxes
watch(() => form.category_id, async (newCategoryId, oldCategoryId) => {
    // Skip if category hasn't actually changed
    if (newCategoryId === oldCategoryId) return;

    if (!newCategoryId) {
        template.value = null;
        templateFields.value = [];
        templateBrands.value = [];
        form.template_id = '';
        form.attributes = {};
        return;
    }

    // Inherit charge_taxes from the selected category
    const selectedCategory = props.categories.find(c => c.id === Number(newCategoryId));
    if (selectedCategory) {
        form.charge_taxes = selectedCategory.charge_taxes;
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

        // Initialize attributes with default values (preserve existing values if field exists)
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

// Pricing calculations
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

function submit() {
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
                        <Link
                            :href="`/products/${product.id}/print-barcode`"
                            class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                        >
                            <PrinterIcon class="-ml-0.5 size-5" />
                            Print Barcode
                        </Link>
                        <button
                            type="button"
                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                            @click="router.visit(`/products/${product.id}`)"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>
                </div>

                <!-- AI Error Alert -->
                <div v-if="aiError" class="mb-4 rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="size-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-red-800 dark:text-red-200">{{ aiError }}</p>
                        </div>
                        <div class="ml-auto pl-3">
                            <button type="button" class="text-red-500 hover:text-red-600" @click="aiError = null">
                                <XMarkIcon class="size-5" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Product Information Section (Top) -->
                <div class="mb-6">
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Product Information</h3>
                            <div class="space-y-4">
                                <!-- Category -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Category <span v-if="isFieldRequired('category_id')" class="text-red-500">*</span>
                                    </label>
                                    <CategorySelector
                                        v-model="form.category_id"
                                        :categories="categories"
                                    />
                                    <p v-if="form.errors.category_id" class="mt-1 text-sm text-red-600">{{ form.errors.category_id }}</p>
                                </div>

                                <!-- Title -->
                                <div>
                                    <div class="flex items-center justify-between">
                                        <label for="title_top" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Title <span class="text-red-500">*</span>
                                        </label>
                                        <button
                                            type="button"
                                            :disabled="generatingTitle"
                                            class="inline-flex items-center gap-1 rounded-md bg-gradient-to-r from-purple-600 to-indigo-600 px-2 py-1 text-xs font-medium text-white shadow-sm hover:from-purple-500 hover:to-indigo-500 disabled:opacity-50"
                                            @click="generateTitle"
                                        >
                                            <SparklesIcon class="size-3" :class="{ 'animate-pulse': generatingTitle }" />
                                            {{ generatingTitle ? 'Generating...' : 'Generate with AI' }}
                                        </button>
                                    </div>
                                    <input
                                        id="title_top"
                                        v-model="form.title"
                                        type="text"
                                        required
                                        :disabled="generatingTitle"
                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 disabled:bg-gray-100 disabled:text-gray-500 dark:disabled:bg-gray-600"
                                    />
                                    <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                                </div>

                                <!-- SKU and Barcode -->
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="sku_top" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            SKU
                                        </label>
                                        <input
                                            id="sku_top"
                                            v-model="form.variants[0].sku"
                                            type="text"
                                            :disabled="hasVariants"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 disabled:bg-gray-100 disabled:text-gray-500 dark:disabled:bg-gray-600"
                                        />
                                    </div>
                                    <div>
                                        <label for="barcode_top" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Barcode
                                        </label>
                                        <input
                                            id="barcode_top"
                                            v-model="form.variants[0].barcode"
                                            type="text"
                                            :disabled="hasVariants"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 disabled:bg-gray-100 disabled:text-gray-500 dark:disabled:bg-gray-600"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Product Template Section -->
                        <div v-if="template && templateFields.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('attributes')"
                            >
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Product Template</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ template.name }}</p>
                                </div>
                                <ChevronDownIcon v-if="!sections.attributes" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.attributes" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-6">
                                    <!-- Grouped Fields (Field Sets) -->
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div v-for="(fields, groupName) in groupedTemplateFields.groups" :key="groupName" class="flex gap-2">
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

                                                <!-- Text Input -->
                                                <input
                                                    v-if="field.type === 'text'"
                                                    :id="`attr_${field.id}`"
                                                    v-model="form.attributes[field.id]"
                                                    type="text"
                                                    :placeholder="field.placeholder || ''"
                                                    :required="field.is_required"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />

                                                <!-- Number Input -->
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

                                                <!-- Select -->
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

                                                <!-- Brand Select -->
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

                                                <!-- Date Input -->
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

                                    <!-- Standalone Fields (not in a group) -->
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

                                            <!-- Text Input -->
                                            <input
                                                v-if="field.type === 'text'"
                                                :id="`attr_${field.id}`"
                                                v-model="form.attributes[field.id]"
                                                type="text"
                                                :placeholder="field.placeholder || ''"
                                                :required="field.is_required"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />

                                            <!-- Number Input -->
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

                                            <!-- Textarea -->
                                            <textarea
                                                v-else-if="field.type === 'textarea'"
                                                :id="`attr_${field.id}`"
                                                v-model="form.attributes[field.id]"
                                                :placeholder="field.placeholder || ''"
                                                :required="field.is_required"
                                                rows="3"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />

                                            <!-- Select -->
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

                                            <!-- Brand Select -->
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

                                            <!-- Checkbox -->
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

                                            <!-- Radio -->
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

                                            <!-- Date Input -->
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

                        <!-- Media Section -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('media')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Images</h3>
                                <ChevronDownIcon v-if="!sections.media" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.media" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <!-- Existing images -->
                                <div v-if="product.images.length > 0" class="mb-4">
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Current Images</p>
                                    <div class="flex flex-wrap gap-4">
                                        <div
                                            v-for="image in product.images"
                                            :key="image.id"
                                            class="group relative h-24 w-24 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200 dark:bg-gray-700 dark:ring-gray-600"
                                        >
                                            <img
                                                :src="image.url"
                                                :alt="image.alt || product.title"
                                                class="h-full w-full object-cover"
                                            />
                                            <!-- Actions overlay -->
                                            <div class="absolute inset-0 flex items-center justify-center gap-1 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <button
                                                    v-if="!image.is_primary"
                                                    type="button"
                                                    class="rounded-full bg-white p-1.5 text-yellow-600 hover:bg-yellow-50"
                                                    title="Set as primary"
                                                    @click="setPrimaryImage(image.id)"
                                                >
                                                    <StarIcon class="size-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-full bg-white p-1.5 text-red-600 hover:bg-red-50"
                                                    title="Delete image"
                                                    @click="deleteExistingImage(image.id)"
                                                >
                                                    <TrashIcon class="size-4" />
                                                </button>
                                            </div>
                                            <span
                                                v-if="image.is_primary"
                                                class="absolute bottom-0 left-0 right-0 bg-indigo-600 px-1 py-0.5 text-center text-xs font-medium text-white"
                                            >
                                                Primary
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- New image previews -->
                                <div v-if="newImagePreviews.length > 0" class="mb-4">
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">New Images (will be uploaded on save)</p>
                                    <div class="flex flex-wrap gap-4">
                                        <div
                                            v-for="(preview, index) in newImagePreviews"
                                            :key="index"
                                            class="relative h-24 w-24 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200 ring-dashed dark:bg-gray-700 dark:ring-gray-600"
                                        >
                                            <img
                                                :src="preview"
                                                class="h-full w-full object-cover"
                                            />
                                            <button
                                                type="button"
                                                class="absolute right-1 top-1 rounded-full bg-red-600 p-0.5 text-white hover:bg-red-700"
                                                @click="removeNewImage(index)"
                                            >
                                                <XMarkIcon class="size-4" />
                                            </button>
                                        </div>
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

                        <!-- Pricing Section -->
                        <div v-if="!hasVariants" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('pricing')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Pricing</h3>
                                <ChevronDownIcon v-if="!sections.pricing" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.pricing" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <!-- Row 1: Cost, Wholesale -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Cost
                                        </label>
                                        <div class="mt-1 flex rounded-md shadow-sm">
                                            <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
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
                                            Wholesale - Estimated Value
                                        </label>
                                        <div class="mt-1 flex rounded-md shadow-sm">
                                            <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
                                            <input
                                                v-model="form.variants[0].wholesale_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="block w-full rounded-none rounded-r-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                    </div>

                                    <!-- Row 2: Selling Price, Approx. Retail Price -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Selling Price
                                        </label>
                                        <div class="mt-1 flex rounded-md shadow-sm">
                                            <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
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
                                        <label for="compare_at_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Approx. Retail Price
                                        </label>
                                        <div class="mt-1 flex rounded-md shadow-sm">
                                            <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">$</span>
                                            <input
                                                id="compare_at_price"
                                                v-model="form.compare_at_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="block w-full rounded-none rounded-r-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                    </div>

                                    <!-- Row 3: Price Code -->
                                    <div class="sm:col-span-2">
                                        <label for="price_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Price Code
                                        </label>
                                        <input
                                            id="price_code"
                                            v-model="form.price_code"
                                            type="text"
                                            placeholder="e.g. NWERIU-WE"
                                            class="mt-1 block w-full sm:w-1/2 rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <!-- Charge Taxes Checkbox -->
                                <div class="mt-5 pt-5 border-t border-gray-200 dark:border-gray-700">
                                    <label class="relative flex items-center gap-3 cursor-pointer">
                                        <input
                                            v-model="form.charge_taxes"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Charge tax for this product</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Description Section -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('productInfo')"
                            >
                                <div class="flex items-center gap-3">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Description</h3>
                                    <button
                                        type="button"
                                        :disabled="generatingDescription"
                                        class="inline-flex items-center gap-1 rounded-md bg-gradient-to-r from-purple-600 to-indigo-600 px-2 py-1 text-xs font-medium text-white shadow-sm hover:from-purple-500 hover:to-indigo-500 disabled:opacity-50"
                                        @click.stop="generateDescription"
                                    >
                                        <SparklesIcon class="size-3" :class="{ 'animate-pulse': generatingDescription }" />
                                        {{ generatingDescription ? 'Generating...' : 'Generate with AI' }}
                                    </button>
                                </div>
                                <ChevronDownIcon v-if="!sections.productInfo" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.productInfo" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-4">
                                    <!-- Description -->
                                    <div>
                                        <RichTextEditor
                                            v-model="form.description"
                                            placeholder="Enter product description..."
                                            :disabled="generatingDescription"
                                        />
                                        <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inventory Section (for single products without variants) -->
                        <div v-if="!hasVariants" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('inventory')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Inventory</h3>
                                <ChevronDownIcon v-if="!sections.inventory" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.inventory" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-4">
                                    <!-- Warehouse and Quantity per line -->
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Quantity
                                            </label>
                                            <input
                                                v-model="form.variants[0].quantity"
                                                type="number"
                                                min="0"
                                                required
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                    </div>

                                    <!-- Total Quantity -->
                                    <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Total Quantity</span>
                                        <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ totalQuantity }}</span>
                                    </div>

                                    <!-- Has Variants Toggle -->
                                    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                                        <label class="relative flex items-center gap-3 cursor-pointer">
                                            <input
                                                v-model="hasVariants"
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                            />
                                            <div>
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">This product has variants</span>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">Enable if this product comes in different sizes, colors, or options</p>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Variants Section (only shown when hasVariants is true) -->
                        <div v-if="hasVariants" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('variants')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Variants</h3>
                                <ChevronDownIcon v-if="!sections.variants" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.variants" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <!-- Variants list -->
                                    <div class="mb-4 flex justify-end">
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
                                            <div class="flex items-center justify-between mb-4">
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    Variant {{ index + 1 }}
                                                    <span v-if="variant.option1_value" class="text-gray-500">
                                                        - {{ variant.option1_value }}
                                                        <template v-if="variant.option2_value"> / {{ variant.option2_value }}</template>
                                                        <template v-if="variant.option3_value"> / {{ variant.option3_value }}</template>
                                                    </span>
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

                                            <!-- Options Row -->
                                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-6 mb-4">
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Option 1 Name</label>
                                                    <input
                                                        v-model="variant.option1_name"
                                                        type="text"
                                                        placeholder="e.g. Size"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Option 1 Value</label>
                                                    <input
                                                        v-model="variant.option1_value"
                                                        type="text"
                                                        placeholder="e.g. Medium"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Option 2 Name</label>
                                                    <input
                                                        v-model="variant.option2_name"
                                                        type="text"
                                                        placeholder="e.g. Color"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Option 2 Value</label>
                                                    <input
                                                        v-model="variant.option2_value"
                                                        type="text"
                                                        placeholder="e.g. Red"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Option 3 Name</label>
                                                    <input
                                                        v-model="variant.option3_name"
                                                        type="text"
                                                        placeholder="e.g. Material"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Option 3 Value</label>
                                                    <input
                                                        v-model="variant.option3_value"
                                                        type="text"
                                                        placeholder="e.g. Cotton"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>

                                            <!-- Pricing Row -->
                                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-8">
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                                        SKU <span class="text-red-500">*</span>
                                                    </label>
                                                    <input
                                                        v-model="variant.sku"
                                                        type="text"
                                                        required
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Barcode</label>
                                                    <input
                                                        v-model="variant.barcode"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                                        Price <span class="text-red-500">*</span>
                                                    </label>
                                                    <input
                                                        v-model="variant.price"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        required
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Wholesale</label>
                                                    <input
                                                        v-model="variant.wholesale_price"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Cost</label>
                                                    <input
                                                        v-model="variant.cost"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                                        Quantity <span class="text-red-500">*</span>
                                                    </label>
                                                    <input
                                                        v-model="variant.quantity"
                                                        type="number"
                                                        min="0"
                                                        required
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Warehouse</label>
                                                    <select
                                                        v-model="variant.warehouse_id"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    >
                                                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                                                            {{ warehouse.name }}{{ warehouse.is_default ? ' (Default)' : '' }}
                                                        </option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Weight</label>
                                                    <input
                                                        v-model="variant.weight"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                <p v-if="form.errors.variants" class="mt-2 text-sm text-red-600">{{ form.errors.variants }}</p>
                            </div>
                        </div>

                        <!-- Shipping Section -->
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
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                                    <div>
                                        <label for="weight" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Weight
                                        </label>
                                        <div class="mt-1 flex rounded-md shadow-sm">
                                            <input
                                                id="weight"
                                                v-model="form.weight"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="block w-full rounded-none rounded-l-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <select
                                                v-model="form.weight_unit"
                                                class="rounded-none rounded-r-md border-0 bg-gray-50 py-1.5 text-gray-500 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-gray-400 dark:ring-gray-600"
                                            >
                                                <option value="lb">lb</option>
                                                <option value="oz">oz</option>
                                                <option value="kg">kg</option>
                                                <option value="g">g</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="length" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Length
                                        </label>
                                        <input
                                            id="length"
                                            v-model="form.length"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="width" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Width
                                        </label>
                                        <input
                                            id="width"
                                            v-model="form.width"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="height" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Height
                                        </label>
                                        <input
                                            id="height"
                                            v-model="form.height"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SEO Section -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-4 py-4 sm:px-6"
                                @click="toggleSection('seo')"
                            >
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Search Engine Listing</h3>
                                <ChevronDownIcon v-if="!sections.seo" class="size-5 text-gray-400" />
                                <ChevronUpIcon v-else class="size-5 text-gray-400" />
                            </button>

                            <div v-show="sections.seo" class="border-t border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
                                <div class="space-y-4">
                                    <div>
                                        <label for="seo_page_title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Page Title
                                        </label>
                                        <input
                                            id="seo_page_title"
                                            v-model="form.seo_page_title"
                                            type="text"
                                            maxlength="70"
                                            :placeholder="form.title"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ seoTitleLength }} / 70 characters
                                        </p>
                                    </div>

                                    <div>
                                        <label for="seo_description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Meta Description
                                        </label>
                                        <textarea
                                            id="seo_description"
                                            v-model="form.seo_description"
                                            rows="3"
                                            maxlength="320"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ seoDescriptionLength }} / 320 characters
                                        </p>
                                    </div>

                                    <div>
                                        <label for="handle" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            URL Handle
                                        </label>
                                        <div class="mt-1 flex rounded-md shadow-sm">
                                            <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-gray-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                                /products/
                                            </span>
                                            <input
                                                id="handle"
                                                v-model="form.handle"
                                                type="text"
                                                class="block w-full rounded-none rounded-r-md border-0 bg-white px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <p v-if="form.errors.handle" class="mt-1 text-sm text-red-600">{{ form.errors.handle }}</p>
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

                                <select
                                    v-model="form.status"
                                    class="block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option
                                        v-for="(label, value) in props.availableStatuses"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ label }}
                                    </option>
                                </select>

                                <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                            form.status === 'active' ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20' :
                                            form.status === 'draft' ? 'bg-yellow-50 text-yellow-800 ring-1 ring-inset ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20' :
                                            form.status === 'sold' ? 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20' :
                                            form.status === 'in_memo' ? 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-600/20 dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/20' :
                                            form.status === 'in_repair' ? 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20' :
                                            form.status === 'in_bucket' ? 'bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-600/20 dark:bg-cyan-500/10 dark:text-cyan-400 dark:ring-cyan-500/20' :
                                            form.status === 'archive' ? 'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20' :
                                            'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
                                        ]"
                                    >
                                        {{ props.availableStatuses[form.status] || form.status }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Vendor -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Vendor</h3>

                                <div>
                                    <label for="vendor_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Vendor
                                    </label>
                                    <select
                                        id="vendor_id"
                                        v-model="form.vendor_id"
                                        class="block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option value="">Select a vendor...</option>
                                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">
                                            {{ vendor.name }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.vendor_id" class="mt-1 text-sm text-red-600">{{ form.errors.vendor_id }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Tags -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Tags</h3>
                                <TagInput
                                    v-model="selectedTags"
                                    placeholder="Search or create tags..."
                                />
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Information</h3>
                                <dl class="space-y-3">
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Created</dt>
                                        <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ formatDate(product.created_at) }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Last Updated</dt>
                                        <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ formatDate(product.updated_at) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Activity -->
                        <div v-if="hasActivity" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Activity</h3>
                                <div class="space-y-4">
                                    <!-- Orders/Sales -->
                                    <div v-if="activity.orders.length > 0">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sales</h4>
                                        <ul class="space-y-2">
                                            <li v-for="order in activity.orders" :key="'order-' + order.id" class="text-sm">
                                                <Link
                                                    :href="`/orders/${order.id}`"
                                                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                >
                                                    {{ order.title }}
                                                </Link>
                                                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                                                    <span>{{ order.date }}</span>
                                                    <span :class="getOrderStatusClass(order.status)">{{ formatOrderStatus(order.status) }}</span>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>

                                    <!-- Memos -->
                                    <div v-if="activity.memos.length > 0">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Memos</h4>
                                        <ul class="space-y-2">
                                            <li v-for="memo in activity.memos" :key="'memo-' + memo.id" class="text-sm">
                                                <Link
                                                    :href="`/memos/${memo.id}`"
                                                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                >
                                                    {{ memo.title }}
                                                </Link>
                                                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                                                    <span>{{ memo.date }}</span>
                                                    <span :class="memo.status === 'returned' ? 'text-green-600 dark:text-green-400' : 'text-yellow-600 dark:text-yellow-400'">
                                                        {{ memo.status === 'returned' ? 'Returned' : 'On Memo' }}
                                                    </span>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>

                                    <!-- Repairs -->
                                    <div v-if="activity.repairs.length > 0">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Repairs</h4>
                                        <ul class="space-y-2">
                                            <li v-for="repair in activity.repairs" :key="'repair-' + repair.id" class="text-sm">
                                                <Link
                                                    :href="`/repairs/${repair.id}`"
                                                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                >
                                                    {{ repair.title }}
                                                </Link>
                                                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                                                    <span>{{ repair.date }}</span>
                                                    <span :class="getRepairStatusClass(repair.status)">{{ formatRepairStatus(repair.status) }}</span>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
