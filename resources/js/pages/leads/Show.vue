<script setup lang="ts">
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { NotesSection } from '@/components/notes';
import AttachmentsSection from '@/components/transactions/AttachmentsSection.vue';
import ShippingLabelsSection from '@/components/transactions/ShippingLabelsSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    ArrowLeftIcon,
    PlusIcon,
    CurrencyDollarIcon,
    CheckIcon,
    XMarkIcon,
    ChevronDownIcon,
    TruckIcon,
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
    PhotoIcon,
    CheckBadgeIcon,
} from '@heroicons/vue/24/outline';
import { CustomerCard, CustomerSearch } from '@/components/customers';

interface ItemImage {
    id: number;
    url: string;
    thumbnail_url: string | null;
}

interface LeadItem {
    id: number;
    title: string;
    description: string | null;
    sku: string | null;
    category: { id: number; name: string } | null;
    quantity: number;
    price: number | null;
    buy_price: number | null;
    dwt: number | null;
    precious_metal: string | null;
    condition: string | null;
    attributes: Record<string, any>;
    is_reviewed: boolean;
    reviewed_by: string | null;
    images: ItemImage[];
}

interface Customer {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone: string | null;
    addresses?: Array<{ id: number; one_line_address: string; is_default?: boolean }>;
}

interface User {
    id: number;
    name: string;
}

interface StatusHistoryItem {
    id: number;
    from_status: string | null;
    to_status: string;
    notes: string | null;
    user: User | null;
    created_at: string;
}

interface NoteEntry {
    id: number;
    content: string;
    type: string | null;
    user: User | null;
    created_at: string;
}

interface ShippingLabel {
    id: number;
    tracking_number: string | null;
    carrier: string;
    status: string;
}

interface Attachment {
    id: number;
    url: string;
    thumbnail_url: string | null;
    alt_text: string | null;
    file_name: string | null;
    file_type: string | null;
}

