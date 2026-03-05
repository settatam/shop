<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    CheckIcon,
    UserIcon,
    CubeIcon,
    WrenchScrewdriverIcon,
    ClipboardDocumentListIcon,
    PlusIcon,
    TrashIcon,
    MagnifyingGlassIcon,
    XMarkIcon,
    PencilIcon,
} from '@heroicons/vue/24/outline';
import debounce from 'lodash/debounce';
import CustomerStep from '@/components/customers/CustomerStep.vue';
import AddItemModal from '@/components/transactions/AddItemModal.vue';
import ProductSearch from '@/components/products/ProductSearch.vue';

interface StoreUser {
    id: number;
    name: string;
}

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level: number;
    template_id: number | null;
}

interface Warehouse {
    value: number;
    label: string;
    tax_rate: number | null;
}

interface Customer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email?: string;
    phone_number?: string;
    company_name?: string;
    lead_source_id?: number | null;
    address?: {
        address_line1?: string;
        address_line2?: string;
        city?: string;
        state?: string;
        postal_code?: string;
        country?: string;
    };
}

interface Vendor {
    id: number;
    name: string;
    company_name?: string;
    display_name?: string;
    email?: string;
    phone?: string;
}

interface RepairItem {
    id: string;
    product_id?: number;
    title: string;
    description?: string;
    category_id?: number;
    sku?: string;
    vendor_cost: number;
    customer_cost: number;
    dwt?: number;
    precious_metal?: string;
}

interface Props {
    storeUsers: StoreUser[];
    currentStoreUserId: number | null;
    categories: Category[];
    warehouses: Warehouse[];
    defaultWarehouseId: number | null;
    defaultTaxRate: number;
    isAppraisal?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    isAppraisal: false,
});

const entityLabel = computed(() => props.isAppraisal ? 'Appraisals' : 'Repairs');
const entitySingular = computed(() => props.isAppraisal ? 'Appraisal' : 'Repair');
const basePath = computed(() => props.isAppraisal ? '/appraisals' : '/repairs');

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: entityLabel.value, href: basePath.value },
    { title: `New ${entitySingular.value}`, href: `${basePath.value}/create` },
]);

// Wizard steps
const steps = [
    { id: 1, name: 'Employee', icon: UserIcon },
    { id: 2, name: 'Customer', icon: UserIcon },
    { id: 3, name: 'Items', icon: CubeIcon },
    { id: 4, name: 'Vendor', icon: WrenchScrewdriverIcon },
    { id: 5, name: 'Review', icon: ClipboardDocumentListIcon },
];

const currentStep = ref(1);
const isSubmitting = ref(false);
const errors = ref<Record<string, string>>({});

// Get the default store user ID
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
    if (props.warehouses.length === 1) {
        return props.warehouses[0].value;
    }
    return null;
};

// Get tax rate for a warehouse
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

    // Step 2: Customer
    customer_id: null as number | null,
    customer: null as Customer | null,

    // Step 3: Items
    items: [] as RepairItem[],

    // Step 4: Vendor (optional)
    vendor_id: null as number | null,
    vendor: null as Vendor | null,

    // Step 5: Review
    warehouse_id: getDefaultWarehouseId(),
    service_fee: 0,
    tax_rate: getTaxRateForWarehouse(getDefaultWarehouseId()),
    shipping_cost: 0,
    discount: 0,
    description: '',
    is_appraisal: props.isAppraisal,
});

// Customer ID photo files
const idPhotos = ref<File[]>([]);

// Vendor search
const vendorQuery = ref('');
const vendorResults = ref<Vendor[]>([]);
const isSearchingVendors = ref(false);
const showNewVendorForm = ref(false);
const newVendor = ref({
    name: '',
    company_name: '',
    email: '',
    phone: '',
});

// Modal state for adding/editing items
const showItemModal = ref(false);
const editingItem = ref<any>(null);

function openAddItemModal() {
    editingItem.value = null;
    showItemModal.value = true;
}

function openEditItemModal(item: RepairItem) {
    // Map RepairItem back to TransactionItem format for the modal
    editingItem.value = {
        id: item.id,
        title: item.title,
        description: item.description,
        category_id: item.category_id,
        buy_price: item.vendor_cost,
        price: item.customer_cost,
        precious_metal: item.precious_metal,
        dwt: item.dwt,
        attributes: {},
        images: [],
    };
    showItemModal.value = true;
}

