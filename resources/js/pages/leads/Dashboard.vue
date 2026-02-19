<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface StatusCount {
    id: number | null;
    name: string;
    slug: string;
    color: string | null;
    is_final: boolean;
    count: number;
}

interface Summary {
    active_leads: number;
    total_converted: number;
    total_converted_value: number;
    potential_value: number;
}

const props = defineProps<{
    statusCounts: StatusCount[];
    summary: Summary;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Leads', href: '/leads' },
];

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}

// Group statuses into active (clickable) and final
const activeStatuses = computed(() =>
    props.statusCounts.filter((s) => !s.is_final && s.count > 0),
);

const finalStatuses = computed(() =>
    props.statusCounts.filter((s) => s.is_final && s.count > 0),
);

const totalActiveLeads = computed(() =>
    activeStatuses.value.reduce((sum, s) => sum + s.count, 0),
);

function getStatusColor(status: StatusCount): string {
    if (status.color) {
        return status.color;
    }
    // Default colors based on status slug
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
    return colorMap[status.slug] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
}
</script>

<template>
    <Head title="Leads Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                        Leads Dashboard
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Track online transactions from kit request to payment
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Link
                        href="/reports/leads"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        View Reports
                    </Link>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="flex size-12 items-center justify-center rounded-md bg-blue-100 dark:bg-blue-900/30">
                                    <svg class="size-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">
                                        Active Leads
                                    </dt>
                                    <dd class="text-lg font-semibold text-gray-900 dark:text-white">
                                        {{ totalActiveLeads.toLocaleString() }}
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="flex size-12 items-center justify-center rounded-md bg-green-100 dark:bg-green-900/30">
                                    <svg class="size-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">
                                        Converted to Buys
                                    </dt>
                                    <dd class="text-lg font-semibold text-gray-900 dark:text-white">
                                        {{ summary.total_converted.toLocaleString() }}
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="flex size-12 items-center justify-center rounded-md bg-emerald-100 dark:bg-emerald-900/30">
                                    <svg class="size-6 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">
                                        Converted Value
                                    </dt>
                                    <dd class="text-lg font-semibold text-gray-900 dark:text-white">
                                        {{ formatCurrency(summary.total_converted_value) }}
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="shrink-0">
                                <div class="flex size-12 items-center justify-center rounded-md bg-yellow-100 dark:bg-yellow-900/30">
                                    <svg class="size-6 text-yellow-600 dark:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">
                                        Potential Value
                                    </dt>
                                    <dd class="text-lg font-semibold text-gray-900 dark:text-white">
                                        {{ formatCurrency(summary.potential_value) }}
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status List -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Leads by Status
                    </h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Click on a status to view leads in that stage
                    </p>
                </div>

                <!-- Active Statuses -->
                <ul role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
                    <li v-for="status in activeStatuses" :key="status.slug">
                        <Link
                            :href="`/leads/status/${status.slug}`"
                            class="flex items-center justify-between px-4 py-4 hover:bg-gray-50 dark:hover:bg-gray-700"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="getStatusColor(status)"
                                >
                                    {{ status.name }}
                                </span>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ status.count.toLocaleString() }}
                                </span>
                                <svg class="size-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </div>
                        </Link>
                    </li>

                    <!-- Empty state -->
                    <li v-if="activeStatuses.length === 0" class="px-4 py-8 text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            No active leads at this time.
                        </p>
                    </li>
                </ul>
            </div>

            <!-- Final Statuses (Completed/Closed) -->
            <div v-if="finalStatuses.length > 0" class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Completed / Closed
                    </h2>
                </div>

                <ul role="list" class="divide-y divide-gray-200 dark:divide-gray-700">
                    <li v-for="status in finalStatuses" :key="status.slug">
                        <Link
                            :href="`/leads/status/${status.slug}`"
                            class="flex items-center justify-between px-4 py-4 hover:bg-gray-50 dark:hover:bg-gray-700"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="getStatusColor(status)"
                                >
                                    {{ status.name }}
                                </span>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="text-lg font-semibold text-gray-500 dark:text-gray-400">
                                    {{ status.count.toLocaleString() }}
                                </span>
                                <svg class="size-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </div>
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