interface Lead {
    id: number;
    lead_number: string;
    status: string;
    status_label: string;
    status_color: string;
    type: string;
    source: string | null;
    preliminary_offer: number | null;
    final_offer: number | null;
    estimated_value: number | null;
    payment_method: string | null;
    payment_details: Record<string, any> | null;
    bin_location: string | null;
    customer_notes: string | null;
    internal_notes: string | null;
    customer_description: string | null;
    customer_amount: number | null;
    customer_categories: string | null;
    outbound_tracking_number: string | null;
    outbound_carrier: string | null;
    return_tracking_number: string | null;
    return_carrier: string | null;
    total_value: number;
    total_buy_price: number;
    total_dwt: number;
    item_count: number;
    is_converted: boolean;
    transaction_id: number | null;
    transaction_number: string | null;
    offer_given_at: string | null;
    offer_accepted_at: string | null;
    payment_processed_at: string | null;
    kit_sent_at: string | null;
    kit_delivered_at: string | null;
    items_received_at: string | null;
    items_reviewed_at: string | null;
    created_at: string;
    updated_at: string;
    customer: Customer | null;
    shipping_address: { id: number; full_name: string | null; one_line_address: string } | null;
    user: User | null;
    assigned_user: User | null;
    outbound_label: ShippingLabel | null;
    return_label: ShippingLabel | null;
    items: LeadItem[];
    status_histories: StatusHistoryItem[];
    notes: NoteEntry[];
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

interface TeamMember {
    id: number;
    name: string;
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

interface Props {
    lead: Lead;
    attachments?: Attachment[];
    statuses: Record<string, string>;
    paymentMethods: SelectOption[];
    teamMembers?: TeamMember[];
    shippingOptions?: ShippingOptions;
    customerAddresses?: CustomerAddress[];
    categories?: Array<{ id: number; name: string; full_path: string }>;
    preciousMetals?: SelectOption[];
    conditions?: SelectOption[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Leads', href: '/leads' },
    { title: props.lead.lead_number, href: `/leads/${props.lead.id}` },
];

// --- Helpers ---
function formatCurrency(value: number | null | undefined): string {
    if (value === null || value === undefined) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
}

function formatDate(isoString: string | null): string {
    if (!isoString) return '-';
    return new Date(isoString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function formatDateTime(isoString: string | null): string {
    if (!isoString) return '-';
    return new Date(isoString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatStatusLabel(slug: string): string {
    return slug
        .split('_')
        .map(w => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

// --- Modals ---
const showOfferModal = ref(false);
const showPaymentModal = ref(false);
const showDeclineModal = ref(false);
const showKitSentModal = ref(false);
const showAssignModal = ref(false);

// --- Forms ---
const offerForm = useForm({
    offer: props.lead.final_offer || '',
    notes: '',
});

const paymentForm = useForm({
    payment_method: '',
    payment_details: {} as Record<string, any>,
});

const declineForm = useForm({
    reason: '',
});

const kitSentForm = useForm({
    tracking_number: '',
    carrier: 'fedex',
});

const assignForm = useForm({
    assigned_to: props.lead.assigned_user?.id || '',
});

// --- Computed ---
const canSubmitOffer = computed(() => props.lead.status === 'items_reviewed');
const canAcceptOffer = computed(() => props.lead.status === 'offer_given');
const canDeclineOffer = computed(() => props.lead.status === 'offer_given');
const canProcessPayment = computed(() => props.lead.status === 'offer_accepted');
const canConfirmKit = computed(() => props.lead.status === 'pending_kit_request');
const canRejectKit = computed(() => props.lead.status === 'pending_kit_request');
const canHoldKit = computed(() => props.lead.status === 'pending_kit_request');
const canMarkKitSent = computed(() => props.lead.status === 'kit_request_confirmed');
const canMarkKitDelivered = computed(() => props.lead.status === 'kit_sent');
const canMarkItemsReceived = computed(() => props.lead.status === 'kit_delivered');
const canMarkItemsReviewed = computed(() => props.lead.status === 'items_received');
const canMarkItemsReturned = computed(() => ['customer_declined_offer', 'kit_request_rejected'].includes(props.lead.status));

const hasKitTracking = computed(() => props.lead.outbound_tracking_number || props.lead.return_tracking_number);

// --- Actions ---
function submitOffer() {
    offerForm.post(`/leads/${props.lead.id}/submit-offer`, {
        preserveScroll: true,
        onSuccess: () => {
            showOfferModal.value = false;
            offerForm.reset();
        },
    });
}

function acceptOffer() {
    router.post(`/leads/${props.lead.id}/accept-offer`, {}, { preserveScroll: true });
}

function declineOffer() {
    declineForm.post(`/leads/${props.lead.id}/decline-offer`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeclineModal.value = false;
            declineForm.reset();
        },
    });
}

function processPayment() {
    paymentForm.post(`/leads/${props.lead.id}/process-payment`, {
        preserveScroll: true,
        onSuccess: () => {
            showPaymentModal.value = false;
            paymentForm.reset();
        },
    });
}

function confirmKitRequest() {
    router.post(`/leads/${props.lead.id}/confirm-kit-request`, {}, { preserveScroll: true });
}

function rejectKitRequest() {
    router.post(`/leads/${props.lead.id}/reject-kit-request`, {}, { preserveScroll: true });
}

function holdKitRequest() {
    router.post(`/leads/${props.lead.id}/hold-kit-request`, {}, { preserveScroll: true });
}

function markKitSent() {
    kitSentForm.post(`/leads/${props.lead.id}/mark-kit-sent`, {
        preserveScroll: true,
        onSuccess: () => {
            showKitSentModal.value = false;
            kitSentForm.reset();
        },
    });
}

function markKitDelivered() {
    router.post(`/leads/${props.lead.id}/mark-kit-delivered`, {}, { preserveScroll: true });
}

function markItemsReceived() {
    router.post(`/leads/${props.lead.id}/mark-items-received`, {}, { preserveScroll: true });
}

function markItemsReviewed() {
    router.post(`/leads/${props.lead.id}/mark-items-reviewed`, {}, { preserveScroll: true });
}

function markItemsReturned() {
    router.post(`/leads/${props.lead.id}/mark-items-returned`, {}, { preserveScroll: true });
}

function assignLead() {
    assignForm.post(`/leads/${props.lead.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => {
            showAssignModal.value = false;
        },
    });
}

function updateCustomer(customerId: number | null) {
    router.patch(`/leads/${props.lead.id}`, { customer_id: customerId }, { preserveScroll: true });
}

// Status change via generic dropdown
function changeStatus(statusSlug: string) {
    router.post(`/leads/${props.lead.id}/change-status`, { status: statusSlug }, { preserveScroll: true });
}

// Inline editing
const editingField = ref<string | null>(null);
const editFieldValue = ref('');

function startEdit(field: string, currentValue: string | null) {
    editingField.value = field;
    editFieldValue.value = currentValue || '';
}

function saveEdit(field: string) {
    router.patch(
        `/leads/${props.lead.id}`,
        { [field]: editFieldValue.value || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingField.value = null;
            },
        },
    );
}

function cancelEdit() {
    editingField.value = null;
}
</script>

<template>
    <Head :title="`Lead ${lead.lead_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <Link
                            href="/leads"
                            class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            <ArrowLeftIcon class="size-4" />
                            Leads
                        </Link>
                    </div>
                    <div class="mt-2 flex items-center gap-3">
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                            {{ lead.lead_number }}
                        </h1>
                        <span
                            class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium"
                            :style="{
                                backgroundColor: `${lead.status_color}15`,
                                color: lead.status_color,
                                border: `1px solid ${lead.status_color}30`,
                            }"
                        >
                            {{ lead.status_label }}
                        </span>
                        <span
                            v-if="lead.is_converted"
                            class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400"
                        >
                            <CheckBadgeIcon class="size-4" />
                            Converted to Buy
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Created {{ formatDate(lead.created_at) }}
                        <span v-if="lead.type" class="ml-2 capitalize">{{ lead.type.replace('_', ' ') }}</span>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Status change dropdown -->
                    <Menu as="div" class="relative">
                        <MenuButton
                            class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
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
                            <MenuItems
                                class="absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black/5 focus:outline-none dark:bg-gray-800 dark:ring-white/10"
                            >
                                <div class="py-1">
                                    <MenuItem
                                        v-for="(label, slug) in statuses"
                                        :key="slug"
                                        v-slot="{ active }"
                                        :disabled="slug === lead.status"
                                    >
                                        <button
                                            type="button"
                                            :class="[
                                                active ? 'bg-gray-100 dark:bg-gray-700' : '',
                                                slug === lead.status ? 'font-bold text-indigo-600 dark:text-indigo-400' : 'text-gray-700 dark:text-gray-300',
                                                'block w-full px-4 py-2 text-left text-sm',
                                            ]"
                                            @click="changeStatus(slug as string)"
                                        >
                                            {{ label }}
                                        </button>
                                    </MenuItem>
                                </div>
                            </MenuItems>
                        </transition>
                    </Menu>

                    <!-- Converted buy link -->
                    <Link
                        v-if="lead.is_converted && lead.transaction_id"
                        :href="`/transactions/${lead.transaction_id}`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500"
                    >
                        View Buy {{ lead.transaction_number }}
                    </Link>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Main Content -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Customer -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Customer</h2>
                        </div>
                        <div class="p-4">
                            <div v-if="lead.customer" class="flex items-start gap-4">
                                <div class="flex size-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-6 text-gray-400" />
                                </div>
                                <div class="flex-1">
                                    <Link
                                        :href="`/customers/${lead.customer.id}`"
                                        class="text-lg font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    >
                                        {{ lead.customer.full_name }}
                                    </Link>
                                    <div class="mt-1 flex flex-wrap gap-4 text-sm text-gray-500 dark:text-gray-400">
                                        <span v-if="lead.customer.email" class="inline-flex items-center gap-1">
                                            <EnvelopeIcon class="size-4" />
                                            {{ lead.customer.email }}
                                        </span>
                                        <span v-if="lead.customer.phone" class="inline-flex items-center gap-1">
                                            <PhoneIcon class="size-4" />
                                            {{ lead.customer.phone }}
                                        </span>
                                    </div>
                                    <div v-if="lead.shipping_address" class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">Ship to:</span>
                                        {{ lead.shipping_address.one_line_address }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="text-sm text-gray-400 hover:text-red-500"
                                    @click="updateCustomer(null)"
                                >
                                    <XMarkIcon class="size-5" />
                                </button>
                            </div>
                            <div v-else>
                                <CustomerSearch @select="updateCustomer($event.id)" />
                            </div>
                        </div>
                    </div>

                    <!-- Customer Description (for online/mail-in leads) -->
                    <div
                        v-if="lead.customer_description || lead.customer_amount || lead.customer_categories"
                        class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800"
                    >
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Customer Submission</h2>
                        </div>
                        <div class="space-y-3 p-4">
                            <div v-if="lead.customer_description">
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Description</p>
                                <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ lead.customer_description }}</p>
                            </div>
                            <div v-if="lead.customer_amount" class="flex justify-between">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Customer's Asking Price</span>
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ formatCurrency(lead.customer_amount) }}</span>
                            </div>
                            <div v-if="lead.customer_categories">
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Categories</p>
                                <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ lead.customer_categories }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Items -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Items ({{ lead.items.length }})
                            </h2>
                        </div>
                        <div v-if="lead.items.length > 0" class="divide-y divide-gray-200 dark:divide-gray-700">
                            <div v-for="item in lead.items" :key="item.id" class="p-4">
                                <div class="flex gap-4">
                                    <!-- Item images -->
                                    <div v-if="item.images.length > 0" class="shrink-0">
                                        <img
                                            :src="item.images[0].thumbnail_url || item.images[0].url"
                                            :alt="item.title"
                                            class="size-16 rounded-lg object-cover"
                                        />
                                    </div>
                                    <div v-else class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700">
                                        <PhotoIcon class="size-8 text-gray-400" />
                                    </div>

                                    <!-- Item details -->
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between">
                                            <div>
                                                <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                                <p v-if="item.description" class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                                    {{ item.description }}
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <p class="font-medium text-gray-900 dark:text-white">
                                                    {{ formatCurrency(item.buy_price) }}
                                                </p>
                                                <p v-if="item.price" class="text-xs text-gray-500 dark:text-gray-400">
                                                    Est: {{ formatCurrency(item.price) }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <span
                                                v-if="item.precious_metal"
                                                class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 ring-inset dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-400/30"
                                            >
                                                {{ item.precious_metal.replace('_', ' ').toUpperCase() }}
                                            </span>
                                            <span v-if="item.dwt" class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                <ScaleIcon class="size-3.5" />
                                                {{ item.dwt }} dwt
                                            </span>
                                            <span
                                                v-if="item.category"
                                                class="inline-flex items-center rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-600 ring-1 ring-gray-500/10 ring-inset dark:bg-gray-700 dark:text-gray-400 dark:ring-gray-400/20"
                                            >
                                                {{ item.category.name }}
                                            </span>
                                            <span
                                                v-if="item.condition"
                                                class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-600 ring-1 ring-blue-500/10 ring-inset dark:bg-blue-900/20 dark:text-blue-400 dark:ring-blue-400/30"
                                            >
                                                {{ item.condition.replace('_', ' ') }}
                                            </span>
                                            <span
                                                v-if="item.is_reviewed"
                                                class="inline-flex items-center gap-0.5 rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset dark:bg-green-900/20 dark:text-green-400 dark:ring-green-400/30"
                                            >
                                                <CheckIcon class="size-3" />
                                                Reviewed
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="p-8 text-center">
                            <InboxIcon class="mx-auto size-12 text-gray-400" />
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No items added yet</p>
                        </div>

                        <!-- Items totals -->
                        <div v-if="lead.items.length > 0" class="border-t border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/50">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">{{ lead.item_count }} item(s)</span>
                                <div class="space-x-4">
                                    <span v-if="lead.total_dwt" class="text-gray-500 dark:text-gray-400">
                                        {{ lead.total_dwt.toFixed(2) }} dwt total
                                    </span>
                                    <span class="font-medium text-gray-900 dark:text-white">
                                        Total: {{ formatCurrency(lead.total_buy_price) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Shipping & Tracking -->
                    <div v-if="hasKitTracking" class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Shipping & Tracking</h2>
                        </div>
                        <div class="grid gap-4 p-4 sm:grid-cols-2">
                            <div v-if="lead.outbound_tracking_number" class="rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50">
                                <div class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <PaperAirplaneIcon class="size-4" />
                                    Outbound Kit
                                </div>
                                <p class="mt-1 font-mono text-sm text-gray-900 dark:text-white">
                                    {{ lead.outbound_carrier?.toUpperCase() }}: {{ lead.outbound_tracking_number }}
                                </p>
                                <div class="mt-1 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <p v-if="lead.kit_sent_at">Sent: {{ formatDate(lead.kit_sent_at) }}</p>
                                    <p v-if="lead.kit_delivered_at" class="text-green-600 dark:text-green-400">
                                        Delivered: {{ formatDate(lead.kit_delivered_at) }}
                                    </p>
                                </div>
                            </div>
                            <div v-if="lead.return_tracking_number" class="rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50">
                                <div class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <TruckIcon class="size-4" />
                                    Return Shipment
                                </div>
                                <p class="mt-1 font-mono text-sm text-gray-900 dark:text-white">
                                    {{ lead.return_carrier?.toUpperCase() }}: {{ lead.return_tracking_number }}
                                </p>
                                <div class="mt-1 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <p v-if="lead.items_received_at" class="text-green-600 dark:text-green-400">
                                        Received: {{ formatDate(lead.items_received_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attachments -->
                    <AttachmentsSection
                        v-if="attachments"
                        :attachments="attachments"
                        :upload-url="`/leads/${lead.id}/attachments`"
                        :delete-url-prefix="`/leads/${lead.id}/attachments`"
                    />

                    <!-- Notes -->
                    <NotesSection
                        :notes="lead.notes"
                        :add-url="`/leads/${lead.id}/notes`"
                        :delete-url-prefix="`/leads/${lead.id}/notes`"
                    />

                    <!-- Activity Log -->
                    <ActivityTimeline
                        v-if="activityLogs"
                        :activity-logs="activityLogs"
                    />
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Summary -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Summary</h2>
                        </div>
                        <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                            <div class="flex justify-between px-4 py-3">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Estimated Value</dt>
                                <dd class="text-sm font-medium text-gray-900 dark:text-white">{{ formatCurrency(lead.estimated_value) }}</dd>
                            </div>
                            <div class="flex justify-between px-4 py-3">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Preliminary Offer</dt>
                                <dd class="text-sm font-medium text-gray-900 dark:text-white">{{ formatCurrency(lead.preliminary_offer) }}</dd>
                            </div>
                            <div class="flex justify-between px-4 py-3">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Final Offer</dt>
                                <dd class="text-sm font-semibold text-gray-900 dark:text-white">{{ formatCurrency(lead.final_offer) }}</dd>
                            </div>
                            <div v-if="lead.payment_method" class="flex justify-between px-4 py-3">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Payment Method</dt>
                                <dd class="text-sm font-medium capitalize text-gray-900 dark:text-white">
                                    {{ lead.payment_method.replace(/_/g, ' ') }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between px-4 py-3">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Bin Location</dt>
                                <dd v-if="editingField !== 'bin_location'" class="flex items-center gap-1">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ lead.bin_location || '-' }}</span>
                                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="startEdit('bin_location', lead.bin_location)">
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                        </svg>
                                    </button>
                                </dd>
                                <dd v-else class="flex items-center gap-1">
                                    <input
                                        v-model="editFieldValue"
                                        type="text"
                                        class="w-24 rounded border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        @keydown.enter="saveEdit('bin_location')"
                                        @keydown.escape="cancelEdit"
                                    />
                                    <button type="button" class="text-green-500" @click="saveEdit('bin_location')">
                                        <CheckIcon class="size-4" />
                                    </button>
                                    <button type="button" class="text-gray-400" @click="cancelEdit">
                                        <XMarkIcon class="size-4" />
                                    </button>
                                </dd>
                            </div>
                            <div class="flex justify-between px-4 py-3">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Assigned To</dt>
                                <dd class="text-sm font-medium text-gray-900 dark:text-white">
                                    <button
                                        type="button"
                                        class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                        @click="showAssignModal = true"
                                    >
                                        {{ lead.assigned_user?.name || 'Unassigned' }}
                                    </button>
                                </dd>
                            </div>
                            <div v-if="lead.is_converted" class="px-4 py-3">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">Converted Buy</dt>
                                <dd class="mt-1">
                                    <Link
                                        :href="`/transactions/${lead.transaction_id}`"
                                        class="inline-flex items-center gap-1 text-sm font-medium text-emerald-600 hover:text-emerald-500 dark:text-emerald-400"
                                    >
                                        <CheckBadgeIcon class="size-4" />
                                        {{ lead.transaction_number }}
                                    </Link>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Actions -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Actions</h2>
                        </div>
                        <div class="space-y-2 p-4">
                            <!-- Kit Request actions -->
                            <button
                                v-if="canConfirmKit"
                                type="button"
                                class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="confirmKitRequest"
                            >
                                Confirm Kit Request
                            </button>
                            <button
                                v-if="canHoldKit"
                                type="button"
                                class="w-full rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500"
                                @click="holdKitRequest"
                            >
                                Put On Hold
                            </button>
                            <button
                                v-if="canRejectKit"
                                type="button"
                                class="w-full rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                @click="rejectKitRequest"
                            >
                                Reject Kit Request
                            </button>

                            <!-- Kit shipping actions -->
                            <button
                                v-if="canMarkKitSent"
                                type="button"
                                class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="showKitSentModal = true"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <TruckIcon class="size-4" />
                                    Mark Kit Sent
                                </span>
                            </button>
                            <button
                                v-if="canMarkKitDelivered"
                                type="button"
                                class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="markKitDelivered"
                            >
                                Mark Kit Delivered
                            </button>

                            <!-- Items actions -->
                            <button
                                v-if="canMarkItemsReceived"
                                type="button"
                                class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="markItemsReceived"
                            >
                                Mark Items Received
                            </button>
                            <button
                                v-if="canMarkItemsReviewed"
                                type="button"
                                class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="markItemsReviewed"
                            >
                                Mark Items Reviewed
                            </button>

                            <!-- Offer actions -->
                            <button
                                v-if="canSubmitOffer"
                                type="button"
                                class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="showOfferModal = true"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <CurrencyDollarIcon class="size-4" />
                                    Submit Offer
                                </span>
                            </button>
                            <button
                                v-if="canAcceptOffer"
                                type="button"
                                class="w-full rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                                @click="acceptOffer"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <CheckIcon class="size-4" />
                                    Accept Offer
                                </span>
                            </button>
                            <button
                                v-if="canDeclineOffer"
                                type="button"
                                class="w-full rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                @click="showDeclineModal = true"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <XMarkIcon class="size-4" />
                                    Decline Offer
                                </span>
                            </button>

                            <!-- Payment -->
                            <button
                                v-if="canProcessPayment"
                                type="button"
                                class="w-full rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500"
                                @click="showPaymentModal = true"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <BanknotesIcon class="size-4" />
                                    Process Payment
                                </span>
                            </button>

                            <!-- Return items -->
                            <button
                                v-if="canMarkItemsReturned"
                                type="button"
                                class="w-full rounded-md bg-gray-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500"
                                @click="markItemsReturned"
                            >
                                Mark Items Returned
                            </button>
                        </div>
                    </div>

                    <!-- Notes (sidebar) -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Internal Notes</h2>
                        </div>
                        <div class="p-4">
                            <div v-if="editingField !== 'internal_notes'">
                                <p
                                    v-if="lead.internal_notes"
                                    class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300"
                                >{{ lead.internal_notes }}</p>
                                <p v-else class="text-sm text-gray-400 italic">No internal notes</p>
                                <button
                                    type="button"
                                    class="mt-2 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    @click="startEdit('internal_notes', lead.internal_notes)"
                                >
                                    Edit
                                </button>
                            </div>
                            <div v-else>
                                <textarea
                                    v-model="editFieldValue"
                                    rows="4"
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    placeholder="Add internal notes..."
                                ></textarea>
                                <div class="mt-2 flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500"
                                        @click="saveEdit('internal_notes')"
                                    >
                                        Save
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600"
                                        @click="cancelEdit"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status History -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Status History</h2>
                        </div>
                        <div v-if="lead.status_histories.length > 0" class="p-4">
                            <ul class="space-y-4">
                                <li v-for="(event, index) in lead.status_histories" :key="event.id" class="relative flex gap-3">
                                    <div class="relative flex flex-col items-center">
                                        <div class="flex size-7 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/30">
                                            <ClockIcon class="size-3.5 text-indigo-600 dark:text-indigo-400" />
                                        </div>
                                        <div
                                            v-if="index < lead.status_histories.length - 1"
                                            class="absolute top-7 h-full w-px bg-gray-200 dark:bg-gray-700"
                                        ></div>
                                    </div>
                                    <div class="flex-1 pb-4">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ formatStatusLabel(event.to_status) }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ formatDateTime(event.created_at) }}
                                            <span v-if="event.user"> by {{ event.user.name }}</span>
                                        </p>
                                        <p v-if="event.notes" class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                            {{ event.notes }}
                                        </p>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <div v-else class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            No status changes recorded
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Offer Modal -->
        <TransitionRoot as="template" :show="showOfferModal">
            <Dialog class="relative z-50" @close="showOfferModal = false">
                <TransitionChild
                    enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                    leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                </TransitionChild>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <TransitionChild
                            enter="ease-out duration-300" enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" enter-to="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200" leave-from="opacity-100 translate-y-0 sm:scale-100" leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel class="relative w-full transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                <DialogTitle class="text-lg font-semibold text-gray-900 dark:text-white">Submit Offer</DialogTitle>
                                <div class="mt-4 space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Offer Amount</label>
                                        <div class="relative mt-1">
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                <span class="text-gray-500 sm:text-sm">$</span>
                                            </div>
                                            <input
                                                v-model="offerForm.offer"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="block w-full rounded-md border-gray-300 pl-7 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            />
                                        </div>
                                        <p v-if="offerForm.errors.offer" class="mt-1 text-sm text-red-600">{{ offerForm.errors.offer }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (optional)</label>
                                        <textarea
                                            v-model="offerForm.notes"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        ></textarea>
                                    </div>
                                </div>
                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:col-start-2"
                                        :disabled="offerForm.processing"
                                        @click="submitOffer"
                                    >
                                        {{ offerForm.processing ? 'Submitting...' : 'Submit Offer' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 sm:col-start-1 sm:mt-0 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        @click="showOfferModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Decline Modal -->
        <TransitionRoot as="template" :show="showDeclineModal">
            <Dialog class="relative z-50" @close="showDeclineModal = false">
                <TransitionChild
                    enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                    leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                </TransitionChild>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <TransitionChild
                            enter="ease-out duration-300" enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" enter-to="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200" leave-from="opacity-100 translate-y-0 sm:scale-100" leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel class="relative w-full transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                <DialogTitle class="text-lg font-semibold text-gray-900 dark:text-white">Decline Offer</DialogTitle>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason (optional)</label>
                                    <textarea
                                        v-model="declineForm.reason"
                                        rows="3"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        placeholder="Why is the customer declining?"
                                    ></textarea>
                                </div>
                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:col-start-2"
                                        :disabled="declineForm.processing"
                                        @click="declineOffer"
                                    >
                                        {{ declineForm.processing ? 'Declining...' : 'Decline Offer' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 sm:col-start-1 sm:mt-0 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        @click="showDeclineModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Payment Modal -->
        <TransitionRoot as="template" :show="showPaymentModal">
            <Dialog class="relative z-50" @close="showPaymentModal = false">
                <TransitionChild
                    enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                    leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                </TransitionChild>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <TransitionChild
                            enter="ease-out duration-300" enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" enter-to="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200" leave-from="opacity-100 translate-y-0 sm:scale-100" leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel class="relative w-full transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                <DialogTitle class="text-lg font-semibold text-gray-900 dark:text-white">Process Payment</DialogTitle>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Final offer: {{ formatCurrency(lead.final_offer) }}
                                </p>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Method</label>
                                    <select
                                        v-model="paymentForm.payment_method"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option value="">Select method...</option>
                                        <option v-for="method in paymentMethods" :key="method.value" :value="method.value">
                                            {{ method.label }}
                                        </option>
                                    </select>
                                    <p v-if="paymentForm.errors.payment_method" class="mt-1 text-sm text-red-600">{{ paymentForm.errors.payment_method }}</p>
                                </div>
                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex w-full justify-center rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 sm:col-start-2"
                                        :disabled="paymentForm.processing || !paymentForm.payment_method"
                                        @click="processPayment"
                                    >
                                        {{ paymentForm.processing ? 'Processing...' : 'Process Payment' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 sm:col-start-1 sm:mt-0 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        @click="showPaymentModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Kit Sent Modal -->
        <TransitionRoot as="template" :show="showKitSentModal">
            <Dialog class="relative z-50" @close="showKitSentModal = false">
                <TransitionChild
                    enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                    leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                </TransitionChild>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <TransitionChild
                            enter="ease-out duration-300" enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" enter-to="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200" leave-from="opacity-100 translate-y-0 sm:scale-100" leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel class="relative w-full transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                <DialogTitle class="text-lg font-semibold text-gray-900 dark:text-white">Mark Kit as Sent</DialogTitle>
                                <div class="mt-4 space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tracking Number</label>
                                        <input
                                            v-model="kitSentForm.tracking_number"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            placeholder="Enter tracking number"
                                        />
                                        <p v-if="kitSentForm.errors.tracking_number" class="mt-1 text-sm text-red-600">{{ kitSentForm.errors.tracking_number }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Carrier</label>
                                        <select
                                            v-model="kitSentForm.carrier"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option value="fedex">FedEx</option>
                                            <option value="ups">UPS</option>
                                            <option value="usps">USPS</option>
                                            <option value="dhl">DHL</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:col-start-2"
                                        :disabled="kitSentForm.processing || !kitSentForm.tracking_number"
                                        @click="markKitSent"
                                    >
                                        {{ kitSentForm.processing ? 'Saving...' : 'Mark Sent' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 sm:col-start-1 sm:mt-0 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        @click="showKitSentModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Assign Modal -->
        <TransitionRoot as="template" :show="showAssignModal">
            <Dialog class="relative z-50" @close="showAssignModal = false">
                <TransitionChild
                    enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                    leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                </TransitionChild>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <TransitionChild
                            enter="ease-out duration-300" enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" enter-to="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200" leave-from="opacity-100 translate-y-0 sm:scale-100" leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel class="relative w-full transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                <DialogTitle class="text-lg font-semibold text-gray-900 dark:text-white">Assign Lead</DialogTitle>
                                <div class="mt-4">
                                    <select
                                        v-model="assignForm.assigned_to"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option value="">Unassigned</option>
                                        <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                                            {{ member.name }}
                                        </option>
                                    </select>
                                </div>
                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:col-start-2"
                                        :disabled="assignForm.processing"
                                        @click="assignLead"
                                    >
                                        {{ assignForm.processing ? 'Assigning...' : 'Assign' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 sm:col-start-1 sm:mt-0 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        @click="showAssignModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>
    </AppLayout>
</template>