function closeItemModal() {
    showItemModal.value = false;
    editingItem.value = null;
}

// Computed
const selectedWarehouse = computed(() => {
    return props.warehouses.find(w => w.value === formData.value.warehouse_id);
});

const selectedEmployee = computed(() => {
    return props.storeUsers.find(u => u.id === formData.value.store_user_id);
});

const totalVendorCost = computed(() => {
    return formData.value.items.reduce((sum, item) => sum + item.vendor_cost, 0);
});

const totalCustomerCost = computed(() => {
    return formData.value.items.reduce((sum, item) => sum + item.customer_cost, 0);
});

const subtotal = computed(() => totalCustomerCost.value);

const taxAmount = computed(() => {
    return formData.value.tax_rate > 0 ? subtotal.value * formData.value.tax_rate : 0;
});

const total = computed(() => {
    return subtotal.value + formData.value.service_fee + taxAmount.value + formData.value.shipping_cost - formData.value.discount;
});

const profit = computed(() => totalCustomerCost.value - totalVendorCost.value);

// Watch warehouse changes to update tax rate
watch(() => formData.value.warehouse_id, (newWarehouseId) => {
    formData.value.tax_rate = getTaxRateForWarehouse(newWarehouseId);
});

function handleCustomerSelect(customer: any, customerId: number | null) {
    formData.value.customer = customer ? {
        ...customer,
        id: customer.id ?? 0,
        full_name: customer.full_name || `${customer.first_name} ${customer.last_name}`.trim(),
    } : null;
    formData.value.customer_id = customerId;
}

// Vendor search
const searchVendors = debounce(async (query: string) => {
    if (!query || query.length < 2) {
        vendorResults.value = [];
        return;
    }
    isSearchingVendors.value = true;
    try {
        const response = await fetch(`${basePath.value}/search-vendors?query=${encodeURIComponent(query)}`);
        const data = await response.json();
        vendorResults.value = data.vendors;
    } finally {
        isSearchingVendors.value = false;
    }
}, 300);

watch(vendorQuery, (value) => {
    searchVendors(value);
});

function selectVendor(vendor: Vendor) {
    formData.value.vendor_id = vendor.id;
    formData.value.vendor = vendor;
    vendorQuery.value = '';
    vendorResults.value = [];
    showNewVendorForm.value = false;
}

function clearVendor() {
    formData.value.vendor_id = null;
    formData.value.vendor = null;
}

function startNewVendor() {
    showNewVendorForm.value = true;
    formData.value.vendor_id = null;
    newVendor.value = { name: '', company_name: '', email: '', phone: '' };
}

function cancelNewVendor() {
    showNewVendorForm.value = false;
}

function confirmNewVendor() {
    formData.value.vendor = {
        id: 0,
        name: newVendor.value.name,
        company_name: newVendor.value.company_name,
        display_name: newVendor.value.company_name || newVendor.value.name,
        email: newVendor.value.email,
        phone: newVendor.value.phone,
    };
    showNewVendorForm.value = false;
}

// Item management - save from AddItemModal
function handleSaveItem(item: any) {
    const repairItem: RepairItem = {
        id: item.id,
        title: item.title,
        description: item.description,
        category_id: item.category_id,
        vendor_cost: item.buy_price || 0,
        customer_cost: item.price || 0,
        precious_metal: item.precious_metal,
        dwt: item.dwt,
    };

    const existingIndex = formData.value.items.findIndex(i => i.id === repairItem.id);
    if (existingIndex >= 0) {
        formData.value.items[existingIndex] = repairItem;
    } else {
        formData.value.items.push(repairItem);
    }
}

function removeItem(id: string) {
    formData.value.items = formData.value.items.filter(item => item.id !== id);
}

// Product search
const disabledProductIds = computed(() => {
    return formData.value.items.filter(i => i.product_id).map(i => i.product_id!);
});

