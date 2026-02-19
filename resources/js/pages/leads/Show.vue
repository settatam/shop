<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';

interface Customer {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone_number: string | null;
}

interface TransactionItem {
    id: number;
    title: string;
    description: string | null;
    metal_type: string | null;
    weight: number | null;
    buy_price: number | null;
}

interface StatusTransition {
    id: number;
    name: string;
    slug: string;
    color: string | null;
}

interface StatusHistoryItem {
    id: number;
    from_status: string | null;
    to_status: string;
    user: string | null;
    notes: string | null;
    created_at: string;
}

interface Lead {
    id: number;
    transaction_number: string;
    status: string;
    status_label: string;
    type: string;
    customer: Customer | null;
    items: TransactionItem[];
    final_offer: number | null;
    estimated_value: number | null;
    payment_method: string | null;
    outbound_tracking_number: string | null;
    outbound_carrier: string | null;
    return_tracking_number: string | null;
    return_carrier: string | null;
    kit_sent_at: string | null;
    kit_delivered_at: string | null;
    items_received_at: string | null;
    offer_given_at: string | null;
    offer_accepted_at: string | null;
    payment_processed_at: string | null;
    created_at: string;
    created_at_formatted: string;
}

const props = defineProps<{
    lead: { data: Lead };
    availableTransitions: StatusTransition[];
    statusHistory: StatusHistoryItem[];
}>();

const lead = computed(() => props.lead.data);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Leads', href: '/leads' },
    { title: lead.value.transaction_number, href: `/leads/${lead.value.id}` },
];

function formatCurrency(value: number | null): string {
    if (value === null) return '-';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function getStatusColor(slug: string): string {
    const colorMap: Record<string, string> = {
        pending_kit_request: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        kit_request_confirmed: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
        kit_sent: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        kit_delivered: 'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-400',
        items_received: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400',
        items_reviewed: 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400',
        offer_given: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        offer_accepted: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        offer_declined: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        payment_pending: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
        payment_processed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
        items_returned: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
        kit_request_rejected: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        kit_request_on_hold: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
    };
    return colorMap[slug] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
}

// Status transition modal
const showTransitionModal = ref(false);
const selectedTransition = ref<StatusTransition | null>(null);
const transitionNotes = ref('');
const isTransitioning = ref(false);

function openTransitionModal(transition: StatusTransition) {
    selectedTransition.value = transition;
    transitionNotes.value = '';
    showTransitionModal.value = true;
}

function performTransition() {
    if (!selectedTransition.value) return;

    isTransitioning.value = true;

    router.post(
        `/transactions/${lead.value.id}/change-status`,
        {
            status: selectedTransition.value.slug,
            notes: transitionNotes.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showTransitionModal.value = false;
                selectedTransition.value = null;
                transitionNotes.value = '';
            },
            onFinish: () => {
                isTransitioning.value = false;
            },
        },
    );
}

// Check if this lead has been converted to a buy
const isConverted = computed(
    () => lead.value.status === 'payment_processed',
);
</script>

