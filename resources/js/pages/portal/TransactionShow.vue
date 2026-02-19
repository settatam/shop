<script setup lang="ts">
import PayoutPreferenceForm from '@/components/portal/PayoutPreferenceForm.vue';
import StatusTimeline from '@/components/portal/StatusTimeline.vue';
import { Spinner } from '@/components/ui/spinner';
import PortalLayout from '@/layouts/portal/PortalLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    CheckCircleIcon,
    StarIcon,
    SparklesIcon,
    CheckBadgeIcon,
    ClockIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';

interface ItemImage {
    id: number;
    url: string;
    thumbnail_url: string;
}

interface TransactionItem {
    id: number;
    title: string;
    description: string | null;
    category: { id: number; name: string } | null;
    price: number | null;
    buy_price: number | null;
    images: ItemImage[];
}

interface Offer {
    id: number;
    amount: string;
    status: string;
    tier: string | null;
    tier_label: string | null;
    reasoning: string | null;
    images: number[] | null;
    expires_at: string | null;
    expires_at_formatted: string | null;
    is_expired: boolean;
    admin_notes: string | null;
    customer_response: string | null;
    created_at: string;
}

interface PendingOffer {
    id: number;
    amount: number;
    tier: string | null;
    tier_label: string | null;
    reasoning: string | null;
    images: number[] | null;
    expires_at: string | null;
    expires_at_formatted: string | null;
    is_expired: boolean;
    created_at: string;
}

