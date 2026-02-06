<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ClockIcon,
    CheckCircleIcon,
    XCircleIcon,
    PlayIcon,
    FunnelIcon,
} from '@heroicons/vue/20/solid';
import { ref } from 'vue';

interface Run {
    id: number;
    agent_name: string;
    agent_slug: string;
    status: string;
    status_label: string;
    status_color: string;
    trigger_type: string;
    trigger_type_label: string;
    started_at: string | null;
    completed_at: string | null;
    duration_seconds: number | null;
    actions_count: number;
    summary: Record<string, any> | null;
    error_message: string | null;
    created_at: string;
}

interface Agent {
    slug: string;
    name: string;
}

interface Props {
    runs: Run[];
    agents: Agent[];
    currentAgent: string | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Agents', href: '/agents' },
    { title: 'Run History', href: '/agents/runs' },
];

const showFilters = ref(false);
const selectedAgent = ref(props.currentAgent || '');

function filterByAgent() {
    router.get('/agents/runs', selectedAgent.value ? { agent: selectedAgent.value } : {}, {
        preserveScroll: true,
    });
}

function formatDateTime(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString();
}

function formatDuration(seconds: number | null): string {
    if (!seconds) return '-';
    if (seconds < 60) return `${seconds}s`;
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}m ${secs}s`;
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'completed':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        case 'running':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
        case 'failed':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
        case 'cancelled':
            return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
        default:
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
    }
}

function getTriggerColor(trigger: string): string {
    switch (trigger) {
        case 'scheduled':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
        case 'event':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200';
        case 'manual':
            return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'completed':
            return CheckCircleIcon;
        case 'running':
            return PlayIcon;
        case 'failed':
            return XCircleIcon;
        default:
            return ClockIcon;
    }
}
</script>

<template>
    <Head title="Agent Run History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Run History</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        View all agent execution history
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:hover:bg-gray-700"
                        @click="showFilters = !showFilters"
                    >
                        <FunnelIcon class="-ml-0.5 size-5" />
                        Filter
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div v-if="showFilters" class="mb-6 rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <div class="flex items-end gap-4">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Agent</label>
                        <select
                            v-model="selectedAgent"
                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        >
                            <option value="">All Agents</option>
                            <option v-for="agent in agents" :key="agent.slug" :value="agent.slug">
                                {{ agent.name }}
                            </option>
                        </select>
                    </div>
                    <button
                        type="button"
                        class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="filterByAgent"
                    >
                        Apply Filter
                    </button>
                </div>
            </div>

            <!-- Runs List -->
            <div v-if="runs.length > 0" class="space-y-4">
                <div
                    v-for="run in runs"
                    :key="run.id"
                    class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 overflow-hidden"
                >
                    <div class="px-4 py-4 sm:px-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <component
                                    :is="getStatusIcon(run.status)"
                                    :class="[
                                        'size-6',
                                        run.status === 'completed' ? 'text-green-500' : run.status === 'failed' ? 'text-red-500' : run.status === 'running' ? 'text-blue-500' : 'text-gray-400'
                                    ]"
                                />
                                <div>
                                    <Link
                                        :href="`/agents/${run.agent_slug}`"
                                        class="text-lg font-semibold text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                    >
                                        {{ run.agent_name }}
                                    </Link>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', getStatusColor(run.status)]">
                                            {{ run.status_label }}
                                        </span>
                                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', getTriggerColor(run.trigger_type)]">
                                            {{ run.trigger_type_label }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right text-sm text-gray-500 dark:text-gray-400">
                                <div>{{ formatDateTime(run.started_at) }}</div>
                                <div v-if="run.duration_seconds">Duration: {{ formatDuration(run.duration_seconds) }}</div>
                            </div>
                        </div>

                        <div v-if="run.error_message" class="mt-3 rounded-md bg-red-50 p-3 dark:bg-red-900/20">
                            <p class="text-sm text-red-700 dark:text-red-400">{{ run.error_message }}</p>
                        </div>

                        <div v-if="run.summary" class="mt-3 flex flex-wrap gap-4 text-sm">
                            <div v-for="(value, key) in run.summary" :key="key" class="flex items-center gap-1">
                                <span class="text-gray-500 dark:text-gray-400">{{ String(key).replace(/_/g, ' ') }}:</span>
                                <span class="font-semibold text-gray-900 dark:text-white">{{ value }}</span>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                            <span>{{ run.actions_count }} actions created</span>
                            <span>Run #{{ run.id }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-12 bg-white rounded-lg shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <ClockIcon class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No runs yet</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Agent runs will appear here after execution.
                </p>
                <div class="mt-6">
                    <Link
                        href="/agents"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        View Agents
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
