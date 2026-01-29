<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
    CameraIcon,
    DocumentMagnifyingGlassIcon,
    ArrowUpTrayIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    MagnifyingGlassIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

interface Category {
    id: number;
    name: string;
}

interface Brand {
    id: number;
    name: string;
}

interface Warehouse {
    id: number;
    name: string;
    code: string | null;
    is_default: boolean;
}

interface ExtractedData {
    certificate_number: string | null;
    issue_date: string | null;
    shape: string | null;
    carat_weight: string | null;
    color_grade: string | null;
    clarity_grade: string | null;
    cut_grade: string | null;
    polish: string | null;
    symmetry: string | null;
    fluorescence: string | null;
    measurements: { length?: number; width?: number; depth?: number } | null;
    inscription: string | null;
    comments: string | null;
}

interface SearchResult {
    id: number;
    title: string;
    variants?: { id: number; sku: string; price: number }[];
    primary_image?: { url: string } | null;
}

interface TemplateFieldOption {
    label: string;
    value: string;
}

interface TemplateField {
    id: number;
    name: string;
    canonical_name: string | null;
    label: string;
    type: string;
    placeholder: string | null;
    help_text: string | null;
    default_value: string | null;
    is_required: boolean;
    group_name: string | null;
    group_position: number | null;
    width_class: string | null;
    options: TemplateFieldOption[];
}

// GIA field to canonical name mapping
const GIA_TO_CANONICAL_MAPPING: Record<string, string> = {
    certificate_number: 'certificate_number',
    carat_weight: 'total_carat_weight',
    measurements: 'measurements',
    color_grade: 'color_grade',
    clarity_grade: 'clarity_grade',
    cut_grade: 'cut_grade',
    polish: 'polish',
    symmetry: 'symmetry',
    fluorescence: 'fluorescence',
    shape: 'gemstone_shape',
    inscription: 'inscription',
    comments: 'certificate_comments',
};

