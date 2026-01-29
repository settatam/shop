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
} from '@heroicons/vue/24/outline';
import debounce from 'lodash/debounce';
import LeadSourceSelect from '@/components/customers/LeadSourceSelect.vue';

interface StoreUser {
    id: number;
    name: string;
}

interface Category {
    value: number;
    label: string;
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
    title: string;
    description?: string;
    category_id?: number;
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
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Repairs', href: '/repairs' },
    { title: 'New Repair', href: '/repairs/create' },
];

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
    is_appraisal: false,
});

// Customer search
const customerQuery = ref('');
const customerResults = ref<Customer[]>([]);
const isSearchingCustomers = ref(false);
const showNewCustomerForm = ref(false);
const newCustomer = ref({
    first_name: '',
    last_name: '',
    email: '',
    phone_number: '',
    company_name: '',
    lead_source_id: null as number | null,
});

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

// Current item being added
const newItem = ref<RepairItem>({
    id: '',
    title: '',
    description: '',
    category_id: undefined,
    vendor_cost: 0,
    customer_cost: 0,
});

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

// Customer search
const searchCustomers = debounce(async (query: string) => {
    if (!query || query.length < 2) {
        customerResults.value = [];
        return;
    }
    isSearchingCustomers.value = true;
    try {
        const response = await fetch(`/repairs/search-customers?query=${encodeURIComponent(query)}`);
        const data = await response.json();
        customerResults.value = data.customers;
    } finally {
        isSearchingCustomers.value = false;
    }
}, 300);

watch(customerQuery, (value) => {
    searchCustomers(value);
});

function selectCustomer(customer: Customer) {
    formData.value.customer_id = customer.id;
    formData.value.customer = customer;
    customerQuery.value = '';
    customerResults.value = [];
    showNewCustomerForm.value = false;
}

function clearCustomer() {
    formData.value.customer_id = null;
    formData.value.customer = null;
}

function startNewCustomer() {
    showNewCustomerForm.value = true;
    formData.value.customer_id = null;
    newCustomer.value = { first_name: '', last_name: '', email: '', phone_number: '', company_name: '', lead_source_id: null };
}

function cancelNewCustomer() {
    showNewCustomerForm.value = false;
}

function confirmNewCustomer() {
    formData.value.customer = {
        id: 0,
        first_name: newCustomer.value.first_name,
        last_name: newCustomer.value.last_name,
        full_name: `${newCustomer.value.first_name} ${newCustomer.value.last_name}`,
        email: newCustomer.value.email,
        phone_number: newCustomer.value.phone_number,
        company_name: newCustomer.value.company_name,
        lead_source_id: newCustomer.value.lead_source_id,
    };
    showNewCustomerForm.value = false;
}

