<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { router, Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { useBarcodeScanner } from '@/composables/useBarcodeScanner';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    CheckIcon,
    MagnifyingGlassIcon,
    PlusIcon,
    TrashIcon,
    UserIcon,
    CubeIcon,
    ShoppingCartIcon,
    ClipboardDocumentListIcon,
    XMarkIcon,
    QrCodeIcon,
    ArrowsRightLeftIcon,
    ScaleIcon,
} from '@heroicons/vue/24/outline';

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
    tax_rate?: number;
}

interface PreciousMetal {
    value: string;
    label: string;
}

interface ItemCondition {
    value: string;
    label: string;
}

interface TradeInItem {
    title: string;
    description?: string;
    category_id?: number;
    buy_price: number;
    precious_metal?: string;
    condition?: string;
    dwt?: number;
}

interface Customer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email?: string;
    phone?: string;
}

interface Product {
    id: number;
    variant_id?: number;
    title: string;
    sku?: string;
    description?: string;
    price: number;
    cost?: number;
    quantity: number;
    category?: string;
    image?: string;
}

interface OrderItem {
    product_id: number;
    variant_id?: number;
    title: string;
    sku?: string;
    quantity: number;
    price: number;
    cost?: number;
    discount: number;
    notes?: string;
    image?: string;
}

interface Props {
    storeUsers: StoreUser[];
    currentStoreUserId?: number;
    categories: Category[];
    warehouses: Warehouse[];
    defaultWarehouseId?: number;
    defaultTaxRate: number;
    preciousMetals: PreciousMetal[];
    itemConditions: ItemCondition[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: 'New Order', href: '/orders/create' },
];

// Form state
const form = useForm({
    store_user_id: props.currentStoreUserId ?? null,
    customer_id: null as number | null,
    customer: null as { first_name: string; last_name: string; email?: string; phone?: string } | null,
    items: [] as OrderItem[],
    warehouse_id: props.defaultWarehouseId ?? null,
    tax_rate: props.defaultTaxRate,
    shipping_cost: 0,
    discount_cost: 0,
    notes: '',
    billing_address: null as Record<string, string> | null,
    shipping_address: null as Record<string, string> | null,
    has_trade_in: false,
    trade_in_items: [] as TradeInItem[],
    excess_credit_payout_method: 'cash' as 'cash' | 'check',
});

// Wizard state
const currentStep = ref(1);
const totalSteps = 5;

// Step 1: Employee Selection
const selectedEmployee = computed(() => {
    return props.storeUsers.find(u => u.id === form.store_user_id);
});

// Step 2: Customer Search/Create
const customerSearchQuery = ref('');
const customerSearchResults = ref<Customer[]>([]);
const isSearchingCustomers = ref(false);
const selectedCustomer = ref<Customer | null>(null);
const isCreatingNewCustomer = ref(false);
const newCustomer = ref({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
});

let customerSearchTimeout: ReturnType<typeof setTimeout> | null = null;

function searchCustomers() {
    if (customerSearchTimeout) {
        clearTimeout(customerSearchTimeout);
    }

    if (!customerSearchQuery.value || customerSearchQuery.value.length < 2) {
        customerSearchResults.value = [];
        return;
    }

    customerSearchTimeout = setTimeout(async () => {
        isSearchingCustomers.value = true;
        try {
            const response = await fetch(`/orders/search-customers?query=${encodeURIComponent(customerSearchQuery.value)}`);
            const data = await response.json();
            customerSearchResults.value = data.customers || [];
        } catch (error) {
            console.error('Error searching customers:', error);
            customerSearchResults.value = [];
        } finally {
            isSearchingCustomers.value = false;
        }
    }, 300);
}

function selectCustomer(customer: Customer) {
    selectedCustomer.value = customer;
    form.customer_id = customer.id;
    form.customer = null;
    customerSearchQuery.value = '';
    customerSearchResults.value = [];
    isCreatingNewCustomer.value = false;
}

function clearSelectedCustomer() {
    selectedCustomer.value = null;
    form.customer_id = null;
    form.customer = null;
}

