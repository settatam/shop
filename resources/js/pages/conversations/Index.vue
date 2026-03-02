<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Pagination from '@/components/widgets/Pagination.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface Agent {
    id: number;
    name: string;
}

interface Customer {
    id: number;
    name: string;
    email: string | null;
}

interface Session {
    id: string;
    visitor_id: string;
    title: string | null;
    status: string;
    channel: string;
    assigned_agent: Agent | null;
    customer: Customer | null;
    messages_count: number;
    last_message_at: string | null;
    created_at: string;
}

const props = defineProps<{
    sessions: {
        data: Session[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number;
        to: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Conversations', href: '/conversations' }];

const activeFilter = ref<string>('all');

const filteredSessions = computed(() => {
    if (activeFilter.value === 'all') {
        return props.sessions.data;
    }
    return props.sessions.data.filter((s) => s.status === activeFilter.value);
});

function statusLabel(status: string): string {
    const map: Record<string, string> = {
        open: 'Open',
        waiting_for_agent: 'Waiting',
        assigned: 'Assigned',
        closed: 'Closed',
    };
    return map[status] || status;
}

function statusColor(status: string): string {
    const map: Record<string, string> = {
        open: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        waiting_for_agent: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        assigned: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        closed: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    };
    return map[status] || 'bg-gray-100 text-gray-800';
}

function channelLabel(channel: string): string {
    const map: Record<string, string> = {
        web: 'Web',
        whatsapp: 'WhatsApp',
        slack: 'Slack',
    };
    return map[channel] || channel;
}

function timeAgo(dateStr: string | null): string {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    return `${diffDays}d ago`;
}

const filters = [
    { key: 'all', label: 'All' },
    { key: 'open', label: 'Open' },
    { key: 'waiting_for_agent', label: 'Waiting' },
    { key: 'assigned', label: 'Assigned' },
    { key: 'closed', label: 'Closed' },
];
</script>

<template>
    <Head title="Conversations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Conversations</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ sessions.total }} conversation{{ sessions.total === 1 ? '' : 's' }}
                    </p>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex gap-2">
                <button
                    v-for="filter in filters"
                    :key="filter.key"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        activeFilter === filter.key
                            ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300'
                            : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700'
                    "
                    @click="activeFilter = filter.key"
                >
                    {{ filter.label }}
                </button>
            </div>

            <!-- Conversations Table -->
            <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                    Visitor / Customer
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                    Channel
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                    Status
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                    Agent
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                    Messages
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                    Last Activity
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                            <tr
                                v-for="session in filteredSessions"
                                :key="session.id"
                                class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
                                @click="router.visit(`/conversations/${session.id}`)"
                            >
                                <td class="px-4 py-4 text-sm whitespace-nowrap">
                                    <div v-if="session.customer">
                                        <div class="font-medium text-gray-900 dark:text-white">
                                            {{ session.customer.name }}
                                        </div>
                                        <div v-if="session.customer.email" class="text-gray-500 dark:text-gray-400">
                                            {{ session.customer.email }}
                                        </div>
                                    </div>
                                    <div v-else>
                                        <div class="font-medium text-gray-900 dark:text-white">
                                            {{ session.title || 'New Conversation' }}
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            {{ session.visitor_id.substring(0, 8) }}...
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    {{ channelLabel(session.channel) }}
                                </td>
                                <td class="px-4 py-4 text-sm whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="statusColor(session.status)"
                                    >
                                        {{ statusLabel(session.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    {{ session.assigned_agent?.name || '-' }}
                                </td>
                                <td class="px-4 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    {{ session.messages_count }}
                                </td>
                                <td class="px-4 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    {{ timeAgo(session.last_message_at) }}
                                </td>
                            </tr>

                            <tr v-if="filteredSessions.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No conversations found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="sessions.last_page > 1" class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                    <Pagination :pagination="sessions" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