interface Attachment {
    id: number;
    url: string;
    thumbnail_url: string;
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
    pending_offers: PendingOffer[];
    has_multi_offers: boolean;
    attachments: Attachment[];
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
const acceptingOfferId = ref<number | null>(null);
const showImageModal = ref(false);
const selectedImageUrl = ref<string | null>(null);

const hasMultipleOffers = computed(() => {
    return props.transaction.pending_offers && props.transaction.pending_offers.length > 1;
});

const allImages = computed(() => {
    const images: { id: number; url: string; thumbnail_url: string; title: string }[] = [];

    // Item images
    for (const item of props.transaction.items) {
        for (const img of item.images) {
            images.push({ ...img, title: item.title });
        }
    }

    // Attachments
    for (const att of props.transaction.attachments || []) {
        images.push({ ...att, title: 'Attachment' });
    }

    return images;
});

function acceptOffer() {
    isAccepting.value = true;
    router.post(`/p/transactions/${props.transaction.id}/accept`, {}, {
        onFinish: () => { isAccepting.value = false; },
    });
}

function acceptSpecificOffer(offerId: number) {
    acceptingOfferId.value = offerId;
    router.post(`/p/transactions/${props.transaction.id}/accept-offer`, {
        offer_id: offerId,
    }, {
        onFinish: () => { acceptingOfferId.value = null; },
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

function getTierIcon(tier: string | null) {
    switch (tier) {
        case 'best': return CheckBadgeIcon;
        case 'better': return SparklesIcon;
        case 'good': return StarIcon;
        default: return CheckCircleIcon;
    }
}

function getTierColor(tier: string | null) {
    switch (tier) {
        case 'best': return 'text-emerald-500 bg-emerald-100 dark:bg-emerald-900/30';
        case 'better': return 'text-blue-500 bg-blue-100 dark:bg-blue-900/30';
        case 'good': return 'text-gray-500 bg-gray-100 dark:bg-gray-700';
        default: return 'text-gray-500 bg-gray-100 dark:bg-gray-700';
    }
}

function openImage(url: string) {
    selectedImageUrl.value = url;
    showImageModal.value = true;
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

                <!-- Items with Images -->
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Your Items</h2>

                    <div v-if="transaction.items.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                        No items yet.
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="item in transaction.items"
                            :key="item.id"
                            class="flex gap-4 p-4 rounded-lg bg-gray-50 dark:bg-gray-900/50"
                        >
                            <!-- Item Images -->
                            <div v-if="item.images.length > 0" class="flex gap-2 shrink-0">
                                <button
                                    v-for="img in item.images.slice(0, 3)"
                                    :key="img.id"
                                    type="button"
                                    class="size-16 rounded-md overflow-hidden hover:opacity-80 transition-opacity"
                                    @click="openImage(img.url)"
                                >
                                    <img :src="img.thumbnail_url" :alt="item.title" class="w-full h-full object-cover" />
                                </button>
                                <div
                                    v-if="item.images.length > 3"
                                    class="size-16 rounded-md bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    +{{ item.images.length - 3 }}
                                </div>
                            </div>
                            <div v-else class="size-16 rounded-md bg-gray-200 dark:bg-gray-700 flex items-center justify-center shrink-0">
                                <svg class="size-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>

                            <!-- Item Details -->
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-gray-900 dark:text-white">
                                    {{ item.title || 'Untitled Item' }}
                                </p>
                                <p v-if="item.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400 line-clamp-2">
                                    {{ item.description }}
                                </p>
                                <p v-if="item.category" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                    {{ item.category.name }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Multi-Offer Selection (when multiple offers exist) -->
                <div
                    v-if="hasMultipleOffers && transaction.status === 'offer_given'"
                    class="rounded-lg border-2 border-emerald-400 bg-emerald-50 p-6 dark:border-emerald-600 dark:bg-emerald-900/10"
                >
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Choose Your Offer</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                        We've prepared multiple offer options for you. Select the one that works best.
                    </p>

                    <div class="space-y-4">
                        <div
                            v-for="offer in transaction.pending_offers"
                            :key="offer.id"
                            class="rounded-lg border bg-white p-4 dark:bg-gray-800"
                            :class="offer.is_expired ? 'border-gray-300 dark:border-gray-600 opacity-60' : 'border-gray-200 dark:border-gray-700'"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2 rounded-full" :class="getTierColor(offer.tier)">
                                        <component :is="getTierIcon(offer.tier)" class="size-5" />
                                    </div>
                                    <div>
                                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                                            {{ formatCurrency(offer.amount) }}
                                        </p>
                                        <p v-if="offer.tier_label" class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ offer.tier_label }} Offer
                                        </p>
                                    </div>
                                </div>

                                <button
                                    v-if="!offer.is_expired"
                                    :disabled="acceptingOfferId === offer.id"
                                    class="shrink-0 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    @click="acceptSpecificOffer(offer.id)"
                                >
                                    <Spinner v-if="acceptingOfferId === offer.id" class="mr-2" />
                                    {{ acceptingOfferId === offer.id ? 'Accepting...' : 'Accept' }}
                                </button>
                                <span v-else class="shrink-0 inline-flex items-center gap-1 text-sm text-red-600 dark:text-red-400">
                                    <ExclamationTriangleIcon class="size-4" />
                                    Expired
                                </span>
                            </div>

                            <!-- Reasoning -->
                            <p v-if="offer.reasoning" class="mt-4 text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">
                                {{ offer.reasoning }}
                            </p>

                            <!-- Expiration -->
                            <div v-if="offer.expires_at_formatted && !offer.is_expired" class="mt-3 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400">
                                <ClockIcon class="size-4" />
                                Expires {{ offer.expires_at_formatted }}
                            </div>
                        </div>
                    </div>

                    <!-- Decline All -->
                    <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-4">
                        <button
                            v-if="!showDeclineForm"
                            @click="showDeclineForm = true"
                            class="text-sm text-red-600 hover:text-red-500 dark:text-red-400"
                        >
                            Decline all offers
                        </button>

                        <div v-if="showDeclineForm" class="space-y-3">
                            <textarea
                                v-model="declineForm.reason"
                                placeholder="Reason for declining (optional)"
                                rows="3"
                                class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700"
                            />
                            <div class="flex gap-3">
                                <button
                                    @click="declineOffer"
                                    :disabled="declineForm.processing"
                                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
                                >
                                    <Spinner v-if="declineForm.processing" class="mr-2" />
                                    Confirm Decline
                                </button>
                                <button
                                    @click="showDeclineForm = false"
                                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Single Offer Card (when only one offer) -->
                <div
                    v-else-if="transaction.latest_offer"
                    class="rounded-lg border-2 p-6"
                    :class="transaction.status === 'offer_given'
                        ? 'border-yellow-400 bg-yellow-50 dark:border-yellow-600 dark:bg-yellow-900/10'
                        : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"
                >
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Offer</h2>
                    <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white">
                        {{ formatCurrency(transaction.latest_offer.amount) }}
                    </p>

                    <!-- Reasoning from offer -->
                    <p v-if="transaction.latest_offer.reasoning" class="mt-4 text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">
                        {{ transaction.latest_offer.reasoning }}
                    </p>
                    <p v-else-if="transaction.latest_offer.admin_notes" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
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

        <!-- Image Modal -->
        <Teleport to="body">
            <div
                v-if="showImageModal && selectedImageUrl"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/80"
                @click="showImageModal = false"
            >
                <button
                    type="button"
                    class="absolute top-4 right-4 text-white hover:text-gray-300"
                    @click="showImageModal = false"
                >
                    <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <img
                    :src="selectedImageUrl"
                    alt="Item"
                    class="max-h-[90vh] max-w-[90vw] object-contain"
                    @click.stop
                />
            </div>
        </Teleport>
    </PortalLayout>
</template>
