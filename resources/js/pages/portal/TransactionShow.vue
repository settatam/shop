<script setup lang="ts">
import PayoutPreferenceForm from '@/components/portal/PayoutPreferenceForm.vue';
import StatusTimeline from '@/components/portal/StatusTimeline.vue';
import { Spinner } from '@/components/ui/spinner';
import PortalLayout from '@/layouts/portal/PortalLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TransactionItem {
    id: number;
    description: string;
    category: string | null;
    metal_type: string | null;
    weight: number | null;
    dwt: number | null;
    price: number | null;
    buy_price: number | null;
}

interface Offer {
    id: number;
    amount: string;
    status: string;
    admin_notes: string | null;
    customer_response: string | null;
    created_at: string;
}

interface StatusHistory {
    id: number;
    from_status: string | null;
    to_status: string;
    notes: string | null;
    created_at: string;
}

interface Transaction {
    id: number;
    transaction_number: string;
    status: string;
    type: string;
    final_offer: string | null;
    customer_notes: string | null;
    payment_method: string | null;
    payment_details: { method: string; amount: number; details: Record<string, string> }[] | null;
    created_at: string;
    items: TransactionItem[];
    offers: Offer[];
    latest_offer: Offer | null;
    status_histories: StatusHistory[];
}

const props = defineProps<{
    transaction: Transaction;
    statuses: Record<string, string>;
}>();

const showDeclineForm = ref(false);
const declineForm = useForm({
    reason: '',
});

const isAccepting = ref(false);

function acceptOffer() {
    isAccepting.value = true;
    router.post(`/p/transactions/${props.transaction.id}/accept`, {}, {
        onFinish: () => { isAccepting.value = false; },
    });
}

function declineOffer() {
    declineForm.post(`/p/transactions/${props.transaction.id}/decline`, {
        onSuccess: () => {
            showDeclineForm.value = false;
        },
    });
}

function formatCurrency(amount: string | number | null): string {
    if (amount === null || amount === undefined) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(amount));
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
</script>

<template>
    <PortalLayout :title="transaction.transaction_number">
        <Head :title="transaction.transaction_number" />

        <div class="mb-6">
            <button
                @click="$inertia.visit('/p/')"
                class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
            >
                &larr; Back to transactions
            </button>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Main content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Transaction header -->
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-xl font-bold text-gray-900 dark:text-white">
                                {{ transaction.transaction_number }}
                            </h1>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Submitted {{ formatDate(transaction.created_at) }}
                            </p>
                        </div>
                        <span class="inline-flex rounded-full bg-blue-100 px-3 py-1 text-sm font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">
                            {{ statuses[transaction.status] ?? transaction.status }}
                        </span>
                    </div>
                </div>

                <!-- Items -->
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Items</h2>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Description</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Category</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Weight (DWT)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="item in transaction.items" :key="item.id">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-white">
                                        {{ item.description || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                        {{ item.category || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                        {{ item.dwt ?? '-' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Offer card -->
                <div
                    v-if="transaction.latest_offer"
                    class="rounded-lg border-2 p-6"
                    :class="transaction.status === 'offer_given'
                        ? 'border-yellow-400 bg-yellow-50 dark:border-yellow-600 dark:bg-yellow-900/10'
                        : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"
                >
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Offer</h2>
                    <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white">
                        {{ formatCurrency(transaction.latest_offer.amount) }}
                    </p>
                    <p v-if="transaction.latest_offer.admin_notes" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ transaction.latest_offer.admin_notes }}
                    </p>

                    <!-- Accept/Decline buttons (only when offer is pending) -->
                    <div v-if="transaction.status === 'offer_given'" class="mt-6 space-y-3">
                        <button
                            @click="acceptOffer"
                            :disabled="isAccepting"
                            class="flex w-full justify-center rounded-md bg-green-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-green-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Spinner v-if="isAccepting" class="mr-2" />
                            Accept Offer
                        </button>

                        <button
                            v-if="!showDeclineForm"
                            @click="showDeclineForm = true"
                            class="flex w-full justify-center rounded-md border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50 dark:border-red-700 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-gray-700"
                        >
                            Decline Offer
                        </button>

                        <div v-if="showDeclineForm" class="space-y-3">
                            <textarea
                                v-model="declineForm.reason"
                                placeholder="Reason for declining (optional)"
                                rows="3"
                                class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                            />
                            <div class="flex gap-3">
                                <button
                                    @click="declineOffer"
                                    :disabled="declineForm.processing"
                                    class="flex-1 rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <Spinner v-if="declineForm.processing" class="mr-2" />
                                    Confirm Decline
                                </button>
                                <button
                                    @click="showDeclineForm = false"
                                    class="flex-1 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Show status for non-pending offers -->
                    <div v-else-if="transaction.latest_offer.status !== 'pending'" class="mt-4">
                        <span
                            :class="[
                                'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
                                transaction.latest_offer.status === 'accepted'
                                    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                                    : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
                            ]"
                        >
                            {{ transaction.latest_offer.status === 'accepted' ? 'Accepted' : 'Declined' }}
                        </span>
                    </div>
                </div>

                <!-- Payout Preference -->
                <PayoutPreferenceForm
                    :transaction="transaction"
                    :editable="transaction.status === 'offer_accepted' || transaction.status === 'payment_pending'"
                />
            </div>

            <!-- Sidebar: Status Timeline -->
            <div>
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Status Timeline</h2>
                    <StatusTimeline
                        v-if="transaction.status_histories?.length"
                        :histories="transaction.status_histories"
                        :statuses="statuses"
                        :current-status="transaction.status"
                    />
                    <p v-else class="text-sm text-gray-500 dark:text-gray-400">No status history yet.</p>
                </div>
            </div>
        </div>
    </PortalLayout>
</template>
