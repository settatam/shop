<script setup lang="ts">
import { ref, computed, watch, Teleport } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    UserIcon,
    UserGroupIcon,
    CubeIcon,
    ClipboardDocumentCheckIcon,
    CheckCircleIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    PencilIcon,
    MagnifyingGlassIcon,
    PlusIcon,
    XMarkIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';
import {
    Combobox,
    ComboboxInput,
    ComboboxButton,
    ComboboxOptions,
    ComboboxOption,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

interface StoreUser {
    id: number;
    name: string;
}

interface Category {
    value: number;
    label: string;
}

interface PaymentTerm {
    value: number;
    label: string;
}

interface Warehouse {
    value: number;
    label: string;
    tax_rate: number | null;
}

interface Vendor {
    id?: number;
    name: string;
    company_name?: string;
    display_name?: string;
    email?: string;
    phone?: string;
}

interface Product {
    id: number;
    title: string;
    sku?: string;
    description?: string;
    price?: number;
    cost?: number;
    quantity?: number;
    category?: string;
    image?: string;
}

interface MemoItem {
    id: string;
    product_id: number;
    product: Product;
    price: number;
    tenor: number | null;
    title?: string;
    description?: string;
}

interface Props {
    storeUsers: StoreUser[];
    currentStoreUserId: number | null;
    categories: Category[];
    paymentTerms: PaymentTerm[];
    warehouses: Warehouse[];
    defaultWarehouseId: number | null;
    defaultTaxRate: number;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Memos', href: '/memos' },
    { title: 'New Memo', href: '/memos/create' },
];

// Wizard state
const currentStep = ref(1);
const totalSteps = 4;
const isSubmitting = ref(false);

// Form data
const getDefaultStoreUserId = () => {
    if (props.currentStoreUserId && props.storeUsers.some(u => u.id === props.currentStoreUserId)) {
        return props.currentStoreUserId;
    }
    return null;
};

// Get the default warehouse ID
const getDefaultWarehouseId = () => {
    if (props.defaultWarehouseId && props.warehouses.some(w => w.value === props.defaultWarehouseId)) {
        return props.defaultWarehouseId;
    }
    // If there's only one warehouse, auto-select it
    if (props.warehouses.length === 1) {
        return props.warehouses[0].value;
    }
    return null;
};

// Get tax rate for a warehouse or fall back to store default
const getTaxRateForWarehouse = (warehouseId: number | null): number => {
    if (warehouseId) {
        const warehouse = props.warehouses.find(w => w.value === warehouseId);
        if (warehouse && warehouse.tax_rate !== null) {
            return warehouse.tax_rate;
        }
    }
    return props.defaultTaxRate ?? 0;
};

const formData = ref({
    // Step 1: Store User
    store_user_id: getDefaultStoreUserId(),

    // Step 2: Vendor
    vendor_id: null as number | null,
    vendor: null as Vendor | null,

    // Step 3: Items
    items: [] as MemoItem[],

    // Step 4: Review
    warehouse_id: getDefaultWarehouseId(),
    tenure: 30,
    description: '',
    charge_taxes: false,
    tax_rate: getTaxRateForWarehouse(getDefaultWarehouseId()),
});

const steps = [
    { number: 1, name: 'Select Employee', icon: UserIcon },
    { number: 2, name: 'Vendor', icon: UserGroupIcon },
    { number: 3, name: 'Select Products', icon: CubeIcon },
    { number: 4, name: 'Review', icon: ClipboardDocumentCheckIcon },
];

const canProceed = computed(() => {
    switch (currentStep.value) {
        case 1:
            return formData.value.store_user_id !== null;
        case 2:
            return formData.value.vendor_id !== null || !!formData.value.vendor?.name;
        case 3:
            return formData.value.items.length > 0 && formData.value.items.every(item => item.price >= 0);
        case 4:
            return true;
        default:
            return false;
    }
});

const totalAmount = computed(() => {
    return formData.value.items.reduce((sum, item) => sum + (item.price || 0), 0);
});

const selectedStoreUser = computed(() => {
    return props.storeUsers.find(u => u.id === formData.value.store_user_id);
});

const vendorDisplayName = computed(() => {
    if (formData.value.vendor) {
        return formData.value.vendor.name;
    }
    return null;
});

function nextStep() {
    if (currentStep.value < totalSteps && canProceed.value) {
        currentStep.value++;
    }
}

function prevStep() {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
}

function goToStep(step: number) {
    if (step <= currentStep.value) {
        currentStep.value = step;
    }
}

// Step 1: Store User Selection
function handleStoreUserSelect(id: number) {
    formData.value.store_user_id = id;
}

// Step 2: Vendor Selection
const vendorMode = ref<'search' | 'create'>('search');
const vendorQuery = ref('');
const vendorSearchResults = ref<Vendor[]>([]);
const isVendorLoading = ref(false);
const selectedVendor = ref<Vendor | null>(null);

const newVendor = ref<Vendor>({
    name: '',
    company_name: '',
    email: '',
    phone: '',
});

const searchVendors = useDebounceFn(async (query: string) => {
    if (!query || query.length < 1) {
        vendorSearchResults.value = [];
        return;
    }

    isVendorLoading.value = true;

    try {
        const response = await axios.get('/memos/search-vendors', {
            params: { query },
        });
        vendorSearchResults.value = response.data.vendors;
    } catch (err) {
        vendorSearchResults.value = [];
    } finally {
        isVendorLoading.value = false;
    }
}, 300);

watch(vendorQuery, (value) => {
    searchVendors(value);
});

// Update tax rate when warehouse changes
watch(() => formData.value.warehouse_id, (newWarehouseId) => {
    formData.value.tax_rate = getTaxRateForWarehouse(newWarehouseId);
});

const selectedWarehouse = computed(() => {
    return props.warehouses.find(w => w.value === formData.value.warehouse_id);
});

function selectVendor(vendor: Vendor | null) {
    if (vendor && 'isCreateOption' in vendor) {
        vendorMode.value = 'create';
        newVendor.value.name = vendorQuery.value.trim();
        formData.value.vendor = { ...newVendor.value };
        formData.value.vendor_id = null;
    } else if (vendor) {
        selectedVendor.value = vendor;
        formData.value.vendor_id = vendor.id!;
        formData.value.vendor = null;
    }
}

function clearVendorSelection() {
    selectedVendor.value = null;
    vendorQuery.value = '';
    formData.value.vendor_id = null;
    formData.value.vendor = null;
}

function switchToVendorCreate() {
    vendorMode.value = 'create';
    selectedVendor.value = null;
    formData.value.vendor = { ...newVendor.value };
    formData.value.vendor_id = null;
}

function switchToVendorSearch() {
    vendorMode.value = 'search';
    newVendor.value = { name: '', company_name: '', email: '', phone: '' };
    formData.value.vendor = null;
}

function updateNewVendor() {
    formData.value.vendor = { ...newVendor.value };
    formData.value.vendor_id = null;
}

const vendorFilteredOptions = computed(() => {
    const results = [...vendorSearchResults.value];
    if (vendorQuery.value.length > 0) {
        results.push({ isCreateOption: true, full_name: 'Create new vendor' } as any);
    }
    return results;
});

// Step 3: Product Selection
const productQuery = ref('');
const productSearchResults = ref<Product[]>([]);
const isProductLoading = ref(false);
const selectedCategoryFilter = ref<number | null>(null);

// Quick product creation
const showCreateProductModal = ref(false);
const newProductForm = ref({
    title: '',
    sku: '',
    price: 0,
    cost: 0,
    category_id: null as number | null,
});
const isCreatingProduct = ref(false);
const createProductError = ref<string | null>(null);

const searchProducts = useDebounceFn(async () => {
    isProductLoading.value = true;

    try {
        const response = await axios.get('/memos/search-products', {
            params: {
                query: productQuery.value,
                category_id: selectedCategoryFilter.value,
            },
        });
        productSearchResults.value = response.data.products;
    } catch (err) {
        productSearchResults.value = [];
    } finally {
        isProductLoading.value = false;
    }
}, 300);

watch([productQuery, selectedCategoryFilter], () => {
    if (productQuery.value) {
        searchProducts();
    } else {
        productSearchResults.value = [];
    }
});

function addProduct(product: Product) {
    // Check if already added
    if (formData.value.items.some(item => item.product_id === product.id)) {
        return;
    }

    formData.value.items.push({
        id: `item-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
        product_id: product.id,
        product: product,
        price: parseFloat(String(product.price)) || 0,
        tenor: formData.value.tenure,
        title: product.title,
        description: product.description,
    });
}

function removeItem(itemId: string) {
    formData.value.items = formData.value.items.filter(item => item.id !== itemId);
}

function updateItemPrice(itemId: string, price: number) {
    const item = formData.value.items.find(i => i.id === itemId);
    if (item) {
        item.price = price;
    }
}

function updateItemTenor(itemId: string, tenor: number) {
    const item = formData.value.items.find(i => i.id === itemId);
    if (item) {
        item.tenor = tenor;
    }
}

function isProductAdded(productId: number): boolean {
    return formData.value.items.some(item => item.product_id === productId);
}

function openCreateProductModal() {
    newProductForm.value = {
        title: productQuery.value.trim(),
        sku: '',
        price: 0,
        cost: 0,
        category_id: selectedCategoryFilter.value,
    };
    createProductError.value = null;
    showCreateProductModal.value = true;
}

function closeCreateProductModal() {
    showCreateProductModal.value = false;
    createProductError.value = null;
}

async function createProduct() {
    if (isCreatingProduct.value) return;

    isCreatingProduct.value = true;
    createProductError.value = null;

    try {
        const response = await axios.post('/memos/create-product', {
            title: newProductForm.value.title,
            sku: newProductForm.value.sku || null,
            price: newProductForm.value.price,
            cost: newProductForm.value.cost || null,
            category_id: newProductForm.value.category_id,
        });
        const product = response.data.product;
        addProduct(product);
        showCreateProductModal.value = false;
        productQuery.value = '';
    } catch (err: any) {
        createProductError.value = err.response?.data?.message || 'Failed to create product.';
    } finally {
        isCreatingProduct.value = false;
    }
}

// Submit
function submitMemo() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;

    const payload: Record<string, any> = {
        store_user_id: formData.value.store_user_id,
        vendor_id: formData.value.vendor_id,
        vendor: formData.value.vendor_id ? null : formData.value.vendor,
        items: formData.value.items.map(item => ({
            product_id: item.product_id,
            price: item.price,
            tenor: item.tenor,
            title: item.title,
            description: item.description,
        })),
        warehouse_id: formData.value.warehouse_id,
        tenure: formData.value.tenure,
        description: formData.value.description,
        charge_taxes: formData.value.charge_taxes,
        tax_rate: formData.value.tax_rate,
    };

    router.post('/memos', payload, {
        preserveState: false,
        preserveScroll: false,
        onFinish: () => {
            isSubmitting.value = false;
        },
        onError: () => {
            isSubmitting.value = false;
        },
    });
}

function getPaymentTermLabel(days: number): string {
    const term = props.paymentTerms.find(t => t.value === days);
    return term?.label || `${days} Days`;
}
</script>

<template>
    <Head title="New Memo" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Progress steps -->
                <nav aria-label="Progress" class="mb-8">
                    <ol role="list" class="flex items-center justify-between">
                        <li v-for="(step, stepIdx) in steps" :key="step.name" class="relative flex-1">
                            <template v-if="currentStep > step.number">
                                <button type="button" @click="goToStep(step.number)" class="group flex w-full items-center">
                                    <span class="flex items-center px-6 py-4 text-sm font-medium">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-600 group-hover:bg-indigo-700">
                                            <CheckCircleIcon class="size-6 text-white" />
                                        </span>
                                        <span class="ml-4 text-sm font-medium text-gray-900 dark:text-white">{{ step.name }}</span>
                                    </span>
                                </button>
                            </template>
                            <template v-else-if="currentStep === step.number">
                                <div class="flex items-center px-6 py-4 text-sm font-medium" aria-current="step">
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-indigo-600">
                                        <component :is="step.icon" class="size-5 text-indigo-600" />
                                    </span>
                                    <span class="ml-4 text-sm font-medium text-indigo-600">{{ step.name }}</span>
                                </div>
                            </template>
                            <template v-else>
                                <div class="group flex items-center">
                                    <span class="flex items-center px-6 py-4 text-sm font-medium">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 dark:border-gray-600">
                                            <component :is="step.icon" class="size-5 text-gray-500 dark:text-gray-400" />
                                        </span>
                                        <span class="ml-4 text-sm font-medium text-gray-500 dark:text-gray-400">{{ step.name }}</span>
                                    </span>
                                </div>
                            </template>
                            <div v-if="stepIdx !== steps.length - 1" class="absolute right-0 top-0 hidden h-full w-5 md:block" aria-hidden="true">
                                <svg class="size-full text-gray-300 dark:text-gray-600" viewBox="0 0 22 80" fill="none" preserveAspectRatio="none">
                                    <path d="M0 -2L20 40L0 82" vector-effect="non-scaling-stroke" stroke="currentcolor" stroke-linejoin="round" />
                                </svg>
                            </div>
                        </li>
                    </ol>
                </nav>

                <!-- Main content area with summary sidebar -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Summary Sidebar -->
                    <div class="lg:col-span-1">
                        <div class="sticky top-4 space-y-4">
                            <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Memo Summary</h3>

                                <!-- Employee Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Employee</span>
                                        <button v-if="selectedStoreUser && currentStep !== 1" type="button" @click="goToStep(1)" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <p v-if="selectedStoreUser" class="mt-1 text-sm text-gray-900 dark:text-white">{{ selectedStoreUser.name }}</p>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">Not selected</p>
                                </div>

                                <!-- Vendor Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Vendor</span>
                                        <button v-if="(vendorDisplayName || formData.vendor_id) && currentStep !== 2" type="button" @click="goToStep(2)" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="vendorDisplayName || selectedVendor" class="mt-1">
                                        <p class="text-sm text-gray-900 dark:text-white">{{ vendorDisplayName || selectedVendor?.display_name || selectedVendor?.name }}</p>
                                        <p v-if="formData.vendor?.company_name || selectedVendor?.company_name" class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ formData.vendor?.company_name || selectedVendor?.company_name }}
                                        </p>
                                    </div>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">Not selected</p>
                                </div>

                                <!-- Items Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Items</span>
                                        <button v-if="formData.items.length > 0 && currentStep !== 3" type="button" @click="goToStep(3)" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="formData.items.length > 0" class="mt-2 space-y-1">
                                        <div v-for="item in formData.items.slice(0, 3)" :key="item.id" class="flex items-center justify-between text-sm">
                                            <span class="truncate text-gray-700 dark:text-gray-300">{{ item.product.title }}</span>
                                            <span class="ml-2 font-medium text-gray-900 dark:text-white">${{ item.price.toFixed(2) }}</span>
                                        </div>
                                        <p v-if="formData.items.length > 3" class="text-xs text-gray-500 dark:text-gray-400">+{{ formData.items.length - 3 }} more items</p>
                                        <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 dark:border-gray-700">
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Total Expected</span>
                                            <span class="text-sm font-bold text-gray-900 dark:text-white">${{ totalAmount.toFixed(2) }}</span>
                                        </div>
                                    </div>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">No items added</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step content -->
                    <div class="lg:col-span-2">
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="px-6 py-8">
                                <!-- Step 1: Select Employee -->
                                <div v-if="currentStep === 1" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Select Employee</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose the employee handling this memo.</p>
                                    </div>
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <button
                                            v-for="user in storeUsers"
                                            :key="user.id"
                                            type="button"
                                            @click="handleStoreUserSelect(user.id)"
                                            :class="[
                                                'flex items-center gap-4 rounded-lg border-2 p-4 text-left transition-all',
                                                formData.store_user_id === user.id
                                                    ? 'border-indigo-600 bg-indigo-50 dark:border-indigo-500 dark:bg-indigo-900/20'
                                                    : 'border-gray-200 hover:border-gray-300 dark:border-gray-600 dark:hover:border-gray-500',
                                            ]"
                                        >
                                            <div class="flex size-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                                <UserIcon class="size-6 text-gray-500 dark:text-gray-400" />
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900 dark:text-white">{{ user.name }}</p>
                                            </div>
                                            <CheckCircleIcon v-if="formData.store_user_id === user.id" class="ml-auto size-6 text-indigo-600" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Step 2: Vendor -->
                                <div v-else-if="currentStep === 2" class="space-y-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Vendor Information</h2>
                                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Search for an existing vendor or create a new one.</p>
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="button" @click="switchToVendorSearch" :class="['rounded-md px-3 py-1.5 text-sm font-medium', vendorMode === 'search' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400']">Search</button>
                                            <button type="button" @click="switchToVendorCreate" :class="['rounded-md px-3 py-1.5 text-sm font-medium', vendorMode === 'create' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400']">Create New</button>
                                        </div>
                                    </div>

                                    <!-- Search Mode -->
                                    <template v-if="vendorMode === 'search'">
                                        <div v-if="selectedVendor" class="flex items-center justify-between rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-700">
                                            <div class="flex items-center gap-3">
                                                <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                                    <UserGroupIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                                </div>
                                                <div>
                                                    <p class="text-base font-medium text-gray-900 dark:text-white">{{ selectedVendor.display_name || selectedVendor.name }}</p>
                                                    <p v-if="selectedVendor.email || selectedVendor.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ selectedVendor.email || selectedVendor.phone }}</p>
                                                </div>
                                            </div>
                                            <button type="button" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-600" @click="clearVendorSelection">
                                                <XMarkIcon class="size-5" />
                                            </button>
                                        </div>

                                        <Combobox v-else v-model="selectedVendor" @update:model-value="selectVendor" as="div" class="relative">
                                            <ComboboxInput
                                                class="w-full rounded-lg border-0 bg-white py-3 pl-12 pr-10 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="Search by name, email, or phone..."
                                                @change="vendorQuery = $event.target.value"
                                            />
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                                <MagnifyingGlassIcon class="size-5 text-gray-400" />
                                            </div>
                                            <ComboboxButton class="absolute inset-y-0 right-0 flex items-center pr-3">
                                                <ChevronUpDownIcon class="size-5 text-gray-400" />
                                            </ComboboxButton>
                                            <ComboboxOptions v-if="vendorQuery.length > 0" class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm dark:bg-gray-800">
                                                <div v-if="isVendorLoading" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">Searching...</div>
                                                <div v-else-if="vendorSearchResults.length === 0 && vendorQuery.length > 0" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">No vendors found.</div>
                                                <ComboboxOption v-for="vendor in vendorFilteredOptions" :key="'isCreateOption' in vendor ? 'create' : vendor.id" v-slot="{ active }" :value="vendor" as="template">
                                                    <li :class="['relative cursor-pointer select-none py-3 pl-4 pr-9', active ? 'bg-indigo-600 text-white' : 'text-gray-900 dark:text-white', 'isCreateOption' in vendor ? 'border-t border-gray-200 dark:border-gray-700' : '']">
                                                        <template v-if="'isCreateOption' in vendor">
                                                            <div class="flex items-center gap-2">
                                                                <PlusIcon class="size-5" />
                                                                <span class="font-medium">Create new vendor</span>
                                                                <span v-if="vendorQuery" :class="active ? 'text-indigo-200' : 'text-gray-500'">"{{ vendorQuery }}"</span>
                                                            </div>
                                                        </template>
                                                        <template v-else>
                                                            <div class="flex items-center gap-3">
                                                                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-600">
                                                                    <UserGroupIcon :class="['size-5', active ? 'text-white' : 'text-gray-500']" />
                                                                </div>
                                                                <div>
                                                                    <p class="truncate font-medium">{{ vendor.display_name || vendor.name }}</p>
                                                                    <p :class="['truncate text-sm', active ? 'text-indigo-200' : 'text-gray-500']">{{ vendor.email || vendor.phone || 'No contact info' }}</p>
                                                                </div>
                                                            </div>
                                                        </template>
                                                    </li>
                                                </ComboboxOption>
                                            </ComboboxOptions>
                                        </Combobox>
                                    </template>

                                    <!-- Create Mode -->
                                    <template v-else>
                                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                            <div class="sm:col-span-2">
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Vendor Name <span class="text-red-500">*</span></label>
                                                <input v-model="newVendor.name" type="text" @input="updateNewVendor" class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Company Name</label>
                                                <input v-model="newVendor.company_name" type="text" @input="updateNewVendor" class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                                <input v-model="newVendor.email" type="email" @input="updateNewVendor" class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                                <input v-model="newVendor.phone" type="tel" @input="updateNewVendor" class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <!-- Step 3: Select Products -->
                                <div v-else-if="currentStep === 3" class="space-y-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Select Products</h2>
                                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Search for existing products or create a new one.</p>
                                        </div>
                                        <button
                                            type="button"
                                            @click="openCreateProductModal"
                                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500"
                                        >
                                            <PlusIcon class="size-4" />
                                            New Product
                                        </button>
                                    </div>

                                    <!-- Search and filter -->
                                    <div class="flex gap-4">
                                        <div class="relative flex-1">
                                            <input
                                                v-model="productQuery"
                                                type="text"
                                                placeholder="Search products by name or SKU..."
                                                class="w-full rounded-lg border-0 bg-white py-3 pl-12 pr-4 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                                <MagnifyingGlassIcon class="size-5 text-gray-400" />
                                            </div>
                                        </div>
                                        <select
                                            v-model="selectedCategoryFilter"
                                            class="rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">All Categories</option>
                                            <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                        </select>
                                    </div>

                                    <!-- Product results -->
                                    <div class="max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-600">
                                        <div v-if="isProductLoading" class="p-4 text-center text-gray-500 dark:text-gray-400">Loading...</div>
                                        <div v-else-if="!productQuery" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                            <MagnifyingGlassIcon class="mx-auto size-8 text-gray-300 dark:text-gray-600" />
                                            <p class="mt-2">Type to search for products</p>
                                        </div>
                                        <div v-else-if="productSearchResults.length === 0" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                            <p>No products found for "{{ productQuery }}"</p>
                                            <button
                                                type="button"
                                                @click="openCreateProductModal"
                                                class="mt-2 inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                            >
                                                <PlusIcon class="size-4" />
                                                Create "{{ productQuery }}"
                                            </button>
                                        </div>
                                        <div v-else class="divide-y divide-gray-200 dark:divide-gray-600">
                                            <div
                                                v-for="product in productSearchResults"
                                                :key="product.id"
                                                class="flex items-center justify-between p-3 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                            >
                                                <div class="flex items-center gap-3">
                                                    <div class="flex size-12 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                                        <img v-if="product.image" :src="product.image" class="size-12 rounded object-cover" />
                                                        <CubeIcon v-else class="size-6 text-gray-400" />
                                                    </div>
                                                    <div>
                                                        <p class="font-medium text-gray-900 dark:text-white">{{ product.title }}</p>
                                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                                            <span v-if="product.sku">SKU: {{ product.sku }}</span>
                                                            <span v-if="product.sku && product.price"> | </span>
                                                            <span v-if="product.price">${{ product.price }}</span>
                                                        </p>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    @click="addProduct(product)"
                                                    :disabled="isProductAdded(product.id)"
                                                    :class="[
                                                        'inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium',
                                                        isProductAdded(product.id)
                                                            ? 'cursor-not-allowed bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500'
                                                            : 'bg-indigo-600 text-white hover:bg-indigo-500',
                                                    ]"
                                                >
                                                    {{ isProductAdded(product.id) ? 'Added' : 'Add' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Selected items -->
                                    <div v-if="formData.items.length > 0" class="space-y-4">
                                        <h3 class="font-medium text-gray-900 dark:text-white">Selected Items ({{ formData.items.length }})</h3>
                                        <div class="space-y-3">
                                            <div
                                                v-for="item in formData.items"
                                                :key="item.id"
                                                class="flex items-start gap-4 rounded-lg border border-gray-200 p-4 dark:border-gray-600"
                                            >
                                                <div class="flex size-12 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                                    <img v-if="item.product.image" :src="item.product.image" class="size-12 rounded object-cover" />
                                                    <CubeIcon v-else class="size-6 text-gray-400" />
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <p class="font-medium text-gray-900 dark:text-white">{{ item.product.title }}</p>
                                                    <p v-if="item.product.sku" class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.product.sku }}</p>
                                                    <div class="mt-2 flex flex-wrap gap-4">
                                                        <div>
                                                            <label class="block text-xs text-gray-500 dark:text-gray-400">Expected Amount</label>
                                                            <div class="mt-1 flex items-center">
                                                                <span class="text-gray-500 dark:text-gray-400">$</span>
                                                                <input
                                                                    type="number"
                                                                    :value="item.price"
                                                                    @input="updateItemPrice(item.id, parseFloat(($event.target as HTMLInputElement).value) || 0)"
                                                                    min="0"
                                                                    step="0.01"
                                                                    class="w-24 border-0 bg-transparent p-0 pl-1 text-gray-900 focus:ring-0 sm:text-sm dark:text-white"
                                                                />
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <label class="block text-xs text-gray-500 dark:text-gray-400">Tenor</label>
                                                            <select
                                                                :value="item.tenor"
                                                                @change="updateItemTenor(item.id, parseInt(($event.target as HTMLSelectElement).value))"
                                                                class="mt-1 block rounded border-0 bg-transparent py-0 pl-0 pr-6 text-gray-900 focus:ring-0 sm:text-sm dark:text-white"
                                                            >
                                                                <option v-for="term in paymentTerms" :key="term.value" :value="term.value">{{ term.label }}</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <button type="button" @click="removeItem(item.id)" class="p-1 text-gray-400 hover:text-red-500">
                                                    <TrashIcon class="size-5" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 4: Review -->
                                <div v-else-if="currentStep === 4" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Review Memo</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Review the memo details and add any additional information.</p>
                                    </div>

                                    <!-- Memo settings -->
                                    <div class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-600">
                                        <h3 class="font-medium text-gray-900 dark:text-white">Memo Settings</h3>
                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div v-if="warehouses.length > 0">
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Warehouse / Location</label>
                                                <select
                                                    v-model="formData.warehouse_id"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option :value="null">No warehouse (use store default)</option>
                                                    <option v-for="wh in warehouses" :key="wh.value" :value="wh.value">{{ wh.label }}</option>
                                                </select>
                                                <p v-if="selectedWarehouse?.tax_rate !== null && selectedWarehouse?.tax_rate !== undefined" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    Tax rate: {{ (selectedWarehouse.tax_rate * 100).toFixed(2) }}%
                                                </p>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Default Tenure</label>
                                                <select
                                                    v-model="formData.tenure"
                                                    class="mt-1 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option v-for="term in paymentTerms" :key="term.value" :value="term.value">{{ term.label }}</option>
                                                </select>
                                            </div>
                                            <div class="flex items-center gap-4">
                                                <div class="flex items-center">
                                                    <input
                                                        id="charge_taxes"
                                                        v-model="formData.charge_taxes"
                                                        type="checkbox"
                                                        class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                    />
                                                    <label for="charge_taxes" class="ml-2 text-sm text-gray-700 dark:text-gray-300">Charge Taxes</label>
                                                </div>
                                                <div v-if="formData.charge_taxes">
                                                    <input
                                                        v-model.number="formData.tax_rate"
                                                        type="number"
                                                        min="0"
                                                        max="1"
                                                        step="0.01"
                                                        placeholder="0.08"
                                                        class="w-20 rounded-md border-0 px-2 py-1 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description / Notes</label>
                                            <textarea
                                                v-model="formData.description"
                                                rows="3"
                                                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="Add any notes about this memo..."
                                            ></textarea>
                                        </div>
                                    </div>

                                    <!-- Summary -->
                                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-600">
                                        <h3 class="font-medium text-gray-900 dark:text-white">Summary</h3>
                                        <dl class="mt-4 space-y-2">
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-gray-500 dark:text-gray-400">Employee</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ selectedStoreUser?.name }}</dd>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-gray-500 dark:text-gray-400">Vendor</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ vendorDisplayName || selectedVendor?.display_name || selectedVendor?.name }}</dd>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-gray-500 dark:text-gray-400">Items</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ formData.items.length }}</dd>
                                            </div>
                                            <div v-if="formData.warehouse_id" class="flex justify-between text-sm">
                                                <dt class="text-gray-500 dark:text-gray-400">Warehouse</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ selectedWarehouse?.label }}</dd>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-gray-500 dark:text-gray-400">Default Tenure</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ getPaymentTermLabel(formData.tenure) }}</dd>
                                            </div>
                                            <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-medium dark:border-gray-600">
                                                <dt class="text-gray-900 dark:text-white">Total Expected</dt>
                                                <dd class="text-gray-900 dark:text-white">${{ totalAmount.toFixed(2) }}</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer with navigation -->
                            <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <button v-if="currentStep > 1" type="button" @click="prevStep" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                        <ChevronLeftIcon class="size-4" />
                                        Back
                                    </button>
                                    <div v-else></div>

                                    <div class="flex items-center gap-4">
                                        <span v-if="currentStep === 3 || currentStep === 4" class="text-sm text-gray-500 dark:text-gray-400">
                                            Total: <span class="font-semibold text-gray-900 dark:text-white">${{ totalAmount.toFixed(2) }}</span>
                                        </span>

                                        <button
                                            v-if="currentStep < totalSteps"
                                            type="button"
                                            @click="nextStep"
                                            :disabled="!canProceed"
                                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            Continue
                                            <ChevronRightIcon class="size-4" />
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            @click="submitMemo"
                                            :disabled="!canProceed || isSubmitting"
                                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ isSubmitting ? 'Creating...' : 'Create Memo' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Product Creation Modal -->
        <Teleport to="body">
            <div v-if="showCreateProductModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="closeCreateProductModal" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Create New Product</h3>

                        <div v-if="createProductError" class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/50 dark:text-red-300">
                            {{ createProductError }}
                        </div>

                        <form @submit.prevent="createProduct" class="space-y-4">
                            <div>
                                <label for="product_title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Title <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="product_title"
                                    v-model="newProductForm.title"
                                    type="text"
                                    required
                                    class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div>
                                <label for="product_sku" class="block text-sm font-medium text-gray-700 dark:text-gray-300">SKU</label>
                                <input
                                    id="product_sku"
                                    v-model="newProductForm.sku"
                                    type="text"
                                    placeholder="Auto-generated if empty"
                                    class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="product_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Price <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                        <input
                                            id="product_price"
                                            v-model.number="newProductForm.price"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            required
                                            class="block w-full rounded-md border-0 py-1.5 pl-7 pr-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label for="product_cost" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cost</label>
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                        <input
                                            id="product_cost"
                                            v-model.number="newProductForm.cost"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="block w-full rounded-md border-0 py-1.5 pl-7 pr-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label for="product_category" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                <select
                                    id="product_category"
                                    v-model="newProductForm.category_id"
                                    class="mt-1 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option :value="null">No category</option>
                                    <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                </select>
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="closeCreateProductModal"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="isCreatingProduct || !newProductForm.title"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {{ isCreatingProduct ? 'Creating...' : 'Create & Add' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
