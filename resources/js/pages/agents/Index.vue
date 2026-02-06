<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CpuChipIcon,
    PlayIcon,
    ClockIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
} from '@heroicons/vue/20/solid';
import { computed } from 'vue';

interface Agent {
    id: number;
    slug: string;
    name: string;
    description: string;
    type: string;
    type_label: string;
    is_enabled: boolean;
    permission_level: string;
    permission_level_label: string;
    last_run_at: string | null;
    next_run_at: string | null;
}

interface Stats {
    runs_today: number;
    pending_actions: number;
    executed_today: number;
}

interface Props {
    agents: Agent[];
    stats: Stats;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Agents', href: '/agents' },
];

const enabledAgents = computed(() => props.agents.filter(a => a.is_enabled));
const disabledAgents = computed(() => props.agents.filter(a => !a.is_enabled));

function formatRelativeTime(dateString: string | null): string {
    if (!dateString) return 'Never';
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

function getTypeColor(type: string): string {
    switch (type) {
        case 'background':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
        case 'event_triggered':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200';
        case 'goal_oriented':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200';
    }
}

function toggleEnabled(agent: Agent) {
    router.put(`/agents/${agent.slug}`, {
        is_enabled: !agent.is_enabled,
        permission_level: agent.permission_level,
        config: {},
    }, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="AI Agents" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">AI Agents</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage autonomous agents that help run your business
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link
                        href="/agents/actions"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:hover:bg-gray-700"
                    >
                        <ExclamationCircleIcon v-if="stats.pending_actions > 0" class="-ml-0.5 size-5 text-yellow-500" />
                        Pending Actions
                        <span v-if="stats.pending_actions > 0" class="ml-1 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                            {{ stats.pending_actions }}
                        </span>
                    </Link>
                    <Link
                        href="/agents/runs"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:hover:bg-gray-700"
                    >
                        <ClockIcon class="-ml-0.5 size-5" />
                        Run History
                    </Link>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-3">
                        <div class="rounded-lg bg-indigo-100 p-2 dark:bg-indigo-900">
                            <PlayIcon class="size-5 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div>
                            <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.runs_today }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Runs Today</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-3">
                        <div class="rounded-lg bg-yellow-100 p-2 dark:bg-yellow-900">
                            <ExclamationCircleIcon class="size-5 text-yellow-600 dark:text-yellow-400" />
                        </div>
                        <div>
                            <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.pending_actions }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Pending Approval</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-3">
                        <div class="rounded-lg bg-green-100 p-2 dark:bg-green-900">
                            <CheckCircleIcon class="size-5 text-green-600 dark:text-green-400" />
                        </div>
                        <div>
                            <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ stats.executed_today }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Executed Today</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Enabled Agents -->
            <div v-if="enabledAgents.length > 0" class="mb-8">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Active Agents</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="agent in enabledAgents"
                        :key="agent.id"
                        :href="`/agents/${agent.slug}`"
                        class="relative rounded-lg bg-white shadow ring-1 ring-black/5 hover:ring-indigo-500 hover:shadow-md transition-all dark:bg-gray-800 dark:ring-white/10 dark:hover:ring-indigo-500"
                    >
                        <div class="p-5">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="rounded-lg bg-indigo-100 p-2 dark:bg-indigo-900">
                                        <CpuChipIcon class="size-5 text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ agent.name }}</h3>
                                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium mt-1', getTypeColor(agent.type)]">
                                            {{ agent.type_label }}
                                        </span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent bg-indigo-600 transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2"
                                    @click.prevent="toggleEnabled(agent)"
                                >
                                    <span class="translate-x-5 pointer-events-none inline-block size-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" />
                                </button>
                            </div>
                            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400 line-clamp-2">
                                {{ agent.description }}
                            </p>
                            <div class="mt-4 flex items-center justify-between text-sm">
                                <div class="flex items-center gap-1 text-gray-500 dark:text-gray-400">
                                    <ClockIcon class="size-4" />
                                    Last run: {{ formatRelativeTime(agent.last_run_at) }}
                                </div>
                                <span :class="[
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                    agent.permission_level === 'auto' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
                                ]">
                                    {{ agent.permission_level_label }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Disabled Agents -->
            <div v-if="disabledAgents.length > 0">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Disabled Agents</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="agent in disabledAgents"
                        :key="agent.id"
                        :href="`/agents/${agent.slug}`"
                        class="relative rounded-lg bg-gray-50 shadow ring-1 ring-black/5 hover:ring-gray-400 hover:shadow-md transition-all opacity-60 dark:bg-gray-900 dark:ring-white/10"
                    >
                        <div class="p-5">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="rounded-lg bg-gray-200 p-2 dark:bg-gray-700">
                                        <CpuChipIcon class="size-5 text-gray-400" />
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-600 dark:text-gray-400">{{ agent.name }}</h3>
                                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium mt-1', getTypeColor(agent.type)]">
                                            {{ agent.type_label }}
                                        </span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent bg-gray-300 transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 dark:bg-gray-600"
                                    @click.prevent="toggleEnabled(agent)"
                                >
                                    <span class="translate-x-0 pointer-events-none inline-block size-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" />
                                </button>
                            </div>
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-500 line-clamp-2">
                                {{ agent.description }}
                            </p>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="agents.length === 0" class="text-center py-12">
                <CpuChipIcon class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No agents available</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Agents will appear here once they are configured.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
