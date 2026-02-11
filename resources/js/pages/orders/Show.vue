<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { NotesSection } from '@/components/notes';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    ArrowLeftIcon,
    UserIcon,
    CalendarIcon,
    CubeIcon,
    CheckCircleIcon,
    XCircleIcon,
    BanknotesIcon,
    TrashIcon,
    TruckIcon,
    ShoppingBagIcon,
    PrinterIcon,
    ArrowDownTrayIcon,
    MapPinIcon,
    ArrowsRightLeftIcon,
    ScaleIcon,
    PencilIcon,
    XMarkIcon,
    CreditCardIcon,
    ArrowPathIcon,
    BuildingStorefrontIcon,
    GlobeAltIcon,
} from '@heroicons/vue/24/outline';
import CollectPaymentModal from '@/components/payments/CollectPaymentModal.vue';
import ShipOrderModal from '@/components/orders/ShipOrderModal.vue';
import CustomerSearch from '@/components/customers/CustomerSearch.vue';

interface Customer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email?: string;
    phone?: string;
}

interface User {
    id: number;
    name: string;
}

interface Warehouse {
    id: number;
    name: string;
}

interface Product {
    id: number;
    title: string;
    image?: string;
}

interface OrderItem {
    id: number;
    product_id: number;
    product_variant_id?: number;
    sku?: string;
    title: string;
    quantity: number;
    price: number;
    cost?: number;
    discount: number;
    tax?: number;
    line_total: number;
    line_profit?: number;
    notes?: string;
    product?: Product;
}

interface Invoice {
    id: number;
    invoice_number: string;
    status: string;
    total: number;
    balance_due: number;
}

interface Payment {
    id: number;
    amount: number;
    payment_method: string;
    status: string;
    reference?: string;
    notes?: string;
    paid_at?: string;
    user?: User;
}

interface TradeInItem {
    id: number;
    title: string;
    description?: string;
    buy_price: number;
    precious_metal?: string;
    condition?: string;
    dwt?: number;
}

interface TradeInTransaction {
    id: number;
    transaction_number: string;
    final_offer: number;
    status: string;
    items: TradeInItem[];
}

interface SalesChannel {
    id: number;
    name: string;
    type: string;
    type_label: string;
    is_local: boolean;
    color?: string;
    marketplace?: {
        id: number;
        name: string;
        shop_domain?: string;
    };
}

interface PlatformOrder {
    id: number;
    external_order_id: string;
    external_order_number?: string;
    status?: string;
    fulfillment_status?: string;
    payment_status?: string;
    last_synced_at?: string;
    marketplace?: {
        id: number;
        platform: string;
        name: string;
        shop_domain?: string;
    };
}

interface Order {
    id: number;
    order_id?: string;
    invoice_number?: string;
    status: string;
    sub_total: number;
    sales_tax: number;
    tax_rate: number;
    shipping_cost: number;
    discount_cost: number;
    trade_in_credit: number;
    service_fee_value?: number;
    service_fee_unit?: string;
    service_fee_reason?: string;
    total: number;
    total_paid?: number;
    balance_due?: number;
    notes?: string;
    billing_address?: Record<string, string>;
    shipping_address?: Record<string, string>;
    tracking_number?: string;
    shipping_carrier?: string;
    shipped_at?: string;
    tracking_url?: string;
    date_of_purchase?: string;
    source_platform?: string;
    external_marketplace_id?: string;
    created_at: string;
    updated_at: string;

    is_draft: boolean;
    is_pending: boolean;
    is_confirmed: boolean;
    is_paid: boolean;
    is_cancelled: boolean;
    is_fully_paid: boolean;
    is_from_external_platform: boolean;
    has_trade_in: boolean;

    can_be_confirmed: boolean;
    can_be_shipped: boolean;
    can_be_delivered: boolean;
    can_be_completed: boolean;
    can_be_cancelled: boolean;
    can_receive_payment: boolean;
    can_be_deleted: boolean;
    can_sync_from_platform: boolean;

    customer?: Customer;
    user?: User;
    warehouse?: Warehouse;
    sales_channel?: SalesChannel;
    platform_order?: PlatformOrder;
    items: OrderItem[];
    item_count: number;
    invoice?: Invoice;
    payments?: Payment[];
    trade_in_transaction?: TradeInTransaction;
    note_entries: Note[];
}

interface Status {
    value: string;
    label: string;
}

interface PaymentMethod {
    value: string;
    label: string;
}

