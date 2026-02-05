<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    XMarkIcon,
    PhotoIcon,
    ChevronRightIcon,
    FolderIcon,
    FolderOpenIcon,
    CheckIcon,
    ArrowPathIcon,
    CameraIcon,
    ArrowLeftIcon,
} from '@heroicons/vue/24/outline';
import { SparklesIcon, GlobeAltIcon, MagnifyingGlassIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

// Components for the conversion modal
import CustomerStep from '@/components/transactions/CustomerStep.vue';
import PaymentStep from '@/components/transactions/PaymentStep.vue';
import SelectUserStep from '@/components/transactions/SelectUserStep.vue';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level: number;
}

interface SelectOption {
    value: string;
    label: string;
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

interface StoreUser {
    id: number;
    name: string;
}

interface Warehouse {
    value: number;
    label: string;
}

interface Customer {
    id?: number;
    first_name: string;
    last_name: string;
    company_name?: string;
    email?: string;
    phone_number?: string;
    address?: string;
    city?: string;
    state_id?: number;
    zip?: string;
}

interface Payment {
    id: string;
    method: string;
    amount: number;
    details: Record<string, any>;
}

interface SimilarItem {
    id: number;
    title: string;
    description: string | null;
    category: string | null;
    precious_metal: string | null;
    condition: string | null;
    dwt: number | null;
    buy_price: number | null;
    image_url: string | null;
    created_at: string;
    days_ago: number;
    similarity_score: number;
    match_reasons: string[];
}

interface Props {
    categories: Category[];
    paymentMethods: SelectOption[];
    storeUsers: StoreUser[];
    currentStoreUserId: number | null;
    warehouses: Warehouse[];
    defaultWarehouseId: number | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transactions', href: '/transactions' },
    { title: 'Quick Evaluation', href: '/transactions/quick-evaluation' },
];

// Form state
const form = ref({
    title: '',
    description: '',
    category_id: null as number | null,
    attributes: {} as Record<number, string>,
    estimated_value: null as number | null,
});

// Template fields state
const templateFields = ref<TemplateField[]>([]);
const loadingTemplate = ref(false);

// Similar items state
const similarItems = ref<SimilarItem[]>([]);
const loadingSimilarItems = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

// AI research state
const aiResearch = ref<Record<string, any> | null>(null);
const loadingAiResearch = ref(false);
const aiResearchError = ref<string | null>(null);

// Web price search state
const webSearchResults = ref<Record<string, any> | null>(null);
const loadingWebSearch = ref(false);
const webSearchError = ref<string | null>(null);

// Image upload state
const images = ref<File[]>([]);
const imagePreviews = ref<string[]>([]);
const imageInputRef = ref<HTMLInputElement | null>(null);
const cameraInputRef = ref<HTMLInputElement | null>(null);

// Conversion modal state
const showConvertModal = ref(false);
const convertStep = ref(1);
const isConverting = ref(false);
const convertForm = ref({
    store_user_id: props.currentStoreUserId,
    customer_id: null as number | null,
    customer: null as Customer | null,
    buy_price: 0,
    warehouse_id: props.defaultWarehouseId,
    payments: [] as Payment[],
    customer_notes: '',
    internal_notes: '',
});

// Spot price calculation
const spotPrice = ref<number | null>(null);
const loadingSpotPrice = ref(false);
let spotPriceTimeout: ReturnType<typeof setTimeout> | null = null;

// Find precious metal and DWT fields in template
const preciousMetalField = computed(() =>
    templateFields.value.find(f => f.name === 'precious_metal' || f.name === 'metal_type')
);
const dwtField = computed(() =>
    templateFields.value.find(f => f.name === 'dwt' || f.name === 'weight_dwt')
);

// Category tree logic
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

const selectedCategory = computed(() => {
    if (!form.value.category_id) return null;
    return props.categories.find(c => c.id === form.value.category_id);
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
    form.value.category_id = null;
    selectionPath.value = [];
    templateFields.value = [];
    form.value.attributes = {};
}

// Template fields loading
async function loadTemplateFields(categoryId: number) {
    loadingTemplate.value = true;
    try {
        const response = await axios.get(`/categories/${categoryId}/template-fields`);
        const data = response.data;

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

// Watch category changes to load template
watch(() => form.value.category_id, (newId) => {
    if (newId) {
        loadTemplateFields(newId);
    } else {
        templateFields.value = [];
    }
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

// Spot price calculation
function watchSpotPrice() {
    const metalFieldId = preciousMetalField.value?.id;
    const dwtFieldId = dwtField.value?.id;

    if (!metalFieldId || !dwtFieldId) {
        spotPrice.value = null;
        return;
    }

    const metal = form.value.attributes[metalFieldId];
    const dwt = parseFloat(form.value.attributes[dwtFieldId]);

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

// Watch attribute changes for spot price
watch(() => form.value.attributes, () => {
    watchSpotPrice();
}, { deep: true });

function fillSpotPrice() {
    if (spotPrice.value !== null) {
        form.value.estimated_value = spotPrice.value;
    }
}

// Image handling
function handleImageSelect(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        addImages(Array.from(target.files));
    }
}

function handleCameraCapture(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
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
        images.value.push(file);
        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreviews.value.push(e.target?.result as string);
        };
        reader.readAsDataURL(file);
    }
}

function removeImage(index: number) {
    images.value.splice(index, 1);
    imagePreviews.value.splice(index, 1);
}

// Build attributes map with field names for API calls
function getAttributesForApi(): Record<string, string> {
    const result: Record<string, string> = {};
    for (const field of templateFields.value) {
        const value = form.value.attributes[field.id];
        if (value) {
            result[field.name] = value;
        }
    }
    return result;
}

// Similar items search
async function searchSimilarItems() {
    const attributes = getAttributesForApi();
    const hasTitle = form.value.title && form.value.title.length >= 2;
    const hasCategory = form.value.category_id !== null;
    const hasAttributes = Object.values(attributes).some(v => v && v.length > 0);

    // Need at least one search criterion
    if (!hasTitle && !hasCategory && !hasAttributes) {
        similarItems.value = [];
        return;
    }

    loadingSimilarItems.value = true;
    try {
        const response = await axios.post('/transactions/quick-evaluation/similar-items', {
            title: form.value.title || '',
            category_id: form.value.category_id,
            attributes: attributes,
        });
        similarItems.value = response.data.items;
    } catch {
        similarItems.value = [];
    } finally {
        loadingSimilarItems.value = false;
    }
}

// Debounced search - watch title, category, and attributes
watch(() => [form.value.title, form.value.category_id, form.value.attributes], () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        searchSimilarItems();
    }, 300);
}, { deep: true });

// AI Research
async function generateAiResearch() {
    loadingAiResearch.value = true;
    aiResearchError.value = null;

    try {
        const response = await axios.post('/transactions/quick-evaluation/ai-research', {
            title: form.value.title,
            description: form.value.description,
            category_id: form.value.category_id,
            attributes: getAttributesForApi(),
            image_urls: [],
        });

        if (response.data.research?.error) {
            aiResearchError.value = response.data.research.error;
        } else {
            aiResearch.value = response.data.research;
        }
    } catch {
        aiResearchError.value = 'Failed to generate research. Please try again.';
    } finally {
        loadingAiResearch.value = false;
    }
}

// Web Price Search
async function searchWebPrices() {
    loadingWebSearch.value = true;
    webSearchError.value = null;

    try {
        const selectedCategory = props.categories.find(c => c.id === form.value.category_id);
        const response = await axios.post('/transactions/quick-evaluation/web-search', {
            title: form.value.title,
            category: selectedCategory?.name,
            precious_metal: form.value.attributes?.precious_metal || form.value.attributes?.metal_type,
            attributes: form.value.attributes,
        });

        if (response.data.error) {
            webSearchError.value = response.data.error;
        } else {
            webSearchResults.value = response.data;
        }
    } catch {
        webSearchError.value = 'Failed to search web prices. Please try again.';
    } finally {
        loadingWebSearch.value = false;
    }
}

const formatPriceForDisplay = (price: number | null | undefined) => {
    if (price === null || price === undefined) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
};

// Conversion modal
function openConvertModal() {
    // Set initial buy price from estimated value or AI research
    let initialPrice = form.value.estimated_value || 0;
    if (aiResearch.value?.pricing_recommendation?.suggested_buy) {
        initialPrice = aiResearch.value.pricing_recommendation.suggested_buy;
    }
    convertForm.value.buy_price = initialPrice;
    convertStep.value = 1;
    showConvertModal.value = true;
}

function handleStoreUserSelect(id: number) {
    convertForm.value.store_user_id = id;
}

function handleCustomerSelect(customer: Customer | null, customerId: number | null) {
    convertForm.value.customer = customer;
    convertForm.value.customer_id = customerId;
}

function handlePaymentUpdate(data: { payments: Payment[]; customer_notes: string; internal_notes: string }) {
    convertForm.value.payments = data.payments;
    convertForm.value.customer_notes = data.customer_notes;
    convertForm.value.internal_notes = data.internal_notes;
}

const canProceedConvert = computed(() => {
    switch (convertStep.value) {
        case 1:
            return convertForm.value.store_user_id !== null;
        case 2:
            return convertForm.value.customer_id !== null || (convertForm.value.customer?.first_name && convertForm.value.customer?.last_name);
        case 3:
            return convertForm.value.buy_price > 0;
        case 4:
            return validatePaymentStep();
        default:
            return false;
    }
});

function validatePaymentStep(): boolean {
    if (convertForm.value.payments.length === 0) return false;
    const totalPayments = convertForm.value.payments.reduce((sum, p) => sum + p.amount, 0);
    return Math.abs(totalPayments - convertForm.value.buy_price) < 0.01;
}

function nextConvertStep() {
    if (convertStep.value < 4 && canProceedConvert.value) {
        convertStep.value++;
    }
}

function prevConvertStep() {
    if (convertStep.value > 1) {
        convertStep.value--;
    }
}

async function submitConversion() {
    if (isConverting.value) return;

    isConverting.value = true;

    try {
        // First save the evaluation to get an ID
        const evalResponse = await axios.post('/transactions/quick-evaluation', {
            title: form.value.title,
            description: form.value.description,
            category_id: form.value.category_id,
            attributes: form.value.attributes,
            estimated_value: form.value.estimated_value,
        });

        const evaluationId = evalResponse.data.evaluation.id;

        // Convert to transaction
        router.post(`/transactions/quick-evaluation/${evaluationId}/convert`, {
            store_user_id: convertForm.value.store_user_id,
            customer_id: convertForm.value.customer_id,
            customer: convertForm.value.customer_id ? null : convertForm.value.customer,
            buy_price: convertForm.value.buy_price,
            warehouse_id: convertForm.value.warehouse_id,
            payments: convertForm.value.payments.map(p => ({
                method: p.method,
                amount: p.amount,
                details: p.details,
            })),
            customer_notes: convertForm.value.customer_notes,
            internal_notes: convertForm.value.internal_notes,
        }, {
            onFinish: () => {
                isConverting.value = false;
            },
            onError: () => {
                isConverting.value = false;
            },
        });
    } catch {
        isConverting.value = false;
    }
}

// Discard
function discard() {
    router.visit('/transactions');
}

// Format helpers
const formatPrice = (price: number | null | undefined) => {
    if (price === null || price === undefined) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
};

const formatDaysAgo = (days: number) => {
    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return `${days} days ago`;
    if (days < 30) return `${Math.floor(days / 7)} weeks ago`;
    return `${Math.floor(days / 30)} months ago`;
};

const inputClass = 'mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600';

const isFormValid = computed(() => form.value.title.trim().length > 0);

// Get summary of filled attributes for display
const attributeSummary = computed(() => {
    const parts: string[] = [];
    for (const field of templateFields.value) {
        const value = form.value.attributes[field.id];
        if (value) {
            // For select fields, find the label
            if (field.type === 'select' && field.options.length > 0) {
                const option = field.options.find(o => o.value === value);
                if (option) {
                    parts.push(option.label);
                    continue;
                }
            }
            parts.push(value);
        }
    }
    return parts.join(' / ');
});
</script>

<template>
    <Head title="Quick Evaluation" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-7xl">
                <!-- Header -->
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Quick Evaluation</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Evaluate a walk-in item before creating a buy transaction
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        @click="discard"
                    >
                        <XMarkIcon class="size-5" />
                        Discard
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Left Column: Item Form & Photos -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Item Information -->
                        <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Item Information</h2>