interface Props {
    open: boolean;
    categories: Category[];
    brands: Brand[];
    warehouses: Warehouse[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    success: [productId: number];
}>();

// Component state
const step = ref<'upload' | 'review' | 'configure'>('upload');
const processing = ref(false);
const error = ref<string | null>(null);
const extractedData = ref<ExtractedData | null>(null);
const imagePath = ref<string | null>(null);
const duplicateWarning = ref(false);
const mode = ref<'new' | 'existing'>('new');

// File handling
const selectedFile = ref<File | null>(null);
const previewUrl = ref<string | null>(null);
const isDragging = ref(false);

// Form data for new product
const productForm = ref({
    title: '',
    description: '',
    category_id: null as number | null,
    brand_id: null as number | null,
});

const variantForm = ref({
    sku: '',
    price: '',
    cost: '',
    quantity: '1',
    warehouse_id: null as number | null,
});

// Existing product search
const searchQuery = ref('');
const searchResults = ref<SearchResult[]>([]);
const selectedProduct = ref<SearchResult | null>(null);
const searching = ref(false);

// Template fields
const templateFields = ref<TemplateField[]>([]);
const templateFieldValues = ref<Record<number, string>>({});
const loadingTemplateFields = ref(false);

// Default warehouse
const defaultWarehouse = computed(() => {
    return props.warehouses.find(w => w.is_default) || props.warehouses[0] || null;
});

// Generate suggested title from extracted data
const suggestedTitle = computed(() => {
    if (!extractedData.value) return '';
    const parts = [];
    if (extractedData.value.carat_weight) parts.push(`${extractedData.value.carat_weight}ct`);
    if (extractedData.value.shape) parts.push(extractedData.value.shape);
    if (extractedData.value.color_grade && extractedData.value.clarity_grade) {
        parts.push(`${extractedData.value.color_grade}/${extractedData.value.clarity_grade}`);
    }
    parts.push('Diamond');
    if (extractedData.value.certificate_number) {
        parts.push(`GIA ${extractedData.value.certificate_number}`);
    }
    return parts.join(' ');
});

// Generate suggested SKU
const suggestedSku = computed(() => {
    if (!extractedData.value?.certificate_number) return '';
    return `GIA-${extractedData.value.certificate_number}`;
});

// Watch for open changes to reset state
watch(() => props.open, (isOpen) => {
    if (isOpen) {
        resetForm();
    }
});

function resetForm() {
    step.value = 'upload';
    processing.value = false;
    error.value = null;
    extractedData.value = null;
    imagePath.value = null;
    duplicateWarning.value = false;
    mode.value = 'new';
    selectedFile.value = null;
    previewUrl.value = null;
    productForm.value = { title: '', description: '', category_id: null, brand_id: null };
    variantForm.value = {
        sku: '',
        price: '',
        cost: '',
        quantity: '1',
        warehouse_id: defaultWarehouse.value?.id || null,
    };
    searchQuery.value = '';
    searchResults.value = [];
    selectedProduct.value = null;
    templateFields.value = [];
    templateFieldValues.value = {};
}

// Watch category changes to fetch template fields
watch(() => productForm.value.category_id, async (categoryId) => {
    if (!categoryId) {
        templateFields.value = [];
        templateFieldValues.value = {};
        return;
    }

    await fetchTemplateFields(categoryId);
});

// Fetch template fields for the selected category
async function fetchTemplateFields(categoryId: number) {
    loadingTemplateFields.value = true;
    try {
        const response = await axios.get(`/categories/${categoryId}/template-fields`);
        templateFields.value = response.data.fields || [];

        // Auto-populate template fields from GIA data
        if (extractedData.value && templateFields.value.length > 0) {
            populateTemplateFieldsFromGia();
        }
    } catch {
        templateFields.value = [];
    } finally {
        loadingTemplateFields.value = false;
    }
}

// Populate template fields based on GIA data and canonical name mapping
function populateTemplateFieldsFromGia() {
    if (!extractedData.value) return;

    const newValues: Record<number, string> = {};

    // Build canonical_name to field_id lookup
    const canonicalToFieldId: Record<string, number> = {};
    for (const field of templateFields.value) {
        if (field.canonical_name) {
            canonicalToFieldId[field.canonical_name] = field.id;
        }
    }

    // Map GIA data to template fields
    for (const [giaField, canonicalName] of Object.entries(GIA_TO_CANONICAL_MAPPING)) {
        const fieldId = canonicalToFieldId[canonicalName];
        if (!fieldId) continue;

        const value = extractedData.value[giaField as keyof ExtractedData];
        if (value === null || value === undefined) continue;

        // Handle special cases
        if (giaField === 'measurements' && typeof value === 'object') {
            const m = value as { length?: number; width?: number; depth?: number };
            newValues[fieldId] = `${m.length ?? 0} x ${m.width ?? 0} x ${m.depth ?? 0} mm`;
        } else {
            newValues[fieldId] = String(value);
        }
    }

    // Also set gemstone_type to "diamond" if the field exists
    if (canonicalToFieldId['gemstone_type']) {
        newValues[canonicalToFieldId['gemstone_type']] = 'diamond';
    }

    templateFieldValues.value = { ...templateFieldValues.value, ...newValues };
}

function handleClose() {
    emit('update:open', false);
}

// File selection
function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        setFile(input.files[0]);
    }
}

function handleDrop(event: DragEvent) {
    event.preventDefault();
    isDragging.value = false;
    if (event.dataTransfer?.files && event.dataTransfer.files[0]) {
        setFile(event.dataTransfer.files[0]);
    }
}

function handleDragOver(event: DragEvent) {
    event.preventDefault();
    isDragging.value = true;
}

function handleDragLeave() {
    isDragging.value = false;
}

function setFile(file: File) {
    // Validate file type
    const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!allowedTypes.includes(file.type)) {
        error.value = 'Please upload a JPG, PNG, or PDF file.';
        return;
    }

    // Validate file size (10MB)
    if (file.size > 10 * 1024 * 1024) {
        error.value = 'File size cannot exceed 10MB.';
        return;
    }

    selectedFile.value = file;
    error.value = null;

    // Create preview URL for images
    if (file.type.startsWith('image/')) {
        previewUrl.value = URL.createObjectURL(file);
    } else {
        previewUrl.value = null;
    }
}