// Vendor search
const searchVendors = debounce(async (query: string) => {
    if (!query || query.length < 2) {
        vendorResults.value = [];
        return;
    }
    isSearchingVendors.value = true;
    try {
        const response = await fetch(`/repairs/search-vendors?query=${encodeURIComponent(query)}`);
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

// Item management
function addItem() {
    if (!newItem.value.title) return;

    formData.value.items.push({
        ...newItem.value,
        id: crypto.randomUUID(),
    });

    newItem.value = {
        id: '',
        title: '',
        description: '',
        category_id: undefined,
        vendor_cost: 0,
        customer_cost: 0,
    };
}

function removeItem(id: string) {
    formData.value.items = formData.value.items.filter(item => item.id !== id);
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
    const category = props.categories.find(c => c.value === categoryId);
    return category?.label || '';
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

    router.post('/repairs', payload, {
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
    <Head title="New Repair" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-4xl">
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

                <!-- Step Content -->
                <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                    <!-- Step 1: Employee -->
                    <div v-if="currentStep === 1">
                        <h2 class="mb-6 text-xl font-semibold text-gray-900 dark:text-white">Select Employee</h2>
                        <div class="space-y-4">
                            <label v-for="user in storeUsers" :key="user.id" class="flex cursor-pointer items-center gap-4 rounded-lg border p-4 transition-colors" :class="formData.store_user_id === user.id ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600'">
                                <input
                                    type="radio"
                                    :value="user.id"
                                    v-model="formData.store_user_id"
                                    class="size-4 border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                        <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                    </div>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ user.name }}</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Step 2: Customer -->
                    <div v-else-if="currentStep === 2">
                        <h2 class="mb-6 text-xl font-semibold text-gray-900 dark:text-white">Select or Create Customer</h2>

                        <!-- Selected Customer -->
                        <div v-if="formData.customer_id || formData.customer" class="mb-6 flex items-center justify-between rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
                            <div class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                    <UserIcon class="size-6 text-green-600 dark:text-green-400" />
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ formData.customer?.full_name }}</p>
                                    <p v-if="formData.customer?.email" class="text-sm text-gray-500 dark:text-gray-400">{{ formData.customer.email }}</p>
                                </div>
                            </div>
                            <button type="button" @click="clearCustomer" class="rounded p-1 text-gray-400 hover:text-gray-500">
                                <XMarkIcon class="size-5" />
                            </button>
                        </div>

                        <!-- Search / New Customer Form -->
                        <div v-else>
                            <div v-if="!showNewCustomerForm">
                                <div class="relative mb-4">
                                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                    <input
                                        v-model="customerQuery"
                                        type="text"
                                        placeholder="Search customers by name, email, or phone..."
                                        class="w-full rounded-lg border-0 py-3 pl-10 pr-4 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>

                                <!-- Search Results -->
                                <div v-if="customerResults.length > 0" class="mb-4 max-h-64 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                                    <button
                                        v-for="customer in customerResults"
                                        :key="customer.id"
                                        type="button"
                                        @click="selectCustomer(customer)"
                                        class="flex w-full items-center gap-3 rounded-lg p-3 text-left hover:bg-gray-100 dark:hover:bg-gray-700"
                                    >
                                        <UserIcon class="size-8 text-gray-400" />
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-white">{{ customer.full_name }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ customer.email || customer.phone_number }}</p>
                                        </div>
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    @click="startNewCustomer"
                                    class="inline-flex items-center gap-2 text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    <PlusIcon class="size-5" />
                                    Create New Customer
                                </button>
                            </div>

                            <!-- New Customer Form -->
                            <div v-else class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name *</label>
                                        <input v-model="newCustomer.first_name" type="text" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name *</label>
                                        <input v-model="newCustomer.last_name" type="text" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Company Name</label>
                                    <input v-model="newCustomer.company_name" type="text" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                        <input v-model="newCustomer.email" type="email" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                        <input v-model="newCustomer.phone_number" type="tel" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Lead Source</label>
                                    <LeadSourceSelect
                                        v-model="newCustomer.lead_source_id"
                                        placeholder="Select or create lead source..."
                                        class="mt-1"
                                    />
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" @click="cancelNewCustomer" class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                        Cancel
                                    </button>
                                    <button type="button" @click="confirmNewCustomer" :disabled="!newCustomer.first_name || !newCustomer.last_name" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                                        Confirm Customer
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Items -->
                    <div v-else-if="currentStep === 3">
                        <h2 class="mb-6 text-xl font-semibold text-gray-900 dark:text-white">Repair Items</h2>

                        <!-- Items List -->
                        <div v-if="formData.items.length > 0" class="mb-6 space-y-3">
                            <div
                                v-for="item in formData.items"
                                :key="item.id"
                                class="flex items-center justify-between rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                        <CubeIcon class="size-5 text-gray-400" />
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p v-if="item.category_id" class="text-sm text-gray-500 dark:text-gray-400">{{ getCategoryLabel(item.category_id) }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4">
                                    <div class="text-right">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">Vendor: {{ formatCurrency(item.vendor_cost) }}</p>
                                        <p class="font-medium text-gray-900 dark:text-white">Customer: {{ formatCurrency(item.customer_cost) }}</p>
                                    </div>
                                    <button type="button" @click="removeItem(item.id)" class="rounded p-1 text-red-400 hover:text-red-500">
                                        <TrashIcon class="size-5" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Add Item Form -->
                        <div class="space-y-4 rounded-lg border border-dashed border-gray-300 p-4 dark:border-gray-600">
                            <h3 class="font-medium text-gray-900 dark:text-white">Add Item</h3>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Item Title *</label>
                                    <input v-model="newItem.title" type="text" placeholder="e.g., Ring Repair, Watch Battery Replacement" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                    <select v-model="newItem.category_id" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                                        <option :value="undefined">Select category...</option>
                                        <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                    <textarea v-model="newItem.description" rows="2" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Vendor Cost ($)</label>
                                    <input v-model.number="newItem.vendor_cost" type="number" step="0.01" min="0" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Customer Cost ($)</label>
                                    <input v-model.number="newItem.customer_cost" type="number" step="0.01" min="0" class="mt-1 block w-full rounded-md border-0 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="addItem"
                                :disabled="!newItem.title"
                                class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                            >
                                <PlusIcon class="size-4" />
                                Add Item
                            </button>
                        </div>
                    </div>

                    <!-- Step 4: Vendor (Optional) -->
                    <div v-else-if="currentStep === 4">
                        <h2 class="mb-6 text-xl font-semibold text-gray-900 dark:text-white">Select Repair Vendor (Optional)</h2>
                        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                            You can skip this step if you want to assign a vendor later.
                        </p>

                        <!-- Selected Vendor -->
                        <div v-if="formData.vendor_id || formData.vendor" class="mb-6 flex items-center justify-between rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
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
                                            <p class="font-medium text-gray-900 dark:text-white">{{ vendor.display_name || vendor.name }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ vendor.email }}</p>
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
                    <div v-else-if="currentStep === 5">
                        <h2 class="mb-6 text-xl font-semibold text-gray-900 dark:text-white">Review & Create Repair</h2>

                        <div class="space-y-6">
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

                            <!-- Summary -->
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-600">
                                    <h3 class="mb-3 font-medium text-gray-900 dark:text-white">Details</h3>
                                    <dl class="space-y-2 text-sm">
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Employee</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ selectedEmployee?.name }}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Customer</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formData.customer?.full_name }}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Vendor</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formData.vendor?.display_name || formData.vendor?.name || 'Not assigned' }}</dd>
                                        </div>
                                        <div v-if="formData.warehouse_id" class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Warehouse</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ selectedWarehouse?.label }}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Items</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formData.items.length }}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-600">
                                    <h3 class="mb-3 font-medium text-gray-900 dark:text-white">Pricing</h3>
                                    <dl class="space-y-2 text-sm">
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Vendor Total</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formatCurrency(totalVendorCost) }}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Customer Subtotal</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formatCurrency(subtotal) }}</dd>
                                        </div>
                                        <div v-if="formData.service_fee > 0" class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Service Fee</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formatCurrency(formData.service_fee) }}</dd>
                                        </div>
                                        <div v-if="formData.discount > 0" class="flex justify-between text-green-600">
                                            <dt>Discount</dt>
                                            <dd>-{{ formatCurrency(formData.discount) }}</dd>
                                        </div>
                                        <div v-if="taxAmount > 0" class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Tax</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formatCurrency(taxAmount) }}</dd>
                                        </div>
                                        <div class="flex justify-between border-t border-gray-200 pt-2 font-medium dark:border-gray-700">
                                            <dt class="text-gray-900 dark:text-white">Total</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formatCurrency(total) }}</dd>
                                        </div>
                                        <div class="flex justify-between" :class="profit >= 0 ? 'text-green-600' : 'text-red-600'">
                                            <dt>Estimated Profit</dt>
                                            <dd>{{ formatCurrency(profit) }}</dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>

                            <!-- Errors -->
                            <div v-if="Object.keys(errors).length > 0" class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                                <ul class="list-inside list-disc text-sm text-red-600 dark:text-red-400">
                                    <li v-for="(error, key) in errors" :key="key">{{ error }}</li>
                                </ul>
                            </div>
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
                            <span v-else>Create Repair</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
