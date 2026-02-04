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
    UserGroupIcon,
    CubeIcon,
    ShoppingCartIcon,
    ClipboardDocumentListIcon,
    XMarkIcon,
    QrCodeIcon,
    CameraIcon,
    ArrowsRightLeftIcon,
    ScaleIcon,
    ArchiveBoxIcon,
    PencilIcon,
    CheckCircleIcon,
} from '@heroicons/vue/24/outline';
import { ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import {
    Combobox,
    ComboboxInput,
    ComboboxButton,
    ComboboxOptions,
    ComboboxOption,
} from '@headlessui/vue';
import LeadSourceSelect from '@/components/customers/LeadSourceSelect.vue';
import CreateCustomerForm, { type CustomerFormData, getEmptyCustomerForm } from '@/components/customers/CreateCustomerForm.vue';
import AddItemModal from '@/components/transactions/AddItemModal.vue';
import CameraScannerModal from '@/components/scanner/CameraScannerModal.vue';

interface StoreUser {
    id: number;
    name: string;
}

interface Category {
    value: number;
    label: string;
}

interface TradeInCategory {
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
    id: string;
    title: string;
    description?: string;
    category_id?: number;
    buy_price: number;
    attributes: Record<number, string>;
    images: File[];
}

interface BucketItemData {
    id: number;
    title: string;
    description?: string;
    value: number;
    created_at: string;
}

interface BucketData {
    id: number;
    name: string;
    total_value: number;
    items: BucketItemData[];
}

interface SelectedBucketItem {
    id: number;
    title: string;
    value: number;
    price: number;
    bucket_name: string;
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
    tradeInCategories: TradeInCategory[];
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
    date_of_purchase: new Date().toISOString().split('T')[0],
    notes: '',
    billing_address: null as Record<string, string> | null,
    shipping_address: null as Record<string, string> | null,
    has_trade_in: false,
    trade_in_items: [] as TradeInItem[],
    excess_credit_payout_method: 'cash' as 'cash' | 'check',
    sell_from_bucket: false,
    bucket_items: [] as SelectedBucketItem[],
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
const newCustomer = ref<CustomerFormData>(getEmptyCustomerForm());

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

function selectCustomer(customer: Customer | null) {
    if (customer && 'isCreateOption' in customer) {
        // Handle "Create new customer" option
        isCreatingNewCustomer.value = true;
        newCustomer.value.first_name = customerSearchQuery.value.trim();
        selectedCustomer.value = null;
        form.customer_id = null;
    } else if (customer) {
        selectedCustomer.value = customer;
        form.customer_id = customer.id;
        form.customer = null;
        customerSearchQuery.value = '';
        customerSearchResults.value = [];
        isCreatingNewCustomer.value = false;
    }
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
    newCustomer.value = getEmptyCustomerForm();
}

function confirmNewCustomer() {
    if (!newCustomer.value.first_name || !newCustomer.value.last_name) return;

    form.customer = {
        first_name: newCustomer.value.first_name,
        last_name: newCustomer.value.last_name,
        email: newCustomer.value.email || undefined,
        phone: newCustomer.value.phone || undefined,
        lead_source_id: newCustomer.value.lead_source_id || undefined,
        address: newCustomer.value.address.address_line1 ? {
            address_line1: newCustomer.value.address.address_line1,
            address_line2: newCustomer.value.address.address_line2 || undefined,
            city: newCustomer.value.address.city || undefined,
            state: newCustomer.value.address.state || undefined,
            postal_code: newCustomer.value.address.postal_code || undefined,
            country: newCustomer.value.address.country || 'US',
        } : undefined,
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

const customerFilteredOptions = computed(() => {
    const results = [...customerSearchResults.value];
    if (customerSearchQuery.value.length > 0) {
        results.push({ isCreateOption: true, full_name: 'Create new customer' } as any);
    }
    return results;
});

function switchToCustomerSearch() {
    isCreatingNewCustomer.value = false;
    newCustomer.value = getEmptyCustomerForm();
}

function switchToCustomerCreate() {
    isCreatingNewCustomer.value = true;
    selectedCustomer.value = null;
    form.customer_id = null;
}

// Step 3: Trade-In
const showAddTradeInModal = ref(false);
const editingTradeInItem = ref<TradeInItem | null>(null);
const editingTradeInIndex = ref<number | null>(null);

function openAddTradeInModal() {
    editingTradeInItem.value = null;
    editingTradeInIndex.value = null;
    showAddTradeInModal.value = true;
}

function openEditTradeInModal(item: TradeInItem, index: number) {
    editingTradeInItem.value = { ...item };
    editingTradeInIndex.value = index;
    showAddTradeInModal.value = true;
}

function handleTradeInItemSave(item: { id: string; title: string; description?: string; category_id?: number; price?: number; buy_price: number; attributes: Record<number, string>; images: File[] }) {
    const tradeInItem: TradeInItem = {
        id: item.id,
        title: item.title,
        description: item.description,
        category_id: item.category_id,
        buy_price: item.buy_price,
        attributes: item.attributes,
        images: item.images,
    };

    if (editingTradeInIndex.value !== null) {
        // Update existing item
        form.trade_in_items[editingTradeInIndex.value] = tradeInItem;
    } else {
        // Add new item
        form.trade_in_items.push(tradeInItem);
    }

    showAddTradeInModal.value = false;
    editingTradeInItem.value = null;
    editingTradeInIndex.value = null;
}

function removeTradeInItem(index: number) {
    form.trade_in_items.splice(index, 1);
}

const tradeInTotal = computed(() => {
    return form.trade_in_items.reduce((sum, item) => sum + item.buy_price, 0);
});

// Build a sorted category tree for display (only leaves are selectable)
const sortedCategories = computed(() => {
    const categories = [...props.tradeInCategories];
    const parentIds = new Set(categories.map(c => c.parent_id).filter(id => id !== null));

    const result: Array<typeof categories[0] & { isLeaf: boolean }> = [];
    const addedIds = new Set<number>();

    function addWithChildren(parentId: number | null) {
        const children = categories
            .filter(c => c.parent_id === parentId)
            .sort((a, b) => a.name.localeCompare(b.name));
        for (const child of children) {
            if (!addedIds.has(child.id)) {
                addedIds.add(child.id);
                const isLeaf = !parentIds.has(child.id);
                result.push({ ...child, isLeaf });
                addWithChildren(child.id);
            }
        }
    }

    addWithChildren(null);
    return result;
});

function toggleTradeIn() {
    if (!form.has_trade_in) {
        form.trade_in_items = [];
    }
}

watch(() => form.has_trade_in, toggleTradeIn);

// Step 3: Bucket Items (Selling from Bucket)
const availableBuckets = ref<BucketData[]>([]);
const loadingBuckets = ref(false);
const selectedBucketId = ref<number | null>(null);

async function loadBucketItems() {
    if (loadingBuckets.value) return;
    loadingBuckets.value = true;
    try {
        const response = await fetch('/orders/search-bucket-items');
        const data = await response.json();
        availableBuckets.value = data.buckets || [];
    } catch (error) {
        console.error('Error loading bucket items:', error);
        availableBuckets.value = [];
    } finally {
        loadingBuckets.value = false;
    }
}

function addBucketItemToOrder(bucketItem: BucketItemData, bucketName: string) {
    // Check if already added
    if (form.bucket_items.some(item => item.id === bucketItem.id)) {
        return;
    }

    form.bucket_items.push({
        id: bucketItem.id,
        title: bucketItem.title,
        value: bucketItem.value,
        price: bucketItem.value, // Default price to value
        bucket_name: bucketName,
    });
}

function removeBucketItem(index: number) {
    form.bucket_items.splice(index, 1);
}

const bucketItemsTotal = computed(() => {
    return form.bucket_items.reduce((sum, item) => sum + item.price, 0);
});

function toggleSellFromBucket() {
    if (!form.sell_from_bucket) {
        form.bucket_items = [];
    } else {
        // Load bucket items when enabled
        loadBucketItems();
    }
}

watch(() => form.sell_from_bucket, toggleSellFromBucket);

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

    if (!productSearchQuery.value || productSearchQuery.value.length < 2) {
        productSearchResults.value = [];
        return;
    }

    productSearchTimeout = setTimeout(async () => {
        isSearchingProducts.value = true;
        try {
            const url = `/orders/search-products?query=${encodeURIComponent(productSearchQuery.value)}`;
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
        const response = await window.axios.post('/orders/create-product', newProduct.value);
        addProductToOrder(response.data.product);
        cancelCreatingProduct();
    } catch (error) {
        console.error('Error creating product:', error);
    }
}

watch(productSearchQuery, searchProducts);

// Barcode Scanner
const lastScannedBarcode = ref('');
const scannerFeedback = ref<{ type: 'success' | 'error' | 'info'; message: string } | null>(null);
const showCameraScanner = ref(false);
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

    // Auto-select warehouse if there's only one, and set its tax rate
    if (props.warehouses.length === 1 && !form.warehouse_id) {
        form.warehouse_id = props.warehouses[0].value;
        if (props.warehouses[0].tax_rate !== undefined && props.warehouses[0].tax_rate !== null) {
            form.tax_rate = props.warehouses[0].tax_rate;
        }
    }
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
    const productTotal = form.items.reduce((sum, item) => {
        return sum + (item.price * item.quantity) - item.discount;
    }, 0);
    const bucketTotal = form.bucket_items.reduce((sum, item) => sum + item.price, 0);
    return productTotal + bucketTotal;
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
            // Bucket sale step: if sell_from_bucket is enabled, must have at least one bucket item
            const tradeInValid = !form.has_trade_in || form.trade_in_items.length > 0;
            const bucketValid = !form.sell_from_bucket || form.bucket_items.length > 0;
            return tradeInValid && bucketValid;
        case 4:
            // Must have at least one product OR bucket item
            return form.items.length > 0 || form.bucket_items.length > 0;
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
            <div class="mx-auto w-full max-w-6xl">
                <!-- Wizard Steps -->
                <nav aria-label="Progress" class="mb-8">
                    <ol role="list" class="flex items-center justify-between">
                        <li v-for="(step, stepIdx) in steps" :key="step.title" class="relative flex flex-1 items-center">
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
                            <span class="sr-only">{{ step.title }}</span>
                        </li>
                    </ol>
                    <div class="mt-2 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span v-for="step in steps" :key="step.title" class="flex-1 text-center">{{ step.title }}</span>
                    </div>
                </nav>

                <!-- Main content area with summary sidebar -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Summary Sidebar -->
                    <div class="lg:col-span-1">
                        <div class="sticky top-4 space-y-4">
                            <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Order Summary</h3>

                                <!-- Employee Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Employee</span>
                                        <button v-if="selectedEmployee && currentStep !== 1" type="button" @click="goToStep(1)" class="text-indigo-600 hover:text-indigo-500">
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
                                        <button v-if="selectedCustomer && currentStep !== 2" type="button" @click="goToStep(2)" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="selectedCustomer" class="mt-1">
                                        <p class="text-sm text-gray-900 dark:text-white">{{ selectedCustomer.full_name }}</p>
                                        <p v-if="selectedCustomer.email" class="text-xs text-gray-500 dark:text-gray-400">{{ selectedCustomer.email }}</p>
                                    </div>
                                    <p v-else class="mt-1 text-sm italic text-gray-400 dark:text-gray-500">Walk-in Customer</p>
                                </div>

                                <!-- Trade-In Summary -->
                                <div v-if="form.has_trade_in && form.trade_in_items.length > 0" class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Trade-In</span>
                                        <button v-if="currentStep !== 3" type="button" @click="goToStep(3)" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div class="mt-2 space-y-1">
                                        <div v-for="(item, idx) in form.trade_in_items.slice(0, 2)" :key="idx" class="flex items-center justify-between text-sm">
                                            <span class="truncate text-gray-700 dark:text-gray-300">{{ item.title }}</span>
                                            <span class="ml-2 font-medium text-green-600 dark:text-green-400">{{ formatCurrency(item.buy_price) }}</span>
                                        </div>
                                        <p v-if="form.trade_in_items.length > 2" class="text-xs text-gray-500 dark:text-gray-400">+{{ form.trade_in_items.length - 2 }} more items</p>
                                        <div class="flex items-center justify-between border-t border-gray-100 pt-2 dark:border-gray-700">
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Trade-In Credit</span>
                                            <span class="text-sm font-bold text-green-600 dark:text-green-400">{{ formatCurrency(tradeInTotal) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Items Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Items</span>
                                        <button v-if="form.items.length > 0 && currentStep !== 4" type="button" @click="goToStep(4)" class="text-indigo-600 hover:text-indigo-500">
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="form.items.length > 0 || form.bucket_items.length > 0" class="mt-2 space-y-1">
                                        <div v-for="(item, idx) in form.items.slice(0, 3)" :key="idx" class="flex items-center justify-between text-sm">
                                            <span class="truncate text-gray-700 dark:text-gray-300">{{ item.title }} x {{ item.quantity }}</span>
                                            <span class="ml-2 font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.price * item.quantity) }}</span>
                                        </div>
                                        <p v-if="form.items.length > 3" class="text-xs text-gray-500 dark:text-gray-400">+{{ form.items.length - 3 }} more items</p>
                                        <div v-if="form.bucket_items.length > 0" class="text-xs text-amber-600 dark:text-amber-400">+ {{ form.bucket_items.length }} bucket items</div>
                                        <div class="mt-2 space-y-1 border-t border-gray-100 pt-2 dark:border-gray-700">
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-gray-500 dark:text-gray-400">Subtotal</span>
                                                <span class="text-gray-900 dark:text-white">{{ formatCurrency(subtotal) }}</span>
                                            </div>
                                            <div v-if="tradeInCredit > 0" class="flex items-center justify-between text-sm text-green-600 dark:text-green-400">
                                                <span>Trade-In Credit</span>
                                                <span>-{{ formatCurrency(tradeInCredit) }}</span>
                                            </div>
                                            <div v-if="taxAmount > 0" class="flex items-center justify-between text-sm">
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
                            </div>
                        </div>
                    </div>

                    <!-- Step content -->
                    <div class="lg:col-span-2">
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
                    <div v-if="currentStep === 2" class="space-y-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Customer Information</h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Search for an existing customer or create a new one. Leave empty for walk-in sales.</p>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" @click="switchToCustomerSearch" :class="['rounded-md px-3 py-1.5 text-sm font-medium', !isCreatingNewCustomer ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400']">Search</button>
                                <button type="button" @click="switchToCustomerCreate" :class="['rounded-md px-3 py-1.5 text-sm font-medium', isCreatingNewCustomer ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400']">Create New</button>
                            </div>
                        </div>

                        <!-- Search Mode -->
                        <template v-if="!isCreatingNewCustomer">
                            <div v-if="selectedCustomer" class="flex items-center justify-between rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-700">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                        <UserGroupIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <div>
                                        <p class="text-base font-medium text-gray-900 dark:text-white">{{ selectedCustomer.full_name }}</p>
                                        <p v-if="selectedCustomer.email || selectedCustomer.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ selectedCustomer.email || selectedCustomer.phone }}</p>
                                        <p v-if="selectedCustomer.id === 0" class="text-xs text-indigo-600 dark:text-indigo-400">New customer (will be created)</p>
                                    </div>
                                </div>
                                <button type="button" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-600" @click="clearSelectedCustomer">
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
                            <CreateCustomerForm v-model="newCustomer" :show-lead-source="true" />
                        </template>
                    </div>

                    <!-- Step 3: Trade-In & Bucket Items -->
                    <div v-if="currentStep === 3" class="space-y-8">
                        <!-- Trade-In Section -->
                        <div class="rounded-lg border-2 border-green-200 bg-green-50/50 p-6 dark:border-green-800 dark:bg-green-900/20">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="flex size-10 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                    <ScaleIcon class="size-5 text-green-600 dark:text-green-400" />
                                </div>
                                <div>
                                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">Trade-In Items</h2>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Items the customer is trading in for store credit
                                    </p>
                                </div>
                            </div>

                            <!-- Toggle Trade-In -->
                            <div class="mb-6">
                                <p class="mb-2 font-medium text-gray-900 dark:text-white">Customer has items to trade in?</p>
                                <div class="inline-flex rounded-lg border border-gray-300 dark:border-gray-600">
                                    <button
                                        type="button"
                                        @click="form.has_trade_in = true"
                                        :class="[
                                            'px-4 py-2 text-sm font-medium rounded-l-lg transition-colors',
                                            form.has_trade_in
                                                ? 'bg-green-600 text-white'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'
                                        ]"
                                    >
                                        Yes
                                    </button>
                                    <button
                                        type="button"
                                        @click="form.has_trade_in = false"
                                        :class="[
                                            'px-4 py-2 text-sm font-medium rounded-r-lg transition-colors border-l border-gray-300 dark:border-gray-600',
                                            !form.has_trade_in
                                                ? 'bg-gray-600 text-white'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'
                                        ]"
                                    >
                                        No
                                    </button>
                                </div>
                            </div>

                            <!-- Trade-In Form (shown when trade-in is enabled) -->
                            <div v-if="form.has_trade_in" class="space-y-6">
                                <!-- Trade-In Items List -->
                                <div class="rounded-lg border border-green-200 bg-white dark:border-green-700 dark:bg-gray-800">
                                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-600">
                                    <div class="flex items-center justify-between">
                                        <h3 class="font-medium text-gray-900 dark:text-white">Trade-In Items ({{ form.trade_in_items.length }})</h3>
                                        <span class="text-lg font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(tradeInTotal) }}</span>
                                    </div>
                                </div>
                                <div v-if="form.trade_in_items.length > 0" class="divide-y divide-gray-200 dark:divide-gray-600">
                                    <div v-for="(item, index) in form.trade_in_items" :key="item.id" class="flex items-center gap-4 p-4">
                                        <div class="flex size-12 shrink-0 items-center justify-center rounded bg-green-100 dark:bg-green-900">
                                            <ScaleIcon class="size-6 text-green-600 dark:text-green-400" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                            <p v-if="item.description" class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ item.description }}
                                            </p>
                                        </div>
                                        <p class="font-medium text-green-600 dark:text-green-400">
                                            {{ formatCurrency(item.buy_price) }}
                                        </p>
                                        <button type="button" @click="openEditTradeInModal(item, index)" class="text-gray-500 hover:text-gray-600 dark:hover:text-gray-400">
                                            <PencilIcon class="size-5" />
                                        </button>
                                        <button type="button" @click="removeTradeInItem(index)" class="text-red-500 hover:text-red-600">
                                            <TrashIcon class="size-5" />
                                        </button>
                                    </div>
                                </div>
                                <div v-else class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No trade-in items added yet. Click "Add Item" to get started.
                                </div>
                                <div class="border-t border-gray-200 p-4 dark:border-gray-600">
                                    <button
                                        type="button"
                                        @click="openAddTradeInModal"
                                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                    >
                                        <PlusIcon class="size-4" />
                                        Add Item
                                    </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bucket Items Section -->
                        <div class="rounded-lg border-2 border-amber-200 bg-amber-50/50 p-6 dark:border-amber-800 dark:bg-amber-900/20">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="flex size-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900">
                                    <ArchiveBoxIcon class="size-5 text-amber-600 dark:text-amber-400" />
                                </div>
                                <div>
                                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">Bucket Items</h2>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Pre-priced items from buckets to include in this sale
                                    </p>
                                </div>
                            </div>

                            <!-- Toggle Sell From Bucket -->
                            <div class="mb-6">
                                <p class="mb-2 font-medium text-gray-900 dark:text-white">Sell items from a bucket?</p>
                                <div class="inline-flex rounded-lg border border-gray-300 dark:border-gray-600">
                                    <button
                                        type="button"
                                        @click="form.sell_from_bucket = true"
                                        :class="[
                                            'px-4 py-2 text-sm font-medium rounded-l-lg transition-colors',
                                            form.sell_from_bucket
                                                ? 'bg-amber-600 text-white'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'
                                        ]"
                                    >
                                        Yes
                                    </button>
                                    <button
                                        type="button"
                                        @click="form.sell_from_bucket = false"
                                        :class="[
                                            'px-4 py-2 text-sm font-medium rounded-r-lg transition-colors border-l border-gray-300 dark:border-gray-600',
                                            !form.sell_from_bucket
                                                ? 'bg-gray-600 text-white'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'
                                        ]"
                                    >
                                        No
                                    </button>
                                </div>
                            </div>

                        <!-- Bucket Items Selection (shown when sell_from_bucket is enabled) -->
                        <div v-if="form.sell_from_bucket" class="space-y-6">
                            <!-- Loading State -->
                            <div v-if="loadingBuckets" class="text-center py-6 text-gray-500 dark:text-gray-400">
                                Loading buckets...
                            </div>

                            <!-- No Buckets Available -->
                            <div v-else-if="availableBuckets.length === 0" class="text-center py-6">
                                <ArchiveBoxIcon class="mx-auto h-12 w-12 text-gray-400" />
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    No buckets with available items found.
                                </p>
                            </div>

                            <!-- Buckets with Items -->
                            <div v-else class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select a bucket</label>
                                    <select
                                        v-model="selectedBucketId"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option :value="null">Choose a bucket...</option>
                                        <option v-for="bucket in availableBuckets" :key="bucket.id" :value="bucket.id">
                                            {{ bucket.name }} ({{ bucket.items.length }} items - {{ formatCurrency(bucket.total_value) }})
                                        </option>
                                    </select>
                                </div>

                                <!-- Available Items from Selected Bucket -->
                                <div v-if="selectedBucketId" class="rounded-lg border border-gray-200 dark:border-gray-600">
                                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-600">
                                        <h3 class="font-medium text-gray-900 dark:text-white">Available Items</h3>
                                    </div>
                                    <div class="max-h-60 overflow-y-auto divide-y divide-gray-200 dark:divide-gray-600">
                                        <button
                                            v-for="item in availableBuckets.find(b => b.id === selectedBucketId)?.items || []"
                                            :key="item.id"
                                            type="button"
                                            @click="addBucketItemToOrder(item, availableBuckets.find(b => b.id === selectedBucketId)?.name || '')"
                                            :disabled="form.bucket_items.some(bi => bi.id === item.id)"
                                            class="flex w-full items-center gap-4 p-4 text-left hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed dark:hover:bg-gray-700"
                                        >
                                            <div class="flex size-10 shrink-0 items-center justify-center rounded bg-amber-100 dark:bg-amber-900">
                                                <ArchiveBoxIcon class="size-5 text-amber-600 dark:text-amber-400" />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                                <p v-if="item.description" class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ item.description }}</p>
                                            </div>
                                            <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.value) }}</p>
                                            <PlusIcon v-if="!form.bucket_items.some(bi => bi.id === item.id)" class="size-5 text-indigo-600 dark:text-indigo-400" />
                                            <CheckIcon v-else class="size-5 text-green-600 dark:text-green-400" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Selected Bucket Items List -->
                            <div class="rounded-lg border border-gray-200 dark:border-gray-600">
                                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-600">
                                    <div class="flex items-center justify-between">
                                        <h3 class="font-medium text-gray-900 dark:text-white">Bucket Items to Sell ({{ form.bucket_items.length }})</h3>
                                        <span class="text-lg font-semibold text-amber-600 dark:text-amber-400">{{ formatCurrency(bucketItemsTotal) }}</span>
                                    </div>
                                </div>
                                <div v-if="form.bucket_items.length > 0" class="divide-y divide-gray-200 dark:divide-gray-600">
                                    <div v-for="(item, index) in form.bucket_items" :key="item.id" class="flex items-center gap-4 p-4">
                                        <div class="flex size-12 shrink-0 items-center justify-center rounded bg-amber-100 dark:bg-amber-900">
                                            <ArchiveBoxIcon class="size-6 text-amber-600 dark:text-amber-400" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">From: {{ item.bucket_name }}</p>
                                        </div>
                                        <div class="w-28">
                                            <input
                                                v-model.number="item.price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="block w-full rounded-md border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            />
                                        </div>
                                        <button type="button" @click="removeBucketItem(index)" class="text-red-500 hover:text-red-600">
                                            <TrashIcon class="size-5" />
                                        </button>
                                    </div>
                                </div>
                                <div v-else class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No bucket items selected. Choose items from the list above.
                                </div>
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
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                    <QrCodeIcon class="size-5" />
                                    <span>Scanner ready</span>
                                </div>
                                <button
                                    type="button"
                                    @click="showCameraScanner = true"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    <CameraIcon class="size-4" />
                                    Scan
                                </button>
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
                        <div class="mb-6">
                            <div class="relative">
                                <MagnifyingGlassIcon class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                <input
                                    v-model="productSearchQuery"
                                    type="text"
                                    placeholder="Search products by name or SKU..."
                                    class="w-full rounded-lg border-0 bg-white py-3 pl-12 pr-4 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
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

                        <!-- Products List - Commented out, may need later
                        <div class="mb-6">
                            <h3 class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Available Products</h3>
                            <div class="max-h-60 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-600">
                                Products list would go here
                            </div>
                        </div>
                        -->

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
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">SKU</label>
                                    <input
                                        v-model="newProduct.sku"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                    <select
                                        v-model="newProduct.category_id"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option :value="null">Select Category</option>
                                        <option
                                            v-for="cat in sortedCategories"
                                            :key="cat.id"
                                            :value="cat.id"
                                            :disabled="!cat.isLeaf"
                                            :class="{ 'text-gray-400': !cat.isLeaf }"
                                        >
                                            {{ '\u00A0'.repeat(cat.level * 3) }}{{ cat.level > 0 ? '└ ' : '' }}{{ cat.name }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Price *</label>
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                        <input
                                            v-model.number="newProduct.price"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="block w-full rounded-md border-0 py-2 pl-7 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cost</label>
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                        <input
                                            v-model.number="newProduct.cost"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="block w-full rounded-md border-0 py-2 pl-7 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
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
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
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
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Shipping Cost</label>
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                        <input
                                            v-model.number="form.shipping_cost"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="block w-full rounded-md border-0 py-2 pl-7 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Discount</label>
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                        <input
                                            v-model.number="form.discount_cost"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="block w-full rounded-md border-0 py-2 pl-7 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Sale Date</label>
                                    <input
                                        v-model="form.date_of_purchase"
                                        type="date"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Change this to backdate the sale</p>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                <textarea
                                    v-model="form.notes"
                                    rows="3"
                                    class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
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
                                    <div v-if="form.sell_from_bucket && form.bucket_items.length > 0" class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Bucket Items</dt>
                                        <dd class="text-amber-600 dark:text-amber-400">{{ form.bucket_items.length }}</dd>
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

                            <!-- Navigation Buttons inside step content -->
                            <div class="mt-6 flex justify-between border-t border-gray-200 pt-6 dark:border-gray-700">
                                <button
                                    v-if="currentStep > 1"
                                    type="button"
                                    @click="prevStep"
                                    class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                >
                                    <ArrowLeftIcon class="size-4" />
                                    Back
                                </button>
                                <div v-else />

                                <div class="flex items-center gap-4">
                                    <span v-if="currentStep >= 4" class="text-sm text-gray-500 dark:text-gray-400">
                                        Total: <span class="font-semibold text-gray-900 dark:text-white">{{ formatCurrency(total) }}</span>
                                    </span>

                                    <button
                                        v-if="currentStep < totalSteps"
                                        type="button"
                                        @click="nextStep"
                                        :disabled="!canProceed()"
                                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                    >
                                        Continue
                                        <ArrowRightIcon class="size-4" />
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        @click="submitOrder"
                                        :disabled="form.processing || !canProceed()"
                                        class="inline-flex items-center gap-2 rounded-md bg-green-600 px-6 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                                    >
                                        <CheckIcon class="size-4" />
                                        {{ form.processing ? 'Creating...' : 'Create Order' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Trade-In Item Modal -->
        <AddItemModal
            :open="showAddTradeInModal"
            :categories="tradeInCategories"
            :editing-item="editingTradeInItem"
            @close="showAddTradeInModal = false"
            @save="handleTradeInItemSave"
        />

        <!-- Camera Scanner Modal -->
        <CameraScannerModal
            :show="showCameraScanner"
            @close="showCameraScanner = false"
            @scan="handleBarcodeScan"
        />
    </AppLayout>
</template>
