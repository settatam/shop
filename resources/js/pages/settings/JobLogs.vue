<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    ArrowPathIcon,
    CheckCircleIcon,
    XCircleIcon,
    ClockIcon,
    PlayIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Deferred } from '@inertiajs/vue3';

interface JobLog {
    id: string;
    job: string;
    job_class: string;
    queue: string;
    store_id: number | null;
    status: 'running' | 'completed' | 'failed';
    payload: string;
    started_at: string;
    completed_at: string | null;
    failed_at: string | null;
    duration_ms: number | null;
    error: {
        message: string;
        file: string;
        line: number;
    } | null;
    result: string | null;
}

interface JobStats {
    total: number;
    completed: number;
    failed: number;
    running: number;
    avg_duration_ms: number;
}

interface Props {
    logs: JobLog[];
    stats: JobStats;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Job Logs',
        href: '/settings/job-logs',
    },
];

const isRefreshing = ref(false);
const statusFilter = ref<string | null>(null);
const expandedLogId = ref<string | null>(null);

const filteredLogs = computed(() => {
    if (!props.logs) return [];
    if (!statusFilter.value) return props.logs;
    return props.logs.filter(log => log.status === statusFilter.value);
});

async function refreshLogs() {
    if (isRefreshing.value) return;
    isRefreshing.value = true;

    try {
        const response = await fetch('/settings/job-logs/logs');
        if (response.ok) {
            // Refresh page to get new data
            window.location.reload();
        }
    } catch (error) {
        console.error('Failed to refresh logs:', error);
    } finally {
        isRefreshing.value = false;
    }
}

function toggleExpand(logId: string) {
    expandedLogId.value = expandedLogId.value === logId ? null : logId;
}