interface NoteUser {
    id: number;
    name: string;
}

interface Note {
    id: number;
    content: string;
    user: NoteUser | null;
    created_at: string;
    updated_at: string;
}

interface ActivityItem {
    id: number;
    activity: string;
    description: string;
    user: { name: string } | null;
    changes: Record<string, { old: string; new: string }> | null;
    time: string;
    created_at: string;
    icon: string;
    color: string;
}

interface ActivityDay {
    date: string;
    dateTime: string;
    items: ActivityItem[];
}

interface Props {
    order: Order;
    statuses: Status[];
    paymentMethods: PaymentMethod[];
    activityLogs?: ActivityDay[];
    fedexConfigured?: boolean;
    shipstationConfigured?: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: props.order.invoice_number || `Order #${props.order.id}`, href: `/orders/${props.order.id}` },
];

// Payment modal
const showPaymentModal = ref(false);
const isProcessing = ref(false);

// Ship modal
const showShipModal = ref(false);

// Edit mode
const isEditMode = ref(false);
const editingItems = ref<Record<number, { quantity: number; price: number; discount: number }>>({});

// Date editing
const isEditingDate = ref(false);
const editingDate = ref('');

// Customer editing
const isAddingCustomer = ref(false);
const selectedCustomer = ref<Customer | null>(null);

const canEditOrder = computed(() => {
    return props.order.is_pending || props.order.is_draft;
});

function enterEditMode() {
    // Initialize editing state for all items
    editingItems.value = {};
    props.order.items.forEach(item => {
        editingItems.value[item.id] = {
            quantity: item.quantity,
            price: item.price,
            discount: item.discount,
        };
    });
    isEditMode.value = true;
}

function cancelEditMode() {
    isEditMode.value = false;
    editingItems.value = {};
}

function updateItem(itemId: number) {
    if (isProcessing.value) return;
    const itemData = editingItems.value[itemId];
    if (!itemData) return;

    isProcessing.value = true;
    router.patch(`/orders/${props.order.id}/items/${itemId}`, {
        quantity: itemData.quantity,
        price: itemData.price,
        discount: itemData.discount,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            // Item updated, stay in edit mode for other items
        },
        onFinish: () => { isProcessing.value = false; },
    });
}

function removeItem(itemId: number) {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to remove this item?')) return;

    isProcessing.value = true;
    router.delete(`/orders/${props.order.id}/items/${itemId}`, {
        preserveScroll: true,
        onSuccess: () => {
            delete editingItems.value[itemId];
        },
        onFinish: () => { isProcessing.value = false; },
    });
}

const statusColors: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    processing: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    shipped: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    delivered: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    refunded: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    partial_payment: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
};

const statusLabels: Record<string, string> = {
    draft: 'Draft',
    pending: 'Pending',
    confirmed: 'Confirmed',
    processing: 'Processing',
    shipped: 'Shipped',
    delivered: 'Delivered',
    completed: 'Completed',
    cancelled: 'Cancelled',
    refunded: 'Refunded',
    partial_payment: 'Partial Payment',
};

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function formatAddress(address?: Record<string, string>): string {
    if (!address) return '';
    const parts = [
        address.address_line_1,
        address.address_line_2,
        [address.city, address.state, address.postal_code].filter(Boolean).join(', '),
        address.country,
    ].filter(Boolean);
    return parts.join('\n');
}

function formatCarrier(carrier?: string): string {
    const carriers: Record<string, string> = {
        fedex: 'FedEx',
        ups: 'UPS',
        usps: 'USPS',
        dhl: 'DHL',
        other: 'Other',
    };
    return carriers[carrier ?? ''] || carrier || 'Unknown';
}

function formatPlatformName(platform?: string): string {
    const platforms: Record<string, string> = {
        shopify: 'Shopify',
        ebay: 'eBay',
        amazon: 'Amazon',
        etsy: 'Etsy',
        woocommerce: 'WooCommerce',
        pos: 'In Store',
        in_store: 'In Store',
        website: 'Website',
        online: 'Online',
    };
    return platforms[platform ?? ''] || (platform ? platform.charAt(0).toUpperCase() + platform.slice(1) : 'Unknown');
}

const platformBadgeColors: Record<string, string> = {
    shopify: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    ebay: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    amazon: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
    etsy: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
    default: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
};

function getPlatformBadgeColor(platform?: string): string {
    return platformBadgeColors[platform ?? ''] || platformBadgeColors.default;
}

