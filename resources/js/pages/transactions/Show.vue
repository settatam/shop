<script setup lang="ts">
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { NotesSection } from '@/components/notes';
import AddItemModal from '@/components/transactions/AddItemModal.vue';
import AttachmentsSection from '@/components/transactions/AttachmentsSection.vue';
import ShippingLabelsSection from '@/components/transactions/ShippingLabelsSection.vue';
import SmsMessagesSection from '@/components/transactions/SmsMessagesSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import {
    ArrowLeftIcon,
    PencilIcon,
    TrashIcon,
    PlusIcon,
    CurrencyDollarIcon,
    CheckIcon,
    XMarkIcon,
    PrinterIcon,
    DocumentTextIcon,
    ChevronDownIcon,
    TruckIcon,
    ArrowPathIcon,
} from '@heroicons/vue/20/solid';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    UserIcon,
    EnvelopeIcon,
    PhoneIcon,
    ClockIcon,
    ScaleIcon,
    BanknotesIcon,
    InboxIcon,
    PaperAirplaneIcon,
    ArchiveBoxIcon,
    ArrowUturnLeftIcon,
    ClipboardDocumentListIcon,
    PhotoIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    CheckBadgeIcon,
} from '@heroicons/vue/24/outline';
import { CustomerSearch } from '@/components/customers';
import CustomerEditModal from '@/components/customers/CustomerEditModal.vue';
import axios from 'axios';

interface ItemImage {
    id: number;
    url: string;
    thumbnail_url: string | null;
    is_primary: boolean;
}

interface TransactionItem {
    id: number;
    title: string;
    description: string | null;
    category: { id: number; name: string } | null;
    metal_type: string | null;
    karat: string | null;
    weight: number | null;
    dwt: number | null;
    price: number | null;
    buy_price: number | null;
    notes: string | null;
    is_added_to_inventory: boolean;
    reviewed_at: string | null;
    images: ItemImage[];
}

interface Customer {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone_number: string | null;
}

interface User {
    id: number;
    name: string;
}

interface TeamMember {
    id: number;
    name: string;
}

interface StatusHistoryItem {
    id: number;
    from_status: string | null;
    to_status: string;
    from_status_label: string | null;
    to_status_label: string;
    notes: string | null;
    user: User | null;
    created_at: string;
}

interface Offer {
    id: number;
    amount: number;
    status: 'pending' | 'accepted' | 'declined' | 'superseded';
    admin_notes: string | null;
    customer_response: string | null;
    responded_at: string | null;
    created_at: string;
    user: User | null;
    responded_by_user: User | null;
    responded_by_customer: { id: number; name: string } | null;
    responder_name: string | null;
    was_responded_by_customer: boolean;
}

interface ShippingLabelInfo {
    id: number;
    tracking_number: string | null;
    carrier: string;
    service_type: string | null;
    status: string;
    shipping_cost: number | null;
    tracking_url: string | null;
    created_at: string;
}

interface TransactionPayout {
    id: number;
    provider: string;
    recipient_type: string;
    recipient_value: string;
    recipient_wallet: string | null;
    amount: number;
    currency: string;
    status: string;
    payout_batch_id: string | null;
    payout_item_id: string | null;
    transaction_id_external: string | null;
    error_code: string | null;
    error_message: string | null;
    tracking_url: string | null;
    processed_at: string | null;
    created_at: string;
}

interface NoteEntry {
    id: number;
    content: string;
    user: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
}

interface Transaction {
    id: number;
    transaction_number: string;
    status: string;
    type: string;
    source: string | null;
    preliminary_offer: number | null;
    final_offer: number | null;
    estimated_value: number | null;
    payment_method: string | null;
    payment_details: {
        // Check payment details
        check_name?: string;
        check_address?: string;
        check_address_2?: string;
        check_city?: string;
        check_state?: string;
        check_zip?: string;
        // Bank/ACH/Wire payment details
        bank_name?: string;
        routing_number?: string;
        account_number?: string;
        account_name?: string;
        account_type?: string;
        bank_address?: string;
        bank_city?: string;
        bank_state?: string;
        bank_zip?: string;
        // PayPal
        paypal_email?: string;
        // Venmo
        venmo_handle?: string;
    } | null;
    bin_location: string | null;
    customer_notes: string | null;
    internal_notes: string | null;
    offer_given_at: string | null;
    offer_accepted_at: string | null;
    payment_processed_at: string | null;
    created_at: string;
    updated_at: string;
    item_count: number;
    total_dwt: number;
    total_value: number;
    total_buy_price: number;
    can_submit_offer: boolean;
    can_accept_offer: boolean;
    can_process_payment: boolean;
    can_be_cancelled: boolean;
    is_in_store: boolean;
    is_online: boolean;
    customer: Customer | null;
    user: User | null;
    assigned_user: User | null;
    items: TransactionItem[];
    // Online transaction fields
    customer_description?: string | null;
    customer_amount?: number | null;
    customer_categories?: string | null;
    outbound_tracking_number?: string | null;
    outbound_carrier?: string | null;
    return_tracking_number?: string | null;
    return_carrier?: string | null;
    kit_sent_at?: string | null;
    kit_delivered_at?: string | null;
    items_received_at?: string | null;
    items_reviewed_at?: string | null;
    return_shipped_at?: string | null;
    return_delivered_at?: string | null;
    status_history?: StatusHistoryItem[];
    // Offer tracking
    offers?: Offer[];
    pending_offer?: {
        id: number;
        amount: number;
        admin_notes: string | null;
        created_at: string;
    } | null;
    // Shipping labels
    outbound_label?: ShippingLabelInfo | null;
    return_label?: ShippingLabelInfo | null;
    // Shipping address
    shipping_address_id?: number | null;
    shipping_address?: CustomerAddress | null;
    // Rollback actions
    rollback_actions?: Record<string, string>;
    // Payouts
    payouts?: TransactionPayout[];
    // Notes
    note_entries?: NoteEntry[];
}

interface Status {
    value: string;
    label: string;
}

interface PaymentMethod {
    value: string;
    label: string;
}