                            <div class="space-y-4">
                                <!-- Title -->
                                <div>
                                    <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Title <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="title"
                                        v-model="form.title"
                                        type="text"
                                        :class="inputClass"
                                        placeholder="14K Gold Diamond Ring"
                                    />
                                </div>

                                <!-- Category -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Category
                                    </label>

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

                                    <div v-else class="rounded-md border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-700">
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

                                <!-- Template Fields Loading -->
                                <div v-if="loadingTemplate" class="flex items-center justify-center gap-2 py-4 text-gray-500 dark:text-gray-400">
                                    <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                    <span>Loading template fields...</span>
                                </div>

                                <!-- No template assigned -->
                                <div v-else-if="form.category_id && templateFields.length === 0 && !loadingTemplate" class="rounded-md bg-amber-50 p-4 dark:bg-amber-900/20">
                                    <p class="text-sm text-amber-700 dark:text-amber-400">
                                        No template fields configured for this category.
                                        <a :href="`/categories/${form.category_id}/settings`" class="underline hover:no-underline">
                                            Assign a template in Category Settings
                                        </a>
                                        to add item details like metal type, condition, weight, etc.
                                    </p>
                                </div>

                                <!-- Template Fields -->
                                <div v-else-if="templateFields.length > 0" class="space-y-4">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">Item Details</h4>

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