// Compute the source platform for display
const orderPlatform = computed(() => {
    // First try to get from platform_order
    if (props.order.platform_order?.marketplace?.platform) {
        return props.order.platform_order.marketplace.platform;
    }
    // Fall back to sales channel type if it's not local
    if (props.order.sales_channel && !props.order.sales_channel.is_local) {
        return props.order.sales_channel.type;
    }
    // Fall back to source_platform
    return props.order.source_platform;
});

// Actions
function confirmOrder() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/confirm`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

const isSyncing = ref(false);

function syncFromMarketplace() {
    if (isSyncing.value) return;
    isSyncing.value = true;
    router.post(`/orders/${props.order.id}/sync-from-marketplace`, {}, {
        preserveScroll: true,
        onFinish: () => { isSyncing.value = false; },
    });
}

function openShipModal() {
    showShipModal.value = true;
}

function closeShipModal() {
    showShipModal.value = false;
}

function onShipSuccess() {
    showShipModal.value = false;
    router.reload();
}

function deliverOrder() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/deliver`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function completeOrder() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/complete`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function cancelOrder() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to cancel this order? Stock will be restored.')) return;
    isProcessing.value = true;
    router.post(`/orders/${props.order.id}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => { isProcessing.value = false; },
    });
}

function deleteOrder() {
    if (isProcessing.value) return;
    if (!confirm('Are you sure you want to delete this order? This action cannot be undone.')) return;
    isProcessing.value = true;
    router.delete(`/orders/${props.order.id}`, {
        onFinish: () => { isProcessing.value = false; },
    });
}

function openPaymentModal() {
    showPaymentModal.value = true;
}

function closePaymentModal() {
    showPaymentModal.value = false;
}

function onPaymentSuccess() {
    showPaymentModal.value = false;
    router.reload();
}

// Invoice functions
const hasInvoice = computed(() => !!props.order.invoice);

function printInvoice() {
    window.open(`/orders/${props.order.id}/print-invoice`, '_blank');
}

function downloadInvoice() {
    if (!props.order.invoice) return;
    window.location.href = `/invoices/${props.order.invoice.id}/pdf`;
}

const totalProfit = computed(() => {
    return props.order.items.reduce((sum, item) => sum + (item.line_profit ?? 0), 0);
});

const serviceFeeAmount = computed(() => {
    const value = props.order.service_fee_value ?? 0;
    if (value <= 0) return 0;

    if (props.order.service_fee_unit === 'percent') {
        const subtotalAfterDiscount = props.order.sub_total - (props.order.discount_cost ?? 0);
        return subtotalAfterDiscount * value / 100;
    }
    return value;
});

function formatPaymentMethod(method: string): string {
    const labels: Record<string, string> = {
        cash: 'Cash',
        credit_card: 'Credit Card',
        debit_card: 'Debit Card',
        check: 'Check',
        store_credit: 'Store Credit',
        gift_card: 'Gift Card',
        trade_in: 'Trade-In',
        wire_transfer: 'Wire Transfer',
        other: 'Other',
    };
    return labels[method] || method.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function startEditingDate() {
    // Format the date for the input (YYYY-MM-DD)
    const date = props.order.date_of_purchase
        ? new Date(props.order.date_of_purchase).toISOString().split('T')[0]
        : new Date().toISOString().split('T')[0];
    editingDate.value = date;
    isEditingDate.value = true;
}

function cancelEditingDate() {
    isEditingDate.value = false;
    editingDate.value = '';
}

function saveDate() {
    if (isProcessing.value) return;
    isProcessing.value = true;
    router.patch(`/orders/${props.order.id}`, {
        date_of_purchase: editingDate.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            isEditingDate.value = false;
        },
        onFinish: () => { isProcessing.value = false; },
    });
}

function saveCustomer() {
    if (isProcessing.value || !selectedCustomer.value) return;
    isProcessing.value = true;
    router.patch(`/orders/${props.order.id}/customer`, {
        customer_id: selectedCustomer.value.id,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            isAddingCustomer.value = false;
            selectedCustomer.value = null;
        },
        onFinish: () => { isProcessing.value = false; },
    });
}

function cancelAddingCustomer() {
    isAddingCustomer.value = false;
    selectedCustomer.value = null;
}
</script>