interface ShippingOptions {
    service_types: Record<string, string>;
    packaging_types: Record<string, string>;
    default_package: {
        weight: number;
        length: number;
        width: number;
        height: number;
    };
    is_configured: boolean;
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

interface SmsMessage {
    id: number;
    content: string;
    channel: string;
    direction: 'inbound' | 'outbound';
    status: string;
    recipient: string;
    sent_at: string | null;
    delivered_at: string | null;
    created_at: string;
}

interface CategoryOption {
    value: number;
    label: string;
}

interface SelectOption {
    value: string;
    label: string;
}

interface CustomerAddress {
    id: number;
    full_name: string | null;
    one_line_address: string;
    is_valid_for_shipping: boolean;
    is_default: boolean;
    is_shipping: boolean;
    address: string | null;
    city: string | null;
    state_id: number | null;
    zip: string | null;
    phone: string | null;
}

interface Attachment {
    id: number;
    url: string;
    thumbnail_url: string | null;
    alt_text: string | null;
}

interface Props {
    transaction: Transaction;
    attachments?: Attachment[];
    statuses: Status[];
    paymentMethods: PaymentMethod[];
    teamMembers?: TeamMember[];
    shippingOptions?: ShippingOptions;
    categories?: CategoryOption[];
    preciousMetals?: SelectOption[];
    conditions?: SelectOption[];
    smsMessages?: SmsMessage[];
    activityLogs?: ActivityDay[];
    customerAddresses?: CustomerAddress[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transactions', href: '/transactions' },
    { title: props.transaction.transaction_number, href: `/transactions/${props.transaction.id}` },
];

// Forms
const offerForm = useForm({
    offer: props.transaction.final_offer || '',
    notes: '',
    send_notification: true, // Default to sending notification
});

// Multiple payments support
interface PaymentEntry {
    id: string;
    method: string;
    amount: number;
    details: Record<string, any>;
}

const payments = ref<PaymentEntry[]>([]);
const paymentProcessing = ref(false);

function initializePayments() {
    if (payments.value.length === 0) {
        payments.value.push({
            id: crypto.randomUUID(),
            method: '',
            amount: props.transaction.final_offer || 0,
            details: {},
        });
    }
}

function addPayment() {
    const remainingAmount = (props.transaction.final_offer || 0) - totalPaymentsAmount.value;
    payments.value.push({
        id: crypto.randomUUID(),
        method: '',
        amount: remainingAmount > 0 ? remainingAmount : 0,
        details: {},
    });
}

function removePayment(paymentId: string) {
    if (payments.value.length > 1) {
        payments.value = payments.value.filter(p => p.id !== paymentId);
    }
}

function updatePaymentMethod(paymentId: string, method: string) {
    const payment = payments.value.find(p => p.id === paymentId);
    if (payment) {
        payment.method = method;
        payment.details = {};
    }
}

function updatePaymentDetails(paymentId: string, details: Record<string, any>) {
    const payment = payments.value.find(p => p.id === paymentId);
    if (payment) {
        payment.details = { ...payment.details, ...details };
    }
}

const totalPaymentsAmount = computed(() => {
    return payments.value.reduce((sum, p) => sum + (p.amount || 0), 0);
});

const paymentRemainingBalance = computed(() => {
    return (props.transaction.final_offer || 0) - totalPaymentsAmount.value;
});

const paymentsBalanced = computed(() => {
    return Math.abs(paymentRemainingBalance.value) < 0.01;
});

const declineForm = useForm({
    offer_id: null as number | null,
    reason: '',
});

const kitSentForm = useForm({
    tracking_number: '',
    carrier: 'fedex',
});

const returnShippedForm = useForm({
    tracking_number: '',
    carrier: 'fedex',
});

const assignForm = useForm({
    assigned_to: props.transaction.assigned_user?.id || '',
});

// Modal states
const showOfferModal = ref(false);
const showPaymentModal = ref(false);
const showDeclineModal = ref(false);
const showKitSentModal = ref(false);
const showReturnShippedModal = ref(false);
const showAssignModal = ref(false);
const showRejectKitModal = ref(false);
const showAddItemModal = ref(false);
const showCustomerEditModal = ref(false);

// Item editing state
interface EditableItem {
    id: string;
    title: string;
    description?: string;
    category_id?: number;
    precious_metal?: string;
    dwt?: number;
    condition?: string;
    price?: number;
    buy_price: number;
}
const editingItem = ref<EditableItem | null>(null);
const editingItemImages = ref<{ id: number; url: string; thumbnail_url: string | null }[]>([]);
const savingItem = ref(false);

// Inline price editing state
const inlineEditingPrices = ref<Record<number, { price: number | null; buy_price: number | null }>>({});
const inlineUpdating = ref<Record<number, boolean>>({});
let debounceTimers: Record<number, ReturnType<typeof setTimeout>> = {};

function initializeInlinePrices() {
    props.transaction.items.forEach(item => {
        inlineEditingPrices.value[item.id] = {
            price: item.price,
            buy_price: item.buy_price,
        };
    });
}

// Initialize on mount
initializeInlinePrices();

// Watch for transaction changes (e.g., after item added/removed)
watch(() => props.transaction.items, () => {
    initializeInlinePrices();
}, { deep: true });

async function saveInlinePrice(itemId: number, field: 'price' | 'buy_price') {
    // Clear any existing timer for this item
    if (debounceTimers[itemId]) {
        clearTimeout(debounceTimers[itemId]);
    }

    // Debounce the save
    debounceTimers[itemId] = setTimeout(async () => {
        const values = inlineEditingPrices.value[itemId];
        if (!values) return;

        inlineUpdating.value[itemId] = true;

        try {
            const response = await axios.patch(
                `/transactions/${props.transaction.id}/items/${itemId}/quick-update`,
                {
                    [field]: values[field],
                }
            );

            // Update local state with returned values
            if (response.data.success) {
                const item = props.transaction.items.find(i => i.id === itemId);
                if (item) {
                    item.price = response.data.item.price;
                    item.buy_price = response.data.item.buy_price;
                }
                // Update transaction totals
                if (response.data.transaction) {
                    props.transaction.total_buy_price = response.data.transaction.total_buy_price;
                }
            }
        } catch (error) {
            console.error('Failed to update price:', error);
            // Revert to original value on error
            const item = props.transaction.items.find(i => i.id === itemId);
            if (item) {
                inlineEditingPrices.value[itemId] = {
                    price: item.price,
                    buy_price: item.buy_price,
                };
            }
        } finally {
            inlineUpdating.value[itemId] = false;
        }
    }, 500);
}

// Image lightbox modal state
const showImageModal = ref(false);
const modalImages = ref<ItemImage[]>([]);
const modalImageIndex = ref(0);
const modalItemTitle = ref('');

function openImageModal(item: TransactionItem) {
    if (!item.images || item.images.length === 0) return;
    modalImages.value = item.images;
    modalImageIndex.value = 0;
    modalItemTitle.value = item.title || 'Item';
    showImageModal.value = true;
}

function closeImageModal() {
    showImageModal.value = false;
}

function nextModalImage() {
    if (modalImages.value.length <= 1) return;
    modalImageIndex.value = (modalImageIndex.value + 1) % modalImages.value.length;
}

function prevModalImage() {
    if (modalImages.value.length <= 1) return;
    modalImageIndex.value = (modalImageIndex.value - 1 + modalImages.value.length) % modalImages.value.length;
}

function openAddItemModal() {
    editingItem.value = null;
    editingItemImages.value = [];
    showAddItemModal.value = true;
}

function openEditItemModal(item: TransactionItem) {
    editingItem.value = {
        id: String(item.id),
        title: item.title || '',
        description: item.description || '',
        category_id: item.category?.id,
        precious_metal: item.metal_type || '',
        dwt: item.dwt || undefined,
        condition: '',
        price: item.price || undefined,
        buy_price: item.buy_price || 0,
    };
    editingItemImages.value = item.images?.map(img => ({
        id: img.id,
        url: img.url,
        thumbnail_url: img.thumbnail_url,
    })) || [];
    showAddItemModal.value = true;
}

async function handleSaveItem(item: EditableItem, deletedImageIds: number[] = []) {
    savingItem.value = true;
    try {
        let itemId: number;

        if (editingItem.value && editingItem.value.id && !editingItem.value.id.includes('-')) {
            // Update existing item
            itemId = parseInt(editingItem.value.id);
            await axios.put(`/api/v1/transactions/${props.transaction.id}/items/${itemId}`, {
                title: item.title,
                description: item.description,
                category_id: item.category_id,
                precious_metal: item.precious_metal,
                dwt: item.dwt,
                condition: item.condition,
                price: item.price,
                buy_price: item.buy_price,
            });

            // Delete images that were marked for removal
            for (const imageId of deletedImageIds) {
                await axios.delete(`/transactions/${props.transaction.id}/items/${itemId}/images/${imageId}`);
            }
        } else {
            // Add new item
            const response = await axios.post(`/api/v1/transactions/${props.transaction.id}/items`, {
                title: item.title,
                description: item.description,
                category_id: item.category_id,
                precious_metal: item.precious_metal,
                dwt: item.dwt,
                condition: item.condition,
                price: item.price,
                buy_price: item.buy_price,
            });
            itemId = response.data.item?.id || response.data.data?.id || response.data.id;
        }

        // Upload images if any were added
        if (item.images && item.images.length > 0) {
            const formData = new FormData();
            item.images.forEach((file: File) => {
                formData.append('images[]', file);
            });

            await axios.post(
                `/transactions/${props.transaction.id}/items/${itemId}/images`,
                formData,
                {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    },
                }
            );
        }

        // Refresh the page to get updated data
        router.reload({ only: ['transaction'] });
        showAddItemModal.value = false;
    } catch (error: any) {
        console.error('Failed to save item:', error);
        alert(error.response?.data?.message || 'Failed to save item');
    } finally {
        savingItem.value = false;
    }
}

async function handleDeleteItem(itemId: number) {
    if (!confirm('Are you sure you want to delete this item?')) return;

    try {
        await axios.delete(`/api/v1/transactions/${props.transaction.id}/items/${itemId}`);
        router.reload({ only: ['transaction'] });
    } catch (error: any) {
        console.error('Failed to delete item:', error);
        alert(error.response?.data?.message || 'Failed to delete item');
    }
}

async function handleReviewItem(item: TransactionItem) {
    if (item.reviewed_at) return;

    if (!confirm('Are you sure you want to mark this item as reviewed?')) {
        return;
    }

    try {
        await axios.post(`/transactions/${props.transaction.id}/items/${item.id}/review`);
        router.reload({ only: ['transaction', 'activityLogs'] });
    } catch (error: any) {
        console.error('Failed to review item:', error);
        alert(error.response?.data?.message || 'Failed to review item');
    }
}

function formatReviewDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// Loading states for shipping labels
const creatingOutboundLabel = ref(false);
const creatingReturnLabel = ref(false);

// Shipping label modal state and form
const showShippingLabelModal = ref(false);
const shippingLabelType = ref<'outbound' | 'return'>('outbound');

const shippingLabelForm = useForm({
    service_type: 'FEDEX_GROUND',
    packaging_type: 'YOUR_PACKAGING',
    weight: props.shippingOptions?.default_package?.weight || 1,
    length: props.shippingOptions?.default_package?.length || 12,
    width: props.shippingOptions?.default_package?.width || 12,
    height: props.shippingOptions?.default_package?.height || 6,
});

function openShippingLabelModal(type: 'outbound' | 'return') {
    shippingLabelType.value = type;
    // Reset form to defaults
    shippingLabelForm.service_type = 'FEDEX_GROUND';
    shippingLabelForm.packaging_type = 'YOUR_PACKAGING';
    shippingLabelForm.weight = props.shippingOptions?.default_package?.weight || 1;
    shippingLabelForm.length = props.shippingOptions?.default_package?.length || 12;
    shippingLabelForm.width = props.shippingOptions?.default_package?.width || 12;
    shippingLabelForm.height = props.shippingOptions?.default_package?.height || 6;
    showShippingLabelModal.value = true;
}

function submitShippingLabel() {
    const url = shippingLabelType.value === 'outbound'
        ? `/transactions/${props.transaction.id}/create-outbound-label`
        : `/transactions/${props.transaction.id}/create-return-label`;

    if (shippingLabelType.value === 'outbound') {
        creatingOutboundLabel.value = true;
    } else {
        creatingReturnLabel.value = true;
    }

    shippingLabelForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            showShippingLabelModal.value = false;
        },
        onFinish: () => {
            creatingOutboundLabel.value = false;
            creatingReturnLabel.value = false;
        },
    });
}

// Reject kit form
const rejectKitForm = useForm({
    reason: '',
});

// Payout modal state and form
const showPayoutModal = ref(false);
const payoutProcessing = ref(false);
const payoutForm = useForm({
    recipient_value: '',
    amount: 0,
    wallet: 'PAYPAL' as 'PAYPAL' | 'VENMO',
    note: '',
});

function initializePayout() {
    // Pre-fill with customer email if available and payment method is PayPal/Venmo
    const payments = props.transaction.payment_method ? [{ method: props.transaction.payment_method }] : [];
    const paypalPayment = payments.find(p => p.method === 'paypal' || p.method === 'venmo');
    if (paypalPayment && props.transaction.customer?.email) {
        payoutForm.recipient_value = props.transaction.customer.email;
        payoutForm.wallet = paypalPayment.method === 'venmo' ? 'VENMO' : 'PAYPAL';
    }
    payoutForm.amount = props.transaction.final_offer || 0;
}