                                <!-- Estimated Value -->
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="estimated_value" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Estimated Value
                                        </label>
                                        <div class="relative mt-1">
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
                                            </div>
                                            <input
                                                id="estimated_value"
                                                v-model.number="form.estimated_value"
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
                                                Spot value: ${{ spotPrice.toFixed(2) }} - click to fill
                                            </button>
                                        </div>
                                        <div v-else-if="loadingSpotPrice" class="mt-1 text-xs text-gray-400">
                                            Calculating spot price...
                                        </div>
                                    </div>
                                </div>

                                <!-- Description -->
                                <div>
                                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Description
                                    </label>
                                    <textarea
                                        id="description"
                                        v-model="form.description"
                                        rows="3"
                                        :class="inputClass"
                                        placeholder="Additional details about the item..."
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Photos -->
                        <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Photos</h2>

                            <!-- Image previews -->
                            <div v-if="imagePreviews.length > 0" class="mb-4 flex flex-wrap gap-3">
                                <div
                                    v-for="(preview, index) in imagePreviews"
                                    :key="index"
                                    class="relative h-24 w-24 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200 dark:bg-gray-700 dark:ring-gray-600"
                                >
                                    <img :src="preview" class="h-full w-full object-cover" />
                                    <button
                                        type="button"
                                        class="absolute right-1 top-1 rounded-full bg-red-600 p-1 text-white hover:bg-red-700"
                                        @click="removeImage(index)"
                                    >
                                        <XMarkIcon class="size-4" />
                                    </button>
                                </div>
                            </div>

