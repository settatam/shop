<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BellIcon,
    DocumentTextIcon,
    PaperAirplaneIcon,
    ChartBarIcon,
    CheckCircleIcon,
    XCircleIcon,
    ClockIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface NotificationLog {
    id: number;
    recipient: string;
    channel: string;
    activity: string | null;
    status: string;
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
    stats: {
        templates: number;
        subscriptions: number;
        sent_today: number;
        sent_week: number;
    };
    recentLogs: NotificationLog[];
    channelTypes: string[];
}

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Notifications',
        href: '/settings/notifications',
    },
];

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
        case 'delivered':
            return CheckCircleIcon;
        case 'failed':
        case 'bounced':
            return XCircleIcon;
        default:
            return ClockIcon;
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

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleString();
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Notification settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Notifications"
                    description="Manage notification templates, triggers, and channels"
                />

                <!-- Stats Cards -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <div class="rounded-lg bg-indigo-100 p-2 dark:bg-indigo-900/30">
                                <DocumentTextIcon class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                            </div>
                            <div>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.templates }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Templates</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <div class="rounded-lg bg-green-100 p-2 dark:bg-green-900/30">
                                <BellIcon class="h-5 w-5 text-green-600 dark:text-green-400" />
                            </div>
                            <div>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.subscriptions }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Triggers</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <div class="rounded-lg bg-blue-100 p-2 dark:bg-blue-900/30">
                                <PaperAirplaneIcon class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                            </div>
                            <div>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.sent_today }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Sent Today</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <div class="rounded-lg bg-purple-100 p-2 dark:bg-purple-900/30">
                                <ChartBarIcon class="h-5 w-5 text-purple-600 dark:text-purple-400" />
                            </div>
                            <div>
                                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.sent_week }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">This Week</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="flex flex-wrap gap-3">
                    <Button as-child size="sm">
                        <Link href="/settings/notifications/templates/create">
                            <DocumentTextIcon class="mr-2 h-4 w-4" />
                            Create Template
                        </Link>
                    </Button>
                    <Button as-child variant="outline" size="sm">
                        <Link href="/settings/notifications/subscriptions">
                            <BellIcon class="mr-2 h-4 w-4" />
                            Manage Triggers
                        </Link>
                    </Button>
                    <Button as-child variant="outline" size="sm">
                        <Link href="/settings/notifications/channels">
                            Configure Channels
                        </Link>
                    </Button>
                </div>

                <!-- Navigation Tabs -->
                <div class="border-b border-gray-200 dark:border-white/10">
                    <nav class="-mb-px flex space-x-8">
                        <Link
                            href="/settings/notifications"
                            class="border-b-2 border-indigo-500 px-1 pb-4 text-sm font-medium text-indigo-600 dark:text-indigo-400"
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
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Logs
                        </Link>
                    </nav>
                </div>

                <!-- Recent Activity -->
                <div>
                    <h3 class="mb-4 text-sm font-medium text-gray-900 dark:text-white">Recent Activity</h3>

                    <div v-if="recentLogs.length > 0" class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
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
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Template
                                    </th>
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Time
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                                <tr v-for="log in recentLogs" :key="log.id">
                                    <td class="whitespace-nowrap py-3 pl-4 pr-3 sm:pl-6">
                                        <component
                                            :is="getStatusIcon(log.status)"
                                            :class="['h-5 w-5', getStatusColor(log.status)]"
                                        />
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-900 dark:text-white">
                                        {{ log.recipient }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm">
                                        <span :class="['inline-flex items-center rounded-full px-2 py-1 text-xs font-medium', getChannelBadgeClass(log.channel)]">
                                            {{ log.channel }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-500 dark:text-gray-400">
                                        {{ log.template?.name || '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-500 dark:text-gray-400">
                                        {{ formatDate(log.created_at) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="rounded-lg border border-gray-200 bg-gray-50 py-12 text-center dark:border-white/10 dark:bg-white/5">
                        <PaperAirplaneIcon class="mx-auto h-12 w-12 text-gray-400" />
                        <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No notifications yet</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Create templates and triggers to start sending notifications.
                        </p>
                        <div class="mt-6">
                            <Button as-child size="sm">
                                <Link href="/settings/notifications/templates/create">
                                    Create Template
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
