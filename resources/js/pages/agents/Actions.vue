<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CheckCircleIcon,
    XCircleIcon,
    ClockIcon,
    ExclamationCircleIcon,
    CurrencyDollarIcon,
    BellIcon,
    TagIcon,
} from '@heroicons/vue/20/solid';
import { ref, computed } from 'vue';

interface Action {
    id: number;
    action_type: string;
    status: string;
    status_label: string;
    status_color: string;
    requires_approval: boolean;
    agent_name: string;
    agent_slug: string;
    actionable_type: string;
    actionable_id: number;
    actionable_title: string;
    payload: Record<string, any>;
    before: any;
    after: any;
    reasoning: string | null;
    approved_by: string | null;
    approved_at: string | null;
    executed_at: string | null;
    created_at: string;
}

interface Counts {
    pending: number;
    executed: number;
    rejected: number;
}

interface Props {
    actions: Action[];
    currentStatus: string;
    counts: Counts;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Agents', href: '/agents' },
    { title: 'Actions', href: '/agents/actions' },
];

const selectedActions = ref<number[]>([]);
const showDetailModal = ref(false);
const selectedAction = ref<Action | null>(null);

const allSelected = computed({
    get: () => props.actions.length > 0 && selectedActions.value.length === props.actions.filter(a => a.status === 'pending').length,
    set: (value: boolean) => {
        selectedActions.value = value ? props.actions.filter(a => a.status === 'pending').map(a => a.id) : [];
    },
});

function toggleSelection(actionId: number) {
    const index = selectedActions.value.indexOf(actionId);
    if (index === -1) {
        selectedActions.value.push(actionId);
    } else {
        selectedActions.value.splice(index, 1);
    }
}

function approveAction(action: Action) {
    router.post(`/agents/actions/${action.id}/approve`, {}, {
        preserveScroll: true,
    });
}

function rejectAction(action: Action) {
    router.post(`/agents/actions/${action.id}/reject`, {}, {
        preserveScroll: true,
    });
}

function bulkApprove() {
    if (selectedActions.value.length === 0) return;
    router.post('/agents/actions/bulk-approve', {
        action_ids: selectedActions.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedActions.value = [];
        },
    });
}

function bulkReject() {
    if (selectedActions.value.length === 0) return;
    router.post('/agents/actions/bulk-reject', {
        action_ids: selectedActions.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedActions.value = [];
        },
    });
}

function showDetails(action: Action) {
    selectedAction.value = action;
    showDetailModal.value = true;
}

function formatDateTime(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString();
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function getActionIcon(actionType: string) {
    switch (actionType) {
        case 'price_update':
            return CurrencyDollarIcon;
        case 'markdown_schedule':
            return TagIcon;
        case 'send_notification':
            return BellIcon;
        default:
            return ExclamationCircleIcon;
    }
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'pending':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
        case 'approved':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
        case 'executed':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        case 'rejected':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
        case 'failed':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
    }
}
</script>

