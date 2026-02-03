<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    ChatBubbleLeftRightIcon,
    EnvelopeIcon,
    EnvelopeOpenIcon,
    ArrowDownTrayIcon,
    ArrowUpTrayIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    ClockIcon,
    FunnelIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline';
import { CheckIcon, XMarkIcon } from '@heroicons/vue/20/solid';

interface SmsMessage {
    id: number;
    transaction_id: number | null;
    transaction_number: string | null;
    customer_id: number | null;
    customer_name: string | null;
    customer_phone: string | null;
    direction: 'inbound' | 'outbound';
    from: string | null;
    to: string | null;
    content: string;
    status: string;
    is_read: boolean;
    read_at: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    created_at: string;
}

interface Template {
    id: number;
    name: string;
    content: string;
    category: string | null;
}

interface PaginatedMessages {
    data: SmsMessage[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Filters {
    direction?: string;
    read_status?: string;
    status?: string;
    search?: string;
    date_from?: string;
    date_to?: string;
}

interface Props {
    messages: PaginatedMessages;
    templates: Template[];
    unreadCount: number;
    filters: Filters;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Messages', href: '/messages' },
];

// Filter state
const search = ref(props.filters.search || '');
const direction = ref(props.filters.direction || '');
const readStatus = ref(props.filters.read_status || '');

// Selected messages for bulk actions
const selectedIds = ref<number[]>([]);
const selectAll = ref(false);

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout>;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
});

watch([direction, readStatus], () => {
    applyFilters();
});