                            <!-- Upload area -->
                            <div class="flex gap-4">
                                <!-- Camera capture -->
                                <button
                                    type="button"
                                    class="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 p-6 hover:border-indigo-400 dark:border-gray-600 dark:hover:border-indigo-500"
                                    @click="cameraInputRef?.click()"
                                >
                                    <CameraIcon class="size-8 text-gray-400 dark:text-gray-500" />
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Take Photo</span>
                                </button>
                                <input
                                    ref="cameraInputRef"
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    class="hidden"
                                    @change="handleCameraCapture"
                                />

                                <!-- File upload -->
                                <div
                                    class="flex flex-1 flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 p-6 hover:border-indigo-400 dark:border-gray-600 dark:hover:border-indigo-500 cursor-pointer transition-colors"
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

                        <!-- Similar Items -->
                        <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">
                                Similar Past Purchases
                            </h2>
                            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                                Items we've previously bought that match your search criteria
                            </p>

                            <!-- Loading -->
                            <div v-if="loadingSimilarItems" class="animate-pulse space-y-3">
                                <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                            </div>

                            <!-- Empty state -->
                            <div v-else-if="similarItems.length === 0 && (form.title || form.category_id || Object.values(form.attributes).some(v => v))" class="py-8 text-center">
                                <p class="text-sm text-gray-500 dark:text-gray-400">No similar items found in past purchases.</p>
                            </div>