function formatDuration(ms: number | null): string {
    if (ms === null) return '-';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${(ms / 60000).toFixed(1)}m`;
}

function formatTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    return date.toLocaleString();
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'completed':
            return CheckCircleIcon;
        case 'failed':
            return XCircleIcon;
        case 'running':
            return PlayIcon;
        default:
            return ClockIcon;
    }
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'completed':
            return 'text-green-600 dark:text-green-400';
        case 'failed':
            return 'text-red-600 dark:text-red-400';
        case 'running':
            return 'text-blue-600 dark:text-blue-400';
        default:
            return 'text-gray-600 dark:text-gray-400';
    }
}

function getStatusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed':
            return 'default';
        case 'failed':
            return 'destructive';
        case 'running':
            return 'secondary';
        default:
            return 'outline';
    }
}

function parsePayload(payload: string): Record<string, unknown> {
    try {
        return JSON.parse(payload);
    } catch {
        return {};
    }
}

function parseResult(result: string | null): unknown {
    if (!result) return null;
    try {
        return JSON.parse(result);
    } catch {
        return result;
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Job Logs" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Job Logs"
                        description="Monitor background job execution and performance"
                    />
                    <Button @click="refreshLogs" :disabled="isRefreshing" variant="outline" size="sm">
                        <ArrowPathIcon :class="['mr-2 h-4 w-4', isRefreshing ? 'animate-spin' : '']" />
                        Refresh
                    </Button>
                </div>

                <!-- Stats Cards -->
                <Deferred data="stats">
                    <template #fallback>
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div v-for="i in 4" :key="i" class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                                <div class="h-4 w-16 animate-pulse rounded bg-gray-200 dark:bg-gray-700 mb-2"></div>
                                <div class="h-8 w-12 animate-pulse rounded bg-gray-200 dark:bg-gray-700"></div>
                            </div>
                        </div>
                    </template>

                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                            <p class="text-sm text-gray-500 dark:text-gray-400">Total Jobs</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ stats?.total ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                            <p class="text-sm text-gray-500 dark:text-gray-400">Completed</p>
                            <p class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400">{{ stats?.completed ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                            <p class="text-sm text-gray-500 dark:text-gray-400">Failed</p>
                            <p class="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">{{ stats?.failed ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                            <p class="text-sm text-gray-500 dark:text-gray-400">Avg Duration</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ formatDuration(stats?.avg_duration_ms ?? null) }}</p>
                        </div>
                    </div>
                </Deferred>

                <!-- Filter Buttons -->
                <div class="flex gap-2">
                    <Button
                        :variant="statusFilter === null ? 'default' : 'outline'"
                        size="sm"
                        @click="statusFilter = null"
                    >
                        All
                    </Button>
                    <Button
                        :variant="statusFilter === 'running' ? 'default' : 'outline'"
                        size="sm"
                        @click="statusFilter = 'running'"
                    >
                        Running
                    </Button>
                    <Button
                        :variant="statusFilter === 'completed' ? 'default' : 'outline'"
                        size="sm"
                        @click="statusFilter = 'completed'"
                    >
                        Completed
                    </Button>
                    <Button
                        :variant="statusFilter === 'failed' ? 'default' : 'outline'"
                        size="sm"
                        @click="statusFilter = 'failed'"
                    >
                        Failed
                    </Button>
                </div>

                <!-- Job Logs List -->
                <Deferred data="logs">
                    <template #fallback>
                        <div class="space-y-3">
                            <div v-for="i in 5" :key="i" class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="h-5 w-5 animate-pulse rounded-full bg-gray-200 dark:bg-gray-700"></div>
                                        <div class="h-4 w-40 animate-pulse rounded bg-gray-200 dark:bg-gray-700"></div>
                                    </div>
                                    <div class="h-4 w-16 animate-pulse rounded bg-gray-200 dark:bg-gray-700"></div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div class="space-y-3">
                        <div
                            v-for="log in filteredLogs"
                            :key="log.id"
                            class="rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5"
                        >
                            <!-- Log Header -->
                            <div
                                class="flex cursor-pointer items-center justify-between p-4"
                                @click="toggleExpand(log.id)"
                            >
                                <div class="flex items-center gap-3">
                                    <component
                                        :is="getStatusIcon(log.status)"
                                        :class="['h-5 w-5', getStatusColor(log.status)]"
                                    />
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ log.job }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ formatTime(log.started_at) }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ formatDuration(log.duration_ms) }}
                                    </span>
                                    <Badge :variant="getStatusBadgeVariant(log.status)">
                                        {{ log.status }}
                                    </Badge>
                                </div>
                            </div>

                            <!-- Expanded Details -->
                            <div
                                v-if="expandedLogId === log.id"
                                class="border-t border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5"
                            >
                                <div class="grid gap-4 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Job Class</p>
                                        <p class="mt-1 font-mono text-xs text-gray-600 dark:text-gray-400">{{ log.job_class }}</p>
                                    </div>

                                    <div>
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Queue</p>
                                        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ log.queue }}</p>
                                    </div>

                                    <div v-if="log.payload">
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Payload</p>
                                        <pre class="mt-1 overflow-x-auto rounded bg-gray-100 p-2 text-xs dark:bg-gray-800">{{ JSON.stringify(parsePayload(log.payload), null, 2) }}</pre>
                                    </div>

                                    <div v-if="log.result">
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Result</p>
                                        <pre class="mt-1 overflow-x-auto rounded bg-gray-100 p-2 text-xs dark:bg-gray-800">{{ JSON.stringify(parseResult(log.result), null, 2) }}</pre>
                                    </div>

                                    <div v-if="log.error">
                                        <p class="font-medium text-red-700 dark:text-red-400">Error</p>
                                        <div class="mt-1 rounded bg-red-50 p-2 dark:bg-red-900/20">
                                            <p class="text-sm text-red-800 dark:text-red-200">{{ log.error.message }}</p>
                                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">
                                                {{ log.error.file }}:{{ log.error.line }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <p class="font-medium text-gray-700 dark:text-gray-300">Started</p>
                                            <p class="mt-1 text-gray-600 dark:text-gray-400">{{ formatTime(log.started_at) }}</p>
                                        </div>
                                        <div v-if="log.completed_at">
                                            <p class="font-medium text-gray-700 dark:text-gray-300">Completed</p>
                                            <p class="mt-1 text-gray-600 dark:text-gray-400">{{ formatTime(log.completed_at) }}</p>
                                        </div>
                                        <div v-if="log.failed_at">
                                            <p class="font-medium text-gray-700 dark:text-gray-300">Failed</p>
                                            <p class="mt-1 text-gray-600 dark:text-gray-400">{{ formatTime(log.failed_at) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p
                            v-if="filteredLogs.length === 0"
                            class="py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                        >
                            No job logs found. Jobs will appear here once they run.
                        </p>
                    </div>
                </Deferred>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
