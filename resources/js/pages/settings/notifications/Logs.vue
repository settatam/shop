<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Deferred } from '@inertiajs/vue3';
import {
    FunnelIcon,
    MagnifyingGlassIcon,
    CheckCircleIcon,
    XCircleIcon,
    ClockIcon,
    PaperAirplaneIcon,
    ExclamationTriangleIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    ArrowPathIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface NotificationLog {
    id: number;
    recipient: string;
    channel: string;
    activity: string | null;
    status: string;
    subject: string | null;
    content: string | null;
    error_message: string | null;
    metadata: Record<string, unknown> | null;
    sent_at: string | null;
    created_at: string;
    template?: {
        id: number;
        name: string;
        channel: string;
    } | null;
    subscription?: {
        id: number;
        activity: string;
        name: string | null;
    } | null;
}

interface Props {
    logs?: NotificationLog[];
    channelTypes: string[];
    statusTypes: string[];
    filters?: {
        search?: string;
        channel?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
    };
    pagination?: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Notifications',
        href: '/settings/notifications',
    },
    {
        title: 'Logs',
        href: '/settings/notifications/logs',
    },
];

// Filter state
const search = ref(props.filters?.search || '');
const selectedChannel = ref(props.filters?.channel || '');
const selectedStatus = ref(props.filters?.status || '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const showFilters = ref(false);
const expandedLogId = ref<number | null>(null);

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

function applyFilters() {
    router.get('/settings/notifications/logs', {
        search: search.value || undefined,
        channel: selectedChannel.value || undefined,
        status: selectedStatus.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    search.value = '';
    selectedChannel.value = '';
    selectedStatus.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    router.get('/settings/notifications/logs', {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function handleSearchInput() {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
}

function toggleExpand(logId: number) {
    expandedLogId.value = expandedLogId.value === logId ? null : logId;
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'sent':
        case 'delivered':
            return 'text-green-600 dark:text-green-400';
        case 'failed':
        case 'bounced':
            return 'text-red-600 dark:text-red-400';
        case 'pending':
            return 'text-yellow-600 dark:text-yellow-400';
        default:
            return 'text-gray-600 dark:text-gray-400';
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'sent':
            return PaperAirplaneIcon;
        case 'delivered':
            return CheckCircleIcon;
        case 'failed':
            return XCircleIcon;
        case 'bounced':
            return ExclamationTriangleIcon;
        case 'pending':
            return ClockIcon;
        default:
            return ClockIcon;
    }
}

function getStatusBadgeClass(status: string): string {
    switch (status) {
        case 'sent':
        case 'delivered':
            return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
        case 'failed':
        case 'bounced':
            return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
        case 'pending':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
}

function getChannelBadgeClass(channel: string): string {
    switch (channel) {
        case 'email':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
        case 'sms':
            return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
        case 'slack':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
        case 'webhook':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
}

function formatDate(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString();
}

function formatDateShort(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now.getTime() - date.getTime();

    if (diff < 60000) {
        return 'Just now';
    } else if (diff < 3600000) {
        const mins = Math.floor(diff / 60000);
        return `${mins}m ago`;
    } else if (diff < 86400000) {
        const hours = Math.floor(diff / 3600000);
        return `${hours}h ago`;
    } else if (diff < 604800000) {
        const days = Math.floor(diff / 86400000);
        return `${days}d ago`;
    }

    return date.toLocaleDateString();
}

const hasActiveFilters = computed(() => {
    return search.value || selectedChannel.value || selectedStatus.value || dateFrom.value || dateTo.value;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Notification logs" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Notification Logs"
                        description="View history of sent notifications"
                    />
                    <Button variant="outline" size="sm" @click="applyFilters">
                        <ArrowPathIcon class="mr-1.5 h-4 w-4" />
                        Refresh
                    </Button>
                </div>

                <!-- Navigation Tabs -->
                <div class="border-b border-gray-200 dark:border-white/10">
                    <nav class="-mb-px flex space-x-8">
                        <Link
                            href="/settings/notifications"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Overview
                        </Link>
                        <Link
                            href="/settings/notifications/templates"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Templates
                        </Link>
                        <Link
                            href="/settings/notifications/subscriptions"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Triggers
                        </Link>
                        <Link
                            href="/settings/notifications/channels"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Channels
                        </Link>
                        <Link
                            href="/settings/notifications/logs"
                            class="border-b-2 border-indigo-500 px-1 pb-4 text-sm font-medium text-indigo-600 dark:text-indigo-400"
                        >
                            Logs
                        </Link>
                    </nav>
                </div>

                <!-- Search and Filters -->
                <div class="space-y-4">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                        <div class="relative flex-1">
                            <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <Input
                                v-model="search"
                                type="text"
                                placeholder="Search by recipient..."
                                class="pl-9"
                                @input="handleSearchInput"
                            />
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="showFilters = !showFilters"
                            :class="{ 'bg-indigo-50 dark:bg-indigo-900/20': hasActiveFilters }"
                        >
                            <FunnelIcon class="mr-1.5 h-4 w-4" />
                            Filters
                            <span v-if="hasActiveFilters" class="ml-1.5 rounded-full bg-indigo-600 px-1.5 text-xs text-white">
                                !
                            </span>
                        </Button>
                    </div>

                    <!-- Filter panel -->
                    <div v-if="showFilters" class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Channel</label>
                                <select
                                    v-model="selectedChannel"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-900 dark:text-white dark:ring-white/10 sm:text-sm sm:leading-6"
                                    @change="applyFilters"
                                >
                                    <option value="">All channels</option>
                                    <option v-for="channel in channelTypes" :key="channel" :value="channel">
                                        {{ channel }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                                <select
                                    v-model="selectedStatus"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-900 dark:text-white dark:ring-white/10 sm:text-sm sm:leading-6"
                                    @change="applyFilters"
                                >
                                    <option value="">All statuses</option>
                                    <option v-for="status in statusTypes" :key="status" :value="status">
                                        {{ status }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">From Date</label>
                                <Input
                                    v-model="dateFrom"
                                    type="date"
                                    class="mt-1"
                                    @change="applyFilters"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">To Date</label>
                                <Input
                                    v-model="dateTo"
                                    type="date"
                                    class="mt-1"
                                    @change="applyFilters"
                                />
                            </div>
                        </div>
                        <div v-if="hasActiveFilters" class="mt-4">
                            <Button variant="ghost" size="sm" @click="clearFilters">
                                Clear all filters
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Logs Table -->
                <Deferred data="logs">
                    <template #fallback>
                        <div class="animate-pulse space-y-4">
                            <div v-for="i in 5" :key="i" class="h-16 rounded-lg bg-gray-200 dark:bg-white/10"></div>
                        </div>
                    </template>

                    <div v-if="logs && logs.length > 0" class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th scope="col" class="py-3 pl-4 pr-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 sm:pl-6">
                                        Status
                                    </th>
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Recipient
                                    </th>
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Channel
                                    </th>
                                    <th scope="col" class="hidden px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 md:table-cell">
                                        Template / Activity
                                    </th>
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Time
                                    </th>
                                    <th scope="col" class="relative py-3 pl-3 pr-4 sm:pr-6">
                                        <span class="sr-only">Expand</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                                <template v-for="log in logs" :key="log.id">
                                    <tr
                                        class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5"
                                        @click="toggleExpand(log.id)"
                                    >
                                        <td class="whitespace-nowrap py-3 pl-4 pr-3 sm:pl-6">
                                            <span :class="['inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium', getStatusBadgeClass(log.status)]">
                                                <component :is="getStatusIcon(log.status)" class="h-3.5 w-3.5" />
                                                {{ log.status }}
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-900 dark:text-white">
                                            <div class="max-w-[200px] truncate">{{ log.recipient }}</div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3 text-sm">
                                            <span :class="['inline-flex items-center rounded-full px-2 py-1 text-xs font-medium', getChannelBadgeClass(log.channel)]">
                                                {{ log.channel }}
                                            </span>
                                        </td>
                                        <td class="hidden whitespace-nowrap px-3 py-3 text-sm text-gray-500 dark:text-gray-400 md:table-cell">
                                            <div class="max-w-[200px] truncate">
                                                {{ log.template?.name || log.activity || '-' }}
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-500 dark:text-gray-400">
                                            <span :title="formatDate(log.created_at)">
                                                {{ formatDateShort(log.created_at) }}
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap py-3 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                            <component
                                                :is="expandedLogId === log.id ? ChevronUpIcon : ChevronDownIcon"
                                                class="h-5 w-5 text-gray-400"
                                            />
                                        </td>
                                    </tr>

                                    <!-- Expanded row -->
                                    <tr v-if="expandedLogId === log.id" class="bg-gray-50 dark:bg-white/5">
                                        <td colspan="6" class="px-6 py-4">
                                            <div class="space-y-4">
                                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                                    <div>
                                                        <h4 class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Recipient</h4>
                                                        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ log.recipient }}</p>
                                                    </div>
                                                    <div>
                                                        <h4 class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Created</h4>
                                                        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ formatDate(log.created_at) }}</p>
                                                    </div>
                                                    <div>
                                                        <h4 class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Sent At</h4>
                                                        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ formatDate(log.sent_at) }}</p>
                                                    </div>
                                                </div>

                                                <div v-if="log.subject">
                                                    <h4 class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Subject</h4>
                                                    <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ log.subject }}</p>
                                                </div>

                                                <div v-if="log.content">
                                                    <h4 class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Content Preview</h4>
                                                    <div class="mt-1 max-h-48 overflow-auto rounded-md border border-gray-200 bg-white p-3 text-sm text-gray-900 dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                                        <div v-if="log.channel === 'email'" v-html="log.content"></div>
                                                        <pre v-else class="whitespace-pre-wrap">{{ log.content }}</pre>
                                                    </div>
                                                </div>

                                                <div v-if="log.error_message" class="rounded-md bg-red-50 p-3 dark:bg-red-900/20">
                                                    <h4 class="text-xs font-medium uppercase text-red-700 dark:text-red-400">Error</h4>
                                                    <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ log.error_message }}</p>
                                                </div>

                                                <div v-if="log.template" class="flex items-center gap-2 text-sm">
                                                    <span class="text-gray-500 dark:text-gray-400">Template:</span>
                                                    <Link
                                                        :href="`/settings/notifications/templates/${log.template.id}/edit`"
                                                        class="text-indigo-600 hover:underline dark:text-indigo-400"
                                                    >
                                                        {{ log.template.name }}
                                                    </Link>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="rounded-lg border border-gray-200 bg-gray-50 py-12 text-center dark:border-white/10 dark:bg-white/5">
                        <PaperAirplaneIcon class="mx-auto h-12 w-12 text-gray-400" />
                        <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No notification logs</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            <template v-if="hasActiveFilters">
                                No logs match your current filters. Try adjusting your search criteria.
                            </template>
                            <template v-else>
                                Notification logs will appear here once notifications are sent.
                            </template>
                        </p>
                    </div>
                </Deferred>

                <!-- Pagination -->
                <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to
                        {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} of
                        {{ pagination.total }} results
                    </div>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="pagination.current_page === 1"
                            @click="router.get('/settings/notifications/logs', { ...props.filters, page: pagination.current_page - 1 })"
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="pagination.current_page === pagination.last_page"
                            @click="router.get('/settings/notifications/logs', { ...props.filters, page: pagination.current_page + 1 })"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