<template>
    <Head title="Agent Actions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Agent Actions</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Review and approve actions proposed by AI agents
                    </p>
                </div>
                <div v-if="selectedActions.length > 0" class="flex gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                        @click="bulkApprove"
                    >
                        <CheckCircleIcon class="-ml-0.5 size-5" />
                        Approve Selected ({{ selectedActions.length }})
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                        @click="bulkReject"
                    >
                        <XCircleIcon class="-ml-0.5 size-5" />
                        Reject Selected
                    </button>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
                <nav class="-mb-px flex space-x-8">
                    <Link
                        href="/agents/actions?status=pending"
                        :class="[
                            'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                            currentStatus === 'pending' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                        ]"
                    >
                        Pending
                        <span v-if="counts.pending > 0" class="ml-2 rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                            {{ counts.pending }}
                        </span>
                    </Link>
                    <Link
                        href="/agents/actions?status=executed"
                        :class="[
                            'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                            currentStatus === 'executed' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                        ]"
                    >
                        Executed
                        <span class="ml-2 text-gray-400">{{ counts.executed }}</span>
                    </Link>
                    <Link
                        href="/agents/actions?status=rejected"
                        :class="[
                            'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                            currentStatus === 'rejected' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                        ]"
                    >
                        Rejected
                        <span class="ml-2 text-gray-400">{{ counts.rejected }}</span>
                    </Link>
                    <Link
                        href="/agents/actions?status=all"
                        :class="[
                            'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                            currentStatus === 'all' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                        ]"
                    >
                        All
                    </Link>
                </nav>
            </div>

            <!-- Actions Table -->
            <div v-if="actions.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th v-if="currentStatus === 'pending'" scope="col" class="relative w-12 px-6 sm:w-16 sm:px-8">
                                <input
                                    type="checkbox"
                                    v-model="allSelected"
                                    class="absolute left-4 top-1/2 -mt-2 size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                />
                            </th>
                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                Action
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Target
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Change
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Agent
                            </th>
                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                Status
                            </th>
                            <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr
                            v-for="action in actions"
                            :key="action.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                        >
                            <td v-if="currentStatus === 'pending'" class="relative w-12 px-6 sm:w-16 sm:px-8">
                                <input
                                    type="checkbox"
                                    :checked="selectedActions.includes(action.id)"
                                    @change="toggleSelection(action.id)"
                                    class="absolute left-4 top-1/2 -mt-2 size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                />
                            </td>
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 sm:pl-6">
                                <div class="flex items-center gap-2">
                                    <component :is="getActionIcon(action.action_type)" class="size-5 text-gray-400" />
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ action.action_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) }}
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <div class="text-gray-900 dark:text-white">{{ action.actionable_title }}</div>
                                <div class="text-gray-500 dark:text-gray-400 text-xs">{{ action.actionable_type }} #{{ action.actionable_id }}</div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <div v-if="action.action_type === 'price_update' || action.action_type === 'markdown_schedule'" class="flex items-center gap-1">
                                    <span class="text-gray-500 line-through">{{ formatCurrency(action.before?.price || 0) }}</span>
                                    <span class="text-gray-400">&rarr;</span>
                                    <span class="font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(action.after?.price || 0) }}</span>
                                </div>
                                <div v-else class="text-gray-500 dark:text-gray-400">
                                    {{ action.payload?.notification_type || '-' }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <Link
                                    :href="`/agents/${action.agent_slug}`"
                                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    {{ action.agent_name }}
                                </Link>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', getStatusColor(action.status)]">
                                    {{ action.status_label }}
                                </span>
                            </td>
                            <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="text-gray-400 hover:text-gray-500"
                                        @click="showDetails(action)"
                                    >
                                        Details
                                    </button>
                                    <template v-if="action.status === 'pending'">
                                        <button
                                            type="button"
                                            class="text-green-600 hover:text-green-500"
                                            @click="approveAction(action)"
                                        >
                                            Approve
                                        </button>
                                        <button
                                            type="button"
                                            class="text-red-600 hover:text-red-500"
                                            @click="rejectAction(action)"
                                        >
                                            Reject
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-12 bg-white rounded-lg shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                <ClockIcon class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No {{ currentStatus }} actions</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Actions will appear here when agents propose changes.
                </p>
            </div>
        </div>

        <!-- Detail Modal -->
        <Teleport to="body">
            <div v-if="showDetailModal && selectedAction" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showDetailModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                Action Details
                            </h3>

                            <dl class="space-y-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Action Type</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ selectedAction.action_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Target</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ selectedAction.actionable_title }} ({{ selectedAction.actionable_type }} #{{ selectedAction.actionable_id }})
                                    </dd>
                                </div>
                                <div v-if="selectedAction.before?.price !== undefined">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Price Change</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ formatCurrency(selectedAction.before.price) }} &rarr; {{ formatCurrency(selectedAction.after.price) }}
                                        <span v-if="selectedAction.payload?.discount_percent" class="ml-2 text-green-600 dark:text-green-400">
                                            ({{ selectedAction.payload.discount_percent }}% off)
                                        </span>
                                    </dd>
                                </div>
                                <div v-if="selectedAction.reasoning">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">AI Reasoning</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ selectedAction.reasoning }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Created</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ formatDateTime(selectedAction.created_at) }}
                                    </dd>
                                </div>
                                <div v-if="selectedAction.approved_by">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                        {{ selectedAction.status === 'rejected' ? 'Rejected' : 'Approved' }} By
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ selectedAction.approved_by }} at {{ formatDateTime(selectedAction.approved_at) }}
                                    </dd>
                                </div>
                                <div v-if="selectedAction.executed_at">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Executed</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ formatDateTime(selectedAction.executed_at) }}
                                    </dd>
                                </div>
                            </dl>

                            <div class="mt-5 sm:mt-6">
                                <button
                                    type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    @click="showDetailModal = false"
                                >
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