// Formatting helpers
const formatCurrency = (value: number | null) => {
    if (value === null || value === undefined) return '-';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatShortDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatWeight = (weight: number | string | null) => {
    if (weight === null || weight === undefined || weight === '') return '-';
    const num = typeof weight === 'string' ? parseFloat(weight) : weight;
    if (isNaN(num)) return '-';
    return `${num.toFixed(2)} dwt`;
};

function printPackingSlip() {
    window.open(`/transactions/${props.transaction.id}/packing-slip/stream`, '_blank');
}

// Status helpers
const statusColors: Record<string, string> = {
    // Kit Request Phase
    pending_kit_request: 'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20',
    kit_request_confirmed: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
    kit_request_rejected: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
    kit_request_on_hold: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20',
    // Kit Shipping Phase
    kit_sent: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20',
    kit_delivered: 'bg-purple-50 text-purple-700 ring-purple-600/20 dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/20',
    // Items Phase
    pending: 'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20',
    items_received: 'bg-cyan-50 text-cyan-700 ring-cyan-600/20 dark:bg-cyan-500/10 dark:text-cyan-400 dark:ring-cyan-500/20',
    kit_received: 'bg-purple-50 text-purple-700 ring-purple-600/20 dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/20',
    items_reviewed: 'bg-teal-50 text-teal-700 ring-teal-600/20 dark:bg-teal-500/10 dark:text-teal-400 dark:ring-teal-500/20',
    // Offer Phase
    offer_given: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
    offer_accepted: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20',
    offer_declined: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
    // Payment Phase
    payment_pending: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20',
    payment_processed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20',
    // Return/Cancellation
    return_requested: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
    items_returned: 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
    cancelled: 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
};

const statusLabels: Record<string, string> = {
    // Kit Request Phase
    pending_kit_request: 'Pending Kit Request',
    kit_request_confirmed: 'Kit Request Confirmed',
    kit_request_rejected: 'Kit Request Rejected',
    kit_request_on_hold: 'Kit Request On Hold',
    // Kit Shipping Phase
    kit_sent: 'Kit Sent',
    kit_delivered: 'Kit Delivered',
    // Items Phase
    pending: 'Pending',
    items_received: 'Items Received',
    kit_received: 'Kit Received',
    items_reviewed: 'Items Reviewed',
    // Offer Phase
    offer_given: 'Offer Given',
    offer_accepted: 'Offer Accepted',
    offer_declined: 'Offer Declined',
    // Payment Phase
    payment_pending: 'Payment Pending',
    payment_processed: 'Payment Processed',
    // Return/Cancellation
    return_requested: 'Return Requested',
    items_returned: 'Items Returned',
    cancelled: 'Cancelled',
};

const typeLabels: Record<string, string> = {
    in_store: 'In-Store',
    mail_in: 'Mail-In',
};

const carrierLabels: Record<string, string> = {
    fedex: 'FedEx',
    ups: 'UPS',
    usps: 'USPS',
    dhl: 'DHL',
};

// Actions
const submitOffer = () => {
    offerForm.post(`/transactions/${props.transaction.id}/offer`, {
        onSuccess: () => {
            showOfferModal.value = false;
            offerForm.reset();
        },
    });
};

const acceptOffer = (offerId?: number) => {
    const offerIdToUse = offerId || props.transaction.pending_offer?.id;
    if (!offerIdToUse) return;

    router.post(`/transactions/${props.transaction.id}/accept`, {
        offer_id: offerIdToUse,
    });
};

const openDeclineModal = (offerId?: number) => {
    declineForm.offer_id = offerId || props.transaction.pending_offer?.id || null;
    showDeclineModal.value = true;
};

const declineOffer = () => {
    if (!declineForm.offer_id) return;

    declineForm.post(`/transactions/${props.transaction.id}/decline`, {
        onSuccess: () => {
            showDeclineModal.value = false;
            declineForm.reset();
        },
    });
};

const processPayment = () => {
    if (!paymentsBalanced.value) {
        alert('Total payments must equal the offer amount.');
        return;
    }

    paymentProcessing.value = true;
    router.post(`/transactions/${props.transaction.id}/process-payment`, {
        payments: payments.value.map(p => ({
            method: p.method,
            amount: p.amount,
            details: p.details,
        })),
    }, {
        onSuccess: () => {
            showPaymentModal.value = false;
            payments.value = [];
        },
        onFinish: () => {
            paymentProcessing.value = false;
        },
    });
};

const deleteTransaction = () => {
    if (confirm(`Are you sure you want to delete transaction ${props.transaction.transaction_number}?`)) {
        router.delete(`/transactions/${props.transaction.id}`);
    }
};

const changeStatus = (status: string) => {
    if (status === props.transaction.status) return;
    router.post(`/transactions/${props.transaction.id}/change-status`, { status });
};

// Online transaction actions
const confirmKitRequest = () => {
    router.post(`/transactions/${props.transaction.id}/confirm-kit-request`);
};

const rejectKitRequest = () => {
    if (confirm('Are you sure you want to reject this kit request?')) {
        router.post(`/transactions/${props.transaction.id}/reject-kit-request`);
    }
};

const holdKitRequest = () => {
    router.post(`/transactions/${props.transaction.id}/hold-kit-request`);
};

const markKitSent = () => {
    kitSentForm.post(`/transactions/${props.transaction.id}/mark-kit-sent`, {
        onSuccess: () => {
            showKitSentModal.value = false;
            kitSentForm.reset();
        },
    });
};

const markKitDelivered = () => {
    router.post(`/transactions/${props.transaction.id}/mark-kit-delivered`);
};

const markItemsReceived = () => {
    router.post(`/transactions/${props.transaction.id}/mark-items-received`);
};

const markItemsReviewed = () => {
    router.post(`/transactions/${props.transaction.id}/mark-items-reviewed`);
};

const requestReturn = () => {
    if (confirm('Are you sure you want to request a return for this transaction?')) {
        router.post(`/transactions/${props.transaction.id}/request-return`);
    }
};

const markReturnShipped = () => {
    returnShippedForm.post(`/transactions/${props.transaction.id}/mark-return-shipped`, {
        onSuccess: () => {
            showReturnShippedModal.value = false;
            returnShippedForm.reset();
        },
    });
};

const markItemsReturned = () => {
    router.post(`/transactions/${props.transaction.id}/mark-items-returned`);
};

// Shipping Label Actions
const createOutboundLabel = () => {
    openShippingLabelModal('outbound');
};

const createReturnLabel = () => {
    openShippingLabelModal('return');
};

const printOutboundLabel = () => {
    window.open(`/transactions/${props.transaction.id}/print-outbound-label`, '_blank');
};

const printReturnLabel = () => {
    window.open(`/transactions/${props.transaction.id}/print-return-label`, '_blank');
};

// Reject Kit Action
const rejectKit = () => {
    rejectKitForm.post(`/transactions/${props.transaction.id}/reject-kit`, {
        onSuccess: () => {
            showRejectKitModal.value = false;
            rejectKitForm.reset();
        },
    });
};

// Initiate Return Action
const initiateReturn = () => {
    if (confirm('Are you sure you want to initiate a return for this transaction? This will move the transaction to Return Requested status.')) {
        router.post(`/transactions/${props.transaction.id}/initiate-return`);
    }
};

// Rollback/Reset Actions
const resetToItemsReviewed = () => {
    if (confirm('Are you sure you want to reset this transaction to Items Reviewed? This will allow you to submit a new offer.')) {
        router.post(`/transactions/${props.transaction.id}/reset-to-items-reviewed`);
    }
};

const reopenOffer = () => {
    if (confirm('Are you sure you want to reopen the offer? The customer will be able to respond to the offer again.')) {
        router.post(`/transactions/${props.transaction.id}/reopen-offer`);
    }
};

const cancelReturn = () => {
    if (confirm('Are you sure you want to cancel the pending return?')) {
        router.post(`/transactions/${props.transaction.id}/cancel-return`);
    }
};

const undoPayment = () => {
    if (confirm('Are you sure you want to undo the payment? This will move the transaction back to Offer Accepted status.')) {
        router.post(`/transactions/${props.transaction.id}/undo-payment`);
    }
};

// PayPal Payout Actions
const openPayoutModal = () => {
    initializePayout();
    showPayoutModal.value = true;
};

const sendPayout = () => {
    payoutProcessing.value = true;
    payoutForm.post(`/transactions/${props.transaction.id}/send-payout`, {
        onSuccess: () => {
            showPayoutModal.value = false;
            payoutForm.reset();
        },
        onFinish: () => {
            payoutProcessing.value = false;
        },
    });
};

const refreshPayoutStatus = (payoutId: number) => {
    router.post(`/transactions/${props.transaction.id}/refresh-payout-status`, {
        payout_id: payoutId,
    });
};

// Check if transaction has PayPal/Venmo payments
const hasPayPalOrVenmoPayment = computed(() => {
    // Check payment_method
    if (props.transaction.payment_method === 'paypal' || props.transaction.payment_method === 'venmo') {
        return true;
    }
    return false;
});

// Get payout status display info
const payoutStatusColors: Record<string, string> = {
    pending: 'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20',
    processing: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
    SUCCESS: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20',
    FAILED: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
    UNCLAIMED: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-400 dark:ring-orange-500/20',
    RETURNED: 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
    ONHOLD: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
    BLOCKED: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
    REFUNDED: 'bg-purple-50 text-purple-700 ring-purple-600/20 dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/20',
};

const payoutStatusLabels: Record<string, string> = {
    pending: 'Pending',
    processing: 'Processing',
    SUCCESS: 'Successful',
    FAILED: 'Failed',
    UNCLAIMED: 'Unclaimed',
    RETURNED: 'Returned',
    ONHOLD: 'On Hold',
    BLOCKED: 'Blocked',
    REFUNDED: 'Refunded',
};

// Execute rollback action by key
const executeRollbackAction = (actionKey: string) => {
    switch (actionKey) {
        case 'reset_to_items_reviewed':
            resetToItemsReviewed();
            break;
        case 'reopen_offer':
            reopenOffer();
            break;
        case 'cancel_return':
            cancelReturn();
            break;
        case 'undo_payment':
            undoPayment();
            break;
    }
};

// Check if there are any available rollback actions
const hasRollbackActions = computed(() => {
    return props.transaction.rollback_actions && Object.keys(props.transaction.rollback_actions).length > 0;
});


const assignTransaction = () => {
    assignForm.post(`/transactions/${props.transaction.id}/assign`, {
        onSuccess: () => {
            showAssignModal.value = false;
        },
    });
};

// Filter statuses that can be changed to (exclude payment_processed if already processed)
const availableStatuses = computed(() => {
    return props.statuses.filter(status => {
        if (status.value === props.transaction.status) return false;
        if (props.transaction.status === 'payment_processed') return false;
        return true;
    });
});

// Get the current status label
const currentStatusLabel = computed(() => {
    const status = props.statuses.find(s => s.value === props.transaction.status);
    return status?.label || props.transaction.status;
});

// Computed helpers for online workflow actions
const canConfirmKitRequest = computed(() =>
    props.transaction.is_online && props.transaction.status === 'pending_kit_request'
);

const canRejectKitRequest = computed(() =>
    props.transaction.is_online && ['pending_kit_request', 'kit_request_on_hold'].includes(props.transaction.status)
);

const canHoldKitRequest = computed(() =>
    props.transaction.is_online && props.transaction.status === 'pending_kit_request'
);

const canMarkKitSent = computed(() =>
    props.transaction.is_online && ['kit_request_confirmed', 'kit_request_on_hold'].includes(props.transaction.status)
);

const canMarkKitDelivered = computed(() =>
    props.transaction.is_online && props.transaction.status === 'kit_sent'
);

const canMarkItemsReceived = computed(() =>
    props.transaction.is_online && props.transaction.status === 'kit_delivered'
);

const canMarkItemsReviewed = computed(() =>
    props.transaction.is_online && props.transaction.status === 'items_received'
);

const canRequestReturn = computed(() =>
    props.transaction.is_online && ['offer_declined', 'items_reviewed'].includes(props.transaction.status)
);

const canMarkReturnShipped = computed(() =>
    props.transaction.is_online && props.transaction.status === 'return_requested'
);

const canMarkItemsReturned = computed(() =>
    props.transaction.is_online && props.transaction.status === 'return_requested' && props.transaction.return_tracking_number
);

// Shipping label helpers
// Allow creating outbound labels for any online transaction that doesn't have one
// (except completed/cancelled transactions)
const canCreateOutboundLabel = computed(() =>
    props.transaction.is_online &&
    props.shippingOptions?.is_configured &&
    !props.transaction.outbound_label &&
    !['completed', 'cancelled'].includes(props.transaction.status)
);

// Allow creating return labels for any online transaction that doesn't have one
// (except completed/cancelled transactions and early statuses before items received)
const canCreateReturnLabel = computed(() =>
    props.transaction.is_online &&
    props.shippingOptions?.is_configured &&
    !props.transaction.return_label &&
    !['pending', 'kit_request_confirmed', 'kit_request_on_hold', 'kit_sent', 'completed', 'cancelled'].includes(props.transaction.status)
);

const canRejectKit = computed(() =>
    props.transaction.is_online &&
    ['items_received', 'items_reviewed'].includes(props.transaction.status)
);

const canInitiateReturn = computed(() =>
    props.transaction.is_online &&
    ['offer_declined', 'kit_request_rejected'].includes(props.transaction.status)
);

// Offer status colors
const offerStatusColors: Record<string, string> = {
    pending: 'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20',
    accepted: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20',
    declined: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
    superseded: 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
};

// Customer selection
const selectedCustomer = ref<Customer | null>(props.transaction.customer);
const savingCustomer = ref(false);

watch(selectedCustomer, async (newCustomer) => {
    if (savingCustomer.value) return;

    const newCustomerId = newCustomer?.id || null;
    const currentCustomerId = props.transaction.customer?.id || null;

    if (newCustomerId === currentCustomerId) return;

    savingCustomer.value = true;
    try {
        await axios.patch(`/api/v1/transactions/${props.transaction.id}`, {
            customer_id: newCustomerId,
        });
        // Refresh the page to get updated data
        router.reload({ only: ['transaction'] });
    } catch (err) {
        // Revert on error
        selectedCustomer.value = props.transaction.customer;
        console.error('Failed to update customer', err);
    } finally {
        savingCustomer.value = false;
    }
});

// Helper for tracking URLs
const getTrackingUrl = (trackingNumber: string, carrier: string) => {
    const urls: Record<string, string> = {
        fedex: `https://www.fedex.com/fedextrack/?trknbr=${trackingNumber}`,
        ups: `https://www.ups.com/track?tracknum=${trackingNumber}`,
        usps: `https://tools.usps.com/go/TrackConfirmAction?tLabels=${trackingNumber}`,
        dhl: `https://www.dhl.com/us-en/home/tracking.html?tracking-id=${trackingNumber}`,
    };
    return urls[carrier] || '#';
};
</script>

<template>
    <Head :title="transaction.transaction_number" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-4">
                    <Link
                        href="/transactions"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                                {{ transaction.transaction_number }}
                            </h1>
                            <span
                                :class="[
                                    'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset',
                                    statusColors[transaction.status] || statusColors.pending,
                                ]"
                            >
                                {{ statusLabels[transaction.status] || transaction.status }}
                            </span>
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                {{ typeLabels[transaction.type] || transaction.type }}
                            </span>
                            <span v-if="transaction.source" class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400">
                                {{ transaction.source }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Created {{ formatDate(transaction.created_at) }}
                            <span v-if="transaction.assigned_user"> &middot; Assigned to {{ transaction.assigned_user.name }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <!-- Print Barcode -->
                    <Link
                        :href="`/transactions/${transaction.id}/print-barcode`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        <PrinterIcon class="-ml-0.5 size-5" />
                        Barcode
                    </Link>

                    <!-- Customer Invoice -->
                    <Link
                        :href="`/transactions/${transaction.id}/print-invoice?type=customer`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        <DocumentTextIcon class="-ml-0.5 size-5" />
                        Customer Invoice
                    </Link>

                    <!-- Buy/Store Invoice -->
                    <Link
                        :href="`/transactions/${transaction.id}/print-invoice?type=store`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        <DocumentTextIcon class="-ml-0.5 size-5" />
                        Buy Invoice
                    </Link>

                    <!-- Print Packing Slip -->
                    <button
                        type="button"
                        @click="printPackingSlip"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        <ClipboardDocumentListIcon class="-ml-0.5 size-5" />
                        Packing Slip
                    </button>

                    <!-- Assign (Online only) -->
<!--                    <button-->
<!--                        v-if="transaction.is_online"-->
<!--                        type="button"-->
<!--                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"-->
<!--                        @click="showAssignModal = true"-->
<!--                    >-->
<!--                        <UserIcon class="-ml-0.5 size-5" />-->
<!--                        Assign-->
<!--                    </button>-->

                    <!-- Change Status Dropdown -->
<!--                    <Menu as="div" class="relative">-->
<!--                        <MenuButton-->
<!--                            class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700"-->
<!--                        >-->
<!--                            Status-->
<!--                            <ChevronDownIcon class="-mr-1 size-5 text-gray-400" />-->
<!--                        </MenuButton>-->
<!--                        <transition-->
<!--                            enter-active-class="transition ease-out duration-100"-->
<!--                            enter-from-class="transform opacity-0 scale-95"-->
<!--                            enter-to-class="transform opacity-100 scale-100"-->
<!--                            leave-active-class="transition ease-in duration-75"-->
<!--                            leave-from-class="transform opacity-100 scale-100"-->
<!--                            leave-to-class="transform opacity-0 scale-95"-->
<!--                        >-->
<!--                            <MenuItems class="absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 focus:outline-none dark:bg-gray-800 dark:ring-white/10 max-h-64 overflow-y-auto">-->
<!--                                <MenuItem v-for="status in availableStatuses" :key="status.value" v-slot="{ active }">-->
<!--                                    <button-->
<!--                                        type="button"-->
<!--                                        :class="[-->
<!--                                            active ? 'bg-gray-100 dark:bg-gray-700' : '',-->
<!--                                            'block w-full px-4 py-2 text-left text-sm text-gray-700 dark:text-gray-200',-->
<!--                                        ]"-->
<!--                                        @click="changeStatus(status.value)"-->
<!--                                    >-->
<!--                                        {{ status.label }}-->
<!--                                    </button>-->
<!--                                </MenuItem>-->
<!--                            </MenuItems>-->
<!--                        </transition>-->
<!--                    </Menu>-->

                    <!-- Delete -->
                    <button
                        v-if="transaction.can_be_cancelled"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-red-50 dark:bg-gray-800 dark:ring-gray-600 dark:hover:bg-red-900/20"
                        @click="deleteTransaction"
                    >
                        <TrashIcon class="-ml-0.5 size-5" />
                        Delete
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Status Section - shown for all transactions -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Status</h3>
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                            statusColors[transaction.status] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                        ]"
                                    >
                                        {{ currentStatusLabel }}
                                    </span>
                                </div>
                                <Menu as="div" class="relative">
                                    <MenuButton
                                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    >
                                        Change Status
                                        <ChevronDownIcon class="-mr-1 size-5 text-gray-400" />
                                    </MenuButton>
                                    <transition
                                        enter-active-class="transition ease-out duration-100"
                                        enter-from-class="transform opacity-0 scale-95"
                                        enter-to-class="transform opacity-100 scale-100"
                                        leave-active-class="transition ease-in duration-75"
                                        leave-from-class="transform opacity-100 scale-100"
                                        leave-to-class="transform opacity-0 scale-95"
                                    >
                                        <MenuItems class="absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black/5 focus:outline-none dark:bg-gray-700 dark:ring-white/10">
                                            <div class="py-1">
                                                <MenuItem v-for="status in availableStatuses" :key="status.value" v-slot="{ active }">
                                                    <button
                                                        type="button"
                                                        :class="[active ? 'bg-gray-100 dark:bg-gray-600' : '', 'block w-full px-4 py-2 text-left text-sm text-gray-700 dark:text-gray-200']"
                                                        @click="changeStatus(status.value)"
                                                    >
                                                        {{ status.label }}
                                                    </button>
                                                </MenuItem>
                                            </div>
                                        </MenuItems>
                                    </transition>
                                </Menu>
                            </div>
                        </div>
                    </div>

                    <!-- Online Transaction Actions -->
                    <div v-if="transaction.is_online" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Workflow Actions</h3>
                            <div class="flex flex-wrap gap-3">
                                <!-- Kit Request Phase -->
                                <button
                                    v-if="canConfirmKitRequest"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                                    @click="confirmKitRequest"
                                >
                                    <CheckIcon class="-ml-0.5 size-5" />
                                    Confirm Kit Request
                                </button>
                                <button
                                    v-if="canHoldKitRequest"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-orange-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-500"
                                    @click="holdKitRequest"
                                >
                                    <ClockIcon class="-ml-0.5 size-5" />
                                    Put On Hold
                                </button>
                                <button
                                    v-if="canRejectKitRequest"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                    @click="rejectKitRequest"
                                >
                                    <XMarkIcon class="-ml-0.5 size-5" />
                                    Reject Kit Request
                                </button>

                                <!-- Kit Shipping Phase -->
                                <button
                                    v-if="canMarkKitSent"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    @click="showKitSentModal = true"
                                >
                                    <TruckIcon class="-ml-0.5 size-5" />
                                    Mark Kit Sent
                                </button>
                                <button
                                    v-if="canMarkKitDelivered"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-purple-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-500"
                                    @click="markKitDelivered"
                                >
                                    <InboxIcon class="-ml-0.5 size-5" />
                                    Mark Kit Delivered
                                </button>

                                <!-- Items Phase -->
                                <button
                                    v-if="canMarkItemsReceived"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-cyan-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500"
                                    @click="markItemsReceived"
                                >
                                    <ArchiveBoxIcon class="-ml-0.5 size-5" />
                                    Mark Items Received
                                </button>
                                <button
                                    v-if="canMarkItemsReviewed"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-teal-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-500"
                                    @click="markItemsReviewed"
                                >
                                    <CheckIcon class="-ml-0.5 size-5" />
                                    Mark Items Reviewed
                                </button>

                                <!-- Offer Phase (for online transactions after review) -->
                                <button
                                    v-if="transaction.can_submit_offer"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"
                                    @click="showOfferModal = true"
                                >
                                    <CurrencyDollarIcon class="-ml-0.5 size-5" />
                                    Submit Offer
                                </button>
                                <button
                                    v-if="transaction.can_accept_offer && transaction.pending_offer"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                                    @click="acceptOffer()"
                                >
                                    <CheckIcon class="-ml-0.5 size-5" />
                                    Accept Offer
                                </button>
                                <button
                                    v-if="transaction.can_accept_offer && transaction.pending_offer"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                    @click="openDeclineModal()"
                                >
                                    <XMarkIcon class="-ml-0.5 size-5" />
                                    Decline Offer
                                </button>

                                <!-- Kit Rejection (after items received/reviewed) -->
                                <button
                                    v-if="canRejectKit"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                    @click="showRejectKitModal = true"
                                >
                                    <XMarkIcon class="-ml-0.5 size-5" />
                                    Reject Kit
                                </button>

                                <!-- Initiate Return -->
                                <button
                                    v-if="canInitiateReturn"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500"
                                    @click="initiateReturn"
                                >
                                    <ArrowUturnLeftIcon class="-ml-0.5 size-5" />
                                    Initiate Return
                                </button>

                                <!-- Payment Phase -->
                                <button
                                    v-if="transaction.can_process_payment"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500"
                                    @click="showPaymentModal = true"
                                >
                                    <BanknotesIcon class="-ml-0.5 size-5" />
                                    Process Payment
                                </button>

                                <!-- Return Phase -->
                                <button
                                    v-if="canRequestReturn"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500"
                                    @click="requestReturn"
                                >
                                    <ArrowUturnLeftIcon class="-ml-0.5 size-5" />
                                    Request Return
                                </button>
                                <button
                                    v-if="canMarkReturnShipped"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-orange-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-500"
                                    @click="showReturnShippedModal = true"
                                >
                                    <TruckIcon class="-ml-0.5 size-5" />
                                    Mark Return Shipped
                                </button>
                                <button
                                    v-if="canMarkItemsReturned"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-gray-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500"
                                    @click="markItemsReturned"
                                >
                                    <CheckIcon class="-ml-0.5 size-5" />
                                    Mark Items Returned
                                </button>

                                <!-- Send Payout (PayPal/Venmo) -->
                                <button
                                    v-if="transaction.status === 'payment_processed'"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"
                                    @click="openPayoutModal"
                                >
                                    <CurrencyDollarIcon class="-ml-0.5 size-5" />
                                    Send Payout
                                </button>
                            </div>

                            <!-- Payouts List -->
                            <div v-if="transaction.payouts && transaction.payouts.length > 0" class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Payouts</h4>
                                <ul class="space-y-2">
                                    <li v-for="payout in transaction.payouts" :key="payout.id" class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-700/50">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                                {{ payout.recipient_wallet === 'VENMO' ? 'Venmo' : 'PayPal' }}
                                            </span>
                                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ payout.recipient_value }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                                {{ formatCurrency(payout.amount) }}
                                            </span>
                                            <span :class="[payoutStatusColors[payout.status] || 'bg-gray-100 text-gray-800', 'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset']">
                                                {{ payoutStatusLabels[payout.status] || payout.status }}
                                            </span>
                                            <button
                                                v-if="['pending', 'processing', 'UNCLAIMED', 'ONHOLD'].includes(payout.status)"
                                                type="button"
                                                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                                title="Refresh status"
                                                @click="refreshPayoutStatus(payout.id)"
                                            >
                                                <ArrowPathIcon class="size-4" />
                                            </button>
                                            <a
                                                v-if="payout.tracking_url"
                                                :href="payout.tracking_url"
                                                target="_blank"
                                                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-xs"
                                            >
                                                View
                                            </a>
                                        </div>
                                    </li>
                                </ul>
                            </div>

                            <!-- Rollback Actions -->
                            <div v-if="hasRollbackActions" class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Go Back / Reset</h4>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="(label, actionKey) in transaction.rollback_actions"
                                        :key="actionKey"
                                        type="button"
                                        class="inline-flex items-center gap-x-1.5 rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                                        @click="executeRollbackAction(actionKey)"
                                    >
                                        <ArrowPathIcon class="-ml-0.5 size-4" />
                                        {{ label }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- In-Store Actions (simpler) -->
                    <div v-else class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Actions</h3>
                            <div class="flex flex-wrap gap-3">
                                <button
                                    v-if="transaction.can_submit_offer"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    @click="showOfferModal = true"
                                >
                                    <CurrencyDollarIcon class="-ml-0.5 size-5" />
                                    Submit Offer
                                </button>
                                <button
                                    v-if="transaction.can_accept_offer && transaction.pending_offer"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                                    @click="acceptOffer()"
                                >
                                    <CheckIcon class="-ml-0.5 size-5" />
                                    Accept Offer
                                </button>
                                <button
                                    v-if="transaction.can_accept_offer && transaction.pending_offer"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                    @click="openDeclineModal()"
                                >
                                    <XMarkIcon class="-ml-0.5 size-5" />
                                    Decline Offer
                                </button>
                                <button
                                    v-if="transaction.can_process_payment"
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500"
                                    @click="showPaymentModal = true"
                                >
                                    <BanknotesIcon class="-ml-0.5 size-5" />
                                    Process Payment
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Items Section (for in-store transactions) -->
                    <div v-if="!transaction.is_online" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Items ({{ transaction.items.length }})
                                </h3>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    @click="openAddItemModal"
                                >
                                    <PlusIcon class="-ml-0.5 size-5" />
                                    Add Item
                                </button>
                            </div>

                            <div v-if="transaction.items.length > 0" class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Image</th>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Title</th>
                                            <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">DWT</th>
                                            <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Est. Price</th>
                                            <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Buy Price</th>
                                            <th class="px-3 py-3.5 text-center text-sm font-semibold text-gray-900 dark:text-white">Review</th>
                                            <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="item in transaction.items" :key="item.id">
                                            <!-- Image Column -->
                                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                                <div class="relative w-16 h-16 flex-shrink-0">
                                                    <!-- Clickable Image Thumbnail -->
                                                    <template v-if="item.images && item.images.length > 0">
                                                        <button
                                                            type="button"
                                                            class="w-16 h-16 rounded-md overflow-hidden focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                                            @click="openImageModal(item)"
                                                        >
                                                            <img
                                                                :src="item.images[0]?.thumbnail_url || item.images[0]?.url"
                                                                :alt="item.title || 'Item image'"
                                                                class="w-full h-full object-cover hover:opacity-80 transition-opacity"
                                                            />
                                                        </button>
                                                        <!-- Image count badge -->
                                                        <span
                                                            v-if="item.images.length > 1"
                                                            class="absolute bottom-0.5 right-0.5 bg-black/60 text-white text-[10px] px-1 rounded"
                                                        >
                                                            {{ item.images.length }}
                                                        </span>
                                                    </template>
                                                    <!-- Silhouette Placeholder -->
                                                    <template v-else>
                                                        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-md flex items-center justify-center">
                                                            <PhotoIcon class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                                                        </div>
                                                    </template>
                                                </div>
                                            </td>
                                            <td class="px-3 py-4 text-sm text-gray-900 dark:text-white">
                                                <Link
                                                    :href="`/transactions/${transaction.id}/items/${item.id}`"
                                                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                >
                                                    {{ item.title || 'Untitled Item' }}
                                                </Link>
                                                <p
                                                    v-if="item.description"
                                                    class="mt-1 text-xs text-gray-500 dark:text-gray-400 line-clamp-2"
                                                >
                                                    {{ item.description }}
                                                </p>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300 text-right">
                                                {{ formatWeight(item.dwt) }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-sm text-right">
                                                <div class="relative inline-flex items-center">
                                                    <span class="absolute left-2 text-gray-400 dark:text-gray-500 pointer-events-none">$</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        :value="inlineEditingPrices[item.id]?.price ?? ''"
                                                        @input="(e) => { inlineEditingPrices[item.id].price = parseFloat((e.target as HTMLInputElement).value) || null; saveInlinePrice(item.id, 'price'); }"
                                                        class="w-24 pl-5 pr-2 py-1 text-right text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                                        :class="{ 'opacity-50': inlineUpdating[item.id] }"
                                                    />
                                                    <span v-if="inlineUpdating[item.id]" class="absolute right-1 top-1/2 -translate-y-1/2">
                                                        <ArrowPathIcon class="size-3 animate-spin text-indigo-500" />
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-sm text-right">
                                                <div class="relative inline-flex items-center">
                                                    <span class="absolute left-2 text-gray-400 dark:text-gray-500 pointer-events-none">$</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        :value="inlineEditingPrices[item.id]?.buy_price ?? ''"
                                                        @input="(e) => { inlineEditingPrices[item.id].buy_price = parseFloat((e.target as HTMLInputElement).value) || null; saveInlinePrice(item.id, 'buy_price'); }"
                                                        class="w-24 pl-5 pr-2 py-1 text-right text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white font-medium focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                                        :class="{ 'opacity-50': inlineUpdating[item.id] }"
                                                    />
                                                    <span v-if="inlineUpdating[item.id]" class="absolute right-1 top-1/2 -translate-y-1/2">
                                                        <ArrowPathIcon class="size-3 animate-spin text-indigo-500" />
                                                    </span>
                                                </div>
                                            </td>
                                            <!-- Status Column -->
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-center">
                                                <span
                                                    v-if="item.reviewed_at"
                                                    class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                                    :title="`Reviewed on ${formatReviewDate(item.reviewed_at)}`"
                                                >
                                                    <CheckBadgeIcon class="size-3.5" />
                                                    Reviewed
                                                </span>
                                                <button
                                                    v-else
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-indigo-100 hover:text-indigo-700 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-400 transition-colors"
                                                    title="Mark as reviewed"
                                                    @click="handleReviewItem(item)"
                                                >
                                                    <CheckBadgeIcon class="size-3.5" />
                                                    Review
                                                </button>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-right">
                                                <button
                                                    type="button"
                                                    class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 mr-2"
                                                    title="Edit item"
                                                    @click="openEditItemModal(item)"
                                                >
                                                    <PencilIcon class="size-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="text-red-400 hover:text-red-500"
                                                    title="Delete item"
                                                    @click="handleDeleteItem(item.id)"
                                                >
                                                    <TrashIcon class="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-gray-50 dark:bg-gray-700/50">
                                            <td colspan="2" class="px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white">
                                                Totals
                                            </td>
                                            <td class="px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white text-right">
                                                {{ formatWeight(transaction.total_dwt) }}
                                            </td>
                                            <td class="px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white text-right">
                                                {{ formatCurrency(transaction.total_value) }}
                                            </td>
                                            <td class="px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white text-right">
                                                {{ formatCurrency(transaction.total_buy_price) }}
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">
                                No items added yet. Click "Add Item" to start adding items to this transaction.
                            </p>
                        </div>
                    </div>

                    <!-- Customer Request Details (Online only) -->
                    <div v-if="transaction.is_online && (transaction.customer_description || transaction.customer_amount || transaction.customer_categories)" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Customer's Request</h3>
                            <dl class="space-y-4">
                                <div v-if="transaction.customer_description">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Description</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white whitespace-pre-wrap">
                                        {{ transaction.customer_description }}
                                    </dd>
                                </div>
                                <div v-if="transaction.customer_categories">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Categories</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ transaction.customer_categories }}
                                    </dd>
                                </div>
                                <div v-if="transaction.customer_amount">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Expected Amount</dt>
                                    <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ formatCurrency(transaction.customer_amount) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Items (for online transactions - shown after workflow sections) -->
                    <div v-if="transaction.is_online" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Items ({{ transaction.items.length }})
                                </h3>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="openAddItemModal"
                                >
                                    <PlusIcon class="-ml-0.5 size-4" />
                                    Add Item
                                </button>
                            </div>

                            <div v-if="transaction.items.length > 0" class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Image</th>
                                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Title</th>
                                            <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">DWT</th>
                                            <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Est. Price</th>
                                            <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Buy Price</th>
                                            <th class="px-3 py-3.5 text-center text-sm font-semibold text-gray-900 dark:text-white">Review</th>
                                            <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="item in transaction.items" :key="item.id">
                                            <!-- Image Column -->
                                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                                <div class="relative w-16 h-16 flex-shrink-0">
                                                    <!-- Clickable Image Thumbnail -->
                                                    <template v-if="item.images && item.images.length > 0">
                                                        <button
                                                            type="button"
                                                            class="w-16 h-16 rounded-md overflow-hidden focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                                            @click="openImageModal(item)"
                                                        >
                                                            <img
                                                                :src="item.images[0]?.thumbnail_url || item.images[0]?.url"
                                                                :alt="item.title || 'Item image'"
                                                                class="w-full h-full object-cover hover:opacity-80 transition-opacity"
                                                            />
                                                        </button>
                                                        <!-- Image count badge -->
                                                        <span
                                                            v-if="item.images.length > 1"
                                                            class="absolute bottom-0.5 right-0.5 bg-black/60 text-white text-[10px] px-1 rounded"
                                                        >
                                                            {{ item.images.length }}
                                                        </span>
                                                    </template>
                                                    <!-- Silhouette Placeholder -->
                                                    <template v-else>
                                                        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-md flex items-center justify-center">
                                                            <PhotoIcon class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                                                        </div>
                                                    </template>
                                                </div>
                                            </td>
                                            <td class="px-3 py-4 text-sm text-gray-900 dark:text-white">
                                                <Link
                                                    :href="`/transactions/${transaction.id}/items/${item.id}`"
                                                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                >
                                                    {{ item.title || 'Untitled Item' }}
                                                </Link>
                                                <p
                                                    v-if="item.description"
                                                    class="mt-1 text-xs text-gray-500 dark:text-gray-400 line-clamp-2"
                                                >
                                                    {{ item.description }}
                                                </p>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300 text-right">
                                                {{ formatWeight(item.dwt) }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-sm text-right">
                                                <div class="relative inline-flex items-center">
                                                    <span class="absolute left-2 text-gray-400 dark:text-gray-500 pointer-events-none">$</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        :value="inlineEditingPrices[item.id]?.price ?? ''"
                                                        @input="(e) => { inlineEditingPrices[item.id].price = parseFloat((e.target as HTMLInputElement).value) || null; saveInlinePrice(item.id, 'price'); }"
                                                        class="w-24 pl-5 pr-2 py-1 text-right text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                                        :class="{ 'opacity-50': inlineUpdating[item.id] }"
                                                    />
                                                    <span v-if="inlineUpdating[item.id]" class="absolute right-1 top-1/2 -translate-y-1/2">
                                                        <ArrowPathIcon class="size-3 animate-spin text-indigo-500" />
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-sm text-right">
                                                <div class="relative inline-flex items-center">
                                                    <span class="absolute left-2 text-gray-400 dark:text-gray-500 pointer-events-none">$</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        :value="inlineEditingPrices[item.id]?.buy_price ?? ''"
                                                        @input="(e) => { inlineEditingPrices[item.id].buy_price = parseFloat((e.target as HTMLInputElement).value) || null; saveInlinePrice(item.id, 'buy_price'); }"
                                                        class="w-24 pl-5 pr-2 py-1 text-right text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white font-medium focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                                        :class="{ 'opacity-50': inlineUpdating[item.id] }"
                                                    />
                                                    <span v-if="inlineUpdating[item.id]" class="absolute right-1 top-1/2 -translate-y-1/2">
                                                        <ArrowPathIcon class="size-3 animate-spin text-indigo-500" />
                                                    </span>
                                                </div>
                                            </td>
                                            <!-- Status Column -->
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-center">
                                                <span
                                                    v-if="item.reviewed_at"
                                                    class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                                    :title="`Reviewed on ${formatReviewDate(item.reviewed_at)}`"
                                                >
                                                    <CheckBadgeIcon class="size-3.5" />
                                                    Reviewed
                                                </span>
                                                <button
                                                    v-else
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-indigo-100 hover:text-indigo-700 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-400 transition-colors"
                                                    title="Mark as reviewed"
                                                    @click="handleReviewItem(item)"
                                                >
                                                    <CheckBadgeIcon class="size-3.5" />
                                                    Review
                                                </button>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-right">
                                                <button
                                                    type="button"
                                                    class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 mr-2"
                                                    title="Edit item"
                                                    @click="openEditItemModal(item)"
                                                >
                                                    <PencilIcon class="size-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="text-red-400 hover:text-red-500"
                                                    title="Delete item"
                                                    @click="handleDeleteItem(item.id)"
                                                >
                                                    <TrashIcon class="size-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-gray-50 dark:bg-gray-700/50">
                                            <td colspan="2" class="px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white">
                                                Totals
                                            </td>
                                            <td class="px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white text-right">
                                                {{ formatWeight(transaction.total_dwt) }}
                                            </td>
                                            <td class="px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white text-right">
                                                {{ formatCurrency(transaction.total_value) }}
                                            </td>
                                            <td class="px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white text-right">
                                                {{ formatCurrency(transaction.total_buy_price) }}
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">
                                No items added yet. Click "Add Item" to start adding items to this transaction.
                            </p>
                        </div>
                    </div>

                    <!-- Shipping Labels Section (for online transactions) -->
                    <ShippingLabelsSection
                        v-if="transaction.is_online"
                        :transaction-id="transaction.id"
                        :outbound-label="transaction.outbound_label || null"
                        :return-label="transaction.return_label || null"
                        :can-create-outbound="canCreateOutboundLabel"
                        :can-create-return="canCreateReturnLabel"
                        :shipping-options="shippingOptions"
                        :customer-addresses="customerAddresses || []"
                        :shipping-address="transaction.shipping_address || null"
                        :shipping-address-id="transaction.shipping_address_id || null"
                        :customer="transaction.customer"
                    />

                    <!-- SMS Messaging Section (for transactions with customer) -->
                    <SmsMessagesSection
                        v-if="transaction.customer"
                        :messages="smsMessages || []"
                        :transaction-id="transaction.id"
                        :customer-phone="transaction.customer?.phone_number || null"
                    />

                    <!-- Notes -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Notes</h3>
                            <dl class="space-y-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Customer Notes</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white whitespace-pre-wrap">
                                        {{ transaction.customer_notes || 'No customer notes' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Internal Notes</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white whitespace-pre-wrap">
                                        {{ transaction.internal_notes || 'No internal notes' }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Notes Section -->
                    <NotesSection
                        :notes="transaction.note_entries ?? []"
                        notable-type="transaction"
                        :notable-id="transaction.id"
                    />

                    <!-- Attachments Section (ID photos, documentation) -->
                    <AttachmentsSection
                        :transaction-id="transaction.id"
                        :attachments="attachments ?? []"
                        @updated="router.reload({ only: ['attachments'] })"
                    />

                    <!-- Activity Log -->
                    <ActivityTimeline :activities="activityLogs" />
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Customer -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Customer</h3>
                                <div class="flex items-center gap-2">
                                    <span v-if="savingCustomer" class="text-xs text-gray-500 dark:text-gray-400">Saving...</span>
                                    <button
                                        v-if="selectedCustomer"
                                        type="button"
                                        class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                                        title="Edit customer"
                                        @click="showCustomerEditModal = true"
                                    >
                                        <PencilIcon class="size-4" />
                                    </button>
                                </div>
                            </div>
                            <CustomerSearch
                                v-model="selectedCustomer"
                                placeholder="Search or add customer..."
                            />
                            <!-- Lead Source -->
                            <div v-if="selectedCustomer" class="mt-3 flex items-center gap-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Lead Source:</span>
                                <span
                                    v-if="selectedCustomer.lead_source"
                                    class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30"
                                >
                                    {{ selectedCustomer.lead_source.name }}
                                </span>
                                <span v-else class="text-xs text-gray-400 dark:text-gray-500 italic">
                                    Unknown
                                </span>
                            </div>
                            <div v-if="selectedCustomer" class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                <Link
                                    :href="`/customers/${selectedCustomer.id}`"
                                    class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    View customer details
                                </Link>
                                <button
                                    type="button"
                                    class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                    @click="showCustomerEditModal = true"
                                >
                                    Edit
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Offer & Payment -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Offer & Payment</h3>
                            <dl class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Preliminary Offer</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(transaction.preliminary_offer) }}
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Final Offer</dt>
                                    <dd class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ formatCurrency(transaction.final_offer) }}
                                    </dd>
                                </div>
                                <div v-if="transaction.payment_method" class="pt-2 border-t border-gray-100 dark:border-gray-700">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Payment Method</dt>
                                    <dd class="text-sm text-gray-900 dark:text-white">
                                        <span class="font-medium capitalize">{{ transaction.payment_method.replace('_', ' ') }}</span>

                                        <!-- Check payment details -->
                                        <div v-if="transaction.payment_method === 'check' && transaction.payment_details" class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                            <p v-if="transaction.payment_details.check_name">{{ transaction.payment_details.check_name }}</p>
                                            <p v-if="transaction.payment_details.check_address">{{ transaction.payment_details.check_address }}</p>
                                            <p v-if="transaction.payment_details.check_address_2">{{ transaction.payment_details.check_address_2 }}</p>
                                            <p v-if="transaction.payment_details.check_city || transaction.payment_details.check_state || transaction.payment_details.check_zip">
                                                {{ [transaction.payment_details.check_city, transaction.payment_details.check_state, transaction.payment_details.check_zip].filter(Boolean).join(', ') }}
                                            </p>
                                        </div>

                                        <!-- Bank/ACH/Wire payment details -->
                                        <div v-if="['ach', 'wire_transfer', 'bank_transfer'].includes(transaction.payment_method) && transaction.payment_details" class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                            <p v-if="transaction.payment_details.bank_name" class="font-medium">{{ transaction.payment_details.bank_name }}</p>
                                            <p v-if="transaction.payment_details.account_name">Account: {{ transaction.payment_details.account_name }}</p>
                                            <p v-if="transaction.payment_details.account_type">Type: {{ transaction.payment_details.account_type }}</p>
                                            <p v-if="transaction.payment_details.routing_number">Routing: ****{{ transaction.payment_details.routing_number.slice(-4) }}</p>
                                            <p v-if="transaction.payment_details.account_number">Account: ****{{ transaction.payment_details.account_number.slice(-4) }}</p>
                                            <p v-if="transaction.payment_details.bank_address">{{ transaction.payment_details.bank_address }}</p>
                                            <p v-if="transaction.payment_details.bank_city || transaction.payment_details.bank_state || transaction.payment_details.bank_zip">
                                                {{ [transaction.payment_details.bank_city, transaction.payment_details.bank_state, transaction.payment_details.bank_zip].filter(Boolean).join(', ') }}
                                            </p>
                                        </div>

                                        <!-- PayPal details -->
                                        <div v-if="transaction.payment_method === 'paypal' && transaction.payment_details?.paypal_email" class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                            <p>{{ transaction.payment_details.paypal_email }}</p>
                                        </div>

                                        <!-- Venmo details -->
                                        <div v-if="transaction.payment_method === 'venmo' && transaction.payment_details?.venmo_handle" class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                            <p>{{ transaction.payment_details.venmo_handle }}</p>
                                        </div>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Offer History (Online only) -->
                    <div v-if="transaction.is_online && transaction.offers && transaction.offers.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Offer History</h3>
                            <div class="flow-root">
                                <ul role="list" class="-mb-8">
                                    <li v-for="(offer, index) in transaction.offers" :key="offer.id">
                                        <div class="relative pb-8">
                                            <span
                                                v-if="index !== transaction.offers.length - 1"
                                                class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-700"
                                                aria-hidden="true"
                                            />
                                            <div class="relative flex space-x-3">
                                                <div>
                                                    <span
                                                        :class="[
                                                            offerStatusColors[offer.status] || 'bg-gray-100 dark:bg-gray-700',
                                                            'flex size-8 items-center justify-center rounded-full ring-8 ring-white dark:ring-gray-800',
                                                        ]"
                                                    >
                                                        <CurrencyDollarIcon class="size-4 text-gray-600 dark:text-gray-300" />
                                                    </span>
                                                </div>
                                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                                    <div class="flex-1">
                                                        <div class="flex items-center gap-2">
                                                            <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                                                {{ formatCurrency(offer.amount) }}
                                                            </span>
                                                            <span
                                                                :class="[
                                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                                                    offerStatusColors[offer.status] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                                                ]"
                                                            >
                                                                {{ offer.status }}
                                                            </span>
                                                        </div>
                                                        <p v-if="offer.user" class="text-xs text-gray-500 dark:text-gray-400">
                                                            Created by {{ offer.user.name }}
                                                        </p>
                                                        <p
                                                            v-if="offer.status === 'accepted' && offer.responder_name"
                                                            class="text-xs text-green-600 dark:text-green-400"
                                                        >
                                                            Accepted by {{ offer.responder_name }}
                                                            <span v-if="offer.was_responded_by_customer" class="text-gray-500 dark:text-gray-400">(Customer)</span>
                                                            <span v-else class="text-gray-500 dark:text-gray-400">(Admin)</span>
                                                        </p>
                                                        <p
                                                            v-if="offer.status === 'declined' && offer.responder_name"
                                                            class="text-xs text-red-600 dark:text-red-400"
                                                        >
                                                            Declined by {{ offer.responder_name }}
                                                            <span v-if="offer.was_responded_by_customer" class="text-gray-500 dark:text-gray-400">(Customer)</span>
                                                            <span v-else class="text-gray-500 dark:text-gray-400">(Admin)</span>
                                                        </p>
                                                        <p v-if="offer.admin_notes" class="mt-1 text-xs text-gray-500 dark:text-gray-400 italic">
                                                            Note: {{ offer.admin_notes }}
                                                        </p>
                                                        <p v-if="offer.customer_response" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                                            Response: {{ offer.customer_response }}
                                                        </p>
                                                        <!-- Actions for pending offers -->
                                                        <div v-if="offer.status === 'pending'" class="mt-2 flex gap-2">
                                                            <button
                                                                type="button"
                                                                class="inline-flex items-center gap-x-1 rounded-md bg-green-600 px-2 py-1 text-xs font-medium text-white shadow-sm hover:bg-green-500"
                                                                @click="acceptOffer(offer.id)"
                                                            >
                                                                <CheckIcon class="size-3" />
                                                                Accept
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="inline-flex items-center gap-x-1 rounded-md bg-red-600 px-2 py-1 text-xs font-medium text-white shadow-sm hover:bg-red-500"
                                                                @click="openDeclineModal(offer.id)"
                                                            >
                                                                <XMarkIcon class="size-3" />
                                                                Decline
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div class="whitespace-nowrap text-right text-xs text-gray-500 dark:text-gray-400">
                                                        {{ formatShortDate(offer.created_at) }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Status History -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Status History</h3>
                            <div v-if="transaction.status_history && transaction.status_history.length > 0" class="flow-root">
                                <ul role="list" class="-mb-8">
                                    <li v-for="(entry, index) in transaction.status_history" :key="entry.id">
                                        <div class="relative pb-8">
                                            <span
                                                v-if="index !== transaction.status_history.length - 1"
                                                class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-700"
                                                aria-hidden="true"
                                            />
                                            <div class="relative flex space-x-3">
                                                <div>
                                                    <span
                                                        :class="[
                                                            statusColors[entry.to_status] || 'bg-gray-100 dark:bg-gray-700',
                                                            'flex size-8 items-center justify-center rounded-full ring-8 ring-white dark:ring-gray-800',
                                                        ]"
                                                    >
                                                        <ClockIcon class="size-4 text-gray-600 dark:text-gray-300" />
                                                    </span>
                                                </div>
                                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                                    <div>
                                                        <p class="text-sm text-gray-900 dark:text-white">
                                                            <span class="font-medium">{{ entry.to_status_label }}</span>
                                                            <span v-if="entry.from_status_label" class="text-gray-500 dark:text-gray-400">
                                                                from {{ entry.from_status_label }}
                                                            </span>
                                                        </p>
                                                        <p v-if="entry.user" class="text-xs text-gray-500 dark:text-gray-400">
                                                            by {{ entry.user.name }}
                                                        </p>
                                                        <p v-if="entry.notes" class="mt-1 text-xs text-gray-500 dark:text-gray-400 italic">
                                                            {{ entry.notes }}
                                                        </p>
                                                    </div>
                                                    <div class="whitespace-nowrap text-right text-xs text-gray-500 dark:text-gray-400">
                                                        {{ formatShortDate(entry.created_at) }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                            <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">
                                No status history available.
                            </p>
                        </div>
                    </div>

                    <!-- Additional Info -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Additional Info</h3>
                            <dl class="space-y-3">
                                <div v-if="transaction.bin_location" class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Bin Location</dt>
                                    <dd class="text-sm font-mono text-gray-900 dark:text-white">
                                        {{ transaction.bin_location }}
                                    </dd>
                                </div>
                                <div v-if="transaction.user" class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Created By</dt>
                                    <dd class="text-sm text-gray-900 dark:text-white">
                                        {{ transaction.user.name }}
                                    </dd>
                                </div>
                                <div v-if="transaction.assigned_user" class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Assigned To</dt>
                                    <dd class="text-sm text-gray-900 dark:text-white">
                                        {{ transaction.assigned_user.name }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Submit Offer Modal -->
        <Teleport to="body">
            <div v-if="showOfferModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showOfferModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            {{ transaction.status === 'offer_declined' ? 'Send Counter-Offer' : 'Submit Offer' }}
                        </h3>
                        <form @submit.prevent="submitOffer">
                            <div class="mb-4">
                                <label for="offer" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Offer Amount</label>
                                <div class="relative mt-1 rounded-md shadow-sm">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                        <span class="text-gray-500 sm:text-sm">$</span>
                                    </div>
                                    <input
                                        id="offer"
                                        v-model="offerForm.offer"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="block w-full rounded-md border-0 py-1.5 pl-7 pr-12 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        placeholder="0.00"
                                        required
                                    />
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="offer_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (optional)</label>
                                <textarea
                                    id="offer_notes"
                                    v-model="offerForm.notes"
                                    rows="2"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Internal notes about this offer..."
                                />
                            </div>
                            <div v-if="transaction.is_online && transaction.customer" class="mb-4">
                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        v-model="offerForm.send_notification"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                    />
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Send notification to customer (email/SMS)</span>
                                </label>
                            </div>
                            <div class="flex gap-3 justify-end">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showOfferModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="offerForm.processing"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {{ transaction.status === 'offer_declined' ? 'Send Counter-Offer' : 'Submit Offer' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Process Payment Modal -->
        <Teleport to="body">
            <div v-if="showPaymentModal" class="fixed inset-0 z-50 overflow-y-auto" @vue:mounted="initializePayments">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showPaymentModal = false" />
                    <div class="relative w-full max-w-2xl max-h-[90vh] transform overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-800">
                        <div class="px-4 pt-5 sm:px-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Process Payment</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Add one or more payments. The total must equal the offer amount.</p>
                        </div>

                        <div class="overflow-y-auto max-h-[calc(90vh-200px)] px-4 py-4 sm:px-6">
                            <!-- Balance Summary -->
                            <div class="mb-4 rounded-lg bg-indigo-50 p-3 dark:bg-indigo-900/20">
                                <div class="grid grid-cols-3 gap-4 text-center">
                                    <div>
                                        <span class="block text-xs font-medium text-indigo-700 dark:text-indigo-300">Offer Amount</span>
                                        <span class="text-lg font-bold text-indigo-900 dark:text-indigo-100">{{ formatCurrency(transaction.final_offer) }}</span>
                                    </div>
                                    <div>
                                        <span class="block text-xs font-medium text-indigo-700 dark:text-indigo-300">Total Payments</span>
                                        <span class="text-lg font-bold text-indigo-900 dark:text-indigo-100">{{ formatCurrency(totalPaymentsAmount) }}</span>
                                    </div>
                                    <div>
                                        <span class="block text-xs font-medium" :class="paymentsBalanced ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300'">
                                            {{ paymentRemainingBalance >= 0 ? 'Remaining' : 'Over' }}
                                        </span>
                                        <span class="text-lg font-bold" :class="paymentsBalanced ? 'text-green-900 dark:text-green-100' : 'text-red-900 dark:text-red-100'">
                                            {{ formatCurrency(Math.abs(paymentRemainingBalance)) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Payments List -->
                            <div class="space-y-4">
                                <div
                                    v-for="(payment, index) in payments"
                                    :key="payment.id"
                                    class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                                >
                                    <div class="mb-3 flex items-center justify-between">
                                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Payment {{ index + 1 }}</h4>
                                        <button
                                            v-if="payments.length > 1"
                                            type="button"
                                            @click="removePayment(payment.id)"
                                            class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700"
                                        >
                                            <TrashIcon class="size-4" />
                                        </button>
                                    </div>

                                    <!-- Amount & Method Row -->
                                    <div class="grid grid-cols-2 gap-4 mb-3">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                                            <div class="relative mt-1">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                    <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
                                                </div>
                                                <input
                                                    type="number"
                                                    v-model.number="payment.amount"
                                                    step="0.01"
                                                    min="0"
                                                    class="block w-full rounded-md border-0 py-2 pl-7 pr-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="0.00"
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Method</label>
                                            <select
                                                :value="payment.method"
                                                @change="updatePaymentMethod(payment.id, ($event.target as HTMLSelectElement).value)"
                                                class="mt-1 block w-full rounded-md border-0 py-2 px-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option value="">Select method</option>
                                                <option v-for="method in paymentMethods" :key="method.value" :value="method.value">
                                                    {{ method.label }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Method-specific details -->
                                    <div v-if="payment.method" class="border-t border-gray-200 pt-3 dark:border-gray-700">
                                        <!-- PayPal Details -->
                                        <div v-if="payment.method === 'paypal'">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">PayPal Email</label>
                                            <input
                                                type="email"
                                                :value="payment.details.paypal_email || ''"
                                                @input="updatePaymentDetails(payment.id, { paypal_email: ($event.target as HTMLInputElement).value })"
                                                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="customer@email.com"
                                            />
                                        </div>

                                        <!-- Venmo Details -->
                                        <div v-if="payment.method === 'venmo'">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Venmo Username</label>
                                            <div class="relative mt-1">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                    <span class="text-gray-500 dark:text-gray-400 sm:text-sm">@</span>
                                                </div>
                                                <input
                                                    type="text"
                                                    :value="payment.details.venmo_handle || ''"
                                                    @input="updatePaymentDetails(payment.id, { venmo_handle: ($event.target as HTMLInputElement).value })"
                                                    class="block w-full rounded-md border-0 py-2 pl-7 pr-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="username"
                                                />
                                            </div>
                                        </div>

                                        <!-- Check Mailing Address -->
                                        <div v-if="payment.method === 'check'" class="space-y-3">
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Mailing Address</p>
                                            <input
                                                type="text"
                                                :value="payment.details.mailing_name || ''"
                                                @input="updatePaymentDetails(payment.id, { mailing_name: ($event.target as HTMLInputElement).value })"
                                                class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                :placeholder="transaction.customer?.full_name || 'Recipient Name'"
                                            />
                                            <input
                                                type="text"
                                                :value="payment.details.mailing_address || ''"
                                                @input="updatePaymentDetails(payment.id, { mailing_address: ($event.target as HTMLInputElement).value })"
                                                class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="Street Address"
                                            />
                                            <div class="grid grid-cols-6 gap-3">
                                                <input
                                                    type="text"
                                                    :value="payment.details.mailing_city || ''"
                                                    @input="updatePaymentDetails(payment.id, { mailing_city: ($event.target as HTMLInputElement).value })"
                                                    class="col-span-3 block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="City"
                                                />
                                                <input
                                                    type="text"
                                                    :value="payment.details.mailing_state || ''"
                                                    @input="updatePaymentDetails(payment.id, { mailing_state: ($event.target as HTMLInputElement).value })"
                                                    class="col-span-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="ST"
                                                    maxlength="2"
                                                />
                                                <input
                                                    type="text"
                                                    :value="payment.details.mailing_zip || ''"
                                                    @input="updatePaymentDetails(payment.id, { mailing_zip: ($event.target as HTMLInputElement).value })"
                                                    class="col-span-2 block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="ZIP"
                                                />
                                            </div>
                                        </div>

                                        <!-- Bank Details (ACH / Wire Transfer) -->
                                        <div v-if="['ach', 'wire_transfer'].includes(payment.method)" class="space-y-3">
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Bank Information</p>
                                            <input
                                                type="text"
                                                :value="payment.details.bank_name || ''"
                                                @input="updatePaymentDetails(payment.id, { bank_name: ($event.target as HTMLInputElement).value })"
                                                class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="Bank Name"
                                            />
                                            <input
                                                type="text"
                                                :value="payment.details.account_name || ''"
                                                @input="updatePaymentDetails(payment.id, { account_name: ($event.target as HTMLInputElement).value })"
                                                class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="Account Holder Name"
                                            />
                                            <div class="grid grid-cols-2 gap-3">
                                                <input
                                                    type="text"
                                                    :value="payment.details.routing_number || ''"
                                                    @input="updatePaymentDetails(payment.id, { routing_number: ($event.target as HTMLInputElement).value })"
                                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="Routing Number"
                                                    maxlength="9"
                                                />
                                                <input
                                                    type="text"
                                                    :value="payment.details.account_number || ''"
                                                    @input="updatePaymentDetails(payment.id, { account_number: ($event.target as HTMLInputElement).value })"
                                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="Account Number"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Add Payment Button -->
                            <button
                                type="button"
                                @click="addPayment"
                                class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:border-gray-400 hover:text-gray-700 dark:border-gray-600 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-gray-300"
                            >
                                <PlusIcon class="size-4" />
                                Add Another Payment Method
                            </button>
                        </div>

                        <!-- Footer -->
                        <div class="border-t border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
                            <div class="flex gap-3 justify-end">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showPaymentModal = false; payments = []"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="paymentProcessing || !paymentsBalanced || payments.some(p => !p.method)"
                                    class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    @click="processPayment"
                                >
                                    {{ paymentProcessing ? 'Processing...' : 'Process Payment' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Send Payout Modal -->
        <Teleport to="body">
            <div v-if="showPayoutModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showPayoutModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Send Payout</h3>
                        <form @submit.prevent="sendPayout">
                            <div class="space-y-4">
                                <!-- Wallet Type -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Payment Method</label>
                                    <div class="flex gap-3">
                                        <label class="flex items-center">
                                            <input
                                                type="radio"
                                                v-model="payoutForm.wallet"
                                                value="PAYPAL"
                                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 dark:bg-gray-700"
                                            />
                                            <span class="ml-2 text-sm text-gray-900 dark:text-white">PayPal</span>
                                        </label>
                                        <label class="flex items-center">
                                            <input
                                                type="radio"
                                                v-model="payoutForm.wallet"
                                                value="VENMO"
                                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 dark:bg-gray-700"
                                            />
                                            <span class="ml-2 text-sm text-gray-900 dark:text-white">Venmo</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Recipient -->
                                <div>
                                    <label for="recipient_value" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ payoutForm.wallet === 'VENMO' ? 'Email or Phone' : 'PayPal Email' }}
                                    </label>
                                    <input
                                        id="recipient_value"
                                        v-model="payoutForm.recipient_value"
                                        type="text"
                                        required
                                        :placeholder="payoutForm.wallet === 'VENMO' ? 'email@example.com or +1234567890' : 'email@example.com'"
                                        class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                    <p v-if="payoutForm.errors.recipient_value" class="mt-1 text-sm text-red-600">{{ payoutForm.errors.recipient_value }}</p>
                                </div>

                                <!-- Amount -->
                                <div>
                                    <label for="payout_amount" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                                    <div class="relative mt-1">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                        <input
                                            id="payout_amount"
                                            v-model.number="payoutForm.amount"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            required
                                            class="block w-full rounded-md border-0 py-1.5 pl-7 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                    <p v-if="payoutForm.errors.amount" class="mt-1 text-sm text-red-600">{{ payoutForm.errors.amount }}</p>
                                </div>

                                <!-- Note -->
                                <div>
                                    <label for="payout_note" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Note (optional)</label>
                                    <textarea
                                        id="payout_note"
                                        v-model="payoutForm.note"
                                        rows="2"
                                        placeholder="Payment for your items..."
                                        class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>

                            <div class="mt-6 flex gap-3 justify-end">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showPayoutModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="payoutProcessing || !payoutForm.recipient_value || !payoutForm.amount"
                                    class="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {{ payoutProcessing ? 'Sending...' : 'Send Payout' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Decline Offer Modal -->
        <Teleport to="body">
            <div v-if="showDeclineModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showDeclineModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Decline Offer</h3>
                        <form @submit.prevent="declineOffer">
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                Declining offer of <span class="font-semibold text-gray-900 dark:text-white">{{ formatCurrency(transaction.pending_offer?.amount || transaction.final_offer) }}</span>
                            </p>
                            <div class="mb-4">
                                <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Customer Response (optional)</label>
                                <textarea
                                    id="reason"
                                    v-model="declineForm.reason"
                                    rows="3"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter customer's reason for declining..."
                                />
                            </div>
                            <div class="flex gap-3 justify-end">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showDeclineModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="declineForm.processing || !declineForm.offer_id"
                                    class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
                                >
                                    Decline Offer
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Reject Kit Modal -->
        <Teleport to="body">
            <div v-if="showRejectKitModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showRejectKitModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Reject Kit</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Rejecting this kit will require returning the items to the customer. A return shipping label will need to be created.
                        </p>
                        <form @submit.prevent="rejectKit">
                            <div class="mb-4">
                                <label for="reject_reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason (optional)</label>
                                <textarea
                                    id="reject_reason"
                                    v-model="rejectKitForm.reason"
                                    rows="3"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter reason for rejection..."
                                />
                            </div>
                            <div class="flex gap-3 justify-end">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showRejectKitModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="rejectKitForm.processing"
                                    class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
                                >
                                    Reject Kit
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Mark Kit Sent Modal -->
        <Teleport to="body">
            <div v-if="showKitSentModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showKitSentModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Mark Kit as Sent</h3>
                        <form @submit.prevent="markKitSent">
                            <div class="space-y-4">
                                <div>
                                    <label for="kit_tracking" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tracking Number</label>
                                    <input
                                        id="kit_tracking"
                                        v-model="kitSentForm.tracking_number"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        placeholder="Enter tracking number"
                                        required
                                    />
                                </div>
                                <div>
                                    <label for="kit_carrier" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Carrier</label>
                                    <select
                                        id="kit_carrier"
                                        v-model="kitSentForm.carrier"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option value="fedex">FedEx</option>
                                        <option value="ups">UPS</option>
                                        <option value="usps">USPS</option>
                                        <option value="dhl">DHL</option>
                                    </select>
                                </div>
                            </div>
                            <div class="flex gap-3 justify-end mt-6">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showKitSentModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="kitSentForm.processing"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    Mark as Sent
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Mark Return Shipped Modal -->
        <Teleport to="body">
            <div v-if="showReturnShippedModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showReturnShippedModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Mark Return as Shipped</h3>
                        <form @submit.prevent="markReturnShipped">
                            <div class="space-y-4">
                                <div>
                                    <label for="return_tracking" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tracking Number</label>
                                    <input
                                        id="return_tracking"
                                        v-model="returnShippedForm.tracking_number"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        placeholder="Enter tracking number"
                                        required
                                    />
                                </div>
                                <div>
                                    <label for="return_carrier" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Carrier</label>
                                    <select
                                        id="return_carrier"
                                        v-model="returnShippedForm.carrier"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option value="fedex">FedEx</option>
                                        <option value="ups">UPS</option>
                                        <option value="usps">USPS</option>
                                        <option value="dhl">DHL</option>
                                    </select>
                                </div>
                            </div>
                            <div class="flex gap-3 justify-end mt-6">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showReturnShippedModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="returnShippedForm.processing"
                                    class="rounded-md bg-orange-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-500 disabled:opacity-50"
                                >
                                    Mark as Shipped
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Assign Transaction Modal -->
        <Teleport to="body">
            <div v-if="showAssignModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showAssignModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Assign Transaction</h3>
                        <form @submit.prevent="assignTransaction">
                            <div class="mb-4">
                                <label for="assigned_to" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Assign To</label>
                                <select
                                    id="assigned_to"
                                    v-model="assignForm.assigned_to"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option value="">Unassigned</option>
                                    <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                                        {{ member.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="flex gap-3 justify-end">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showAssignModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="assignForm.processing"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    Assign
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Shipping Label Modal -->
        <Teleport to="body">
            <div v-if="showShippingLabelModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showShippingLabelModal = false" />
                    <div class="relative w-full max-w-lg transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            Create {{ shippingLabelType === 'outbound' ? 'Outbound' : 'Return' }} Shipping Label
                        </h3>
                        <form @submit.prevent="submitShippingLabel">
                            <div class="space-y-4">
                                <!-- Service Type -->
                                <div>
                                    <label for="service_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Service Type
                                    </label>
                                    <select
                                        id="service_type"
                                        v-model="shippingLabelForm.service_type"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option
                                            v-for="(label, value) in shippingOptions?.service_types || {}"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ label }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Packaging Type -->
                                <div>
                                    <label for="packaging_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Packaging Type
                                    </label>
                                    <select
                                        id="packaging_type"
                                        v-model="shippingLabelForm.packaging_type"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option
                                            v-for="(label, value) in shippingOptions?.packaging_types || {}"
                                            :key="value"
                                            :value="value"
                                        >
                                            {{ label }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Package Dimensions -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Package Dimensions
                                    </label>
                                    <div class="grid grid-cols-4 gap-3">
                                        <div>
                                            <label for="weight" class="block text-xs text-gray-500 dark:text-gray-400">Weight (lbs)</label>
                                            <input
                                                id="weight"
                                                v-model.number="shippingLabelForm.weight"
                                                type="number"
                                                step="0.1"
                                                min="0.1"
                                                max="150"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label for="length" class="block text-xs text-gray-500 dark:text-gray-400">Length (in)</label>
                                            <input
                                                id="length"
                                                v-model.number="shippingLabelForm.length"
                                                type="number"
                                                step="1"
                                                min="1"
                                                max="108"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label for="width" class="block text-xs text-gray-500 dark:text-gray-400">Width (in)</label>
                                            <input
                                                id="width"
                                                v-model.number="shippingLabelForm.width"
                                                type="number"
                                                step="1"
                                                min="1"
                                                max="108"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label for="height" class="block text-xs text-gray-500 dark:text-gray-400">Height (in)</label>
                                            <input
                                                id="height"
                                                v-model.number="shippingLabelForm.height"
                                                type="number"
                                                step="1"
                                                min="1"
                                                max="108"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <!-- Error display -->
                                <div v-if="shippingLabelForm.errors && Object.keys(shippingLabelForm.errors).length > 0" class="rounded-md bg-red-50 p-3 dark:bg-red-500/10">
                                    <div class="text-sm text-red-700 dark:text-red-400">
                                        <ul class="list-disc list-inside space-y-1">
                                            <li v-for="(error, key) in shippingLabelForm.errors" :key="key">{{ error }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-3 justify-end mt-6">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showShippingLabelModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="shippingLabelForm.processing || creatingOutboundLabel || creatingReturnLabel"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <span v-if="shippingLabelForm.processing || creatingOutboundLabel || creatingReturnLabel">
                                        Creating...
                                    </span>
                                    <span v-else>
                                        Create Label
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Add/Edit Item Modal -->
        <AddItemModal
            :open="showAddItemModal"
            :categories="categories ?? []"
            :editing-item="editingItem"
            :existing-images="editingItemImages"
            @close="showAddItemModal = false"
            @save="handleSaveItem"
        />

        <!-- Customer Edit Modal -->
        <CustomerEditModal
            v-if="selectedCustomer"
            :show="showCustomerEditModal"
            :customer="selectedCustomer"
            :selected-address-id="transaction.shipping_address_id"
            entity-type="transaction"
            :entity-id="transaction.id"
            @close="showCustomerEditModal = false"
            @saved="showCustomerEditModal = false"
        />

        <!-- Image Lightbox Modal -->
        <TransitionRoot as="template" :show="showImageModal">
            <Dialog as="div" class="relative z-50" @close="closeImageModal">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-300"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-in duration-200"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black/80 transition-opacity" />
                </TransitionChild>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4">
                        <TransitionChild
                            as="template"
                            enter="ease-out duration-300"
                            enter-from="opacity-0 scale-95"
                            enter-to="opacity-100 scale-100"
                            leave="ease-in duration-200"
                            leave-from="opacity-100 scale-100"
                            leave-to="opacity-0 scale-95"
                        >
                            <DialogPanel class="relative max-w-4xl w-full">
                                <!-- Close button -->
                                <button
                                    type="button"
                                    class="absolute -top-12 right-0 text-white hover:text-gray-300 focus:outline-none"
                                    @click="closeImageModal"
                                >
                                    <XMarkIcon class="h-8 w-8" />
                                </button>

                                <!-- Image title -->
                                <DialogTitle class="text-white text-center mb-4 text-lg font-medium">
                                    {{ modalItemTitle }}
                                </DialogTitle>

                                <!-- Main image -->
                                <div class="relative">
                                    <img
                                        v-if="modalImages[modalImageIndex]"
                                        :src="modalImages[modalImageIndex].url"
                                        :alt="modalItemTitle"
                                        class="w-full max-h-[70vh] object-contain rounded-lg"
                                    />

                                    <!-- Navigation arrows -->
                                    <template v-if="modalImages.length > 1">
                                        <button
                                            type="button"
                                            class="absolute left-2 top-1/2 -translate-y-1/2 bg-black/50 hover:bg-black/70 text-white rounded-full p-2 focus:outline-none"
                                            @click="prevModalImage"
                                        >
                                            <ChevronLeftIcon class="w-6 h-6" />
                                        </button>
                                        <button
                                            type="button"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 bg-black/50 hover:bg-black/70 text-white rounded-full p-2 focus:outline-none"
                                            @click="nextModalImage"
                                        >
                                            <ChevronRightIcon class="w-6 h-6" />
                                        </button>
                                    </template>
                                </div>

                                <!-- Image counter and thumbnails -->
                                <div v-if="modalImages.length > 1" class="mt-4">
                                    <p class="text-white text-center text-sm mb-3">
                                        {{ modalImageIndex + 1 }} / {{ modalImages.length }}
                                    </p>
                                    <div class="flex justify-center gap-2 flex-wrap">
                                        <button
                                            v-for="(img, idx) in modalImages"
                                            :key="img.id"
                                            type="button"
                                            class="w-16 h-16 rounded-md overflow-hidden focus:outline-none transition-all"
                                            :class="idx === modalImageIndex ? 'ring-2 ring-white ring-offset-2 ring-offset-black' : 'opacity-60 hover:opacity-100'"
                                            @click="modalImageIndex = idx"
                                        >
                                            <img
                                                :src="img.thumbnail_url || img.url"
                                                :alt="`Thumbnail ${idx + 1}`"
                                                class="w-full h-full object-cover"
                                            />
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
