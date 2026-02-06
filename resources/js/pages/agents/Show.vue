<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CpuChipIcon,
    PlayIcon,
    ClockIcon,
    CheckCircleIcon,
    XCircleIcon,
    Cog6ToothIcon,
} from '@heroicons/vue/20/solid';
import { ref, computed } from 'vue';

interface ConfigField {
    type: string;
    label: string;
    description?: string;
    default?: any;
    options?: Record<string, string>;
}

interface Agent {
    id: number;
    slug: string;
    name: string;
    description: string;
    type: string;
    type_label: string;
    default_config: Record<string, any>;
}

interface StoreAgent {
    id: number;
    is_enabled: boolean;
    permission_level: string;
    config: Record<string, any>;
    last_run_at: string | null;
    next_run_at: string | null;
}

interface PermissionLevel {
    value: string;
    label: string;
    description: string;
}

interface Run {
    id: number;
    status: string;
    status_label: string;
    trigger_type: string;
    started_at: string | null;
    completed_at: string | null;
    duration_seconds: number | null;
    actions_count: number;
    pending_actions_count: number;
    executed_actions_count: number;
    summary: Record<string, any> | null;
    error_message: string | null;
}

interface Props {
    agent: Agent;
    storeAgent: StoreAgent;
    configSchema: Record<string, ConfigField>;
    permissionLevels: PermissionLevel[];
    recentRuns: Run[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Agents', href: '/agents' },
    { title: props.agent.name, href: `/agents/${props.agent.slug}` },
];

const showConfigModal = ref(false);
const isRunning = ref(false);

const form = useForm({
    is_enabled: props.storeAgent.is_enabled,
    permission_level: props.storeAgent.permission_level,
    config: { ...props.storeAgent.config },
});

function saveSettings() {
    form.put(`/agents/${props.agent.slug}`, {
        preserveScroll: true,
        onSuccess: () => {
            showConfigModal.value = false;
        },
    });
}

function runAgent() {
    isRunning.value = true;
    router.post(`/agents/${props.agent.slug}/run`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isRunning.value = false;
        },
    });
}