function startCreatingCustomer() {
    isCreatingNewCustomer.value = true;
    selectedCustomer.value = null;
    form.customer_id = null;
}

function cancelCreatingCustomer() {
    isCreatingNewCustomer.value = false;
    newCustomer.value = { first_name: '', last_name: '', email: '', phone: '' };
}

function confirmNewCustomer() {
    if (!newCustomer.value.first_name || !newCustomer.value.last_name) return;

    form.customer = {
        first_name: newCustomer.value.first_name,
        last_name: newCustomer.value.last_name,
        email: newCustomer.value.email || undefined,
        phone: newCustomer.value.phone || undefined,
    };
    form.customer_id = null;

    selectedCustomer.value = {
        id: 0,
        first_name: newCustomer.value.first_name,
        last_name: newCustomer.value.last_name,
        full_name: `${newCustomer.value.first_name} ${newCustomer.value.last_name}`,
        email: newCustomer.value.email,
        phone: newCustomer.value.phone,
    };

    isCreatingNewCustomer.value = false;
}

watch(customerSearchQuery, searchCustomers);

// Step 3: Trade-In
const newTradeInItem = ref<TradeInItem>({
    title: '',
    description: '',
    category_id: undefined,
    buy_price: 0,
    precious_metal: '',
    condition: '',
    dwt: undefined,
});

function addTradeInItem() {
    if (!newTradeInItem.value.title || newTradeInItem.value.buy_price <= 0) return;

    form.trade_in_items.push({
        title: newTradeInItem.value.title,
        description: newTradeInItem.value.description || undefined,
        category_id: newTradeInItem.value.category_id || undefined,
        buy_price: newTradeInItem.value.buy_price,
        precious_metal: newTradeInItem.value.precious_metal || undefined,
        condition: newTradeInItem.value.condition || undefined,
        dwt: newTradeInItem.value.dwt || undefined,
    });

    // Reset form
    newTradeInItem.value = {
        title: '',
        description: '',
        category_id: undefined,
        buy_price: 0,
        precious_metal: '',
        condition: '',
        dwt: undefined,
    };
}

function removeTradeInItem(index: number) {
    form.trade_in_items.splice(index, 1);
}

const tradeInTotal = computed(() => {
    return form.trade_in_items.reduce((sum, item) => sum + item.buy_price, 0);
});

function toggleTradeIn() {
    if (!form.has_trade_in) {
        form.trade_in_items = [];
    }
}

watch(() => form.has_trade_in, toggleTradeIn);

// Step 4: Product Search/Add
const productSearchQuery = ref('');
const productSearchResults = ref<Product[]>([]);
const isSearchingProducts = ref(false);
const selectedCategoryId = ref<number | null>(null);
const isCreatingNewProduct = ref(false);
const newProduct = ref({
    title: '',
    sku: '',
    price: 0,
    cost: 0,
    category_id: null as number | null,
});

let productSearchTimeout: ReturnType<typeof setTimeout> | null = null;

function searchProducts() {
    if (productSearchTimeout) {
        clearTimeout(productSearchTimeout);
    }

    productSearchTimeout = setTimeout(async () => {
        isSearchingProducts.value = true;
        try {
            let url = `/orders/search-products?query=${encodeURIComponent(productSearchQuery.value)}`;
            if (selectedCategoryId.value) {
                url += `&category_id=${selectedCategoryId.value}`;
            }
            const response = await fetch(url);
            const data = await response.json();
            productSearchResults.value = data.products || [];
        } catch (error) {
            console.error('Error searching products:', error);
            productSearchResults.value = [];
        } finally {
            isSearchingProducts.value = false;
        }
    }, 300);
}

function addProductToOrder(product: Product) {
    // Check if product is already in the order
    const existingIndex = form.items.findIndex(item => item.product_id === product.id);
    if (existingIndex !== -1) {
        // Increment quantity
        form.items[existingIndex].quantity += 1;
    } else {
        form.items.push({
            product_id: product.id,
            variant_id: product.variant_id,
            title: product.title,
            sku: product.sku,
            quantity: 1,
            price: product.price,
            cost: product.cost,
            discount: 0,
            image: product.image,
        });
    }
}

