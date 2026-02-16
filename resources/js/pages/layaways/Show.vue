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
    ClockIcon,
    CubeIcon,
    CheckCircleIcon,
    XCircleIcon,
    BanknotesIcon,
    TrashIcon,
    CurrencyDollarIcon,
} from '@heroicons/vue/24/outline';
import CollectPaymentModal from '@/components/payments/CollectPaymentModal.vue';

interface LeadSource {
    id: number;
    name: string;
}

interface Customer {
    id: number;
    first_name: string;
    last_name?: string;
    full_name: string;
    email?: string;
    phone?: string;
    lead_source?: LeadSource;
}

interface User {
    id: number;
    name: string;
}

interface Product {
    id: number;
    title: string;
    sku?: string;
    image?: string;
}

interface LayawayItem {
    id: number;
    product_id: number;
    sku?: string;
    title: string;
    description?: string;
    quantity: number;
    price: number;
    line_total: number;
    is_reserved: boolean;
    product?: Product;
}

interface LayawaySchedule {
    id: number;
    installment_number: number;
    due_date: string;
    amount_due: number;
    amount_paid: number;
    remaining_amount: number;
    status: string;
    is_overdue: boolean;
    paid_at?: string;
}

interface Order {
    id: number;
    order_id: string;
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

interface Warehouse {
    id: number;
    name: string;
}

interface Layaway {
    id: number;
    layaway_number: string;
    status: string;
    payment_type: string;
    term_days: number;

    subtotal: number;
    tax_rate: number;
    tax_amount: number;
    total: number;
    deposit_amount: number;
    minimum_deposit: number;
    total_paid: number;
    balance_due: number;
    cancellation_fee: number;

    minimum_deposit_percent: number;
    cancellation_fee_percent: number;

    start_date?: string;
    due_date?: string;
    days_remaining: number;
    completed_at?: string;
    cancelled_at?: string;
    created_at: string;
    updated_at: string;

    progress_percentage: number;
    is_overdue: boolean;

    is_pending: boolean;
    is_active: boolean;
    is_completed: boolean;
    is_cancelled: boolean;
    is_defaulted: boolean;
    is_flexible: boolean;
    is_scheduled: boolean;
    is_fully_paid: boolean;
    can_receive_payment: boolean;

    admin_notes?: string;

    customer?: Customer;
    user?: User;
    warehouse?: Warehouse;
    items: LayawayItem[];
    schedules: LayawaySchedule[];
    next_scheduled_payment?: {
        id: number;
        due_date: string;
        amount_due: number;
        remaining_amount: number;
    };
    order?: Order;
    payments?: Payment[];
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
    label: string;
    items: ActivityItem[];
}

interface Props {
    layaway: Layaway;
    statuses: Status[];
    paymentMethods: PaymentMethod[];
    activityLogs?: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Layaways', href: '/layaways' },
    { title: props.layaway.layaway_number, href: `/layaways/${props.layaway.id}` },
]);

// Modal states
const showPaymentModal = ref(false);
const showCancelModal = ref(false);
const isProcessing = ref(false);

// Status colors and labels
const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800',
    active: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    cancelled: 'bg-gray-100 text-gray-800',
    defaulted: 'bg-red-100 text-red-800',
};

const statusLabels: Record<string, string> = {
    pending: 'Pending Deposit',
    active: 'Active',
    completed: 'Completed',
    cancelled: 'Cancelled',
    defaulted: 'Defaulted',
};

const paymentTypeLabels: Record<string, string> = {
    flexible: 'Flexible Payments',
    scheduled: 'Scheduled Payments',
};

const scheduleStatusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800',
    paid: 'bg-green-100 text-green-800',
    overdue: 'bg-red-100 text-red-800',
};

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatDateTime(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function handleActivate() {
    if (confirm('Activate this layaway? The customer must have paid the minimum deposit.')) {
        router.post(`/layaways/${props.layaway.id}/activate`, {}, {
            preserveScroll: true,
        });
    }
}

function handleComplete() {
    if (confirm('Complete this layaway? An order will be created for the customer.')) {
        router.post(`/layaways/${props.layaway.id}/complete`, {}, {
            preserveScroll: true,
        });
    }
}

function handleCancel() {
    if (confirm('Cancel this layaway? Items will be released back to inventory.')) {
        router.post(`/layaways/${props.layaway.id}/cancel`, {}, {
            preserveScroll: true,
        });
    }
}

function handlePaymentSuccess() {
    showPaymentModal.value = false;
    router.reload({ only: ['layaway'] });
}

// Payment model for CollectPaymentModal
const paymentModel = computed(() => ({
    id: props.layaway.id,
    total: props.layaway.total,
    charge_taxes: props.layaway.tax_rate > 0,
    tax_rate: props.layaway.tax_rate,
    grand_total: props.layaway.total,
    total_paid: props.layaway.total_paid,
    balance_due: props.layaway.balance_due,
}));
</script>

<template>
    <Head :title="`Layaway ${layaway.layaway_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Link
                        href="/layaways"
                        class="rounded-full p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                                {{ layaway.layaway_number }}
                            </h1>
                            <span
                                :class="[
                                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                    statusColors[layaway.status] || 'bg-gray-100 text-gray-800',
                                ]"
                            >
                                {{ statusLabels[layaway.status] || layaway.status }}
                            </span>
                            <span
                                v-if="layaway.is_overdue"
                                class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800"
                            >
                                Overdue
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ paymentTypeLabels[layaway.payment_type] }} &middot; {{ layaway.term_days }} day term
                        </p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-2">
                    <button
                        v-if="layaway.can_receive_payment"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="showPaymentModal = true"
                    >
                        <BanknotesIcon class="-ml-0.5 size-5" />
                        Collect Payment
                    </button>
                    <button
                        v-if="layaway.is_pending && layaway.total_paid >= layaway.minimum_deposit"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                        @click="handleActivate"
                    >
                        <CheckCircleIcon class="-ml-0.5 size-5" />
                        Activate
                    </button>
                    <button
                        v-if="layaway.is_active && layaway.is_fully_paid"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                        @click="handleComplete"
                    >
                        <CheckCircleIcon class="-ml-0.5 size-5" />
                        Complete
                    </button>
                    <button
                        v-if="!layaway.is_completed && !layaway.is_cancelled"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                        @click="handleCancel"
                    >
                        <XCircleIcon class="-ml-0.5 size-5" />
                        Cancel
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main Content -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Progress Card -->
                    <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Payment Progress</h2>
                        <div class="space-y-4">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">Progress</span>
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ Math.round(layaway.progress_percentage) }}%
                                </span>
                            </div>
                            <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-3 rounded-full transition-all duration-300"
                                    :class="layaway.progress_percentage >= 100 ? 'bg-green-500' : 'bg-indigo-600'"
                                    :style="{ width: `${Math.min(100, layaway.progress_percentage)}%` }"
                                />
                            </div>
                            <div class="grid grid-cols-3 gap-4 text-center">
                                <div>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                                        {{ formatCurrency(layaway.total_paid) }}
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Paid</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-orange-600">
                                        {{ formatCurrency(layaway.balance_due) }}
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Remaining</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                                        {{ formatCurrency(layaway.total) }}
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Total</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Items -->
                    <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-medium text-gray-900 dark:text-white">
                                Items ({{ layaway.items.length }})
                            </h2>
                        </div>
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            <div
                                v-for="item in layaway.items"
                                :key="item.id"
                                class="flex items-center gap-4 px-6 py-4"
                            >
                                <div
                                    v-if="item.product?.image"
                                    class="size-16 shrink-0 overflow-hidden rounded-lg bg-gray-100"
                                >
                                    <img :src="item.product.image" :alt="item.title" class="size-full object-cover" />
                                </div>
                                <div v-else class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-gray-100">
                                    <CubeIcon class="size-8 text-gray-400" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                    <p v-if="item.sku" class="text-sm text-gray-500 dark:text-gray-400">
                                        SKU: {{ item.sku }}
                                    </p>
                                    <div class="mt-1 flex items-center gap-2">
                                        <span
                                            v-if="item.is_reserved"
                                            class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800"
                                        >
                                            Reserved
                                        </span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(item.price) }}
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Qty: {{ item.quantity }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Schedule (for scheduled type) -->
                    <div v-if="layaway.is_scheduled && layaway.schedules.length > 0" class="rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Payment Schedule</h2>
                        </div>
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            <div
                                v-for="schedule in layaway.schedules"
                                :key="schedule.id"
                                class="flex items-center justify-between px-6 py-4"
                            >
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white">
                                        Payment {{ schedule.installment_number }}
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Due: {{ formatDate(schedule.due_date) }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(schedule.amount_due) }}
                                    </p>
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            scheduleStatusColors[schedule.status] || 'bg-gray-100 text-gray-800',
                                        ]"
                                    >
                                        {{ schedule.status === 'paid' ? 'Paid' : schedule.is_overdue ? 'Overdue' : 'Pending' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <div v-if="layaway.payments && layaway.payments.length > 0" class="rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Payment History</h2>
                        </div>
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            <div
                                v-for="payment in layaway.payments"
                                :key="payment.id"
                                class="flex items-center justify-between px-6 py-4"
                            >
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white">
                                        {{ formatCurrency(payment.amount) }}
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ payment.payment_method }} &middot;
                                        {{ payment.paid_at ? formatDateTime(payment.paid_at) : 'Pending' }}
                                    </p>
                                </div>
                                <div>
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                            payment.status === 'completed'
                                                ? 'bg-green-100 text-green-800'
                                                : 'bg-yellow-100 text-yellow-800',
                                        ]"
                                    >
                                        {{ payment.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes Section -->
                    <NotesSection
                        :notes="layaway.note_entries"
                        notable-type="App\Models\Layaway"
                        :notable-id="layaway.id"
                    />

                    <!-- Activity Timeline -->
                    <ActivityTimeline v-if="activityLogs" :activity-days="activityLogs" />
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Customer Info -->
                    <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                        <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Customer</h3>
                        <div v-if="layaway.customer" class="space-y-3">
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <UserIcon class="size-5 text-gray-400" />
                                </div>
                                <div>
                                    <Link
                                        :href="`/customers/${layaway.customer.id}`"
                                        class="font-medium text-indigo-600 hover:text-indigo-500"
                                    >
                                        {{ layaway.customer.full_name }}
                                    </Link>
                                    <p v-if="layaway.customer.email" class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ layaway.customer.email }}
                                    </p>
                                </div>
                            </div>
                            <p v-if="layaway.customer.phone" class="text-sm text-gray-600 dark:text-gray-300">
                                {{ layaway.customer.phone }}
                            </p>
                            <div class="mt-2 flex items-center gap-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Lead Source:</span>
                                <span
                                    v-if="layaway.customer.lead_source"
                                    class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30"
                                >
                                    {{ layaway.customer.lead_source.name }}
                                </span>
                                <span v-else class="text-xs text-gray-400 dark:text-gray-500 italic">
                                    Unknown
                                </span>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">No customer assigned</p>
                    </div>

                    <!-- Layaway Details -->
                    <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                        <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Details</h3>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Created</dt>
                                <dd class="text-gray-900 dark:text-white">{{ formatDate(layaway.created_at) }}</dd>
                            </div>
                            <div v-if="layaway.start_date" class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Start Date</dt>
                                <dd class="text-gray-900 dark:text-white">{{ formatDate(layaway.start_date) }}</dd>
                            </div>
                            <div v-if="layaway.due_date" class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Due Date</dt>
                                <dd :class="layaway.is_overdue ? 'font-medium text-red-600' : 'text-gray-900 dark:text-white'">
                                    {{ formatDate(layaway.due_date) }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Days Remaining</dt>
                                <dd :class="layaway.days_remaining <= 7 ? 'font-medium text-orange-600' : 'text-gray-900 dark:text-white'">
                                    {{ layaway.days_remaining }} days
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Created By</dt>
                                <dd class="text-gray-900 dark:text-white">{{ layaway.user?.name || 'Unknown' }}</dd>
                            </div>
                            <div v-if="layaway.warehouse" class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Warehouse</dt>
                                <dd class="text-gray-900 dark:text-white">{{ layaway.warehouse.name }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Terms -->
                    <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                        <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Terms</h3>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Minimum Deposit</dt>
                                <dd class="text-gray-900 dark:text-white">
                                    {{ layaway.minimum_deposit_percent }}% ({{ formatCurrency(layaway.minimum_deposit) }})
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Cancellation Fee</dt>
                                <dd class="text-gray-900 dark:text-white">
                                    {{ layaway.cancellation_fee_percent }}%
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Tax Rate</dt>
                                <dd class="text-gray-900 dark:text-white">
                                    {{ (layaway.tax_rate * 100).toFixed(2) }}%
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Summary -->
                    <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                        <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Summary</h3>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
                                <dd class="text-gray-900 dark:text-white">{{ formatCurrency(layaway.subtotal) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Tax</dt>
                                <dd class="text-gray-900 dark:text-white">{{ formatCurrency(layaway.tax_amount) }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-gray-200 pt-3 dark:border-gray-700">
                                <dt class="font-medium text-gray-900 dark:text-white">Total</dt>
                                <dd class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(layaway.total) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Total Paid</dt>
                                <dd class="text-green-600">{{ formatCurrency(layaway.total_paid) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="font-medium text-gray-900 dark:text-white">Balance Due</dt>
                                <dd class="font-medium" :class="layaway.balance_due > 0 ? 'text-orange-600' : 'text-green-600'">
                                    {{ formatCurrency(layaway.balance_due) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Order Link (if completed) -->
                    <div v-if="layaway.order" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                        <h3 class="mb-4 font-medium text-gray-900 dark:text-white">Linked Order</h3>
                        <Link
                            :href="`/orders/${layaway.order.id}`"
                            class="text-indigo-600 hover:text-indigo-500"
                        >
                            View Order {{ layaway.order.order_id }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modal -->
        <CollectPaymentModal
            v-if="showPaymentModal"
            :show="showPaymentModal"
            model-type="layaway"
            :model="paymentModel"
            :title="`Layaway ${layaway.layaway_number}`"
            :subtitle="layaway.customer?.full_name || ''"
            :show-adjustments="false"
            @close="showPaymentModal = false"
            @success="handlePaymentSuccess"
        />
    </AppLayout>
</template>