function handleProductSelect(product: any) {
    const repairItem: RepairItem = {
        id: `item-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
        product_id: product.id,
        title: product.title,
        description: product.description || '',
        sku: product.sku,
        vendor_cost: parseFloat(String(product.cost)) || 0,
        customer_cost: parseFloat(String(product.price)) || 0,
    };
    formData.value.items.push(repairItem);
}

// Validation
function canProceed(): boolean {
    switch (currentStep.value) {
        case 1:
            return !!formData.value.store_user_id;
        case 2:
            return !!(formData.value.customer_id || (formData.value.customer && formData.value.customer.first_name && formData.value.customer.last_name));
        case 3:
            return formData.value.items.length > 0;
        case 4:
            return true; // Vendor is optional
        case 5:
            return true;
        default:
            return false;
    }
}

function goBack() {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
}

function goNext() {
    if (canProceed() && currentStep.value < steps.length) {
        currentStep.value++;
    }
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function getCategoryLabel(categoryId?: number): string {
    if (!categoryId) return '';
    const category = props.categories.find(c => c.id === categoryId);
    return category?.name || '';
}

// Submit
async function submit() {
    if (isSubmitting.value || !canProceed()) return;

    isSubmitting.value = true;
    errors.value = {};

    const payload: Record<string, any> = {
        store_user_id: formData.value.store_user_id,
        customer_id: formData.value.customer_id,
        customer: formData.value.customer_id ? null : {
            first_name: formData.value.customer?.first_name,
            last_name: formData.value.customer?.last_name,
            email: formData.value.customer?.email,
            phone_number: formData.value.customer?.phone_number,
            company_name: formData.value.customer?.company_name,
            lead_source_id: formData.value.customer?.lead_source_id,
        },
        items: formData.value.items.map(item => ({
            product_id: item.product_id || null,
            title: item.title,
            description: item.description,
            category_id: item.category_id,
            vendor_cost: item.vendor_cost,
            customer_cost: item.customer_cost,
            dwt: item.dwt,
            precious_metal: item.precious_metal,
        })),
        vendor_id: formData.value.vendor_id,
        vendor: formData.value.vendor_id ? null : (formData.value.vendor ? {
            name: formData.value.vendor.name,
            company_name: formData.value.vendor.company_name,
            email: formData.value.vendor.email,
            phone: formData.value.vendor.phone,
        } : null),
        warehouse_id: formData.value.warehouse_id,
        service_fee: formData.value.service_fee,
        tax_rate: formData.value.tax_rate,
        shipping_cost: formData.value.shipping_cost,
        discount: formData.value.discount,
        description: formData.value.description,
        is_appraisal: formData.value.is_appraisal,
    };

    // Include ID photos if present
    if (idPhotos.value.length > 0) {
        payload.id_photos = idPhotos.value;
    }

    router.post(basePath.value, payload, {
        onError: (errs) => {
            errors.value = errs;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`New ${entitySingular}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Wizard Steps -->
                <nav aria-label="Progress" class="mb-8">
                    <ol role="list" class="flex items-center justify-between">
                        <li v-for="(step, stepIdx) in steps" :key="step.name" class="relative flex flex-1 items-center">
                            <div v-if="stepIdx !== 0" class="absolute left-0 top-1/2 h-0.5 w-full -translate-y-1/2" :class="step.id <= currentStep ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700'" />
                            <button
                                type="button"
                                @click="step.id < currentStep && (currentStep = step.id)"
                                :disabled="step.id > currentStep"
                                class="relative z-10 flex size-10 items-center justify-center rounded-full border-2 transition-colors"
                                :class="[
                                    step.id < currentStep ? 'border-indigo-600 bg-indigo-600 text-white' : '',
                                    step.id === currentStep ? 'border-indigo-600 bg-white text-indigo-600 dark:bg-gray-800' : '',
                                    step.id > currentStep ? 'border-gray-300 bg-white text-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-500' : '',
                                ]"
                            >
                                <CheckIcon v-if="step.id < currentStep" class="size-5" />
                                <component :is="step.icon" v-else class="size-5" />
                            </button>
                            <span class="sr-only">{{ step.name }}</span>
                        </li>
                    </ol>
                    <div class="mt-2 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span v-for="step in steps" :key="step.name" class="flex-1 text-center">{{ step.name }}</span>
                    </div>
                </nav>

                <!-- Main content area with summary sidebar -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Summary Sidebar -->
                    <div class="lg:col-span-1">
                        <div class="sticky top-4 space-y-4">
                            <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Repair Summary</h3>

                                <!-- Employee Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Employee</span>
                                        <button v-if="selectedEmployee && currentStep !== 1" type="button" @click="currentStep = 1" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <p v-if="selectedEmployee" class="mt-1 text-sm text-gray-900 dark:text-white">{{ selectedEmployee.name }}</p>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">Not selected</p>
                                </div>

                                <!-- Customer Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Customer</span>
                                        <button v-if="formData.customer && currentStep !== 2" type="button" @click="currentStep = 2" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="formData.customer" class="mt-1">
                                        <p class="text-sm text-gray-900 dark:text-white">{{ formData.customer.full_name }}</p>
                                        <p v-if="formData.customer.email" class="text-xs text-gray-500 dark:text-gray-400">{{ formData.customer.email }}</p>
                                    </div>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">Not selected</p>
                                </div>

                                <!-- Items Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Items</span>
                                        <button v-if="formData.items.length > 0 && currentStep !== 3" type="button" @click="currentStep = 3" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="formData.items.length > 0" class="mt-2 space-y-1">
                                        <div v-for="item in formData.items.slice(0, 3)" :key="item.id" class="flex items-center justify-between text-sm">
                                            <span class="truncate text-gray-700 dark:text-gray-300">{{ item.title }}</span>
                                            <span class="ml-2 font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.customer_cost) }}</span>
                                        </div>
                                        <p v-if="formData.items.length > 3" class="text-xs text-gray-500 dark:text-gray-400">+{{ formData.items.length - 3 }} more items</p>
                                        <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 dark:border-gray-700">
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Subtotal</span>
                                            <span class="text-sm font-bold text-gray-900 dark:text-white">{{ formatCurrency(totalCustomerCost) }}</span>
                                        </div>
                                    </div>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">No items added</p>
                                </div>

                                <!-- Vendor Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Vendor</span>
                                        <button v-if="formData.vendor && currentStep !== 4" type="button" @click="currentStep = 4" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="formData.vendor" class="mt-1">
                                        <p class="text-sm text-gray-900 dark:text-white">{{ formData.vendor.display_name || formData.vendor.name }}</p>
                                        <p v-if="formData.vendor.company_name" class="text-xs text-gray-500 dark:text-gray-400">{{ formData.vendor.company_name }}</p>
                                    </div>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">Not assigned (optional)</p>
                                </div>

                                <!-- Totals -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-gray-500 dark:text-gray-400">Vendor Cost</span>
                                            <span class="text-gray-900 dark:text-white">{{ formatCurrency(totalVendorCost) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500 dark:text-gray-400">Customer Total</span>
                                            <span class="text-gray-900 dark:text-white">{{ formatCurrency(total) }}</span>
                                        </div>
                                        <div class="flex justify-between font-medium" :class="profit >= 0 ? 'text-green-600' : 'text-red-600'">
                                            <span>Est. Profit</span>
                                            <span>{{ formatCurrency(profit) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step content -->
                    <div class="lg:col-span-2">
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="px-6 py-8">
                                <!-- Step 1: Employee -->
                                <div v-if="currentStep === 1" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Select Employee</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose the employee handling this repair.</p>
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        <button
                                            v-for="user in storeUsers"
                                            :key="user.id"
                                            type="button"
                                            @click="formData.store_user_id = user.id"
                                            :class="[
                                                'flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-colors',
                                                formData.store_user_id === user.id
                                                    ? 'border-indigo-600 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-900/20'
                                                    : 'border-gray-200 hover:border-gray-300 dark:border-gray-600 dark:hover:border-gray-500',
                                            ]"
                                        >
                                            <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                                <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                            </div>
                                            <span class="font-medium text-gray-900 dark:text-white">{{ user.name }}</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Step 2: Customer -->
                                <CustomerStep
                                    v-else-if="currentStep === 2"
                                    :customer-id="formData.customer_id"
                                    :customer="formData.customer"
                                    :show-id-photos="true"
                                    :show-company-name="true"
                                    :show-lead-source="true"
                                    @update="handleCustomerSelect"
                                    @update:id-photos="(files: File[]) => idPhotos = files"
                                />

                                <!-- Step 3: Items -->
                                <div v-else-if="currentStep === 3" class="space-y-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Repair Items</h2>
                                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Search for an existing product or add a custom item.</p>
                                        </div>
                                        <button
                                            type="button"
                                            @click="openAddItemModal"
                                            class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                        >
                                            <PlusIcon class="-ml-0.5 size-5" aria-hidden="true" />
                                            Add Custom Item
                                        </button>
                                    </div>

                                    <!-- Product Search -->
                                    <ProductSearch
                                        :search-url="`${basePath}/search-products`"
                                        :disabled-product-ids="disabledProductIds"
                                        @select="handleProductSelect"
                                    />

                                    <!-- Empty state -->
                                    <div
                                        v-if="formData.items.length === 0"
                                        class="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center dark:border-gray-600"
                                    >
                                        <CubeIcon class="mx-auto size-12 text-gray-400" />
                                        <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No items</h3>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            Add items that need to be repaired.
                                        </p>
                                        <div class="mt-6">
                                            <button
                                                type="button"
                                                @click="openAddItemModal"
                                                class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                            >
                                                <PlusIcon class="-ml-0.5 mr-1.5 size-5" aria-hidden="true" />
                                                Add Item
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Items table -->
                                    <div v-if="formData.items.length > 0" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                            <thead class="bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">Item</th>
                                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Category</th>
                                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Metal</th>
                                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">DWT</th>
                                                    <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Vendor Cost</th>
                                                    <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Customer Cost</th>
                                                    <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"><span class="sr-only">Actions</span></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                                                <tr v-for="item in formData.items" :key="item.id">
                                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 sm:pl-6">
                                                        <div class="font-medium text-gray-900 dark:text-white">{{ item.title }}</div>
                                                        <div v-if="item.description" class="text-sm text-gray-500 dark:text-gray-400 truncate max-w-48">{{ item.description }}</div>
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                                        {{ getCategoryLabel(item.category_id) || '-' }}
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                                        {{ item.precious_metal || '-' }}
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                                        {{ item.dwt ? item.dwt.toFixed(2) : '-' }}
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-right text-sm text-gray-500 dark:text-gray-400">
                                                        {{ formatCurrency(item.vendor_cost) }}
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-right text-sm font-medium text-gray-900 dark:text-white">
                                                        {{ formatCurrency(item.customer_cost) }}
                                                    </td>
                                                    <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                                        <div class="flex items-center justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                @click="openEditItemModal(item)"
                                                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-indigo-600 dark:hover:bg-gray-700"
                                                                title="Edit"
                                                            >
                                                                <PencilIcon class="size-4" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                @click="removeItem(item.id)"
                                                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700"
                                                                title="Remove"
                                                            >
                                                                <TrashIcon class="size-4" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot class="bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    <td colspan="4" class="py-3 pl-4 pr-3 text-right text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                                        Totals
                                                    </td>
                                                    <td class="py-3 px-3 text-right text-sm font-semibold text-gray-900 dark:text-white">
                                                        {{ formatCurrency(totalVendorCost) }}
                                                    </td>
                                                    <td class="py-3 px-3 text-right text-sm font-semibold text-gray-900 dark:text-white">
                                                        {{ formatCurrency(totalCustomerCost) }}
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>

                                    <!-- Add/Edit Item Modal -->
                                    <AddItemModal
                                        :open="showItemModal"
                                        :categories="categories"
                                        :editing-item="editingItem"
                                        mode="repair"
                                        @close="closeItemModal"
                                        @save="handleSaveItem"
                                    />
                                </div>

                                <!-- Step 4: Vendor (Optional) -->
                                <div v-else-if="currentStep === 4" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Select Repair Vendor (Optional)</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">You can skip this step if you want to assign a vendor later.</p>
                                    </div>

                                    <!-- Selected Vendor -->
                                    <div v-if="formData.vendor_id || formData.vendor" class="flex items-center justify-between rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
                                        <div class="flex items-center gap-3">
                                            <div class="flex size-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                                <WrenchScrewdriverIcon class="size-6 text-green-600 dark:text-green-400" />
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900 dark:text-white">{{ formData.vendor?.display_name || formData.vendor?.name }}</p>
                                                <p v-if="formData.vendor?.email" class="text-sm text-gray-500 dark:text-gray-400">{{ formData.vendor.email }}</p>
                                            </div>
                                        </div>
                                        <button type="button" @click="clearVendor" class="rounded p-1 text-gray-400 hover:text-gray-500">
                                            <XMarkIcon class="size-5" />
                                        </button>
                                    </div>

                                    <!-- Search / New Vendor Form -->
                                    <div v-else>
                                        <div v-if="!showNewVendorForm">
                                            <div class="relative mb-4">
                                                <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                                <input
                                                    v-model="vendorQuery"
                                                    type="text"
                                                    placeholder="Search vendors by name or email..."
                                                    class="w-full rounded-lg border-0 py-3 pl-10 pr-4 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>

                                            <!-- Search Results -->
                                            <div v-if="vendorResults.length > 0" class="mb-4 max-h-64 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                                                <button
                                                    v-for="vendor in vendorResults"
                                                    :key="vendor.id"
                                                    type="button"
                                                    @click="selectVendor(vendor)"
                                                    class="flex w-full items-center gap-3 rounded-lg p-3 text-left hover:bg-gray-100 dark:hover:bg-gray-700"
                                                >
                                                    <WrenchScrewdriverIcon class="size-8 text-gray-400" />
                                                    <div>
                                                        <p class="font-medium text-gray-900 dark:text-white">{{ vendor.company_name || vendor.name }}</p>
                                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                                            <span v-if="vendor.company_name && vendor.name">{{ vendor.name }}</span>
                                                            <span v-if="vendor.company_name && vendor.name && vendor.email"> &middot; </span>
                                                            <span v-if="vendor.email">{{ vendor.email }}</span>
                                                        </p>
                                                    </div>
                                                </button>
                                            </div>

                                            <button
                                                type="button"
                                                @click="startNewVendor"
                                                class="inline-flex items-center gap-2 text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                            >
                                                <PlusIcon class="size-5" />
                                                Create New Vendor
                                            </button>
                                        </div>

                                        <!-- New Vendor Form -->
                                        <div v-else class="space-y-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name *</label>
                                                <input v-model="newVendor.name" type="text" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Company Name</label>
                                                <input v-model="newVendor.company_name" type="text" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                                    <input v-model="newVendor.email" type="email" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                                    <input v-model="newVendor.phone" type="tel" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                                </div>
                                            </div>
                                            <div class="flex gap-2">
                                                <button type="button" @click="cancelNewVendor" class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                                    Cancel
                                                </button>
                                                <button type="button" @click="confirmNewVendor" :disabled="!newVendor.name" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                                    Confirm Vendor
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 5: Review -->
                                <div v-else-if="currentStep === 5" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Review & Create {{ entitySingular }}</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Review the details and create the repair order.</p>
                                    </div>

                                    <!-- Settings -->
                                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-600">
                                        <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Repair Settings</h3>
                                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                            <div v-if="warehouses.length > 0">
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Warehouse / Location</label>
                                                <select v-model="formData.warehouse_id" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                                                    <option :value="null">No warehouse (use store default)</option>
                                                    <option v-for="wh in warehouses" :key="wh.value" :value="wh.value">{{ wh.label }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tax Rate</label>
                                                <div class="mt-1 flex items-center gap-2">
                                                    <input v-model.number="formData.tax_rate" type="number" step="0.0001" min="0" max="1" class="block w-24 rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                                    <span class="text-sm text-gray-500 dark:text-gray-400">({{ (formData.tax_rate * 100).toFixed(2) }}%)</span>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Service Fee ($)</label>
                                                <input v-model.number="formData.service_fee" type="number" step="0.01" min="0" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Discount ($)</label>
                                                <input v-model.number="formData.discount" type="number" step="0.01" min="0" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div class="md:col-span-2">
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                                <textarea v-model="formData.description" rows="3" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <input id="is_appraisal" v-model="formData.is_appraisal" type="checkbox" class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600" />
                                                <label for="is_appraisal" class="text-sm text-gray-700 dark:text-gray-300">This is an appraisal</label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Errors -->
                                    <div v-if="Object.keys(errors).length > 0" class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                                        <ul class="list-inside list-disc text-sm text-red-600 dark:text-red-400">
                                            <li v-for="(error, key) in errors" :key="key">{{ error }}</li>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Navigation -->
                                <div class="mt-8 flex justify-between border-t border-gray-200 pt-6 dark:border-gray-700">
                                    <button
                                        v-if="currentStep > 1"
                                        type="button"
                                        @click="goBack"
                                        class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                    >
                                        <ChevronLeftIcon class="size-4" />
                                        Back
                                    </button>
                                    <div v-else />

                                    <button
                                        v-if="currentStep < steps.length"
                                        type="button"
                                        @click="goNext"
                                        :disabled="!canProceed()"
                                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                    >
                                        Next
                                        <ChevronRightIcon class="size-4" />
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        @click="submit"
                                        :disabled="isSubmitting || !canProceed()"
                                        class="inline-flex items-center gap-2 rounded-md bg-green-600 px-6 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                                    >
                                        <CheckIcon v-if="!isSubmitting" class="size-4" />
                                        <span v-if="isSubmitting">Creating...</span>
                                        <span v-else>Create {{ entitySingular }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
