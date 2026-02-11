<script setup lang="ts">
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { NotesSection } from '@/components/notes';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    PencilIcon,
    TrashIcon,
    BuildingOffice2Icon,
    EnvelopeIcon,
    PhoneIcon,
    GlobeAltIcon,
    MapPinIcon,
    ClockIcon,
    CurrencyDollarIcon,
    DocumentTextIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArrowLeftIcon,
} from '@heroicons/vue/20/solid';

interface PurchaseOrder {
    id: number;
    po_number: string;
    status: string;
    total: number;
    order_date: string | null;
}

interface Vendor {
    id: number;
    name: string;
    code: string | null;
    company_name: string | null;
    display_name: string;
    email: string | null;
    phone: string | null;
    website: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    full_address: string | null;
    tax_id: string | null;
    payment_terms: string | null;
    lead_time_days: number | null;
    currency_code: string | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    is_active: boolean;
    notes: string | null;
    purchase_orders_count: number;
    product_variants_count: number;
    memos_count: number;
    repairs_count: number;
    products_count: number;
    recent_purchase_orders: PurchaseOrder[];
    note_entries: Note[];
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

interface SoldItem {
    id: number;
    sku: string | null;
    title: string | null;
    order_id: number;
    invoice_number: string | null;
    date: string | null;
    cost: number;
    wholesale: number;
    amount_sold: number;
    profit: number;
    profit_percent: number;
}

interface SoldItemsData {
    items: SoldItem[];
    totals: {
        cost: number;
        wholesale: number;
        amount_sold: number;
        profit: number;
        profit_percent: number;
    };
}

interface MemoItem {
    id: number;
    memo_number: string;
    status: string;
    total: number;
    grand_total: number;
    user: string | null;
    created_at: string;
}

interface RepairItem {
    id: number;
    repair_number: string;
    status: string;
    total: number;
    customer: string | null;
    user: string | null;
    created_at: string;
}

interface StockItem {
    id: number;
    title: string;
    sku: string | null;
    quantity: number;
    price: number;
    cost: number;
    status: string;
}

interface Props {
    vendor: Vendor;
    paymentTerms: string[];
    soldItems?: SoldItemsData;
    memos?: MemoItem[];
    repairs?: RepairItem[];
    currentStock?: StockItem[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Vendors', href: '/vendors' },
    { title: props.vendor.name, href: `/vendors/${props.vendor.id}` },
];

const showEditModal = ref(false);
const showDeleteModal = ref(false);

const form = useForm({
    name: props.vendor.name,
    code: props.vendor.code || '',
    company_name: props.vendor.company_name || '',
    email: props.vendor.email || '',
    phone: props.vendor.phone || '',
    website: props.vendor.website || '',
    address_line1: props.vendor.address_line1 || '',
    address_line2: props.vendor.address_line2 || '',
    city: props.vendor.city || '',
    state: props.vendor.state || '',
    postal_code: props.vendor.postal_code || '',
    country: props.vendor.country || '',
    tax_id: props.vendor.tax_id || '',
    payment_terms: props.vendor.payment_terms || '',
    lead_time_days: props.vendor.lead_time_days?.toString() || '',
    currency_code: props.vendor.currency_code || 'USD',
    contact_name: props.vendor.contact_name || '',
    contact_email: props.vendor.contact_email || '',
    contact_phone: props.vendor.contact_phone || '',
    is_active: props.vendor.is_active,
    notes: props.vendor.notes || '',
});

function submitForm() {
    form.put(`/vendors/${props.vendor.id}`, {
        onSuccess: () => {
            showEditModal.value = false;
        },
    });
}

function handleDelete() {
    router.delete(`/vendors/${props.vendor.id}`, {
        onSuccess: () => {
            // Will redirect to index
        },
    });
}

const formatPaymentTerms = (terms: string | null) => {
    if (!terms) return '-';
    return terms.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.vendor.currency_code || 'USD',
    }).format(amount);
};

const statusColors: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    submitted: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
    approved: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    partial: 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
    received: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    closed: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
};

const memoStatusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
    sent_to_vendor: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    vendor_received: 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
    payment_received: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    returned: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
};

const repairStatusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
    sent_to_vendor: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    vendor_received: 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
    completed: 'bg-teal-100 text-teal-700 dark:bg-teal-900 dark:text-teal-300',
    payment_received: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
};

const stockStatusColors: Record<string, string> = {
    active: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    inactive: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
    sold: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
};

