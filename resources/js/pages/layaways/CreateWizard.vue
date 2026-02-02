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
    CalendarIcon,
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

interface TermOption {
    value: number;
    label: string;
}

interface PaymentType {
    value: string;
    label: string;
}

interface PaymentFrequency {
    value: string;
    label: string;
}

interface Warehouse {
    value: number;
    label: string;
    tax_rate: number | null;
}

interface Customer {
    id?: number;
    first_name: string;
    last_name?: string;
    full_name?: string;
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
    charge_taxes?: boolean;
}

interface LayawayItem {
    id: string;
    product_id: number;
    product: Product;
    price: number;
    quantity: number;
    tax_rate: number;
    title?: string;
    description?: string;
}

interface Props {
    storeUsers: StoreUser[];
    currentStoreUserId: number | null;
    categories: Category[];
    termOptions: TermOption[];
    paymentTypes: PaymentType[];
    paymentFrequencies: PaymentFrequency[];
    warehouses: Warehouse[];
    defaultWarehouseId: number | null;
    defaultTaxRate: number;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Layaways', href: '/layaways' },
    { title: 'New Layaway', href: '/layaways/create' },
];

// Wizard state
const currentStep = ref(1);
const totalSteps = 5;
const isSubmitting = ref(false);

// Form data
const getDefaultStoreUserId = () => {
    if (props.currentStoreUserId && props.storeUsers.some(u => u.id === props.currentStoreUserId)) {
        return props.currentStoreUserId;
    }
    return null;
};

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
    items: [] as LayawayItem[],
    warehouse_id: getDefaultWarehouseId(),

    // Step 4: Terms
    payment_type: 'flexible',
    term_days: 90,
    minimum_deposit_percent: 10,
    cancellation_fee_percent: 10,
    num_payments: 4,
    payment_frequency: 'biweekly',

    // Step 5: Review
    admin_notes: '',
});

// Watch warehouse changes to update default tax rate for new items
const defaultTaxRate = computed(() => getTaxRateForWarehouse(formData.value.warehouse_id));

const selectedWarehouse = computed(() => {
    return props.warehouses.find(w => w.value === formData.value.warehouse_id);
});

// Steps configuration
const steps = [
    { number: 1, name: 'Select Employee', icon: UserIcon },
    { number: 2, name: 'Customer', icon: UserGroupIcon },
    { number: 3, name: 'Select Products', icon: CubeIcon },
    { number: 4, name: 'Terms', icon: CalendarIcon },
    { number: 5, name: 'Review', icon: ClipboardDocumentCheckIcon },
];

// Customer search
const customerSearchQuery = ref('');
const customerSearchResults = ref<Customer[]>([]);
const isSearchingCustomers = ref(false);
const isCreatingCustomer = ref(false);
const selectedCustomer = ref<Customer | null>(null);
const newCustomer = ref<Customer>({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
});

const searchCustomers = useDebounceFn(async (query: string) => {
    if (!query || query.length < 1) {
        customerSearchResults.value = [];
        return;
    }

    isSearchingCustomers.value = true;
    try {
        const response = await axios.get('/layaways/search-customers', { params: { query } });
        customerSearchResults.value = response.data.customers;
    } catch (error) {
        console.error('Error searching customers:', error);
        customerSearchResults.value = [];
    } finally {
        isSearchingCustomers.value = false;
    }
}, 300);

watch(customerSearchQuery, query => {
    searchCustomers(query);
});

function selectCustomer(customer: Customer | null) {
    if (customer && 'isCreateOption' in customer) {
        isCreatingCustomer.value = true;
        newCustomer.value.first_name = customerSearchQuery.value.trim();
        formData.value.customer = { ...newCustomer.value };
        formData.value.customer_id = null;
    } else if (customer) {
        selectedCustomer.value = customer;
        formData.value.customer_id = customer.id ?? null;
        formData.value.customer = null;
    }
}

function clearCustomerSelection() {
    selectedCustomer.value = null;
    customerSearchQuery.value = '';
    formData.value.customer_id = null;
    formData.value.customer = null;
    isCreatingCustomer.value = false;
}

function switchToCustomerCreate() {
    isCreatingCustomer.value = true;
    selectedCustomer.value = null;
    formData.value.customer = { ...newCustomer.value };
    formData.value.customer_id = null;
}

