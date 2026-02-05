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
import { SparklesIcon } from '@heroicons/vue/20/solid';
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
    preciousMetals: SelectOption[];
    conditions: SelectOption[];
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
    precious_metal: '',
    condition: '',
    estimated_weight: null as number | null,
    estimated_value: null as number | null,
});

// Similar items state
const similarItems = ref<SimilarItem[]>([]);
const loadingSimilarItems = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

// AI research state
const aiResearch = ref<Record<string, any> | null>(null);
const loadingAiResearch = ref(false);
const aiResearchError = ref<string | null>(null);

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

// Similar items search
async function searchSimilarItems() {
    if (!form.value.title || form.value.title.length < 2) {
        similarItems.value = [];
        return;
    }

    loadingSimilarItems.value = true;
    try {
        const response = await axios.post('/transactions/quick-evaluation/similar-items', {
            title: form.value.title,
            category_id: form.value.category_id,
            precious_metal: form.value.precious_metal,
            condition: form.value.condition,
        });
        similarItems.value = response.data.items;
    } catch {
        similarItems.value = [];
    } finally {
        loadingSimilarItems.value = false;
    }
}

// Debounced search
watch(() => [form.value.title, form.value.category_id, form.value.precious_metal, form.value.condition], () => {
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
        // Get image URLs from previews (base64 data URLs won't work for AI)
        // In production, you'd upload images first and get URLs
        const response = await axios.post('/transactions/quick-evaluation/ai-research', {
            title: form.value.title,
            description: form.value.description,
            category_id: form.value.category_id,
            precious_metal: form.value.precious_metal,
            condition: form.value.condition,
            estimated_weight: form.value.estimated_weight,
            image_urls: [], // Would need actual URLs
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

// Conversion modal
const selectedStoreUser = computed(() => {
    return props.storeUsers.find(u => u.id === convertForm.value.store_user_id);
});

const customerDisplayName = computed(() => {
    if (convertForm.value.customer) {
        return `${convertForm.value.customer.first_name} ${convertForm.value.customer.last_name}`.trim();
    }
    return null;
});

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

    // First save the evaluation to get an ID
    try {
        const evalResponse = await axios.post('/transactions/quick-evaluation', {
            title: form.value.title,
            description: form.value.description,
            category_id: form.value.category_id,
            precious_metal: form.value.precious_metal,
            condition: form.value.condition,
            estimated_weight: form.value.estimated_weight,
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

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <!-- Metal Type -->
                                    <div>
                                        <label for="precious_metal" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Metal Type
                                        </label>
                                        <select id="precious_metal" v-model="form.precious_metal" :class="inputClass">
                                            <option value="">Select metal...</option>
                                            <option v-for="metal in preciousMetals" :key="metal.value" :value="metal.value">
                                                {{ metal.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Condition -->
                                    <div>
                                        <label for="condition" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Condition
                                        </label>
                                        <select id="condition" v-model="form.condition" :class="inputClass">
                                            <option value="">Select condition...</option>
                                            <option v-for="cond in conditions" :key="cond.value" :value="cond.value">
                                                {{ cond.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Weight -->
                                    <div>
                                        <label for="weight" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Weight (DWT)
                                        </label>
                                        <input
                                            id="weight"
                                            v-model.number="form.estimated_weight"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :class="inputClass"
                                            placeholder="0.00"
                                        />
                                    </div>

                                    <!-- Estimated Value -->
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
                            <div v-else-if="similarItems.length === 0 && form.title" class="py-8 text-center">
                                <p class="text-sm text-gray-500 dark:text-gray-400">No similar items found in past purchases.</p>
                            </div>

                            <div v-else-if="similarItems.length === 0" class="py-8 text-center">
                                <p class="text-sm text-gray-500 dark:text-gray-400">Enter an item title to find similar past purchases.</p>
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
                                                <span v-if="form.precious_metal">{{ preciousMetals.find(m => m.value === form.precious_metal)?.label }}</span>
                                                <span v-if="form.condition">{{ conditions.find(c => c.value === form.condition)?.label }}</span>
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
