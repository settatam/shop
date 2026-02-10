<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Building2, ArrowRight, Package, ShoppingCart, ExternalLink } from 'lucide-vue-next';

interface TransactionItem {
    id: number;
    customer_name: string;
    status: string;
    status_label: string;
    type: string;
    total_offer: number | null;
    created_at: string;
    created_at_time: string;
}

interface OrderItem {
    id: number;
    invoice_number: string | null;
    customer_name: string;
    status: string;
    status_label: string;
    total: number | null;
    source_platform: string | null;
    created_at: string;
    created_at_time: string;
}

interface StoreData {
    id: number;
    name: string;
    slug: string;
    logo: string | null;
    edition: string;
    is_active: boolean;
    role: string;
    role_label: string;
    transactions: TransactionItem[];
    orders: OrderItem[];
    transactions_by_status: Record<string, number>;
    orders_by_status: Record<string, number>;
    total_transactions: number;
    total_orders: number;
}

interface Summary {
    total_stores: number;
    total_transactions: number;
    total_orders: number;
}

const props = defineProps<{
    stores: StoreData[];
    summary: Summary;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Account', href: '/account' },
    { title: 'Dashboard', href: '/account/dashboard' },
];

function getStatusColor(status: string): string {
    const colors: Record<string, string> = {
        // Transaction statuses
        pending_kit_request: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        kit_request_confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        kit_sent: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
        items_received: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        items_reviewed: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400',
        offer_given: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
        offer_accepted: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        offer_declined: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        payment_processed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        items_returned: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
        pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        // Order statuses
        draft: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
        confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        processing: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
        shipped: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        delivered: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400',
        completed: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        cancelled: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        refunded: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
    };
    return colors[status] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
}

function getEditionBadgeColor(edition: string): string {
    const colors: Record<string, string> = {
        standard: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        simple: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
        client_x: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    };
    return colors[edition] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
}

function formatCurrency(amount: number | null): string {
    if (amount === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
}
</script>

<template>
    <Head title="Account Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 lg:p-8 space-y-8">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Account Overview</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Manage all your stores and view recent activity
                </p>
            </div>

            <!-- Summary Stats -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-blue-100 p-3 dark:bg-blue-900/30">
                            <Building2 class="size-6 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Stores</p>
                            <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ summary.total_stores }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-green-100 p-3 dark:bg-green-900/30">
                            <Package class="size-6 text-green-600 dark:text-green-400" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Transactions</p>
                            <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ summary.total_transactions }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-purple-100 p-3 dark:bg-purple-900/30">
                            <ShoppingCart class="size-6 text-purple-600 dark:text-purple-400" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Orders</p>
                            <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ summary.total_orders }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stores List -->
            <div class="space-y-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Your Stores</h2>

                <div v-for="store in stores" :key="store.id" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <!-- Store Header -->
                    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div v-if="store.logo" class="size-12 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-700">
                                    <img :src="store.logo" :alt="store.name" class="size-full object-cover" />
                                </div>
                                <div v-else class="flex size-12 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700">
                                    <Building2 class="size-6 text-gray-400" />
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ store.name }}</h3>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="getEditionBadgeColor(store.edition)"
                                        >
                                            {{ store.edition }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">
                                            {{ store.role_label }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <Link
                                :href="`/stores/${store.id}/switch`"
                                class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
                            >
                                Go to Dashboard
                                <ArrowRight class="size-4" />
                            </Link>
                        </div>
                    </div>

                    <!-- Store Content -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-gray-200 dark:divide-gray-700">
                        <!-- Recent Transactions -->
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                                    <Package class="size-4" />
                                    Recent Transactions
                                </h4>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ store.total_transactions }} total
                                </span>
                            </div>

                            <div v-if="store.transactions.length > 0" class="space-y-3">
                                <div
                                    v-for="transaction in store.transactions"
                                    :key="transaction.id"
                                    class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-700 last:border-0"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                            {{ transaction.customer_name }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ transaction.created_at }} at {{ transaction.created_at_time }}
                                        </p>
                                    </div>
                                    <div class="ml-4 flex items-center gap-3">
                                        <span v-if="transaction.total_offer" class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ formatCurrency(transaction.total_offer) }}
                                        </span>
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                            :class="getStatusColor(transaction.status)"
                                        >
                                            {{ transaction.status_label }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">
                                No transactions yet
                            </p>

                            <Link
                                v-if="store.transactions.length > 0"
                                :href="`/stores/${store.id}/switch?redirect=/transactions`"
                                class="mt-4 inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                            >
                                View all transactions
                                <ExternalLink class="size-3" />
                            </Link>
                        </div>

                        <!-- Recent Orders -->
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                                    <ShoppingCart class="size-4" />
                                    Recent Orders (Sales)
                                </h4>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ store.total_orders }} total
                                </span>
                            </div>

                            <div v-if="store.orders.length > 0" class="space-y-3">
                                <div
                                    v-for="order in store.orders"
                                    :key="order.id"
                                    class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-700 last:border-0"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                            {{ order.invoice_number || `#${order.id}` }}
                                            <span class="text-gray-500 dark:text-gray-400 font-normal">
                                                - {{ order.customer_name }}
                                            </span>
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ order.created_at }} at {{ order.created_at_time }}
                                            <span v-if="order.source_platform" class="ml-1">
                                                via {{ order.source_platform }}
                                            </span>
                                        </p>
                                    </div>
                                    <div class="ml-4 flex items-center gap-3">
                                        <span v-if="order.total" class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ formatCurrency(order.total) }}
                                        </span>
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                            :class="getStatusColor(order.status)"
                                        >
                                            {{ order.status_label }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">
                                No orders yet
                            </p>

                            <Link
                                v-if="store.orders.length > 0"
                                :href="`/stores/${store.id}/switch?redirect=/orders`"
                                class="mt-4 inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                            >
                                View all orders
                                <ExternalLink class="size-3" />
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-if="stores.length === 0"
                    class="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center dark:border-gray-700"
                >
                    <Building2 class="mx-auto size-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No stores</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        You don't have owner or admin access to any stores.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