function removeItem(index: number) {
    form.items.splice(index, 1);
}

function updateItemQuantity(index: number, quantity: number) {
    if (quantity > 0) {
        form.items[index].quantity = quantity;
    }
}

function startCreatingProduct() {
    isCreatingNewProduct.value = true;
}

function cancelCreatingProduct() {
    isCreatingNewProduct.value = false;
    newProduct.value = { title: '', sku: '', price: 0, cost: 0, category_id: null };
}

async function createNewProduct() {
    if (!newProduct.value.title || newProduct.value.price < 0) return;

    try {
        const response = await fetch('/orders/create-product', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify(newProduct.value),
        });

        if (!response.ok) throw new Error('Failed to create product');

        const data = await response.json();
        addProductToOrder(data.product);
        cancelCreatingProduct();
    } catch (error) {
        console.error('Error creating product:', error);
    }
}

watch([productSearchQuery, selectedCategoryId], searchProducts);

// Barcode Scanner
const lastScannedBarcode = ref('');
const scannerFeedback = ref<{ type: 'success' | 'error' | 'info'; message: string } | null>(null);
let feedbackTimeout: ReturnType<typeof setTimeout> | null = null;

function showScannerFeedback(type: 'success' | 'error' | 'info', message: string) {
    scannerFeedback.value = { type, message };
    if (feedbackTimeout) {
        clearTimeout(feedbackTimeout);
    }
    feedbackTimeout = setTimeout(() => {
        scannerFeedback.value = null;
    }, 3000);
}

async function handleBarcodeScan(barcode: string) {
    lastScannedBarcode.value = barcode;

    // Only process on the products step (step 4)
    if (currentStep.value !== 4) {
        // Navigate to products step first
        currentStep.value = 4;
    }

    try {
        const response = await fetch(`/orders/lookup-barcode?barcode=${encodeURIComponent(barcode)}`);
        const data = await response.json();

        if (data.found && data.product) {
            addProductToOrder(data.product);
            showScannerFeedback('success', `Added: ${data.product.title}`);

            // Play a success sound if available
            try {
                const audio = new Audio('/sounds/beep-success.mp3');
                audio.volume = 0.3;
                audio.play().catch(() => {});
            } catch {}
        } else {
            showScannerFeedback('error', `Product not found: ${barcode}`);
            // Put the barcode in the search field
            productSearchQuery.value = barcode;

            // Play an error sound if available
            try {
                const audio = new Audio('/sounds/beep-error.mp3');
                audio.volume = 0.3;
                audio.play().catch(() => {});
            } catch {}
        }
    } catch (error) {
        console.error('Barcode lookup error:', error);
        showScannerFeedback('error', 'Failed to lookup barcode');
    }
}

const { isEnabled: scannerEnabled } = useBarcodeScanner({
    onScan: handleBarcodeScan,
    maxKeystrokeDelay: 50,
    minLength: 3,
    preventDefault: true,
});

onMounted(() => {
    searchProducts();
});

// Step 4: Review & Settings
const selectedWarehouse = computed(() => {
    return props.warehouses.find(w => w.value === form.warehouse_id);
});

watch(() => form.warehouse_id, (newWarehouseId) => {
    const warehouse = props.warehouses.find(w => w.value === newWarehouseId);
    if (warehouse?.tax_rate !== undefined && warehouse?.tax_rate !== null) {
        form.tax_rate = warehouse.tax_rate;
    } else {
        form.tax_rate = props.defaultTaxRate;
    }
});

// Calculations
const subtotal = computed(() => {
    return form.items.reduce((sum, item) => {
        return sum + (item.price * item.quantity) - item.discount;
    }, 0);
});

const tradeInCredit = computed(() => {
    return form.has_trade_in ? tradeInTotal.value : 0;
});

const taxableAmount = computed(() => {
    return Math.max(0, subtotal.value - form.discount_cost - tradeInCredit.value);
});

const taxAmount = computed(() => {
    return taxableAmount.value * form.tax_rate;
});