function applyFilters() {
    router.get('/messages', {
        search: search.value || undefined,
        direction: direction.value || undefined,
        read_status: readStatus.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    search.value = '';
    direction.value = '';
    readStatus.value = '';
    router.get('/messages', {}, { preserveState: true });
}

const hasActiveFilters = computed(() => {
    return search.value || direction.value || readStatus.value;
});

function toggleSelectAll() {
    if (selectAll.value) {
        selectedIds.value = props.messages.data.map(m => m.id);
    } else {
        selectedIds.value = [];
    }
}

function toggleSelect(id: number) {
    const index = selectedIds.value.indexOf(id);
    if (index === -1) {
        selectedIds.value.push(id);
    } else {
        selectedIds.value.splice(index, 1);
    }
}

function markSelectedAsRead() {
    if (selectedIds.value.length === 0) return;

    router.post('/messages/mark-read', {
        ids: selectedIds.value,
    }, {
        preserveState: true,
        onSuccess: () => {
            selectedIds.value = [];
            selectAll.value = false;
        },
    });
}

function goToPage(page: number) {
    router.get('/messages', {
        ...props.filters,
        page,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function formatDate(dateString: string | null): string {
    if (!dateString) return '-';
    const date = new Date(dateString);
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (days === 0) {
        return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    } else if (days === 1) {
        return 'Yesterday';
    } else if (days < 7) {
        return date.toLocaleDateString('en-US', { weekday: 'short' });
    }
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function formatFullDate(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function truncateMessage(content: string, length: number = 50): string {
    if (content.length <= length) return content;
    return content.substring(0, length) + '...';
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'delivered':
            return 'text-green-600 dark:text-green-400';
        case 'sent':
            return 'text-blue-600 dark:text-blue-400';
        case 'failed':
            return 'text-red-600 dark:text-red-400';
        case 'pending':
        default:
            return 'text-gray-500 dark:text-gray-400';
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'delivered':
            return CheckCircleIcon;
        case 'failed':
            return ExclamationCircleIcon;
        case 'pending':
        default:
            return ClockIcon;
    }
}
</script>

<template>
    <Head title="Messages" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                        SMS Message Center
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        View and manage SMS messages with customers
                        <span v-if="unreadCount > 0" class="ml-2 inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-400">
                            {{ unreadCount }} unread
                        </span>
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-4">
                <!-- Search -->
                <div class="relative flex-1 max-w-md">
                    <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search messages, phone numbers, or names..."
                        class="block w-full rounded-md border-0 py-1.5 pl-10 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                    />
                </div>

                <!-- Direction Filter -->
                <select
                    v-model="direction"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Messages</option>
                    <option value="inbound">Incoming</option>
                    <option value="outbound">Outgoing</option>
                </select>

                <!-- Read Status Filter -->
                <select
                    v-model="readStatus"
                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">All Status</option>
                    <option value="unread">Unread</option>
                    <option value="read">Read</option>
                </select>

                <!-- Clear Filters -->
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                    @click="clearFilters"
                >
                    <XMarkIcon class="-ml-0.5 size-4" />
                    Clear
                </button>

                <!-- Bulk Actions -->
                <div v-if="selectedIds.length > 0" class="flex items-center gap-2 ml-auto">
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ selectedIds.length }} selected
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="markSelectedAsRead"
                    >
                        <CheckIcon class="-ml-0.5 size-4" />
                        Mark as Read
                    </button>
                </div>
            </div>

            <!-- Messages Table -->
            <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th scope="col" class="relative px-4 py-3">
                                <input
                                    v-model="selectAll"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                    @change="toggleSelectAll"
                                />
                            </th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Txn ID
                            </th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Customer
                            </th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Status
                            </th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                From
                            </th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                To
                            </th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Message
                            </th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Date
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr
                            v-for="message in messages.data"
                            :key="message.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                            :class="{ 'bg-indigo-50/50 dark:bg-indigo-900/10': !message.is_read && message.direction === 'inbound' }"
                        >
                            <td class="relative px-4 py-4">
                                <input
                                    type="checkbox"
                                    :checked="selectedIds.includes(message.id)"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                    @change="toggleSelect(message.id)"
                                />
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <Link
                                    v-if="message.transaction_id"
                                    :href="`/transactions/${message.transaction_id}`"
                                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    {{ message.transaction_number || `#${message.transaction_id}` }}
                                </Link>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <div v-if="message.customer_name" class="font-medium text-gray-900 dark:text-white">
                                    {{ message.customer_name }}
                                </div>
                                <div class="text-gray-500 dark:text-gray-400">
                                    {{ message.customer_phone || '-' }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <div class="flex items-center gap-2">
                                    <!-- Direction indicator -->
                                    <span
                                        :class="message.direction === 'inbound' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'"
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                    >
                                        <ArrowDownTrayIcon v-if="message.direction === 'inbound'" class="size-3" />
                                        <ArrowUpTrayIcon v-else class="size-3" />
                                        {{ message.direction === 'inbound' ? 'In' : 'Out' }}
                                    </span>
                                    <!-- Read status for inbound -->
                                    <span
                                        v-if="message.direction === 'inbound'"
                                        :class="message.is_read ? 'text-gray-400' : 'text-indigo-600 dark:text-indigo-400'"
                                    >
                                        <EnvelopeOpenIcon v-if="message.is_read" class="size-4" />
                                        <EnvelopeIcon v-else class="size-4" />
                                    </span>
                                    <!-- Delivery status for outbound -->
                                    <component
                                        v-else
                                        :is="getStatusIcon(message.status)"
                                        class="size-4"
                                        :class="getStatusColor(message.status)"
                                    />
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ message.from || '-' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ message.to || '-' }}
                            </td>
                            <td class="px-3 py-4 text-sm text-gray-900 dark:text-white max-w-xs">
                                <Link
                                    :href="`/messages/${message.id}`"
                                    class="hover:text-indigo-600 dark:hover:text-indigo-400"
                                    :class="{ 'font-semibold': !message.is_read && message.direction === 'inbound' }"
                                    :title="message.content"
                                >
                                    {{ truncateMessage(message.content, 60) }}
                                </Link>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400" :title="formatFullDate(message.created_at)">
                                {{ formatDate(message.created_at) }}
                            </td>
                        </tr>

                        <!-- Empty State -->
                        <tr v-if="messages.data.length === 0">
                            <td colspan="8" class="px-6 py-12 text-center">
                                <ChatBubbleLeftRightIcon class="mx-auto size-12 text-gray-400" />
                                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No messages</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ hasActiveFilters ? 'No messages match your filters.' : 'No SMS messages have been sent or received yet.' }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="messages.last_page > 1" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800 sm:px-6">
                    <div class="flex flex-1 justify-between sm:hidden">
                        <button
                            :disabled="messages.current_page === 1"
                            class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                            @click="goToPage(messages.current_page - 1)"
                        >
                            Previous
                        </button>
                        <button
                            :disabled="messages.current_page === messages.last_page"
                            class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                            @click="goToPage(messages.current_page + 1)"
                        >
                            Next
                        </button>
                    </div>
                    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                Showing
                                <span class="font-medium">{{ messages.from }}</span>
                                to
                                <span class="font-medium">{{ messages.to }}</span>
                                of
                                <span class="font-medium">{{ messages.total }}</span>
                                results
                            </p>
                        </div>
                        <div>
                            <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                <button
                                    :disabled="messages.current_page === 1"
                                    class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 disabled:opacity-50 dark:ring-gray-600 dark:hover:bg-gray-700"
                                    @click="goToPage(messages.current_page - 1)"
                                >
                                    <span class="sr-only">Previous</span>
                                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                                <span class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 dark:text-white dark:ring-gray-600">
                                    {{ messages.current_page }} / {{ messages.last_page }}
                                </span>
                                <button
                                    :disabled="messages.current_page === messages.last_page"
                                    class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 disabled:opacity-50 dark:ring-gray-600 dark:hover:bg-gray-700"
                                    @click="goToPage(messages.current_page + 1)"
                                >
                                    <span class="sr-only">Next</span>
                                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
