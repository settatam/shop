<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Pagination from '@/components/widgets/Pagination.vue';
import { type BreadcrumbItem, type PaginatedResponse } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';

interface Customer {
    id: number;
    name: string;
    email: string | null;
}

interface Lead {
    id: number;
    transaction_number: string;
    customer: Customer | null;
    status: string;
    status_label: string;
    final_offer: number | null;
    estimated_value: number | null;
    payment_method: string | null;
    created_at: string;
    created_at_formatted: string;
}

interface StatusCount {
    id: number | null;
    name: string;
    slug: string;
    color: string | null;
    is_final: boolean;
    count: number;
}

interface CurrentStatus {
    id?: number;
    name: string;
    slug: string;
    color?: string | null;
}

const props = defineProps<{
    leads: PaginatedResponse<Lead>;
    currentStatus: CurrentStatus;
    statusCounts: StatusCount[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Leads', href: '/leads' },
    { title: props.currentStatus.name, href: `/leads/status/${props.currentStatus.slug}` },
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

// Filter statuses to show only those with counts > 0
const activeStatuses = props.statusCounts.filter((s) => s.count > 0);
</script>

<template>
    <Head :title="`Leads - ${currentStatus.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 lg:flex-row">
            <!-- Sidebar: Status Navigation -->
            <div class="w-full shrink-0 lg:w-64">
                <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                            Filter by Status
                        </h2>
                    </div>
                    <nav class="max-h-96 overflow-y-auto lg:max-h-none">
                        <Link
                            v-for="status in activeStatuses"
                            :key="status.slug"
                            :href="`/leads/status/${status.slug}`"
                            class="flex items-center justify-between px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700"
                            :class="{
                                'bg-gray-100 dark:bg-gray-700': status.slug === currentStatus.slug,
                            }"
                        >
                            <span class="truncate text-gray-700 dark:text-gray-300">
                                {{ status.name }}
                            </span>
                            <span
                                class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-600 dark:text-gray-300"
                            >
                                {{ status.count }}
                            </span>
                        </Link>
                    </nav>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1">
                <!-- Header -->
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">
                            {{ currentStatus.name }}
                        </h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ leads.meta.total }} lead{{ leads.meta.total === 1 ? '' : 's' }}
                        </p>
                    </div>
                    <Link
                        href="/leads"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                        </svg>
                        Back to Dashboard
                    </Link>
                </div>

                <!-- Leads Table -->
                <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                        Lead #
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                        Customer
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                        Status
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                        Est. Value
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                        Offer
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                        Created
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                <tr
                                    v-for="lead in leads.data"
                                    :key="lead.id"
                                    class="hover:bg-gray-50 dark:hover:bg-gray-700"
                                >
                                    <td class="px-4 py-4 text-sm font-medium whitespace-nowrap">
                                        <Link
                                            :href="`/leads/${lead.id}`"
                                            class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                        >
                                            {{ lead.transaction_number }}
                                        </Link>
                                    </td>
                                    <td class="px-4 py-4 text-sm whitespace-nowrap">
                                        <div v-if="lead.customer">
                                            <div class="font-medium text-gray-900 dark:text-white">
                                                {{ lead.customer.name }}
                                            </div>
                                            <div v-if="lead.customer.email" class="text-gray-500 dark:text-gray-400">
                                                {{ lead.customer.email }}
                                            </div>
                                        </div>
                                        <span v-else class="text-gray-400">-</span>
                                    </td>
                                    <td class="px-4 py-4 text-sm whitespace-nowrap">
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                            :class="getStatusColor(lead.status)"
                                        >
                                            {{ lead.status_label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white">
                                        {{ formatCurrency(lead.estimated_value) }}
                                    </td>
                                    <td class="px-4 py-4 text-right text-sm whitespace-nowrap text-gray-900 dark:text-white">
                                        {{ formatCurrency(lead.final_offer) }}
                                    </td>
                                    <td class="px-4 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                        {{ lead.created_at_formatted }}
                                    </td>
                                    <td class="px-4 py-4 text-right text-sm whitespace-nowrap">
                                        <Link
                                            :href="`/leads/${lead.id}`"
                                            class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                        >
                                            View
                                        </Link>
                                    </td>
                                </tr>

                                <!-- Empty state -->
                                <tr v-if="leads.data.length === 0">
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No leads found with this status.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="leads.meta.last_page > 1" class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                        <Pagination :meta="leads.meta" :links="leads.links" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