                            <div v-else-if="similarItems.length === 0" class="py-8 text-center">
                                <p class="text-sm text-gray-500 dark:text-gray-400">Select a category and fill in attributes to find similar past purchases.</p>
                            </div>

                            <!-- Items list -->
                            <div v-else class="space-y-3">
                                <div
                                    v-for="item in similarItems"
                                    :key="item.id"
                                    class="flex gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                                >
                                    <div class="h-16 w-16 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-700">
                                        <img
                                            v-if="item.image_url"
                                            :src="item.image_url"
                                            :alt="item.title"
                                            class="h-full w-full object-cover"
                                        />
                                        <div v-else class="flex h-full w-full items-center justify-center text-gray-400 text-xs">
                                            N/A
                                        </div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p v-if="item.category || item.precious_metal" class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ [item.category, item.precious_metal].filter(Boolean).join(' / ') }}
                                        </p>
                                        <div class="mt-1 flex items-center gap-3">
                                            <span class="text-sm font-semibold text-green-600 dark:text-green-400">
                                                Bought: {{ formatPrice(item.buy_price) }}
                                            </span>
                                            <span class="text-xs text-gray-400">
                                                {{ formatDaysAgo(item.days_ago) }}
                                            </span>
                                            <span
                                                class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                                :class="item.similarity_score >= 50 ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'"
                                            >
                                                {{ item.similarity_score }}% match
                                            </span>
                                        </div>
                                        <div v-if="item.match_reasons?.length > 0" class="mt-1">
                                            <span v-for="(reason, i) in item.match_reasons.slice(0, 3)" :key="i" class="mr-2 text-[10px] text-gray-400">
                                                {{ reason }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: AI Research & Actions -->
                    <div class="space-y-6">
                        <!-- AI Research -->
                        <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                                    <SparklesIcon class="size-5 text-purple-500" />
                                    AI Research
                                </h2>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md bg-purple-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-purple-500 disabled:opacity-50"
                                    :disabled="loadingAiResearch || !form.title"
                                    @click="generateAiResearch"
                                >
                                    <ArrowPathIcon v-if="aiResearch" class="size-3.5" :class="{ 'animate-spin': loadingAiResearch }" />
                                    <SparklesIcon v-else class="size-3.5" />
                                    {{ loadingAiResearch ? 'Analyzing...' : aiResearch ? 'Regenerate' : 'Run Research' }}
                                </button>
                            </div>

                            <!-- Loading -->
                            <div v-if="loadingAiResearch && !aiResearch" class="space-y-4 animate-pulse">
                                <div class="h-20 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                <div class="h-24 bg-gray-200 dark:bg-gray-700 rounded"></div>
                            </div>

                            <!-- Error -->
                            <div v-else-if="aiResearchError" class="rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                                <p class="text-sm text-red-700 dark:text-red-400">{{ aiResearchError }}</p>
                            </div>

                            <!-- No research yet -->
                            <div v-else-if="!aiResearch" class="py-8 text-center">
                                <SparklesIcon class="mx-auto size-10 text-gray-300 dark:text-gray-600" />
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Click "Run Research" to get an AI-powered market analysis.</p>
                            </div>

                            <!-- Research results -->
                            <div v-else class="space-y-5">
                                <!-- Market Value -->
                                <div v-if="aiResearch.market_value" class="rounded-md bg-gray-50 p-4 dark:bg-gray-700/50">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Market Value</h4>
                                    <div class="grid grid-cols-3 gap-3 text-center">
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Low</p>
                                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ formatPrice(aiResearch.market_value.min) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Average</p>
                                            <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">{{ formatPrice(aiResearch.market_value.avg) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">High</p>
                                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ formatPrice(aiResearch.market_value.max) }}</p>
                                        </div>
                                    </div>
                                    <div v-if="aiResearch.market_value.confidence" class="mt-3">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Confidence:</span>
                                            <span
                                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="{
                                                    'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': aiResearch.market_value.confidence === 'high',
                                                    'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400': aiResearch.market_value.confidence === 'medium',
                                                    'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400': aiResearch.market_value.confidence === 'low',
                                                }"
                                            >
                                                {{ aiResearch.market_value.confidence }}
                                            </span>
                                        </div>
                                    </div>
                                    <p v-if="aiResearch.market_value.reasoning" class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                                        {{ aiResearch.market_value.reasoning }}
                                    </p>
                                </div>

                                <!-- Pricing Recommendation -->
                                <div v-if="aiResearch.pricing_recommendation">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Pricing Recommendation</h4>
                                    <div class="space-y-2">
                                        <div class="rounded-md bg-green-50 p-3 dark:bg-green-900/20">
                                            <p class="text-xs text-green-700 dark:text-green-400">Suggested Buy Price</p>
                                            <p class="text-lg font-semibold text-green-800 dark:text-green-300">{{ formatPrice(aiResearch.pricing_recommendation.suggested_buy) }}</p>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div class="rounded-md bg-blue-50 p-2 dark:bg-blue-900/20">
                                                <p class="text-xs text-blue-700 dark:text-blue-400">Retail</p>
                                                <p class="text-sm font-semibold text-blue-800 dark:text-blue-300">{{ formatPrice(aiResearch.pricing_recommendation.suggested_retail) }}</p>
                                            </div>
                                            <div class="rounded-md bg-purple-50 p-2 dark:bg-purple-900/20">
                                                <p class="text-xs text-purple-700 dark:text-purple-400">Wholesale</p>
                                                <p class="text-sm font-semibold text-purple-800 dark:text-purple-300">{{ formatPrice(aiResearch.pricing_recommendation.suggested_wholesale) }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-if="aiResearch.pricing_recommendation.notes" class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                                        {{ aiResearch.pricing_recommendation.notes }}
                                    </p>
                                </div>

                                <!-- Item Analysis -->
                                <div v-if="aiResearch.item_analysis">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Item Analysis</h4>
                                    <p v-if="aiResearch.item_analysis.description" class="text-sm text-gray-600 dark:text-gray-300 mb-2">
                                        {{ aiResearch.item_analysis.description }}
                                    </p>
                                    <div v-if="aiResearch.item_analysis.notable_features?.length > 0" class="mt-2">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Notable Features:</p>
                                        <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-300 space-y-0.5">
                                            <li v-for="(feature, i) in aiResearch.item_analysis.notable_features" :key="i">{{ feature }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Web Price Search -->
                        <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                                    <GlobeAltIcon class="size-5 text-blue-500" />
                                    Web Price Search
                                </h2>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-500 disabled:opacity-50"
                                    :disabled="loadingWebSearch || !form.title"
                                    @click="searchWebPrices"
                                >
                                    <ArrowPathIcon v-if="webSearchResults?.listings?.length" class="size-3.5" :class="{ 'animate-spin': loadingWebSearch }" />
                                    <MagnifyingGlassIcon v-else class="size-3.5" />
                                    {{ loadingWebSearch ? 'Searching...' : webSearchResults?.listings?.length ? 'Refresh' : 'Search Prices' }}
                                </button>
                            </div>

                            <!-- Loading -->
                            <div v-if="loadingWebSearch && !webSearchResults?.listings?.length" class="space-y-3 animate-pulse">
                                <div class="h-16 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                <div class="h-12 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                <div class="h-12 bg-gray-200 dark:bg-gray-700 rounded"></div>
                            </div>

                            <!-- Error -->
                            <div v-else-if="webSearchError" class="rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                                <p class="text-sm text-red-700 dark:text-red-400">{{ webSearchError }}</p>
                            </div>

                            <!-- No results yet -->
                            <div v-else-if="!webSearchResults?.listings?.length" class="py-8 text-center">
                                <GlobeAltIcon class="mx-auto size-10 text-gray-300 dark:text-gray-600" />
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Click "Search Prices" to find comparable listings from Google Shopping and eBay.</p>
                            </div>

                            <!-- Search Results -->
                            <div v-else class="space-y-4">
                                <!-- Price Summary -->
                                <div v-if="webSearchResults.summary?.count" class="rounded-md bg-blue-50 p-4 dark:bg-blue-900/20">
                                    <div class="text-sm font-medium text-blue-900 dark:text-blue-100 mb-2">
                                        Found {{ webSearchResults.summary.count }} comparable listings
                                    </div>
                                    <div class="grid grid-cols-4 gap-3 text-center">
                                        <div>
                                            <p class="text-xs text-blue-600 dark:text-blue-400">Low</p>
                                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">{{ formatPriceForDisplay(webSearchResults.summary.min) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-blue-600 dark:text-blue-400">Average</p>
                                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">{{ formatPriceForDisplay(webSearchResults.summary.avg) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-blue-600 dark:text-blue-400">Median</p>
                                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">{{ formatPriceForDisplay(webSearchResults.summary.median) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-blue-600 dark:text-blue-400">High</p>
                                            <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">{{ formatPriceForDisplay(webSearchResults.summary.max) }}</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Listings -->
                                <div class="space-y-2 max-h-60 overflow-y-auto">
                                    <a
                                        v-for="(listing, index) in webSearchResults.listings?.slice(0, 10)"
                                        :key="index"
                                        :href="listing.link || '#'"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="flex gap-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                        :class="{ 'pointer-events-none': !listing.link }"
                                    >
                                        <div v-if="listing.image" class="shrink-0">
                                            <img
                                                :src="listing.image"
                                                :alt="listing.title"
                                                class="size-10 object-cover rounded-md bg-gray-100 dark:bg-gray-700"
                                                @error="($event.target as HTMLImageElement).style.display = 'none'"
                                            />
                                        </div>
                                        <div v-else class="shrink-0 size-10 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                            <GlobeAltIcon class="size-4 text-gray-400" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white line-clamp-1">
                                                    {{ listing.title }}
                                                </p>
                                                <ArrowTopRightOnSquareIcon v-if="listing.link" class="size-3.5 text-gray-400 shrink-0" />
                                            </div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <span class="text-sm font-semibold text-green-600 dark:text-green-400">
                                                    {{ formatPriceForDisplay(listing.price) }}
                                                </span>
                                                <span
                                                    class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                                    :class="listing.source?.includes('eBay') ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400'"
                                                >
                                                    {{ listing.source }}
                                                </span>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="space-y-3">
                                <button
                                    type="button"
                                    :disabled="!isFormValid"
                                    class="w-full rounded-md bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="openConvertModal"
                                >
                                    Start Buy Transaction
                                </button>
                                <button
                                    type="button"
                                    class="w-full rounded-md bg-white px-4 py-3 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="discard"
                                >
                                    Discard
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Convert to Transaction Modal -->
        <TransitionRoot as="template" :show="showConvertModal">
            <Dialog as="div" class="relative z-50" @close="showConvertModal = false">
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
                                        @click="showConvertModal = false"
                                    >
                                        <span class="sr-only">Close</span>
                                        <XMarkIcon class="size-6" />
                                    </button>
                                </div>

                                <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white mb-6">
                                    Create Buy Transaction
                                </DialogTitle>

                                <!-- Progress indicator -->
                                <div class="mb-6">
                                    <div class="flex items-center justify-between">
                                        <span
                                            v-for="step in 4"
                                            :key="step"
                                            class="flex items-center"
                                        >
                                            <span
                                                class="flex size-8 items-center justify-center rounded-full text-sm font-medium"
                                                :class="step <= convertStep ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                            >
                                                {{ step }}
                                            </span>
                                            <span v-if="step < 4" class="mx-2 h-0.5 w-8 bg-gray-200 dark:bg-gray-700"></span>
                                        </span>
                                    </div>
                                    <div class="mt-2 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                                        <span>Employee</span>
                                        <span>Customer</span>
                                        <span>Price</span>
                                        <span>Payment</span>
                                    </div>
                                </div>

                                <!-- Step 1: Select Employee -->
                                <div v-if="convertStep === 1">
                                    <SelectUserStep
                                        :store-users="storeUsers"
                                        :selected-id="convertForm.store_user_id"
                                        @select="handleStoreUserSelect"
                                    />
                                </div>

                                <!-- Step 2: Customer -->
                                <div v-else-if="convertStep === 2">
                                    <CustomerStep
                                        :customer-id="convertForm.customer_id"
                                        :customer="convertForm.customer"
                                        @update="handleCustomerSelect"
                                    />
                                </div>

                                <!-- Step 3: Buy Price -->
                                <div v-else-if="convertStep === 3">
                                    <div class="space-y-4">
                                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                            <h4 class="font-medium text-gray-900 dark:text-white mb-2">{{ form.title }}</h4>
                                            <p v-if="form.description" class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ form.description }}</p>
                                            <div class="flex flex-wrap gap-2 text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="selectedCategory">{{ selectedCategory.full_path }}</span>
                                                <span v-if="attributeSummary">{{ attributeSummary }}</span>
                                            </div>
                                        </div>

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
                                                    v-model.number="convertForm.buy_price"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-md border-0 py-3 pl-7 pr-2 text-xl font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="0.00"
                                                />
                                            </div>
                                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                The amount you will pay the customer for this item
                                            </p>
                                        </div>

                                        <div v-if="aiResearch?.pricing_recommendation" class="rounded-md bg-purple-50 p-3 dark:bg-purple-900/20">
                                            <p class="text-sm text-purple-700 dark:text-purple-300">
                                                AI suggested buy price: <strong>{{ formatPrice(aiResearch.pricing_recommendation.suggested_buy) }}</strong>
                                            </p>
                                        </div>

                                        <!-- Warehouse selection -->
                                        <div v-if="warehouses.length > 0">
                                            <label for="warehouse" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Location / Warehouse
                                            </label>
                                            <select
                                                id="warehouse"
                                                v-model="convertForm.warehouse_id"
                                                :class="inputClass"
                                            >
                                                <option :value="null">No warehouse (use store default)</option>
                                                <option v-for="wh in warehouses" :key="wh.value" :value="wh.value">
                                                    {{ wh.label }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 4: Payment -->
                                <div v-else-if="convertStep === 4">
                                    <PaymentStep
                                        :payments="convertForm.payments"
                                        :customer-notes="convertForm.customer_notes"
                                        :internal-notes="convertForm.internal_notes"
                                        :payment-methods="paymentMethods"
                                        :total-amount="convertForm.buy_price"
                                        :customer="convertForm.customer"
                                        @update="handlePaymentUpdate"
                                    />
                                </div>

                                <!-- Footer -->
                                <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                                    <button
                                        v-if="convertStep > 1"
                                        type="button"
                                        class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                        @click="prevConvertStep"
                                    >
                                        <ArrowLeftIcon class="size-4" />
                                        Back
                                    </button>
                                    <div v-else></div>

                                    <div class="flex items-center gap-4">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">
                                            Total: <span class="font-semibold text-gray-900 dark:text-white">{{ formatPrice(convertForm.buy_price) }}</span>
                                        </span>

                                        <button
                                            v-if="convertStep < 4"
                                            type="button"
                                            :disabled="!canProceedConvert"
                                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="nextConvertStep"
                                        >
                                            Continue
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            :disabled="!canProceedConvert || isConverting"
                                            class="rounded-md bg-indigo-600 px-6 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="submitConversion"
                                        >
                                            {{ isConverting ? 'Creating...' : 'Create Transaction' }}
                                        </button>
                                    </div>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>
    </AppLayout>
</template>