function clearFile() {
    selectedFile.value = null;
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
}

// Scan the card
async function scanCard() {
    if (!selectedFile.value) return;

    processing.value = true;
    error.value = null;

    const formData = new FormData();
    formData.append('image', selectedFile.value);

    try {
        const response = await axios.post('/gia-scanner/scan', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        extractedData.value = response.data.extracted_data;
        imagePath.value = response.data.image_path;
        duplicateWarning.value = response.data.duplicate_warning;

        // Pre-fill form with suggested values
        productForm.value.title = suggestedTitle.value;
        variantForm.value.sku = suggestedSku.value;

        step.value = 'review';
    } catch (err: unknown) {
        if (axios.isAxiosError(err) && err.response?.data?.message) {
            error.value = err.response.data.message;
        } else {
            error.value = 'Failed to scan the GIA card. Please try again.';
        }
    } finally {
        processing.value = false;
    }
}

// Search for existing products
async function searchProducts() {
    if (!searchQuery.value.trim()) {
        searchResults.value = [];
        return;
    }

    searching.value = true;
    try {
        const response = await axios.get('/gia-scanner/search-products', {
            params: { q: searchQuery.value },
        });
        searchResults.value = response.data;
    } catch {
        searchResults.value = [];
    } finally {
        searching.value = false;
    }
}

function selectProduct(product: SearchResult) {
    selectedProduct.value = product;
}

// Create new product
async function createProduct() {
    if (!extractedData.value) return;

    processing.value = true;
    error.value = null;

    // Build attributes object with non-empty values
    const attributes: Record<number, string> = {};
    for (const [fieldId, value] of Object.entries(templateFieldValues.value)) {
        if (value !== null && value !== undefined && value !== '') {
            attributes[Number(fieldId)] = value;
        }
    }

    try {
        const response = await axios.post('/gia-scanner/create-product', {
            certification_data: extractedData.value,
            image_path: imagePath.value,
            product: productForm.value,
            variant: {
                sku: variantForm.value.sku,
                price: parseFloat(variantForm.value.price) || 0,
                cost: variantForm.value.cost ? parseFloat(variantForm.value.cost) : null,
                quantity: parseInt(variantForm.value.quantity) || 1,
                warehouse_id: variantForm.value.warehouse_id,
            },
            attributes: Object.keys(attributes).length > 0 ? attributes : undefined,
        });

        emit('success', response.data.product.id);
        handleClose();

        // Navigate to the new product
        router.visit(response.data.redirect_url);
    } catch (err: unknown) {
        if (axios.isAxiosError(err) && err.response?.data?.message) {
            error.value = err.response.data.message;
        } else {
            error.value = 'Failed to create product. Please try again.';
        }
    } finally {
        processing.value = false;
    }
}

// Add to existing product
async function addToProduct() {
    if (!extractedData.value || !selectedProduct.value) return;

    processing.value = true;
    error.value = null;

    try {
        await axios.post(`/gia-scanner/add-to-product/${selectedProduct.value.id}`, {
            certification_data: extractedData.value,
            image_path: imagePath.value,
        });

        emit('success', selectedProduct.value.id);
        handleClose();

        // Navigate to the product
        router.visit(`/products/${selectedProduct.value.id}`);
    } catch (err: unknown) {
        if (axios.isAxiosError(err) && err.response?.data?.message) {
            error.value = err.response.data.message;
        } else {
            error.value = 'Failed to add certification to product. Please try again.';
        }
    } finally {
        processing.value = false;
    }
}

// Navigation
function goToReview() {
    step.value = 'review';
}

function goToConfigure() {
    step.value = 'configure';
}

function goBack() {
    if (step.value === 'configure') {
        step.value = 'review';
    } else if (step.value === 'review') {
        step.value = 'upload';
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <CameraIcon class="size-5" />
                    Scan GIA Card
                </DialogTitle>
                <DialogDescription>
                    Upload a photo of a GIA certificate to automatically extract diamond details.
                </DialogDescription>
            </DialogHeader>

            <!-- Step indicator -->
            <div class="flex items-center justify-center gap-2 py-2">
                <div
                    :class="[
                        'flex items-center justify-center size-8 rounded-full text-sm font-medium',
                        step === 'upload' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                    ]"
                >
                    1
                </div>
                <div class="w-12 h-0.5 bg-gray-200 dark:bg-gray-700" />
                <div
                    :class="[
                        'flex items-center justify-center size-8 rounded-full text-sm font-medium',
                        step === 'review' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                    ]"
                >
                    2
                </div>
                <div class="w-12 h-0.5 bg-gray-200 dark:bg-gray-700" />
                <div
                    :class="[
                        'flex items-center justify-center size-8 rounded-full text-sm font-medium',
                        step === 'configure' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                    ]"
                >
                    3
                </div>
            </div>

            <!-- Error message -->
            <div v-if="error" class="rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                <div class="flex">
                    <ExclamationTriangleIcon class="size-5 text-red-400" />
                    <div class="ml-3">
                        <p class="text-sm text-red-700 dark:text-red-400">{{ error }}</p>
                    </div>
                </div>
            </div>

            <!-- Step 1: Upload -->
            <div v-if="step === 'upload'" class="space-y-4">
                <div
                    :class="[
                        'relative border-2 border-dashed rounded-lg p-8 text-center transition-colors',
                        isDragging ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 dark:border-gray-600',
                        selectedFile ? 'bg-gray-50 dark:bg-gray-800' : '',
                    ]"
                    @drop="handleDrop"
                    @dragover="handleDragOver"
                    @dragleave="handleDragLeave"
                >
                    <template v-if="!selectedFile">
                        <ArrowUpTrayIcon class="mx-auto size-12 text-gray-400" />
                        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                            Drag and drop your GIA card image here, or
                        </p>
                        <label class="mt-2 inline-block cursor-pointer">
                            <span class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">browse files</span>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,application/pdf"
                                class="sr-only"
                                @change="handleFileSelect"
                            />
                        </label>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                            JPG, PNG, or PDF up to 10MB
                        </p>
                    </template>

                    <template v-else>
                        <div class="flex items-center gap-4">
                            <div v-if="previewUrl" class="shrink-0">
                                <img :src="previewUrl" alt="Preview" class="h-32 w-auto rounded-lg object-contain" />
                            </div>
                            <div v-else class="shrink-0 flex items-center justify-center h-32 w-24 bg-gray-200 rounded-lg dark:bg-gray-700">
                                <DocumentMagnifyingGlassIcon class="size-8 text-gray-400" />
                            </div>
                            <div class="flex-1 text-left">
                                <p class="font-medium text-gray-900 dark:text-white">{{ selectedFile.name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ (selectedFile.size / 1024 / 1024).toFixed(2) }} MB
                                </p>
                                <button
                                    type="button"
                                    class="mt-2 text-sm text-red-600 hover:text-red-500 dark:text-red-400"
                                    @click="clearFile"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="handleClose">Cancel</Button>
                    <Button :disabled="!selectedFile || processing" @click="scanCard">
                        <template v-if="processing">
                            <span class="animate-spin mr-2">&#9696;</span>
                            Scanning...
                        </template>
                        <template v-else>
                            <DocumentMagnifyingGlassIcon class="-ml-0.5 mr-1.5 size-4" />
                            Scan Card
                        </template>
                    </Button>
                </DialogFooter>
            </div>

            <!-- Step 2: Review -->
            <div v-else-if="step === 'review'" class="space-y-4">
                <!-- Duplicate warning -->
                <div v-if="duplicateWarning" class="rounded-md bg-yellow-50 p-4 dark:bg-yellow-900/20">
                    <div class="flex">
                        <ExclamationTriangleIcon class="size-5 text-yellow-400" />
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700 dark:text-yellow-400">
                                A certificate with this number already exists in your inventory.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Extracted data -->
                <div v-if="extractedData" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label>Certificate Number</Label>
                        <Input v-model="extractedData.certificate_number" />
                    </div>
                    <div>
                        <Label>Issue Date</Label>
                        <Input v-model="extractedData.issue_date" />
                    </div>
                    <div>
                        <Label>Shape</Label>
                        <Input v-model="extractedData.shape" />
                    </div>
                    <div>
                        <Label>Carat Weight</Label>
                        <Input v-model="extractedData.carat_weight" />
                    </div>
                    <div>
                        <Label>Color Grade</Label>
                        <Input v-model="extractedData.color_grade" />
                    </div>
                    <div>
                        <Label>Clarity Grade</Label>
                        <Input v-model="extractedData.clarity_grade" />
                    </div>
                    <div>
                        <Label>Cut Grade</Label>
                        <Input v-model="extractedData.cut_grade" />
                    </div>
                    <div>
                        <Label>Polish</Label>
                        <Input v-model="extractedData.polish" />
                    </div>
                    <div>
                        <Label>Symmetry</Label>
                        <Input v-model="extractedData.symmetry" />
                    </div>
                    <div>
                        <Label>Fluorescence</Label>
                        <Input v-model="extractedData.fluorescence" />
                    </div>
                </div>

                <!-- Loading skeleton -->
                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <div v-for="i in 10" :key="i" class="space-y-2">
                        <Skeleton class="h-4 w-24" />
                        <Skeleton class="h-10 w-full" />
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="goBack">Back</Button>
                    <Button :disabled="!extractedData" @click="goToConfigure">
                        Continue
                    </Button>
                </DialogFooter>
            </div>

            <!-- Step 3: Configure -->
            <div v-else-if="step === 'configure'" class="space-y-4">
                <!-- Mode selector -->
                <div class="flex gap-2">
                    <button
                        type="button"
                        :class="[
                            'flex-1 rounded-lg border-2 p-4 text-left transition-colors',
                            mode === 'new'
                                ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                                : 'border-gray-200 dark:border-gray-700 hover:border-gray-300',
                        ]"
                        @click="mode = 'new'"
                    >
                        <div class="font-medium text-gray-900 dark:text-white">Create New Product</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Add as a new product to your inventory</div>
                    </button>
                    <button
                        type="button"
                        :class="[
                            'flex-1 rounded-lg border-2 p-4 text-left transition-colors',
                            mode === 'existing'
                                ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                                : 'border-gray-200 dark:border-gray-700 hover:border-gray-300',
                        ]"
                        @click="mode = 'existing'"
                    >
                        <div class="font-medium text-gray-900 dark:text-white">Add to Existing</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Link certification to an existing product</div>
                    </button>
                </div>

                <!-- New product form -->
                <div v-if="mode === 'new'" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <Label>Product Title</Label>
                            <Input v-model="productForm.title" placeholder="e.g., 1.05ct Round Diamond" />
                        </div>
                        <div>
                            <Label>Category</Label>
                            <select
                                v-model="productForm.category_id"
                                class="w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-600"
                            >
                                <option :value="null">Select category</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                    {{ cat.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label>Brand</Label>
                            <select
                                v-model="productForm.brand_id"
                                class="w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-600"
                            >
                                <option :value="null">Select brand</option>
                                <option v-for="brand in brands" :key="brand.id" :value="brand.id">
                                    {{ brand.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label>SKU</Label>
                            <Input v-model="variantForm.sku" placeholder="e.g., GIA-123456789" />
                        </div>
                        <div>
                            <Label>Warehouse</Label>
                            <select
                                v-model="variantForm.warehouse_id"
                                class="w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-600"
                            >
                                <option :value="null">Select warehouse</option>
                                <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">
                                    {{ wh.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label>Price</Label>
                            <Input v-model="variantForm.price" type="number" step="0.01" min="0" placeholder="0.00" />
                        </div>
                        <div>
                            <Label>Cost</Label>
                            <Input v-model="variantForm.cost" type="number" step="0.01" min="0" placeholder="0.00" />
                        </div>
                        <div>
                            <Label>Quantity</Label>
                            <Input v-model="variantForm.quantity" type="number" min="0" placeholder="1" />
                        </div>
                    </div>

                    <!-- Template Fields Section -->
                    <div v-if="templateFields.length > 0" class="border-t border-gray-200 pt-4 dark:border-gray-700">
                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">
                            Category Attributes
                            <span class="ml-2 text-xs font-normal text-emerald-600 dark:text-emerald-400">
                                (Auto-populated from GIA data)
                            </span>
                        </h4>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div
                                v-for="field in templateFields"
                                :key="field.id"
                                :class="{
                                    'sm:col-span-2': field.width_class === 'full',
                                    'sm:col-span-1': field.width_class === 'half' || !field.width_class,
                                }"
                            >
                                <Label>
                                    {{ field.label }}
                                    <span v-if="field.is_required" class="text-red-500">*</span>
                                </Label>
                                <!-- Select field -->
                                <select
                                    v-if="field.type === 'select'"
                                    v-model="templateFieldValues[field.id]"
                                    class="w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-600"
                                >
                                    <option value="">{{ field.placeholder || 'Select...' }}</option>
                                    <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                                        {{ opt.label }}
                                    </option>
                                </select>
                                <!-- Textarea field -->
                                <textarea
                                    v-else-if="field.type === 'textarea'"
                                    v-model="templateFieldValues[field.id]"
                                    :placeholder="field.placeholder || ''"
                                    rows="3"
                                    class="w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-600"
                                />
                                <!-- Number field -->
                                <Input
                                    v-else-if="field.type === 'number'"
                                    v-model="templateFieldValues[field.id]"
                                    type="number"
                                    step="any"
                                    :placeholder="field.placeholder || ''"
                                />
                                <!-- Default text field -->
                                <Input
                                    v-else
                                    v-model="templateFieldValues[field.id]"
                                    :placeholder="field.placeholder || ''"
                                />
                                <p v-if="field.help_text" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ field.help_text }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Loading skeleton for template fields -->
                    <div v-else-if="loadingTemplateFields" class="border-t border-gray-200 pt-4 dark:border-gray-700">
                        <Skeleton class="h-4 w-32 mb-3" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div v-for="i in 4" :key="i" class="space-y-2">
                                <Skeleton class="h-4 w-20" />
                                <Skeleton class="h-10 w-full" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Existing product search -->
                <div v-else class="space-y-4">
                    <div class="relative">
                        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Search products by title or SKU..."
                            class="pl-10"
                            @input="searchProducts"
                        />
                    </div>

                    <!-- Search results -->
                    <div v-if="searching" class="space-y-2">
                        <Skeleton v-for="i in 3" :key="i" class="h-16" />
                    </div>
                    <div v-else-if="searchResults.length > 0" class="space-y-2 max-h-60 overflow-y-auto">
                        <button
                            v-for="product in searchResults"
                            :key="product.id"
                            type="button"
                            :class="[
                                'w-full flex items-center gap-3 p-3 rounded-lg border text-left transition-colors',
                                selectedProduct?.id === product.id
                                    ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                                    : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800',
                            ]"
                            @click="selectProduct(product)"
                        >
                            <CheckCircleIcon
                                v-if="selectedProduct?.id === product.id"
                                class="size-5 text-indigo-600 dark:text-indigo-400 shrink-0"
                            />
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-gray-900 dark:text-white truncate">{{ product.title }}</div>
                                <div v-if="product.variants?.length" class="text-sm text-gray-500 dark:text-gray-400">
                                    SKU: {{ product.variants[0].sku }}
                                </div>
                            </div>
                        </button>
                    </div>
                    <div v-else-if="searchQuery" class="text-center py-8 text-gray-500 dark:text-gray-400">
                        No products found matching "{{ searchQuery }}"
                    </div>
                    <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
                        Search for a product to add the certification to
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="goBack">Back</Button>
                    <Button
                        v-if="mode === 'new'"
                        :disabled="!productForm.title || !variantForm.sku || processing"
                        @click="createProduct"
                    >
                        <template v-if="processing">
                            <span class="animate-spin mr-2">&#9696;</span>
                            Creating...
                        </template>
                        <template v-else>
                            Create Product
                        </template>
                    </Button>
                    <Button
                        v-else
                        :disabled="!selectedProduct || processing"
                        @click="addToProduct"
                    >
                        <template v-if="processing">
                            <span class="animate-spin mr-2">&#9696;</span>
                            Adding...
                        </template>
                        <template v-else>
                            Add to Product
                        </template>
                    </Button>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>
</template>