const total = computed(() => {
    return Math.max(0, subtotal.value + form.shipping_cost + taxAmount.value - form.discount_cost - tradeInCredit.value);
});

const excessTradeInCredit = computed(() => {
    const orderTotalBeforeTradeIn = subtotal.value + form.shipping_cost + (subtotal.value - form.discount_cost) * form.tax_rate - form.discount_cost;
    return Math.max(0, tradeInCredit.value - orderTotalBeforeTradeIn);
});

// Navigation
function canProceed(): boolean {
    switch (currentStep.value) {
        case 1:
            return !!form.store_user_id;
        case 2:
            return true; // Customer is optional for walk-in sales
        case 3:
            // Trade-in step: if trade-in is enabled, must have at least one item
            return !form.has_trade_in || form.trade_in_items.length > 0;
        case 4:
            return form.items.length > 0;
        case 5:
            return true;
        default:
            return false;
    }
}

function nextStep() {
    if (canProceed() && currentStep.value < totalSteps) {
        currentStep.value++;
    }
}

function prevStep() {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
}

function goToStep(step: number) {
    if (step <= currentStep.value || canProceed()) {
        currentStep.value = step;
    }
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function submitOrder() {
    form.post('/orders', {
        preserveScroll: true,
    });
}

const steps = [
    { number: 1, title: 'Employee', icon: UserIcon },
    { number: 2, title: 'Customer', icon: UserIcon },
    { number: 3, title: 'Trade-In', icon: ArrowsRightLeftIcon },
    { number: 4, title: 'Products', icon: ShoppingCartIcon },
    { number: 5, title: 'Review', icon: ClipboardDocumentListIcon },
];
</script>

<template>
    <Head title="New Order" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-4xl">
                <!-- Header -->
                <div class="mb-6 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link href="/orders" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                        </Link>
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">New Order</h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Create a new sales order</p>
                        </div>
                    </div>
                </div>

                <!-- Progress Steps -->
                <nav class="mb-8">
                    <ol class="flex items-center">
                        <li v-for="(step, index) in steps" :key="step.number" class="flex items-center" :class="{ 'flex-1': index < steps.length - 1 }">
                            <button
                                type="button"
                                @click="goToStep(step.number)"
                                :class="[
                                    'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                                    currentStep.value >= step.number
                                        ? 'text-indigo-600 dark:text-indigo-400'
                                        : 'text-gray-500 dark:text-gray-400',
                                ]"
                            >
                                <span :class="[
                                    'flex size-8 items-center justify-center rounded-full',
                                    currentStep.value > step.number ? 'bg-indigo-600 text-white' : '',
                                    currentStep.value === step.number ? 'border-2 border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' : '',
                                    currentStep.value < step.number ? 'border-2 border-gray-300 text-gray-500 dark:border-gray-600 dark:text-gray-400' : '',
                                ]">
                                    <CheckIcon v-if="currentStep.value > step.number" class="size-5" />
                                    <span v-else>{{ step.number }}</span>
                                </span>
                                <span class="hidden sm:inline">{{ step.title }}</span>
                            </button>
                            <div v-if="index < steps.length - 1" class="mx-2 h-px flex-1 bg-gray-300 dark:bg-gray-600" />
                        </li>
                    </ol>
                </nav>

                <!-- Step Content -->
                <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                    <!-- Step 1: Employee Selection -->
                    <div v-if="currentStep === 1">
                        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Select Employee</h2>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">Choose the employee handling this order.</p>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <button
                                v-for="user in storeUsers"
                                :key="user.id"
                                type="button"
                                @click="form.store_user_id = user.id"
                                :class="[
                                    'flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-colors',
                                    form.store_user_id === user.id
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

                    <!-- Step 2: Customer Selection -->
                    <div v-if="currentStep === 2">
                        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Select Customer</h2>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">Search for an existing customer or create a new one. Leave empty for walk-in sales.</p>

                        <!-- Selected Customer Display -->
                        <div v-if="selectedCustomer" class="mb-6 flex items-center justify-between rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
                            <div class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-800">
                                    <UserIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ selectedCustomer.full_name }}</p>
                                    <p v-if="selectedCustomer.email" class="text-sm text-gray-500 dark:text-gray-400">{{ selectedCustomer.email }}</p>
                                    <p v-if="selectedCustomer.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ selectedCustomer.phone }}</p>
                                    <p v-if="selectedCustomer.id === 0" class="text-xs text-indigo-600 dark:text-indigo-400">New customer (will be created)</p>
                                </div>
                            </div>
                            <button type="button" @click="clearSelectedCustomer" class="text-gray-400 hover:text-gray-500">
                                <XMarkIcon class="size-5" />
                            </button>
                        </div>

                        <!-- Search or Create -->
                        <div v-else-if="!isCreatingNewCustomer">
                            <div class="relative mb-4">
                                <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                <input
                                    v-model="customerSearchQuery"
                                    type="text"
                                    placeholder="Search customers by name, email, or phone..."
                                    class="w-full rounded-lg border-gray-300 pl-10 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                            </div>

                            <!-- Search Results -->
                            <div v-if="customerSearchResults.length > 0" class="mb-4 max-h-60 space-y-2 overflow-y-auto">
                                <button
                                    v-for="customer in customerSearchResults"
                                    :key="customer.id"
                                    type="button"
                                    @click="selectCustomer(customer)"
                                    class="flex w-full items-center gap-3 rounded-lg border border-gray-200 p-3 text-left hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700"
                                >
                                    <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                        <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ customer.full_name }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ customer.email || customer.phone }}</p>
                                    </div>
                                </button>
                            </div>

                            <button
                                type="button"
                                @click="startCreatingCustomer"
                                class="flex items-center gap-2 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                            >
                                <PlusIcon class="size-4" />
                                Create New Customer
                            </button>
                        </div>

                        <!-- New Customer Form -->
                        <div v-else class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name *</label>
                                    <input
                                        v-model="newCustomer.first_name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name *</label>
                                    <input
                                        v-model="newCustomer.last_name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                    <input
                                        v-model="newCustomer.email"
                                        type="email"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                    <input
                                        v-model="newCustomer.phone"
                                        type="tel"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <button
                                    type="button"
                                    @click="confirmNewCustomer"
                                    :disabled="!newCustomer.first_name || !newCustomer.last_name"
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    Add Customer
                                </button>
                                <button
                                    type="button"
                                    @click="cancelCreatingCustomer"
                                    class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Trade-In -->
                    <div v-if="currentStep === 3">
                        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Trade-In Items</h2>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                            Does the customer have items to trade in? The trade-in value will be applied as credit toward this purchase.
                        </p>

                        <!-- Toggle Trade-In -->
                        <div class="mb-6">
                            <label class="flex cursor-pointer items-center gap-3">
                                <input
                                    v-model="form.has_trade_in"
                                    type="checkbox"
                                    class="size-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <span class="font-medium text-gray-900 dark:text-white">Customer has items to trade in</span>
                            </label>
                        </div>

                        <!-- Trade-In Form (shown when trade-in is enabled) -->
                        <div v-if="form.has_trade_in" class="space-y-6">
                            <!-- Add Trade-In Item Form -->
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-600">
                                <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Add Trade-In Item</h3>
                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div class="sm:col-span-2 lg:col-span-3">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Item Title *</label>
                                        <input
                                            v-model="newTradeInItem.title"
                                            type="text"
                                            placeholder="e.g., 14K Gold Ring, Silver Necklace"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                        <select
                                            v-model="newTradeInItem.category_id"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option :value="undefined">Select Category</option>
                                            <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Precious Metal</label>
                                        <select
                                            v-model="newTradeInItem.precious_metal"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option value="">Select Metal</option>
                                            <option v-for="metal in preciousMetals" :key="metal.value" :value="metal.value">{{ metal.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Condition</label>
                                        <select
                                            v-model="newTradeInItem.condition"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option value="">Select Condition</option>
                                            <option v-for="cond in itemConditions" :key="cond.value" :value="cond.value">{{ cond.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Weight (DWT)</label>
                                        <input
                                            v-model.number="newTradeInItem.dwt"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            placeholder="Pennyweight"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Buy Price *</label>
                                        <div class="relative mt-1">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                                            <input
                                                v-model.number="newTradeInItem.buy_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="block w-full rounded-md border-gray-300 pl-7 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            />
                                        </div>
                                    </div>
                                    <div class="sm:col-span-2 lg:col-span-3">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                        <textarea
                                            v-model="newTradeInItem.description"
                                            rows="2"
                                            placeholder="Additional details about the item..."
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button
                                        type="button"
                                        @click="addTradeInItem"
                                        :disabled="!newTradeInItem.title || newTradeInItem.buy_price <= 0"
                                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                    >
                                        <PlusIcon class="size-4" />
                                        Add Item
                                    </button>
                                </div>
                            </div>

                            <!-- Trade-In Items List -->
                            <div class="rounded-lg border border-gray-200 dark:border-gray-600">
                                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-600">
                                    <div class="flex items-center justify-between">
                                        <h3 class="font-medium text-gray-900 dark:text-white">Trade-In Items ({{ form.trade_in_items.length }})</h3>
                                        <span class="text-lg font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(tradeInTotal) }}</span>
                                    </div>
                                </div>
                                <div v-if="form.trade_in_items.length > 0" class="divide-y divide-gray-200 dark:divide-gray-600">
                                    <div v-for="(item, index) in form.trade_in_items" :key="index" class="flex items-center gap-4 p-4">
                                        <div class="flex size-12 shrink-0 items-center justify-center rounded bg-green-100 dark:bg-green-900">
                                            <ScaleIcon class="size-6 text-green-600 dark:text-green-400" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                                <span v-if="item.precious_metal">{{ preciousMetals.find(m => m.value === item.precious_metal)?.label }}</span>
                                                <span v-if="item.precious_metal && item.condition"> | </span>
                                                <span v-if="item.condition">{{ itemConditions.find(c => c.value === item.condition)?.label }}</span>
                                                <span v-if="(item.precious_metal || item.condition) && item.dwt"> | </span>
                                                <span v-if="item.dwt">{{ item.dwt }} DWT</span>
                                            </p>
                                        </div>
                                        <p class="font-medium text-green-600 dark:text-green-400">
                                            {{ formatCurrency(item.buy_price) }}
                                        </p>
                                        <button type="button" @click="removeTradeInItem(index)" class="text-red-500 hover:text-red-600">
                                            <TrashIcon class="size-5" />
                                        </button>
                                    </div>
                                </div>
                                <div v-else class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No trade-in items added yet. Add items above.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Products -->
                    <div v-if="currentStep === 4">
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Add Products</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Search and add products to this order.</p>
                            </div>
                            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                <QrCodeIcon class="size-5" />
                                <span>Barcode scanner ready</span>
                            </div>
                        </div>

                        <!-- Scanner Feedback Toast -->
                        <Transition
                            enter-active-class="transition ease-out duration-200"
                            enter-from-class="opacity-0 -translate-y-2"
                            enter-to-class="opacity-100 translate-y-0"
                            leave-active-class="transition ease-in duration-150"
                            leave-from-class="opacity-100 translate-y-0"
                            leave-to-class="opacity-0 -translate-y-2"
                        >
                            <div
                                v-if="scannerFeedback"
                                :class="[
                                    'mb-4 rounded-lg px-4 py-3 flex items-center gap-3',
                                    scannerFeedback.type === 'success' ? 'bg-green-50 text-green-800 dark:bg-green-900/50 dark:text-green-200' : '',
                                    scannerFeedback.type === 'error' ? 'bg-red-50 text-red-800 dark:bg-red-900/50 dark:text-red-200' : '',
                                    scannerFeedback.type === 'info' ? 'bg-blue-50 text-blue-800 dark:bg-blue-900/50 dark:text-blue-200' : '',
                                ]"
                            >
                                <QrCodeIcon class="size-5 shrink-0" />
                                <span class="font-medium">{{ scannerFeedback.message }}</span>
                            </div>
                        </Transition>

                        <!-- Product Search -->
                        <div class="mb-6 flex gap-4">
                            <div class="relative flex-1">
                                <MagnifyingGlassIcon class="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                <input
                                    v-model="productSearchQuery"
                                    type="text"
                                    placeholder="Search products by name or SKU..."
                                    class="w-full rounded-lg border-gray-300 pl-10 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                            </div>
                            <select
                                v-model="selectedCategoryId"
                                class="rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option :value="null">All Categories</option>
                                <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                            </select>
                        </div>

                        <!-- Product Search Results -->
                        <div v-if="productSearchResults.length > 0" class="mb-6 max-h-60 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-600">
                            <button
                                v-for="product in productSearchResults"
                                :key="product.id"
                                type="button"
                                @click="addProductToOrder(product)"
                                class="flex w-full items-center gap-3 rounded-lg p-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <div class="flex size-12 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                    <img v-if="product.image" :src="product.image" class="size-12 rounded object-cover" />
                                    <CubeIcon v-else class="size-6 text-gray-400" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ product.title }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        <span v-if="product.sku">SKU: {{ product.sku }}</span>
                                        <span v-if="product.sku && product.category"> | </span>
                                        <span v-if="product.category">{{ product.category }}</span>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(product.price) }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Qty: {{ product.quantity }}</p>
                                </div>
                                <PlusIcon class="size-5 text-indigo-600 dark:text-indigo-400" />
                            </button>
                        </div>

                        <button
                            type="button"
                            @click="startCreatingProduct"
                            class="mb-6 flex items-center gap-2 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                        >
                            <PlusIcon class="size-4" />
                            Create New Product
                        </button>

                        <!-- New Product Form -->
                        <div v-if="isCreatingNewProduct" class="mb-6 rounded-lg border border-gray-200 p-4 dark:border-gray-600">
                            <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Quick Add Product</h3>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Title *</label>
                                    <input
                                        v-model="newProduct.title"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">SKU</label>
                                    <input
                                        v-model="newProduct.sku"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                    <select
                                        v-model="newProduct.category_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option :value="null">Select Category</option>
                                        <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Price *</label>
                                    <input
                                        v-model.number="newProduct.price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cost</label>
                                    <input
                                        v-model.number="newProduct.cost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                            </div>
                            <div class="mt-4 flex gap-3">
                                <button
                                    type="button"
                                    @click="createNewProduct"
                                    :disabled="!newProduct.title || newProduct.price < 0"
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    Add Product
                                </button>
                                <button
                                    type="button"
                                    @click="cancelCreatingProduct"
                                    class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>

                        <!-- Selected Items -->
                        <div class="rounded-lg border border-gray-200 dark:border-gray-600">
                            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-600">
                                <h3 class="font-medium text-gray-900 dark:text-white">Order Items ({{ form.items.length }})</h3>
                            </div>
                            <div v-if="form.items.length > 0" class="divide-y divide-gray-200 dark:divide-gray-600">
                                <div v-for="(item, index) in form.items" :key="index" class="flex items-center gap-4 p-4">
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                        <img v-if="item.image" :src="item.image" class="size-12 rounded object-cover" />
                                        <CubeIcon v-else class="size-6 text-gray-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p v-if="item.sku" class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ item.sku }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input
                                            :value="item.quantity"
                                            @input="updateItemQuantity(index, parseInt(($event.target as HTMLInputElement).value) || 1)"
                                            type="number"
                                            min="1"
                                            class="w-20 rounded-md border-gray-300 text-center focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                        <span class="text-gray-500">x</span>
                                        <input
                                            v-model.number="item.price"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="w-24 rounded-md border-gray-300 text-right focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                    </div>
                                    <p class="w-24 text-right font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(item.price * item.quantity) }}
                                    </p>
                                    <button type="button" @click="removeItem(index)" class="text-red-500 hover:text-red-600">
                                        <TrashIcon class="size-5" />
                                    </button>
                                </div>
                            </div>
                            <div v-else class="p-6 text-center text-gray-500 dark:text-gray-400">
                                No items added yet. Search and add products above.
                            </div>
                        </div>
                    </div>

                    <!-- Step 5: Review -->
                    <div v-if="currentStep === 5">
                        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Review Order</h2>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">Review the order details and confirm.</p>

                        <div class="space-y-6">
                            <!-- Order Settings -->
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Location (Warehouse)</label>
                                    <select
                                        v-model="form.warehouse_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option :value="null">Select Location</option>
                                        <option v-for="warehouse in warehouses" :key="warehouse.value" :value="warehouse.value">
                                            {{ warehouse.label }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tax Rate (%)</label>
                                    <input
                                        :value="(form.tax_rate * 100).toFixed(2)"
                                        @input="form.tax_rate = parseFloat(($event.target as HTMLInputElement).value) / 100 || 0"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Shipping Cost</label>
                                    <input
                                        v-model.number="form.shipping_cost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Discount</label>
                                    <input
                                        v-model.number="form.discount_cost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                </div>
                            </div>

                            <!-- Notes -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                <textarea
                                    v-model="form.notes"
                                    rows="3"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    placeholder="Add any notes for this order..."
                                />
                            </div>

                            <!-- Summary -->
                            <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-700">
                                <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Order Summary</h3>
                                <dl class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Employee</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ selectedEmployee?.name || 'Not selected' }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Customer</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ selectedCustomer?.full_name || 'Walk-in Customer' }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Items</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ form.items.length }}</dd>
                                    </div>
                                    <div v-if="form.has_trade_in" class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Trade-In Items</dt>
                                        <dd class="text-green-600 dark:text-green-400">{{ form.trade_in_items.length }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Location</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ selectedWarehouse?.label || 'Not selected' }}</dd>
                                    </div>
                                    <div class="border-t border-gray-200 pt-2 dark:border-gray-600">
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formatCurrency(subtotal) }}</dd>
                                        </div>
                                        <div v-if="form.discount_cost > 0" class="flex justify-between text-green-600 dark:text-green-400">
                                            <dt>Discount</dt>
                                            <dd>-{{ formatCurrency(form.discount_cost) }}</dd>
                                        </div>
                                        <div v-if="tradeInCredit > 0" class="flex justify-between text-green-600 dark:text-green-400">
                                            <dt>Trade-In Credit</dt>
                                            <dd>-{{ formatCurrency(tradeInCredit) }}</dd>
                                        </div>
                                        <div v-if="form.shipping_cost > 0" class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Shipping</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formatCurrency(form.shipping_cost) }}</dd>
                                        </div>
                                        <div v-if="taxAmount > 0" class="flex justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Tax ({{ (form.tax_rate * 100).toFixed(2) }}%)</dt>
                                            <dd class="text-gray-900 dark:text-white">{{ formatCurrency(taxAmount) }}</dd>
                                        </div>
                                    </div>
                                    <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold dark:border-gray-600">
                                        <dt class="text-gray-900 dark:text-white">Customer Pays</dt>
                                        <dd class="text-indigo-600 dark:text-indigo-400">{{ formatCurrency(total) }}</dd>
                                    </div>
                                    <div v-if="excessTradeInCredit > 0" class="flex justify-between text-sm text-orange-600 dark:text-orange-400">
                                        <dt>Customer Refund (Excess Trade-In)</dt>
                                        <dd>{{ formatCurrency(excessTradeInCredit) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Buttons -->
                <div class="mt-6 flex justify-between">
                    <button
                        v-if="currentStep > 1"
                        type="button"
                        @click="prevStep"
                        class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                    >
                        <ArrowLeftIcon class="size-4" />
                        Previous
                    </button>
                    <div v-else />

                    <button
                        v-if="currentStep < totalSteps"
                        type="button"
                        @click="nextStep"
                        :disabled="!canProceed()"
                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Next
                        <ArrowRightIcon class="size-4" />
                    </button>
                    <button
                        v-else
                        type="button"
                        @click="submitOrder"
                        :disabled="form.processing || !canProceed()"
                        class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                    >
                        <CheckIcon class="size-4" />
                        {{ form.processing ? 'Creating...' : 'Create Order' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