const getMemoStatusClass = (status: string) => {
    return memoStatusColors[status] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
};

const getRepairStatusClass = (status: string) => {
    return repairStatusColors[status] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
};

const getStockStatusClass = (status: string) => {
    return stockStatusColors[status] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
};

const formatMemoStatus = (status: string) => {
    const labels: Record<string, string> = {
        pending: 'Pending',
        sent_to_vendor: 'Sent to Vendor',
        vendor_received: 'Vendor Received',
        payment_received: 'Payment Received',
        returned: 'Returned',
        cancelled: 'Cancelled',
    };
    return labels[status] || status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const formatRepairStatus = (status: string) => {
    const labels: Record<string, string> = {
        pending: 'Pending',
        sent_to_vendor: 'Sent to Vendor',
        vendor_received: 'Vendor Received',
        completed: 'Completed',
        payment_received: 'Payment Received',
        cancelled: 'Cancelled',
    };
    return labels[status] || status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};
</script>

<template>
    <Head :title="vendor.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Back Button & Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-4">
                    <Link
                        href="/vendors"
                        class="mt-1 rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                                {{ vendor.name }}
                            </h1>
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium',
                                    vendor.is_active
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                                        : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                ]"
                            >
                                <CheckCircleIcon v-if="vendor.is_active" class="size-3.5" />
                                <XCircleIcon v-else class="size-3.5" />
                                {{ vendor.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        <p v-if="vendor.code || vendor.company_name" class="text-sm text-gray-500 dark:text-gray-400">
                            <span v-if="vendor.code">{{ vendor.code }}</span>
                            <span v-if="vendor.code && vendor.company_name"> - </span>
                            <span v-if="vendor.company_name">{{ vendor.company_name }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                        @click="showEditModal = true"
                    >
                        <PencilIcon class="-ml-0.5 size-4" />
                        Edit
                    </button>
                    <button
                        v-if="vendor.purchase_orders_count === 0"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                        @click="showDeleteModal = true"
                    >
                        <TrashIcon class="-ml-0.5 size-4" />
                        Delete
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Left Column - Details -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Contact Information -->
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Contact Information</h3>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700">
                            <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div v-if="vendor.email" class="px-4 py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                        <EnvelopeIcon class="size-4" />
                                        Email
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        <a :href="`mailto:${vendor.email}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                            {{ vendor.email }}
                                        </a>
                                    </dd>
                                </div>
                                <div v-if="vendor.phone" class="px-4 py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                        <PhoneIcon class="size-4" />
                                        Phone
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        <a :href="`tel:${vendor.phone}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                            {{ vendor.phone }}
                                        </a>
                                    </dd>
                                </div>
                                <div v-if="vendor.website" class="px-4 py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                        <GlobeAltIcon class="size-4" />
                                        Website
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        <a :href="vendor.website" target="_blank" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                            {{ vendor.website }}
                                        </a>
                                    </dd>
                                </div>
                                <div v-if="vendor.full_address" class="px-4 py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                        <MapPinIcon class="size-4" />
                                        Address
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        {{ vendor.full_address }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Primary Contact -->
                    <div v-if="vendor.contact_name || vendor.contact_email || vendor.contact_phone" class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Primary Contact</h3>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700">
                            <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div v-if="vendor.contact_name" class="px-4 py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Name</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        {{ vendor.contact_name }}
                                    </dd>
                                </div>
                                <div v-if="vendor.contact_email" class="px-4 py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        <a :href="`mailto:${vendor.contact_email}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                            {{ vendor.contact_email }}
                                        </a>
                                    </dd>
                                </div>
                                <div v-if="vendor.contact_phone" class="px-4 py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Phone</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        <a :href="`tel:${vendor.contact_phone}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                            {{ vendor.contact_phone }}
                                        </a>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div v-if="vendor.notes" class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Notes</h3>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-4 sm:px-6">
                            <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ vendor.notes }}</p>
                        </div>
                    </div>

                    <!-- Sold Items Profits Table -->
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Sold Items Profits</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">All sold products from this vendor</p>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700">
                            <div v-if="!soldItems" class="px-4 py-8">
                                <div class="animate-pulse space-y-3">
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-2/3"></div>
                                </div>
                            </div>
                            <div v-else-if="soldItems.items.length === 0" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No sold items found for this vendor.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Order</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cost</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Wholesale</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Sold For</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Profit</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Profit %</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="item in soldItems.items" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                                {{ item.sku || '-' }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                                <Link :href="`/orders/${item.order_id}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                    {{ item.invoice_number || `#${item.order_id}` }}
                                                </Link>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ item.date || '-' }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.cost) }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.wholesale) }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.amount_sold) }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right" :class="item.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                                {{ formatCurrency(item.profit) }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right" :class="item.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                                {{ item.profit_percent }}%
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="bg-gray-50 dark:bg-gray-800/50">
                                        <tr class="font-semibold">
                                            <td colspan="3" class="px-4 py-3 text-sm text-gray-900 dark:text-white">Totals</td>
                                            <td class="px-4 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(soldItems.totals.cost) }}</td>
                                            <td class="px-4 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(soldItems.totals.wholesale) }}</td>
                                            <td class="px-4 py-3 text-sm text-right text-gray-900 dark:text-white">{{ formatCurrency(soldItems.totals.amount_sold) }}</td>
                                            <td class="px-4 py-3 text-sm text-right" :class="soldItems.totals.profit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ formatCurrency(soldItems.totals.profit) }}</td>
                                            <td class="px-4 py-3 text-sm text-right" :class="soldItems.totals.profit_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ soldItems.totals.profit_percent }}%</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Memos Table -->
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Memos</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">All memos for this vendor</p>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700">
                            <div v-if="!memos" class="px-4 py-8">
                                <div class="animate-pulse space-y-3">
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                                </div>
                            </div>
                            <div v-else-if="memos.length === 0" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No memos found for this vendor.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Memo #</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Employee</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="memo in memos" :key="memo.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                                <Link :href="`/memos/${memo.id}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                    {{ memo.memo_number }}
                                                </Link>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                                <span :class="getMemoStatusClass(memo.status)" class="inline-flex rounded-full px-2 py-1 text-xs font-medium">
                                                    {{ formatMemoStatus(memo.status) }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ memo.user || '-' }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ memo.created_at }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                                {{ formatCurrency(memo.grand_total || memo.total) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Repairs Table -->
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Repairs</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">All repairs for this vendor</p>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700">
                            <div v-if="!repairs" class="px-4 py-8">
                                <div class="animate-pulse space-y-3">
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                                </div>
                            </div>
                            <div v-else-if="repairs.length === 0" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No repairs found for this vendor.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Repair #</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Customer</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Employee</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="repair in repairs" :key="repair.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                                <Link :href="`/repairs/${repair.id}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                    {{ repair.repair_number }}
                                                </Link>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                                <span :class="getRepairStatusClass(repair.status)" class="inline-flex rounded-full px-2 py-1 text-xs font-medium">
                                                    {{ formatRepairStatus(repair.status) }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ repair.customer || '-' }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ repair.user || '-' }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ repair.created_at }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                                {{ formatCurrency(repair.total) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Current Stock Table -->
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Current Stock</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Active products from this vendor in inventory</p>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700">
                            <div v-if="!currentStock" class="px-4 py-8">
                                <div class="animate-pulse space-y-3">
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4"></div>
                                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                                </div>
                            </div>
                            <div v-else-if="currentStock.length === 0" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No current stock found for this vendor.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Title</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cost</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Price</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="item in currentStock" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                                <Link :href="`/products/${item.id}`" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                    {{ item.sku || '-' }}
                                                </Link>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white max-w-xs truncate">
                                                {{ item.title }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                                {{ item.quantity }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.cost) }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.price) }}
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                                <span :class="getStockStatusClass(item.status)" class="inline-flex rounded-full px-2 py-1 text-xs font-medium capitalize">
                                                    {{ item.status }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Stats & Business Details -->
                <div class="space-y-6">
                    <!-- Stats -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10 p-4">
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Purchase Orders</dt>
                            <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">
                                {{ vendor.purchase_orders_count }}
                            </dd>
                        </div>
                        <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10 p-4">
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Products</dt>
                            <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">
                                {{ vendor.products_count }}
                            </dd>
                        </div>
                        <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10 p-4">
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Memos</dt>
                            <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">
                                {{ vendor.memos_count }}
                            </dd>
                        </div>
                        <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10 p-4">
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Repairs</dt>
                            <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">
                                {{ vendor.repairs_count }}
                            </dd>
                        </div>
                    </div>

                    <!-- Business Details -->
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Business Details</h3>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700">
                            <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                        <CurrencyDollarIcon class="size-4" />
                                        Payment Terms
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        {{ formatPaymentTerms(vendor.payment_terms) }}
                                    </dd>
                                </div>
                                <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                        <ClockIcon class="size-4" />
                                        Lead Time
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        {{ vendor.lead_time_days ? `${vendor.lead_time_days} days` : '-' }}
                                    </dd>
                                </div>
                                <div v-if="vendor.tax_id" class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                        <DocumentTextIcon class="size-4" />
                                        Tax ID
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        {{ vendor.tax_id }}
                                    </dd>
                                </div>
                                <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Currency</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                                        {{ vendor.currency_code || 'USD' }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Recent Purchase Orders -->
                    <div v-if="vendor.recent_purchase_orders.length > 0" class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Recent Purchase Orders</h3>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700">
                            <ul role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
                                <li v-for="po in vendor.recent_purchase_orders" :key="po.id" class="px-4 py-3 sm:px-6">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                {{ po.po_number }}
                                            </p>
                                            <p v-if="po.order_date" class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ po.order_date }}
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2 py-1 text-xs font-medium',
                                                    statusColors[po.status] || 'bg-gray-100 text-gray-700',
                                                ]"
                                            >
                                                {{ po.status }}
                                            </span>
                                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                                {{ formatCurrency(po.total) }}
                                            </span>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Notes -->
                    <NotesSection
                        :notes="vendor.note_entries"
                        notable-type="vendor"
                        :notable-id="vendor.id"
                    />

                    <!-- Activity Log -->
                    <ActivityTimeline :activities="activityLogs" />
                </div>
            </div>
        </div>

        <!-- Edit Modal (same as Index.vue but for editing only) -->
        <Teleport to="body">
            <div v-if="showEditModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showEditModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6 dark:bg-gray-800">
                            <form @submit.prevent="submitForm">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    Edit Vendor
                                </h3>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 max-h-[60vh] overflow-y-auto pr-2">
                                    <!-- Basic Info -->
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name *</label>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            required
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Code</label>
                                        <input
                                            v-model="form.code"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Company Name</label>
                                        <input
                                            v-model="form.company_name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Contact Info -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                        <input
                                            v-model="form.email"
                                            type="email"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                        <input
                                            v-model="form.phone"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Website</label>
                                        <input
                                            v-model="form.website"
                                            type="url"
                                            placeholder="https://..."
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Address -->
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address Line 1</label>
                                        <input
                                            v-model="form.address_line1"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address Line 2</label>
                                        <input
                                            v-model="form.address_line2"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">City</label>
                                        <input
                                            v-model="form.city"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">State/Province</label>
                                        <input
                                            v-model="form.state"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Postal Code</label>
                                        <input
                                            v-model="form.postal_code"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Country</label>
                                        <input
                                            v-model="form.country"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Business Details -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Terms</label>
                                        <select
                                            v-model="form.payment_terms"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option value="">Select...</option>
                                            <option v-for="term in paymentTerms" :key="term" :value="term">
                                                {{ formatPaymentTerms(term) }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Lead Time (days)</label>
                                        <input
                                            v-model="form.lead_time_days"
                                            type="number"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tax ID</label>
                                        <input
                                            v-model="form.tax_id"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Currency</label>
                                        <input
                                            v-model="form.currency_code"
                                            type="text"
                                            maxlength="3"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Contact Person -->
                                    <div class="sm:col-span-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Primary Contact</h4>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contact Name</label>
                                        <input
                                            v-model="form.contact_name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contact Email</label>
                                        <input
                                            v-model="form.contact_email"
                                            type="email"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contact Phone</label>
                                        <input
                                            v-model="form.contact_phone"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="flex items-center gap-2">
                                            <input
                                                v-model="form.is_active"
                                                type="checkbox"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                            />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Active</span>
                                        </label>
                                    </div>

                                    <!-- Notes -->
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                        <textarea
                                            v-model="form.notes"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <div class="mt-5 sm:mt-6 flex flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        :disabled="form.processing"
                                        class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                    >
                                        {{ form.processing ? 'Saving...' : 'Update' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showEditModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10 dark:bg-red-900">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete Vendor
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Are you sure you want to delete "{{ vendor.name }}"? This action cannot be undone.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                                <button
                                    type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:w-auto"
                                    @click="handleDelete"
                                >
                                    Delete
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showDeleteModal = false"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