function switchToCustomerSearch() {
    isCreatingCustomer.value = false;
    newCustomer.value = { first_name: '', last_name: '', email: '', phone: '' };
    formData.value.customer = null;
}

function updateNewCustomer() {
    formData.value.customer = { ...newCustomer.value };
    formData.value.customer_id = null;
}

const customerFilteredOptions = computed(() => {
    const results = [...customerSearchResults.value];
    if (customerSearchQuery.value.length > 0) {
        results.push({ isCreateOption: true, full_name: 'Create new customer' } as any);
    }
    return results;
});

const customerDisplayName = computed(() => {
    if (formData.value.customer) {
        return `${formData.value.customer.first_name} ${formData.value.customer.last_name || ''}`.trim();
    }
    if (selectedCustomer.value) {
        return selectedCustomer.value.full_name || `${selectedCustomer.value.first_name} ${selectedCustomer.value.last_name || ''}`.trim();
    }
    return null;
});

// Product search
const productSearchQuery = ref('');
const productSearchResults = ref<Product[]>([]);
const isSearchingProducts = ref(false);
const selectedCategoryId = ref<number | null>(null);

const searchProducts = useDebounceFn(async () => {
    isSearchingProducts.value = true;
    try {
        const response = await axios.get('/layaways/search-products', {
            params: {
                query: productSearchQuery.value,
                category_id: selectedCategoryId.value,
            },
        });
        productSearchResults.value = response.data.products;
    } catch (error) {
        console.error('Error searching products:', error);
        productSearchResults.value = [];
    } finally {
        isSearchingProducts.value = false;
    }
}, 300);