<template>
    <Head :title="`Order ${order.invoice_number || order.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Header -->
                <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link href="/orders" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <ArrowLeftIcon class="size-5 text-gray-500 dark:text-gray-400" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                                    {{ order.invoice_number || `Order #${order.id}` }}
                                </h1>
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColors[order.status]]">
                                    {{ statusLabels[order.status] }}
                                </span>
                                <!-- Platform badge for external orders -->
                                <span
                                    v-if="orderPlatform && orderPlatform !== 'pos' && orderPlatform !== 'in_store'"
                                    :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium', getPlatformBadgeColor(orderPlatform)]"
                                >
                                    <GlobeAltIcon class="size-3" />
                                    {{ formatPlatformName(orderPlatform) }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Created {{ formatDate(order.created_at) }}
                                <span v-if="order.customer"> for {{ order.customer.full_name }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="order.can_be_confirmed"
                            type="button"
                            @click="confirmOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 disabled:opacity-50"
                        >
                            <CheckCircleIcon class="size-4" />
                            Confirm
                        </button>

                        <button
                            v-if="order.can_be_shipped"
                            type="button"
                            @click="openShipModal"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-500 disabled:opacity-50"
                        >
                            <TruckIcon class="size-4" />
                            Ship Order
                        </button>

                        <button
                            v-if="order.can_be_delivered"
                            type="button"
                            @click="deliverOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <ShoppingBagIcon class="size-4" />
                            Mark Delivered
                        </button>

                        <button
                            v-if="order.can_be_completed"
                            type="button"
                            @click="completeOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <CheckCircleIcon class="size-4" />
                            Complete
                        </button>

                        <button
                            v-if="order.can_receive_payment"
                            type="button"
                            @click="openPaymentModal"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50"
                        >
                            <BanknotesIcon class="size-4" />
                            Receive Payment
                        </button>

                        <button
                            v-if="order.can_be_cancelled"
                            type="button"
                            @click="cancelOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-yellow-600 px-4 py-2 text-sm font-medium text-white hover:bg-yellow-500 disabled:opacity-50"
                        >
                            <XCircleIcon class="size-4" />
                            Cancel
                        </button>

                        <button
                            v-if="order.can_be_deleted"
                            type="button"
                            @click="deleteOrder"
                            :disabled="isProcessing"
                            class="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50"
                        >
                            <TrashIcon class="size-4" />
                            Delete
                        </button>

                        <!-- Invoice Actions -->
                        <button
                            type="button"
                            @click="printInvoice"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <PrinterIcon class="size-4" />
                            Print Invoice
                        </button>

                        <button
                            v-if="hasInvoice"
                            type="button"
                            @click="downloadInvoice"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <ArrowDownTrayIcon class="size-4" />
                            Download PDF
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main content -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Items -->
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">
                                    Items ({{ order.item_count }})
                                </h2>
                                <div v-if="canEditOrder" class="flex items-center gap-2">
                                    <button
                                        v-if="!isEditMode"
                                        type="button"
                                        @click="enterEditMode"
                                        class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                    >
                                        <PencilIcon class="size-4" />
                                        Edit
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        @click="cancelEditMode"
                                        class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                    >
                                        <XMarkIcon class="size-4" />
                                        Done
                                    </button>
                                </div>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div
                                    v-for="item in order.items"
                                    :key="item.id"
                                    class="flex items-center gap-4 p-4"
                                >
                                    <div class="flex size-16 shrink-0 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
                                        <img v-if="item.product?.image" :src="item.product.image" class="size-16 rounded object-cover" />
                                        <CubeIcon v-else class="size-8 text-gray-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <Link
                                            v-if="item.product_id"
                                            :href="`/products/${item.product_id}`"
                                            class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                        >
                                            {{ item.title }}
                                        </Link>
                                        <p v-else class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="item.sku">SKU: {{ item.sku }}</span>
                                        </p>
                                        <p v-if="item.notes" class="mt-1 text-sm text-gray-400 dark:text-gray-500">{{ item.notes }}</p>
                                    </div>

                                    <!-- Edit Mode -->
                                    <template v-if="isEditMode && editingItems[item.id]">
                                        <div class="flex items-center gap-2">
                                            <div>
                                                <label class="block text-xs text-gray-500 dark:text-gray-400">Qty</label>
                                                <input
                                                    v-model.number="editingItems[item.id].quantity"
                                                    type="number"
                                                    min="1"
                                                    class="w-16 rounded-md border-0 px-2 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-500 dark:text-gray-400">Price</label>
                                                <div class="relative">
                                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-500 text-sm">$</span>
                                                    <input
                                                        v-model.number="editingItems[item.id].price"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="w-24 rounded-md border-0 py-1 pl-5 pr-2 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-500 dark:text-gray-400">Discount</label>
                                                <div class="relative">
                                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-500 text-sm">$</span>
                                                    <input
                                                        v-model.number="editingItems[item.id].discount"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="w-20 rounded-md border-0 py-1 pl-5 pr-2 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                @click="updateItem(item.id)"
                                                :disabled="isProcessing"
                                                class="mt-4 rounded-md bg-indigo-600 px-2 py-1 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                            >
                                                Save
                                            </button>
                                            <button
                                                type="button"
                                                @click="removeItem(item.id)"
                                                :disabled="isProcessing"
                                                class="mt-4 rounded-md p-1 text-red-500 hover:bg-red-50 hover:text-red-600 disabled:opacity-50 dark:hover:bg-red-900/20"
                                            >
                                                <TrashIcon class="size-5" />
                                            </button>
                                        </div>
                                    </template>

                                    <!-- View Mode -->
                                    <template v-else>
                                        <div class="text-right">
                                            <p class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(item.line_total) }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ item.quantity }} × {{ formatCurrency(item.price) }}
                                            </p>
                                            <p v-if="item.discount > 0" class="text-sm text-green-600 dark:text-green-400">
                                                -{{ formatCurrency(item.discount) }} discount
                                            </p>
                                            <p v-if="item.line_profit !== undefined" class="text-xs text-gray-400">
                                                Profit: {{ formatCurrency(item.line_profit) }}
                                            </p>
                                        </div>
                                    </template>
                                </div>
                                <div v-if="order.items.length === 0" class="p-6 text-center text-gray-500 dark:text-gray-400">
                                    No items in this order.
                                </div>
                            </div>
                        </div>

                        <!-- Trade-In Transaction -->
                        <div v-if="order.has_trade_in && order.trade_in_transaction" class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <h2 class="flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                        <ArrowsRightLeftIcon class="size-5 text-green-600 dark:text-green-400" />
                                        Trade-In Items
                                    </h2>
                                    <div class="text-right">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">Ref: {{ order.trade_in_transaction.transaction_number }}</p>
                                        <p class="text-lg font-semibold text-green-600 dark:text-green-400">
                                            {{ formatCurrency(order.trade_in_transaction.final_offer) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div
                                    v-for="item in order.trade_in_transaction.items"
                                    :key="item.id"
                                    class="flex items-center gap-4 p-4"
                                >
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded bg-green-100 dark:bg-green-900">
                                        <ScaleIcon class="size-6 text-green-600 dark:text-green-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span v-if="item.precious_metal">{{ item.precious_metal }}</span>
                                            <span v-if="item.precious_metal && item.condition"> | </span>
                                            <span v-if="item.condition">{{ item.condition }}</span>
                                            <span v-if="(item.precious_metal || item.condition) && item.dwt"> | </span>
                                            <span v-if="item.dwt">{{ item.dwt }} DWT</span>
                                        </p>
                                        <p v-if="item.description" class="mt-1 text-sm text-gray-400 dark:text-gray-500">{{ item.description }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(item.buy_price) }}</p>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t border-gray-200 bg-gray-50 px-6 py-3 dark:border-gray-700 dark:bg-gray-700/50">
                                <Link
                                    :href="`/transactions/${order.trade_in_transaction.id}`"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    View Trade-In Transaction &rarr;
                                </Link>
                            </div>
                        </div>

                        <!-- Payment History -->
                        <div v-if="order.payments && order.payments.length > 0" class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <h2 class="flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                    <CreditCardIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                    Payments ({{ order.payments.length }})
                                </h2>
                            </div>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div v-for="payment in order.payments" :key="payment.id" class="p-4">
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                                <BanknotesIcon class="size-5 text-green-600 dark:text-green-400" />
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900 dark:text-white">
                                                    {{ formatCurrency(payment.amount) }}
                                                </p>
                                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                                    {{ formatPaymentMethod(payment.payment_method) }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span :class="[
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                payment.status === 'completed' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-300'
                                            ]">
                                                {{ payment.status }}
                                            </span>
                                            <p v-if="payment.paid_at" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ formatDate(payment.paid_at) }}
                                            </p>
                                        </div>
                                    </div>
                                    <div v-if="payment.reference || payment.notes || payment.user" class="mt-2 ml-13 space-y-1">
                                        <p v-if="payment.reference" class="text-sm text-gray-500 dark:text-gray-400">
                                            <span class="font-medium">Ref:</span> {{ payment.reference }}
                                        </p>
                                        <p v-if="payment.notes" class="text-sm text-gray-500 dark:text-gray-400">
                                            <span class="font-medium">Note:</span> {{ payment.notes }}
                                        </p>
                                        <p v-if="payment.user" class="text-xs text-gray-400 dark:text-gray-500">
                                            Recorded by {{ payment.user.name }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t border-gray-200 bg-gray-50 px-6 py-3 dark:border-gray-700 dark:bg-gray-700/50">
                                <div class="flex justify-between text-sm">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">Total Paid</span>
                                    <span class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(order.total_paid ?? 0) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div v-if="order.notes" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-3 text-lg font-medium text-gray-900 dark:text-white">Notes</h2>
                            <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ order.notes }}</p>
                        </div>

                        <!-- Addresses -->
                        <div v-if="order.shipping_address || order.billing_address" class="grid gap-6 md:grid-cols-2">
                            <div v-if="order.shipping_address" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                                <h2 class="mb-3 flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                    <MapPinIcon class="size-5" />
                                    Shipping Address
                                </h2>
                                <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ formatAddress(order.shipping_address) }}</p>
                            </div>
                            <div v-if="order.billing_address" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                                <h2 class="mb-3 flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                    <MapPinIcon class="size-5" />
                                    Billing Address
                                </h2>
                                <p class="whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ formatAddress(order.billing_address) }}</p>
                            </div>
                        </div>

                        <!-- Tracking Information -->
                        <div v-if="order.tracking_number" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-3 flex items-center gap-2 text-lg font-medium text-gray-900 dark:text-white">
                                <TruckIcon class="size-5" />
                                Shipping Tracking
                            </h2>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Carrier</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatCarrier(order.shipping_carrier) }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Tracking Number</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ order.tracking_number }}
                                    </span>
                                </div>
                                <div v-if="order.shipped_at" class="flex items-center justify-between">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Shipped</span>
                                    <span class="text-sm text-gray-900 dark:text-white">
                                        {{ formatDate(order.shipped_at) }}
                                    </span>
                                </div>
                                <div v-if="order.tracking_url" class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                                    <a
                                        :href="order.tracking_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-2 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    >
                                        <TruckIcon class="size-4" />
                                        Track Package
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <NotesSection
                            :notes="order.note_entries"
                            notable-type="order"
                            :notable-id="order.id"
                        />

                        <!-- Activity Log -->
                        <ActivityTimeline :activities="activityLogs" />
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6 lg:col-span-1">
                        <!-- Summary -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Summary</h2>
                            <dl class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(order.sub_total) }}</dd>
                                </div>
                                <div v-if="(order.discount_cost ?? 0) > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Discount</dt>
                                    <dd>-{{ formatCurrency(order.discount_cost ?? 0) }}</dd>
                                </div>
                                <div v-if="(order.trade_in_credit ?? 0) > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Trade-In Credit</dt>
                                    <dd>-{{ formatCurrency(order.trade_in_credit ?? 0) }}</dd>
                                </div>
                                <div v-if="(order.shipping_cost ?? 0) > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Shipping</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(order.shipping_cost ?? 0) }}</dd>
                                </div>
                                <div v-if="(order.sales_tax ?? 0) > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">Tax ({{ (order.tax_rate * 100).toFixed(2) }}%)</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(order.sales_tax ?? 0) }}</dd>
                                </div>
                                <div v-if="serviceFeeAmount > 0" class="flex justify-between text-sm">
                                    <dt class="text-gray-500 dark:text-gray-400">
                                        Service Fee
                                        <span v-if="order.service_fee_reason" class="text-xs">({{ order.service_fee_reason }})</span>
                                    </dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(serviceFeeAmount) }}</dd>
                                </div>
                                <div class="flex justify-between border-t border-gray-200 pt-3 text-base font-medium dark:border-gray-700">
                                    <dt class="text-gray-900 dark:text-white">Total</dt>
                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(order.total) }}</dd>
                                </div>
                                <div v-if="(order.total_paid ?? 0) > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                    <dt>Amount Paid</dt>
                                    <dd>-{{ formatCurrency(order.total_paid ?? 0) }}</dd>
                                </div>
                                <div class="flex justify-between text-base font-bold">
                                    <dt class="text-gray-900 dark:text-white">Balance Due</dt>
                                    <dd class="text-indigo-600 dark:text-indigo-400">{{ formatCurrency(order.balance_due ?? order.total) }}</dd>
                                </div>
                                <div v-if="totalProfit > 0" class="flex justify-between border-t border-gray-200 pt-3 text-sm dark:border-gray-700">
                                    <dt class="text-gray-500 dark:text-gray-400">Estimated Profit</dt>
                                    <dd class="font-medium text-green-600 dark:text-green-400">{{ formatCurrency(totalProfit) }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Details -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Details</h2>
                            <dl class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div class="flex-1">
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Sale Date</dt>
                                        <template v-if="isEditingDate">
                                            <div class="mt-1 flex items-center gap-2">
                                                <input
                                                    v-model="editingDate"
                                                    type="date"
                                                    class="block w-full rounded-md border-0 px-2 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                                <button
                                                    type="button"
                                                    @click="saveDate"
                                                    :disabled="isProcessing"
                                                    class="rounded bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                                >
                                                    Save
                                                </button>
                                                <button
                                                    type="button"
                                                    @click="cancelEditingDate"
                                                    class="rounded bg-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-300"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div class="flex items-center gap-2">
                                                <dd class="text-gray-900 dark:text-white">{{ order.date_of_purchase ? formatDate(order.date_of_purchase) : formatDate(order.created_at) }}</dd>
                                                <button
                                                    v-if="canEditOrder"
                                                    type="button"
                                                    @click="startEditingDate"
                                                    class="rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                                                    title="Edit date"
                                                >
                                                    <PencilIcon class="size-3.5" />
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <CalendarIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Created</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ formatDate(order.created_at) }}</dd>
                                    </div>
                                </div>
                                <div v-if="order.sales_channel" class="flex items-start gap-3">
                                    <component :is="order.sales_channel.is_local ? BuildingStorefrontIcon : GlobeAltIcon" class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Sales Channel</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ order.sales_channel.name }}</dd>
                                        <dd v-if="order.sales_channel.marketplace?.shop_domain" class="text-xs text-gray-500 dark:text-gray-400">{{ order.sales_channel.marketplace.shop_domain }}</dd>
                                    </div>
                                </div>
                                <div v-else-if="order.source_platform" class="flex items-start gap-3">
                                    <ShoppingBagIcon class="size-5 shrink-0 text-gray-400" />
                                    <div>
                                        <dt class="text-sm text-gray-500 dark:text-gray-400">Source</dt>
                                        <dd class="text-gray-900 dark:text-white">{{ order.source_platform }}</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <!-- Customer -->
                        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Customer</h2>

                            <!-- Existing customer -->
                            <div v-if="order.customer" class="flex items-center gap-3">
                                <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                                    <UserIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <Link :href="`/customers/${order.customer.id}`" class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                                        {{ order.customer.full_name }}
                                    </Link>
                                    <p v-if="order.customer.email" class="text-sm text-gray-500 dark:text-gray-400">{{ order.customer.email }}</p>
                                    <p v-if="order.customer.phone" class="text-sm text-gray-500 dark:text-gray-400">{{ order.customer.phone }}</p>
                                </div>
                            </div>

                            <!-- No customer - Add customer form -->
                            <div v-else>
                                <div v-if="isAddingCustomer" class="space-y-3">
                                    <CustomerSearch
                                        v-model="selectedCustomer"
                                        placeholder="Search for customer..."
                                    />
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            @click="saveCustomer"
                                            :disabled="isProcessing || !selectedCustomer"
                                            class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                        >
                                            Save
                                        </button>
                                        <button
                                            type="button"
                                            @click="cancelAddingCustomer"
                                            class="inline-flex items-center rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                                <div v-else class="text-center">
                                    <div class="flex size-12 mx-auto items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                        <UserIcon class="size-6 text-gray-400" />
                                    </div>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No customer assigned</p>
                                    <button
                                        type="button"
                                        @click="isAddingCustomer = true"
                                        class="mt-3 inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                                    >
                                        <UserIcon class="size-4" />
                                        Add Customer
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Employee -->
                        <div v-if="order.user" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Handled By</h2>
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                </div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ order.user.name }}</p>
                            </div>
                        </div>

                        <!-- Warehouse -->
                        <div v-if="order.warehouse" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Location</h2>
                            <p class="font-medium text-gray-900 dark:text-white">{{ order.warehouse.name }}</p>
                        </div>

                        <!-- Platform Order (for external marketplace orders) -->
                        <div v-if="order.platform_order || order.can_sync_from_platform" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-white">
                                    {{ formatPlatformName(orderPlatform) }} Order
                                </h2>
                                <button
                                    type="button"
                                    @click="syncFromMarketplace"
                                    :disabled="isSyncing"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                >
                                    <ArrowPathIcon class="size-3.5" :class="{ 'animate-spin': isSyncing }" />
                                    {{ isSyncing ? 'Syncing...' : (order.platform_order ? 'Sync' : 'Fetch Details') }}
                                </button>
                            </div>

                            <!-- Show platform order details if available -->
                            <dl v-if="order.platform_order" class="space-y-3 text-sm">
                                <div v-if="order.platform_order.external_order_number" class="flex justify-between">
                                    <dt class="text-gray-500 dark:text-gray-400">Order #</dt>
                                    <dd class="font-medium text-gray-900 dark:text-white">{{ order.platform_order.external_order_number }}</dd>
                                </div>
                                <div v-if="order.platform_order.status" class="flex justify-between">
                                    <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                                    <dd class="font-medium text-gray-900 dark:text-white capitalize">{{ order.platform_order.status }}</dd>
                                </div>
                                <div v-if="order.platform_order.fulfillment_status" class="flex justify-between">
                                    <dt class="text-gray-500 dark:text-gray-400">Fulfillment</dt>
                                    <dd class="font-medium text-gray-900 dark:text-white capitalize">{{ order.platform_order.fulfillment_status || 'Unfulfilled' }}</dd>
                                </div>
                                <div v-if="order.platform_order.payment_status" class="flex justify-between">
                                    <dt class="text-gray-500 dark:text-gray-400">Payment</dt>
                                    <dd class="font-medium text-gray-900 dark:text-white capitalize">{{ order.platform_order.payment_status }}</dd>
                                </div>
                                <div v-if="order.platform_order.last_synced_at" class="flex justify-between">
                                    <dt class="text-gray-500 dark:text-gray-400">Last Synced</dt>
                                    <dd class="text-gray-600 dark:text-gray-300">{{ formatDate(order.platform_order.last_synced_at) }}</dd>
                                </div>
                                <div v-if="order.platform_order.marketplace?.shop_domain" class="pt-2 border-t border-gray-100 dark:border-gray-700">
                                    <dt class="text-gray-500 dark:text-gray-400 mb-1">Store</dt>
                                    <dd class="text-gray-900 dark:text-white">
                                        {{ order.platform_order.marketplace.name }}
                                        <span class="block text-xs text-gray-500">{{ order.platform_order.marketplace.shop_domain }}</span>
                                    </dd>
                                </div>
                            </dl>

                            <!-- Show minimal info when no platform order yet -->
                            <div v-else class="text-sm">
                                <p class="text-gray-500 dark:text-gray-400 mb-3">
                                    This order was imported from {{ formatPlatformName(orderPlatform) }} but detailed platform data has not been synced yet.
                                </p>
                                <dl class="space-y-2">
                                    <div v-if="order.external_marketplace_id" class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">External ID</dt>
                                        <dd class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ order.external_marketplace_id }}</dd>
                                    </div>
                                    <div v-if="order.sales_channel?.marketplace?.shop_domain" class="flex justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Store</dt>
                                        <dd class="text-gray-700 dark:text-gray-300">{{ order.sales_channel.marketplace.shop_domain }}</dd>
                                    </div>
                                </dl>
                                <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                                    Click "Fetch Details" to download the latest order data from {{ formatPlatformName(orderPlatform) }}.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modal -->
        <CollectPaymentModal
            :show="showPaymentModal"
            model-type="order"
            :model="order"
            :title="order.invoice_number || `Order #${order.id}`"
            :subtitle="order.customer?.full_name || ''"
            @close="closePaymentModal"
            @success="onPaymentSuccess"
        />

        <!-- Ship Order Modal -->
        <ShipOrderModal
            :show="showShipModal"
            :order="{
                id: order.id,
                order_id: order.order_id || order.invoice_number || `Order #${order.id}`,
                tracking_number: order.tracking_number,
                shipping_carrier: order.shipping_carrier,
                shipping_address: order.shipping_address,
            }"
            :fedex-configured="fedexConfigured"
            :shipstation-configured="shipstationConfigured"
            @close="closeShipModal"
            @success="onShipSuccess"
        />
    </AppLayout>
</template>
