<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeftIcon } from '@heroicons/vue/20/solid';
import { BanknotesIcon } from '@heroicons/vue/24/outline';

interface StoreCreditEntry {
    id: number;
    type: 'credit' | 'debit';
    amount: string;
    balance_after: string;
    source: string;
    payout_method: string | null;
    description: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
}

interface PaginatedCredits {
    data: StoreCreditEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface PayoutMethod {
    value: string;
    label: string;
}

interface Customer {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    store_credit_balance: string;
}

interface Props {
    customer: Customer;
    credits: PaginatedCredits;
    payoutMethods: PayoutMethod[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: props.customer.full_name || 'Customer', href: `/customers/${props.customer.id}` },
    { title: 'Store Credits', href: `/customers/${props.customer.id}/store-credits` },
];

const showCashOutModal = ref(false);

const cashOutForm = useForm({
    amount: '',
    payout_method: 'cash',
    notes: '',
});

const openCashOut = () => {
    cashOutForm.amount = props.customer.store_credit_balance;
    cashOutForm.payout_method = 'cash';
    cashOutForm.notes = '';
    showCashOutModal.value = true;
};

const submitCashOut = () => {
    cashOutForm.post(`/customers/${props.customer.id}/store-credits/cash-out`, {
        preserveScroll: true,
        onSuccess: () => {
            showCashOutModal.value = false;
            cashOutForm.reset();
        },
    });
};

const formatCurrency = (value: string | number | null) => {
    if (value === null || value === undefined) return '$0.00';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value));
};

const formatDateTime = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const sourceLabels: Record<string, string> = {
    buy_transaction: 'Buy Transaction',
    order_payment: 'Order Payment',
    cash_out: 'Cash Out',
    refund: 'Refund',
    manual: 'Manual Adjustment',
};

const payoutMethodLabels: Record<string, string> = {
    cash: 'Cash',
    check: 'Check',
    paypal: 'PayPal',
    venmo: 'Venmo',
    ach: 'ACH',
    wire_transfer: 'Wire Transfer',
};
</script>

<template>
    <Head :title="`Store Credits - ${customer.full_name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-4">
                    <Link
                        :href="`/customers/${customer.id}`"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                            Store Credits
                        </h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ customer.full_name }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Balance -->
                    <div class="text-right">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Available Balance</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                            {{ formatCurrency(customer.store_credit_balance) }}
                        </p>
                    </div>

                    <!-- Cash Out Button -->
                    <button
                        v-if="Number(customer.store_credit_balance) > 0"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="openCashOut"
                    >
                        <BanknotesIcon class="-ml-0.5 size-5" />
                        Cash Out
                    </button>
                </div>
            </div>

            <!-- Credit Ledger Table -->
            <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <div class="px-4 py-5 sm:p-6">
                    <div v-if="credits.data.length > 0" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Date</th>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Type</th>
                                    <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Amount</th>
                                    <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Balance</th>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Source</th>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Payout Method</th>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Description</th>
                                    <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="entry in credits.data" :key="entry.id">
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                        {{ formatDateTime(entry.created_at) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm">
                                        <span
                                            :class="[
                                                'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset',
                                                entry.type === 'credit'
                                                    ? 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400'
                                                    : 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400',
                                            ]"
                                        >
                                            {{ entry.type === 'credit' ? 'Credit' : 'Debit' }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-right"
                                        :class="entry.type === 'credit' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                    >
                                        {{ entry.type === 'credit' ? '+' : '-' }}{{ formatCurrency(entry.amount) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900 dark:text-white text-right">
                                        {{ formatCurrency(entry.balance_after) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                        {{ sourceLabels[entry.source] || entry.source }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                        {{ entry.payout_method ? (payoutMethodLabels[entry.payout_method] || entry.payout_method) : '-' }}
                                    </td>
                                    <td class="px-3 py-4 text-sm text-gray-500 dark:text-gray-300 max-w-xs truncate">
                                        {{ entry.description || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                        {{ entry.user?.name || '-' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic py-8 text-center">
                        No store credit history yet.
                    </p>

                    <!-- Pagination -->
                    <div v-if="credits.last_page > 1" class="mt-4 flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Showing page {{ credits.current_page }} of {{ credits.last_page }} ({{ credits.total }} entries)
                        </p>
                        <nav class="flex gap-1">
                            <Link
                                v-for="link in credits.links"
                                :key="link.label"
                                :href="link.url || '#'"
                                :class="[
                                    'px-3 py-1 text-sm rounded-md',
                                    link.active
                                        ? 'bg-indigo-600 text-white'
                                        : link.url
                                            ? 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'
                                            : 'text-gray-400 cursor-not-allowed',
                                ]"
                                v-html="link.label"
                                preserve-scroll
                            />
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cash Out Modal -->
        <Teleport to="body">
            <div v-if="showCashOutModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showCashOutModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Cash Out Store Credit</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Available balance: <span class="font-semibold text-gray-900 dark:text-white">{{ formatCurrency(customer.store_credit_balance) }}</span>
                        </p>
                        <form @submit.prevent="submitCashOut" class="space-y-4">
                            <div>
                                <label for="cashout_amount" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                                <div class="relative mt-1">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                        <span class="text-gray-500 sm:text-sm">$</span>
                                    </div>
                                    <input
                                        id="cashout_amount"
                                        v-model="cashOutForm.amount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        :max="Number(customer.store_credit_balance)"
                                        required
                                        class="block w-full rounded-md border-0 py-1.5 pl-7 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <p v-if="cashOutForm.errors.amount" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ cashOutForm.errors.amount }}
                                </p>
                            </div>
                            <div>
                                <label for="cashout_method" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Payout Method</label>
                                <select
                                    id="cashout_method"
                                    v-model="cashOutForm.payout_method"
                                    required
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option v-for="method in payoutMethods" :key="method.value" :value="method.value">
                                        {{ method.label }}
                                    </option>
                                </select>
                                <p v-if="cashOutForm.errors.payout_method" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ cashOutForm.errors.payout_method }}
                                </p>
                            </div>
                            <div>
                                <label for="cashout_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (optional)</label>
                                <textarea
                                    id="cashout_notes"
                                    v-model="cashOutForm.notes"
                                    rows="2"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="flex gap-3 justify-end pt-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showCashOutModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="cashOutForm.processing"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {{ cashOutForm.processing ? 'Processing...' : 'Cash Out' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