watch([productSearchQuery, selectedCategoryId], () => {
    if (productSearchQuery.value) {
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
        id: crypto.randomUUID(),
        product_id: product.id,
        product,
        price: product.price ?? 0,
        quantity: 1,
        tax_rate: product.charge_taxes ? defaultTaxRate.value : 0,
        title: product.title,
        description: product.description,
    });

    // Clear search after adding
    productSearchQuery.value = '';
    productSearchResults.value = [];
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

function updateItemQuantity(itemId: string, quantity: number) {
    const item = formData.value.items.find(i => i.id === itemId);
    if (item) {
        item.quantity = quantity;
    }
}

function updateItemTaxRate(itemId: string, taxRate: number) {
    const item = formData.value.items.find(i => i.id === itemId);
    if (item) {
        item.tax_rate = taxRate;
    }
}

function isProductAdded(productId: number): boolean {
    return formData.value.items.some(item => item.product_id === productId);
}

// Calculated values
const subtotal = computed(() => {
    return formData.value.items.reduce((sum, item) => sum + item.price * item.quantity, 0);
});

const taxAmount = computed(() => {
    return formData.value.items.reduce((sum, item) => {
        return sum + (item.price * item.quantity * item.tax_rate);
    }, 0);
});

const total = computed(() => {
    return subtotal.value + taxAmount.value;
});

const minimumDeposit = computed(() => {
    return total.value * (formData.value.minimum_deposit_percent / 100);
});

const selectedStoreUser = computed(() => {
    return props.storeUsers.find(u => u.id === formData.value.store_user_id);
});

// Step validation
const canProceed = computed(() => {
    switch (currentStep.value) {
        case 1:
            return formData.value.store_user_id !== null;
        case 2:
            return formData.value.customer_id !== null || (formData.value.customer?.first_name);
        case 3:
            return formData.value.items.length > 0;
        case 4:
            if (formData.value.payment_type === 'scheduled') {
                return formData.value.num_payments >= 2 && formData.value.payment_frequency;
            }
            return true;
        case 5:
            return true;
        default:
            return false;
    }
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

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function getTermLabel(days: number): string {
    const term = props.termOptions.find(t => t.value === days);
    return term?.label || `${days} Days`;
}

// Submit
function submit() {
    if (!canProceed.value || isSubmitting.value) return;

    isSubmitting.value = true;

    // Calculate effective tax rate as weighted average
    const effectiveTaxRate = subtotal.value > 0 ? taxAmount.value / subtotal.value : 0;

    const data = {
        store_user_id: formData.value.store_user_id,
        customer_id: formData.value.customer_id,
        customer: formData.value.customer_id ? null : formData.value.customer,
        items: formData.value.items.map(item => ({
            product_id: item.product_id,
            price: item.price,
            quantity: item.quantity,
            tax_rate: item.tax_rate,
            title: item.title,
            description: item.description,
        })),
        payment_type: formData.value.payment_type,
        term_days: formData.value.term_days,
        minimum_deposit_percent: formData.value.minimum_deposit_percent,
        cancellation_fee_percent: formData.value.cancellation_fee_percent,
        num_payments: formData.value.payment_type === 'scheduled' ? formData.value.num_payments : null,
        payment_frequency: formData.value.payment_type === 'scheduled' ? formData.value.payment_frequency : null,
        warehouse_id: formData.value.warehouse_id,
        tax_rate: effectiveTaxRate,
        admin_notes: formData.value.admin_notes,
    };

    router.post('/layaways', data, {
        preserveState: false,
        preserveScroll: false,
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <Head title="New Layaway" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Wizard Steps -->
                <nav aria-label="Progress" class="mb-8">
                    <ol role="list" class="flex items-center justify-between">
                        <li v-for="(step, stepIdx) in steps" :key="step.name" class="relative flex flex-1 items-center">
                            <div v-if="stepIdx !== 0" class="absolute left-0 top-1/2 h-0.5 w-full -translate-y-1/2" :class="step.number <= currentStep ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700'" />
                            <button
                                type="button"
                                @click="step.number < currentStep && goToStep(step.number)"
                                :disabled="step.number > currentStep"
                                class="relative z-10 flex size-10 items-center justify-center rounded-full border-2 transition-colors"
                                :class="[
                                    step.number < currentStep ? 'border-indigo-600 bg-indigo-600 text-white' : '',
                                    step.number === currentStep ? 'border-indigo-600 bg-white text-indigo-600 dark:bg-gray-800' : '',
                                    step.number > currentStep ? 'border-gray-300 bg-white text-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-500' : '',
                                ]"
                            >
                                <CheckCircleIcon v-if="step.number < currentStep" class="size-5" />
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
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Layaway Summary</h3>

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

                                <!-- Customer Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Customer</span>
                                        <button v-if="customerDisplayName && currentStep !== 2" type="button" @click="goToStep(2)" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="customerDisplayName" class="mt-1">
                                        <p class="text-sm text-gray-900 dark:text-white">{{ customerDisplayName }}</p>
                                        <p v-if="selectedCustomer?.email || formData.customer?.email" class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ selectedCustomer?.email || formData.customer?.email }}
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
                                            <span class="truncate text-gray-700 dark:text-gray-300">{{ item.product.title }} x {{ item.quantity }}</span>
                                            <span class="ml-2 font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.price * item.quantity) }}</span>
                                        </div>
                                        <p v-if="formData.items.length > 3" class="text-xs text-gray-500 dark:text-gray-400">+{{ formData.items.length - 3 }} more items</p>
                                        <div class="mt-2 space-y-1 border-t border-gray-100 pt-2 dark:border-gray-700">
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500 dark:text-gray-400">Subtotal</span>
                                                <span class="text-gray-900 dark:text-white">{{ formatCurrency(subtotal) }}</span>
                                            </div>
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500 dark:text-gray-400">Tax</span>
                                                <span class="text-gray-900 dark:text-white">{{ formatCurrency(taxAmount) }}</span>
                                            </div>
                                            <div class="flex items-center justify-between font-medium">
                                                <span class="text-sm text-gray-700 dark:text-gray-300">Total</span>
                                                <span class="text-sm font-bold text-gray-900 dark:text-white">{{ formatCurrency(total) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">No items added</p>
                                </div>

                                <!-- Terms Summary -->
                                <div v-if="currentStep >= 4" class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Terms</span>
                                        <button v-if="currentStep !== 4" type="button" @click="goToStep(4)" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div class="mt-2 space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-gray-500 dark:text-gray-400">Type</span>
                                            <span class="text-gray-900 dark:text-white">{{ formData.payment_type === 'flexible' ? 'Flexible' : 'Scheduled' }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500 dark:text-gray-400">Term</span>
                                            <span class="text-gray-900 dark:text-white">{{ getTermLabel(formData.term_days) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500 dark:text-gray-400">Min Deposit</span>
                                            <span class="text-gray-900 dark:text-white">{{ formData.minimum_deposit_percent }}% ({{ formatCurrency(minimumDeposit) }})</span>
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
                                <!-- Step 1: Select Employee -->
                                <div v-if="currentStep === 1" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Select Employee</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose the employee handling this layaway.</p>
                                    </div>
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <button
                                            v-for="user in storeUsers"
                                            :key="user.id"
                                            type="button"
                                            @click="formData.store_user_id = user.id"
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

                                <!-- Step 2: Customer -->
                                <div v-else-if="currentStep === 2" class="space-y-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Customer Information</h2>
                                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Search for an existing customer or create a new one.</p>
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="button" @click="switchToCustomerSearch" :class="['rounded-md px-3 py-1.5 text-sm font-medium', !isCreatingCustomer ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400']">Search</button>
                                            <button type="button" @click="switchToCustomerCreate" :class="['rounded-md px-3 py-1.5 text-sm font-medium', isCreatingCustomer ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400']">Create New</button>
                                        </div>
                                    </div>

                                    <!-- Search Mode -->
                                    <template v-if="!isCreatingCustomer">
                                        <div v-if="selectedCustomer" class="flex items-center justify-between rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-700">
                                            <div class="flex items-center gap-3">
                                                <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                                    <UserGroupIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                                </div>
                                                <div>
                                                    <p class="text-base font-medium text-gray-900 dark:text-white">{{ selectedCustomer.full_name }}</p>
                                                    <p v-if="selectedCustomer.email || selectedCustomer.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ selectedCustomer.email || selectedCustomer.phone }}</p>
                                                </div>
                                            </div>
                                            <button type="button" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-600" @click="clearCustomerSelection">
                                                <XMarkIcon class="size-5" />
                                            </button>
                                        </div>

                                        <Combobox v-else v-model="selectedCustomer" @update:model-value="selectCustomer" as="div" class="relative">
                                            <ComboboxInput
                                                class="w-full rounded-lg border-0 bg-white py-3 pl-12 pr-10 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="Search by name, email, or phone..."
                                                @change="customerSearchQuery = $event.target.value"
                                            />
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                                <MagnifyingGlassIcon class="size-5 text-gray-400" />
                                            </div>
                                            <ComboboxButton class="absolute inset-y-0 right-0 flex items-center pr-3">
                                                <ChevronUpDownIcon class="size-5 text-gray-400" />
                                            </ComboboxButton>
                                            <ComboboxOptions v-if="customerSearchQuery.length > 0" class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm dark:bg-gray-800">
                                                <div v-if="isSearchingCustomers" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">Searching...</div>
                                                <div v-else-if="customerSearchResults.length === 0 && customerSearchQuery.length > 0" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">No customers found.</div>
                                                <ComboboxOption v-for="customer in customerFilteredOptions" :key="'isCreateOption' in customer ? 'create' : customer.id" v-slot="{ active }" :value="customer" as="template">
                                                    <li :class="['relative cursor-pointer select-none py-3 pl-4 pr-9', active ? 'bg-indigo-600 text-white' : 'text-gray-900 dark:text-white', 'isCreateOption' in customer ? 'border-t border-gray-200 dark:border-gray-700' : '']">
                                                        <template v-if="'isCreateOption' in customer">
                                                            <div class="flex items-center gap-2">
                                                                <PlusIcon class="size-5" />
                                                                <span class="font-medium">Create new customer</span>
                                                                <span v-if="customerSearchQuery" :class="active ? 'text-indigo-200' : 'text-gray-500'">"{{ customerSearchQuery }}"</span>
                                                            </div>
                                                        </template>
                                                        <template v-else>
                                                            <div class="flex items-center gap-3">
                                                                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-600">
                                                                    <UserGroupIcon :class="['size-5', active ? 'text-white' : 'text-gray-500']" />
                                                                </div>
                                                                <div>
                                                                    <p class="truncate font-medium">{{ customer.full_name }}</p>
                                                                    <p :class="['truncate text-sm', active ? 'text-indigo-200' : 'text-gray-500']">{{ customer.email || customer.phone || 'No contact info' }}</p>
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
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name <span class="text-red-500">*</span></label>
                                                <input v-model="newCustomer.first_name" type="text" @input="updateNewCustomer" class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name</label>
                                                <input v-model="newCustomer.last_name" type="text" @input="updateNewCustomer" class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                                <input v-model="newCustomer.email" type="email" @input="updateNewCustomer" class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                                <input v-model="newCustomer.phone" type="tel" @input="updateNewCustomer" class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <!-- Step 3: Products -->
                                <div v-else-if="currentStep === 3" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Select Products</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Search for products to add to this layaway.</p>
                                    </div>

                                    <!-- Warehouse Selection -->
                                    <div v-if="warehouses.length > 1" class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Warehouse / Location</label>
                                        <select
                                            v-model="formData.warehouse_id"
                                            class="mt-1 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">No warehouse (use store default)</option>
                                            <option v-for="wh in warehouses" :key="wh.value" :value="wh.value">{{ wh.label }}</option>
                                        </select>
                                        <p v-if="selectedWarehouse?.tax_rate !== null && selectedWarehouse?.tax_rate !== undefined" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Default tax rate: {{ (selectedWarehouse.tax_rate * 100).toFixed(2) }}%
                                        </p>
                                    </div>

                                    <!-- Search and filter -->
                                    <div class="flex gap-4">
                                        <div class="relative flex-1">
                                            <input
                                                v-model="productSearchQuery"
                                                type="text"
                                                placeholder="Search products by name or SKU..."
                                                class="w-full rounded-lg border-0 bg-white py-3 pl-12 pr-4 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                                <MagnifyingGlassIcon class="size-5 text-gray-400" />
                                            </div>
                                        </div>
                                        <select
                                            v-model="selectedCategoryId"
                                            class="rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">All Categories</option>
                                            <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                        </select>
                                    </div>

                                    <!-- Product results -->
                                    <div class="max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-600">
                                        <div v-if="isSearchingProducts" class="p-4 text-center text-gray-500 dark:text-gray-400">Loading...</div>
                                        <div v-else-if="!productSearchQuery" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                            <MagnifyingGlassIcon class="mx-auto size-8 text-gray-300 dark:text-gray-600" />
                                            <p class="mt-2">Type to search for products</p>
                                        </div>
                                        <div v-else-if="productSearchResults.length === 0" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                            <p>No products found for "{{ productSearchQuery }}"</p>
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
                                                            <span v-if="product.price">{{ formatCurrency(product.price) }}</span>
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
                                                            <label class="block text-xs text-gray-500 dark:text-gray-400">Price</label>
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
                                                            <label class="block text-xs text-gray-500 dark:text-gray-400">Qty</label>
                                                            <input
                                                                type="number"
                                                                :value="item.quantity"
                                                                @input="updateItemQuantity(item.id, parseInt(($event.target as HTMLInputElement).value) || 1)"
                                                                min="1"
                                                                class="mt-1 w-16 border-0 bg-transparent p-0 text-gray-900 focus:ring-0 sm:text-sm dark:text-white"
                                                            />
                                                        </div>
                                                        <div>
                                                            <label class="block text-xs text-gray-500 dark:text-gray-400">Tax Rate</label>
                                                            <div class="mt-1 flex items-center">
                                                                <input
                                                                    type="number"
                                                                    :value="(item.tax_rate * 100).toFixed(2)"
                                                                    @input="updateItemTaxRate(item.id, (parseFloat(($event.target as HTMLInputElement).value) || 0) / 100)"
                                                                    min="0"
                                                                    max="100"
                                                                    step="0.01"
                                                                    class="w-16 border-0 bg-transparent p-0 text-gray-900 focus:ring-0 sm:text-sm dark:text-white"
                                                                />
                                                                <span class="text-gray-500 dark:text-gray-400">%</span>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <label class="block text-xs text-gray-500 dark:text-gray-400">Line Total</label>
                                                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                                                                {{ formatCurrency(item.price * item.quantity * (1 + item.tax_rate)) }}
                                                            </p>
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

                                <!-- Step 4: Terms -->
                                <div v-else-if="currentStep === 4" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Layaway Terms</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Configure the payment terms for this layaway.</p>
                                    </div>

                                    <!-- Payment Type -->
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Type</label>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div
                                                v-for="pt in paymentTypes"
                                                :key="pt.value"
                                                :class="[
                                                    'cursor-pointer rounded-lg border-2 p-4',
                                                    formData.payment_type === pt.value
                                                        ? 'border-indigo-600 bg-indigo-50 dark:border-indigo-500 dark:bg-indigo-900/20'
                                                        : 'border-gray-200 hover:border-gray-300 dark:border-gray-700',
                                                ]"
                                                @click="formData.payment_type = pt.value"
                                            >
                                                <p class="font-medium text-gray-900 dark:text-white">{{ pt.label }}</p>
                                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                                    {{ pt.value === 'flexible' ? 'Customer pays any amount, anytime' : 'Fixed payments on scheduled dates' }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Term Length -->
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Term Length</label>
                                        <select
                                            v-model="formData.term_days"
                                            class="w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option v-for="term in termOptions" :key="term.value" :value="term.value">{{ term.label }}</option>
                                        </select>
                                    </div>

                                    <!-- Scheduled Payment Options -->
                                    <div v-if="formData.payment_type === 'scheduled'" class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                        <h3 class="font-medium text-gray-900 dark:text-white">Scheduled Payment Options</h3>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Number of Payments</label>
                                                <input
                                                    v-model.number="formData.num_payments"
                                                    type="number"
                                                    min="2"
                                                    max="24"
                                                    class="mt-1 w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Frequency</label>
                                                <select
                                                    v-model="formData.payment_frequency"
                                                    class="mt-1 w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option v-for="freq in paymentFrequencies" :key="freq.value" :value="freq.value">{{ freq.label }}</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Deposit & Fee Percentages -->
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Minimum Deposit (%)</label>
                                            <input
                                                v-model.number="formData.minimum_deposit_percent"
                                                type="number"
                                                min="0"
                                                max="100"
                                                class="mt-1 w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ formatCurrency(minimumDeposit) }} required to activate
                                            </p>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cancellation Fee (%)</label>
                                            <input
                                                v-model.number="formData.cancellation_fee_percent"
                                                type="number"
                                                min="0"
                                                max="100"
                                                class="mt-1 w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 5: Review -->
                                <div v-else-if="currentStep === 5" class="space-y-6">
                                    <div>
                                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Review Layaway</h2>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Review the details and confirm to create the layaway.</p>
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
                                                <dt class="text-gray-500 dark:text-gray-400">Customer</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ customerDisplayName }}</dd>
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
                                                <dt class="text-gray-500 dark:text-gray-400">Payment Type</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ formData.payment_type === 'flexible' ? 'Flexible' : 'Scheduled' }}</dd>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-gray-500 dark:text-gray-400">Term</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ getTermLabel(formData.term_days) }}</dd>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-gray-500 dark:text-gray-400">Minimum Deposit</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ formData.minimum_deposit_percent }}% ({{ formatCurrency(minimumDeposit) }})</dd>
                                            </div>
                                            <div class="flex justify-between border-t border-gray-200 pt-2 text-sm dark:border-gray-600">
                                                <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ formatCurrency(subtotal) }}</dd>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-gray-500 dark:text-gray-400">Tax</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ formatCurrency(taxAmount) }}</dd>
                                            </div>
                                            <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-medium dark:border-gray-600">
                                                <dt class="text-gray-900 dark:text-white">Total</dt>
                                                <dd class="text-gray-900 dark:text-white">{{ formatCurrency(total) }}</dd>
                                            </div>
                                        </dl>
                                    </div>

                                    <!-- Notes -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Internal Notes</label>
                                        <textarea
                                            v-model="formData.admin_notes"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            placeholder="Add any internal notes about this layaway..."
                                        ></textarea>
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
                                        <span v-if="currentStep >= 3" class="text-sm text-gray-500 dark:text-gray-400">
                                            Total: <span class="font-semibold text-gray-900 dark:text-white">{{ formatCurrency(total) }}</span>
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
                                            @click="submit"
                                            :disabled="!canProceed || isSubmitting"
                                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ isSubmitting ? 'Creating...' : 'Create Layaway' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