<template>
    <Head :title="`Lead ${lead.transaction_number}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                            {{ lead.transaction_number }}
                        </h1>
                        <span
                            class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium"
                            :class="getStatusColor(lead.status)"
                        >
                            {{ lead.status_label }}
                        </span>
                        <span
                            v-if="isConverted"
                            class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400"
                        >
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Converted to Buy
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Created {{ lead.created_at_formatted }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        :href="`/leads/status/${lead.status}`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                        </svg>
                        Back to List
                    </Link>
                    <Link
                        :href="`/transactions/${lead.id}`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        View Full Details
                    </Link>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Main Content -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Customer Info -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Customer
                            </h2>
                        </div>
                        <div class="p-4">
                            <div v-if="lead.customer" class="flex items-start gap-4">
                                <div class="flex size-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                    <svg class="size-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                    </svg>
                                </div>
                                <div>
                                    <Link
                                        :href="`/customers/${lead.customer.id}`"
                                        class="text-lg font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    >
                                        {{ lead.customer.full_name }}
                                    </Link>
                                    <p v-if="lead.customer.email" class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ lead.customer.email }}
                                    </p>
                                    <p v-if="lead.customer.phone_number" class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ lead.customer.phone_number }}
                                    </p>
                                </div>
                            </div>
                            <p v-else class="text-sm text-gray-500 dark:text-gray-400">
                                No customer assigned
                            </p>
                        </div>
                    </div>

                    <!-- Items -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Items ({{ lead.items.length }})
                            </h2>
                        </div>
                        <ul v-if="lead.items.length > 0" class="divide-y divide-gray-200 dark:divide-gray-700">
                            <li v-for="item in lead.items" :key="item.id" class="p-4">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">
                                            {{ item.title }}
                                        </p>
                                        <p v-if="item.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            {{ item.description }}
                                        </p>
                                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500 dark:text-gray-400">
                                            <span v-if="item.metal_type">{{ item.metal_type }}</span>
                                            <span v-if="item.weight">{{ item.weight }}g</span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-medium text-gray-900 dark:text-white">
                                            {{ formatCurrency(item.buy_price) }}
                                        </p>
                                    </div>
                                </div>
                            </li>
                        </ul>
                        <div v-else class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            No items added yet
                        </div>
                    </div>

                    <!-- Tracking Info -->
                    <div v-if="lead.outbound_tracking_number || lead.return_tracking_number" class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Shipping & Tracking
                            </h2>
                        </div>
                        <div class="p-4 space-y-4">
                            <div v-if="lead.outbound_tracking_number">
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Outbound Kit
                                </p>
                                <p class="text-sm text-gray-900 dark:text-white">
                                    {{ lead.outbound_carrier?.toUpperCase() || 'N/A' }}: {{ lead.outbound_tracking_number }}
                                </p>
                                <p v-if="lead.kit_sent_at" class="text-xs text-gray-500 dark:text-gray-400">
                                    Sent: {{ lead.kit_sent_at }}
                                </p>
                                <p v-if="lead.kit_delivered_at" class="text-xs text-green-600 dark:text-green-400">
                                    Delivered: {{ lead.kit_delivered_at }}
                                </p>
                            </div>
                            <div v-if="lead.return_tracking_number">
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Return Shipment
                                </p>
                                <p class="text-sm text-gray-900 dark:text-white">
                                    {{ lead.return_carrier?.toUpperCase() || 'N/A' }}: {{ lead.return_tracking_number }}
                                </p>
                                <p v-if="lead.items_received_at" class="text-xs text-green-600 dark:text-green-400">
                                    Received: {{ lead.items_received_at }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Status History -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Status History
                            </h2>
                        </div>
                        <div v-if="statusHistory.length > 0" class="p-4">
                            <ul class="space-y-4">
                                <li v-for="(event, index) in statusHistory" :key="event.id" class="relative flex gap-4">
                                    <div class="relative flex flex-col items-center">
                                        <div class="flex size-8 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/30">
                                            <svg class="size-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                        </div>
                                        <div
                                            v-if="index < statusHistory.length - 1"
                                            class="absolute top-8 h-full w-px bg-gray-200 dark:bg-gray-700"
                                        ></div>
                                    </div>
                                    <div class="flex-1 pb-4">
                                        <p class="font-medium text-gray-900 dark:text-white">
                                            {{ event.to_status }}
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ event.created_at }}
                                            <span v-if="event.user"> by {{ event.user }}</span>
                                        </p>
                                        <p v-if="event.notes" class="mt-1 text-sm text-gray-600 dark:text-gray-300">
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

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Summary -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Summary
                            </h2>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-500 dark:text-gray-400">Estimated Value</span>
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(lead.estimated_value) }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-500 dark:text-gray-400">Final Offer</span>
                                <span class="font-medium text-gray-900 dark:text-white">
                                    {{ formatCurrency(lead.final_offer) }}
                                </span>
                            </div>
                            <div v-if="lead.payment_method" class="flex justify-between">
                                <span class="text-sm text-gray-500 dark:text-gray-400">Payment Method</span>
                                <span class="font-medium text-gray-900 dark:text-white capitalize">
                                    {{ lead.payment_method.replace('_', ' ') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div v-if="availableTransitions.length > 0 && !isConverted" class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Actions
                            </h2>
                        </div>
                        <div class="p-4 space-y-2">
                            <button
                                v-for="transition in availableTransitions"
                                :key="transition.id"
                                type="button"
                                class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="openTransitionModal(transition)"
                            >
                                Move to {{ transition.name }}
                            </button>
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Quick Links
                            </h2>
                        </div>
                        <div class="p-4 space-y-2">
                            <Link
                                :href="`/transactions/${lead.id}`"
                                class="block w-full rounded-md bg-gray-100 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                            >
                                Full Transaction Details
                            </Link>
                            <Link
                                :href="`/transactions/${lead.id}/edit`"
                                class="block w-full rounded-md bg-gray-100 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                            >
                                Edit Transaction
                            </Link>
                            <Link
                                v-if="lead.customer"
                                :href="`/customers/${lead.customer.id}`"
                                class="block w-full rounded-md bg-gray-100 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                            >
                                View Customer
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Transition Modal -->
        <TransitionRoot as="template" :show="showTransitionModal">
            <Dialog class="relative z-50" @close="showTransitionModal = false">
                <TransitionChild
                    enter="ease-out duration-300"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-in duration-200"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                </TransitionChild>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <TransitionChild
                            enter="ease-out duration-300"
                            enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                            enter-to="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200"
                            leave-from="opacity-100 translate-y-0 sm:scale-100"
                            leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel class="relative w-full transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                <div>
                                    <DialogTitle class="text-lg font-semibold text-gray-900 dark:text-white">
                                        Move to {{ selectedTransition?.name }}
                                    </DialogTitle>
                                    <div class="mt-4">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Notes (optional)
                                        </label>
                                        <textarea
                                            v-model="transitionNotes"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            placeholder="Add any notes about this status change..."
                                        ></textarea>
                                    </div>
                                </div>
                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:col-start-2"
                                        :disabled="isTransitioning"
                                        @click="performTransition"
                                    >
                                        {{ isTransitioning ? 'Moving...' : 'Confirm' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 sm:col-start-1 sm:mt-0 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showTransitionModal = false"
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