function formatDateTime(dateString: string | null): string {
    if (!dateString) return 'Never';
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
</script>

<template>
    <Head :title="agent.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-start justify-between mb-6">
                <div class="flex items-center gap-4">
                    <div class="rounded-lg bg-indigo-100 p-3 dark:bg-indigo-900">
                        <CpuChipIcon class="size-8 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ agent.name }}</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ agent.description }}</p>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                                {{ agent.type_label }}
                            </span>
                            <span :class="[
                                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                storeAgent.is_enabled ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
                            ]">
                                {{ storeAgent.is_enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:hover:bg-gray-700"
                        @click="showConfigModal = true"
                    >
                        <Cog6ToothIcon class="-ml-0.5 size-5" />
                        Configure
                    </button>
                    <button
                        type="button"
                        :disabled="!storeAgent.is_enabled || isRunning"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        @click="runAgent"
                    >
                        <PlayIcon class="-ml-0.5 size-5" />
                        {{ isRunning ? 'Running...' : 'Run Now' }}
                    </button>
                </div>
            </div>

            <!-- Status Cards -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-3">
                        <ClockIcon class="size-5 text-gray-400" />
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Last Run</p>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ formatDateTime(storeAgent.last_run_at) }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-3">
                        <ClockIcon class="size-5 text-gray-400" />
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Next Scheduled</p>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ formatDateTime(storeAgent.next_run_at) }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center gap-3">
                        <Cog6ToothIcon class="size-5 text-gray-400" />
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Permission Level</p>
                            <p class="font-semibold text-gray-900 dark:text-white">
                                {{ permissionLevels.find(p => p.value === storeAgent.permission_level)?.label }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Runs -->
            <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Runs</h3>
                </div>
                <div v-if="recentRuns.length > 0" class="divide-y divide-gray-200 dark:divide-gray-700">
                    <div
                        v-for="run in recentRuns"
                        :key="run.id"
                        class="px-4 py-4 sm:px-6"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', getStatusColor(run.status)]">
                                    {{ run.status_label }}
                                </span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ run.trigger_type }} trigger
                                </span>
                            </div>
                            <div class="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                                <span>{{ formatDateTime(run.started_at) }}</span>
                                <span>{{ formatDuration(run.duration_seconds) }}</span>
                            </div>
                        </div>
                        <div class="mt-2 flex items-center gap-4 text-sm">
                            <div class="flex items-center gap-1">
                                <CheckCircleIcon class="size-4 text-green-500" />
                                <span>{{ run.executed_actions_count }} executed</span>
                            </div>
                            <div v-if="run.pending_actions_count > 0" class="flex items-center gap-1">
                                <ClockIcon class="size-4 text-yellow-500" />
                                <span>{{ run.pending_actions_count }} pending</span>
                            </div>
                            <div class="flex items-center gap-1 text-gray-400">
                                <span>{{ run.actions_count }} total actions</span>
                            </div>
                        </div>
                        <div v-if="run.error_message" class="mt-2 text-sm text-red-600 dark:text-red-400">
                            {{ run.error_message }}
                        </div>
                        <div v-if="run.summary" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            <span v-for="(value, key) in run.summary" :key="key" class="mr-4">
                                {{ key.replace(/_/g, ' ') }}: <strong>{{ value }}</strong>
                            </span>
                        </div>
                    </div>
                </div>
                <div v-else class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                    No runs yet. Click "Run Now" to execute this agent.
                </div>
            </div>
        </div>

        <!-- Configuration Modal -->
        <Teleport to="body">
            <div v-if="showConfigModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showConfigModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <form @submit.prevent="saveSettings">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    Configure {{ agent.name }}
                                </h3>

                                <div class="space-y-4">
                                    <!-- Enable/Disable -->
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Enable Agent</label>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Allow this agent to run</p>
                                        </div>
                                        <button
                                            type="button"
                                            :class="[
                                                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2',
                                                form.is_enabled ? 'bg-indigo-600' : 'bg-gray-300 dark:bg-gray-600'
                                            ]"
                                            @click="form.is_enabled = !form.is_enabled"
                                        >
                                            <span :class="[
                                                'pointer-events-none inline-block size-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                                form.is_enabled ? 'translate-x-5' : 'translate-x-0'
                                            ]" />
                                        </button>
                                    </div>

                                    <!-- Permission Level -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Permission Level</label>
                                        <select
                                            v-model="form.permission_level"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option v-for="level in permissionLevels" :key="level.value" :value="level.value">
                                                {{ level.label }} - {{ level.description }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Dynamic Config Fields -->
                                    <div v-for="(field, key) in configSchema" :key="key">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ field.label }}</label>
                                        <p v-if="field.description" class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ field.description }}</p>

                                        <select
                                            v-if="field.type === 'select'"
                                            v-model="form.config[key]"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option v-for="(label, value) in field.options" :key="value" :value="value">
                                                {{ label }}
                                            </option>
                                        </select>

                                        <input
                                            v-else-if="field.type === 'number'"
                                            v-model.number="form.config[key]"
                                            type="number"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />

                                        <div
                                            v-else-if="field.type === 'boolean'"
                                            class="mt-1"
                                        >
                                            <button
                                                type="button"
                                                :class="[
                                                    'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2',
                                                    form.config[key] ? 'bg-indigo-600' : 'bg-gray-300 dark:bg-gray-600'
                                                ]"
                                                @click="form.config[key] = !form.config[key]"
                                            >
                                                <span :class="[
                                                    'pointer-events-none inline-block size-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                                    form.config[key] ? 'translate-x-5' : 'translate-x-0'
                                                ]" />
                                            </button>
                                        </div>

                                        <input
                                            v-else
                                            v-model="form.config[key]"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <div class="mt-5 sm:mt-6 flex flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        :disabled="form.processing"
                                        class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                    >
                                        {{ form.processing ? 'Saving...' : 'Save Configuration' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showConfigModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
