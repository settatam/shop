<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, nextTick, onMounted, onUnmounted } from 'vue';

interface Agent {
    id: number;
    name: string;
}

interface Customer {
    id: number;
    name: string;
    email: string | null;
}

interface Marketplace {
    id: number;
    platform: string;
    shop_domain: string | null;
}

interface Message {
    id: string;
    role: string;
    content: string;
    agent_id: number | null;
    agent: Agent | null;
    created_at: string;
}

interface Session {
    id: string;
    visitor_id: string;
    title: string | null;
    status: string;
    channel: string;
    assigned_agent_id: number | null;
    assigned_agent: Agent | null;
    customer: Customer | null;
    marketplace: Marketplace | null;
    assigned_at: string | null;
    closed_at: string | null;
    created_at: string;
}

const props = defineProps<{
    session: Session;
    messages: Message[];
}>();

const page = usePage();
const currentUserId = page.props.auth.user.id;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Conversations', href: '/conversations' },
    { title: props.session.title || 'Conversation', href: `/conversations/${props.session.id}` },
];

const allMessages = ref<Message[]>([...props.messages]);
const newMessage = ref('');
const sending = ref(false);
const sessionStatus = ref(props.session.status);
const assignedAgentId = ref(props.session.assigned_agent_id);
const assignedAgentName = ref(props.session.assigned_agent?.name || null);
const messagesContainer = ref<HTMLElement | null>(null);

const isAssignedToMe = ref(assignedAgentId.value === currentUserId);

function scrollToBottom(): void {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

async function assignToMe(): Promise<void> {
    try {
        await axios.post(`/api/v1/conversations/${props.session.id}/assign`);
        sessionStatus.value = 'assigned';
        assignedAgentId.value = currentUserId;
        assignedAgentName.value = page.props.auth.user.name;
        isAssignedToMe.value = true;
    } catch (error) {
        console.error('Failed to assign conversation:', error);
    }
}

async function releaseConversation(): Promise<void> {
    try {
        await axios.post(`/api/v1/conversations/${props.session.id}/release`);
        sessionStatus.value = 'open';
        assignedAgentId.value = null;
        assignedAgentName.value = null;
        isAssignedToMe.value = false;
    } catch (error) {
        console.error('Failed to release conversation:', error);
    }
}

async function closeConversation(): Promise<void> {
    try {
        await axios.post(`/api/v1/conversations/${props.session.id}/close`);
        sessionStatus.value = 'closed';
        assignedAgentId.value = null;
        assignedAgentName.value = null;
        isAssignedToMe.value = false;
    } catch (error) {
        console.error('Failed to close conversation:', error);
    }
}

async function sendMessage(): Promise<void> {
    if (!newMessage.value.trim() || sending.value) return;

    sending.value = true;
    const content = newMessage.value;
    newMessage.value = '';

    try {
        const { data } = await axios.post(`/api/v1/conversations/${props.session.id}/messages`, {
            content,
        });

        allMessages.value.push(data.message);
        scrollToBottom();
    } catch (error) {
        console.error('Failed to send message:', error);
        newMessage.value = content;
    } finally {
        sending.value = false;
    }
}

function roleLabel(role: string): string {
    const map: Record<string, string> = {
        user: 'Customer',
        assistant: 'AI',
        agent: 'Agent',
    };
    return map[role] || role;
}

function roleColor(role: string): string {
    const map: Record<string, string> = {
        user: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
        assistant: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        agent: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    };
    return map[role] || 'bg-gray-100 text-gray-800';
}

function statusLabel(status: string): string {
    const map: Record<string, string> = {
        open: 'Open',
        waiting_for_agent: 'Waiting for Agent',
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

function formatTime(dateStr: string): string {
    return new Date(dateStr).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}

// Polling for new messages when on the page
let pollInterval: ReturnType<typeof setInterval> | null = null;

function startPolling(): void {
    pollInterval = setInterval(async () => {
        try {
            const lastMessage = allMessages.value[allMessages.value.length - 1];
            const { data } = await axios.get(`/api/v1/conversations/${props.session.id}/messages`, {
                params: { page: 1 },
            });

            if (data.data && data.data.length > allMessages.value.length) {
                const newMsgs = data.data.slice(allMessages.value.length);
                allMessages.value.push(...newMsgs);
                scrollToBottom();
            }
        } catch {
            // Silently fail on poll errors
        }
    }, 5000);
}

function stopPolling(): void {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

onMounted(() => {
    scrollToBottom();
    startPolling();
});

onUnmounted(() => {
    stopPolling();
});
</script>

<template>
    <Head :title="session.title || 'Conversation'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 gap-0">
            <!-- Message Thread -->
            <div class="flex flex-1 flex-col">
                <!-- Messages -->
                <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4">
                    <div class="mx-auto max-w-3xl space-y-4">
                        <div v-for="message in allMessages" :key="message.id" class="flex gap-3">
                            <!-- Role indicator -->
                            <div class="shrink-0 pt-1">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="roleColor(message.role)"
                                >
                                    {{ roleLabel(message.role) }}
                                </span>
                            </div>

                            <!-- Message content -->
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2">
                                    <span v-if="message.agent" class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ message.agent.name }}
                                    </span>
                                    <span class="text-xs text-gray-400">
                                        {{ formatTime(message.created_at) }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-700 whitespace-pre-wrap dark:text-gray-300">
                                    {{ message.content }}
                                </p>
                            </div>
                        </div>

                        <div v-if="allMessages.length === 0" class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                            No messages yet.
                        </div>
                    </div>
                </div>

                <!-- Agent Input Bar -->
                <div
                    v-if="isAssignedToMe && sessionStatus !== 'closed'"
                    class="border-t border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                >
                    <form class="mx-auto flex max-w-3xl gap-3" @submit.prevent="sendMessage">
                        <input
                            v-model="newMessage"
                            type="text"
                            placeholder="Type a message..."
                            class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"
                            :disabled="sending"
                        />
                        <button
                            type="submit"
                            :disabled="sending || !newMessage.trim()"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ sending ? 'Sending...' : 'Send' }}
                        </button>
                    </form>
                </div>

                <!-- Closed state message -->
                <div
                    v-else-if="sessionStatus === 'closed'"
                    class="border-t border-gray-200 bg-gray-50 p-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                >
                    This conversation has been closed.
                </div>

                <!-- Not assigned to me -->
                <div
                    v-else-if="!isAssignedToMe && sessionStatus !== 'closed'"
                    class="border-t border-gray-200 bg-gray-50 p-4 text-center dark:border-gray-700 dark:bg-gray-800"
                >
                    <button
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        @click="assignToMe"
                    >
                        Assign to Me
                    </button>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="w-80 shrink-0 border-l border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Details</h3>

                <dl class="mt-4 space-y-4">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">Status</dt>
                        <dd class="mt-1">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statusColor(sessionStatus)"
                            >
                                {{ statusLabel(sessionStatus) }}
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">Channel</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            {{ session.channel }}
                        </dd>
                    </div>

                    <div v-if="session.customer">
                        <dt class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">Customer</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            {{ session.customer.name }}
                            <div v-if="session.customer.email" class="text-xs text-gray-500">
                                {{ session.customer.email }}
                            </div>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">Visitor ID</dt>
                        <dd class="mt-1 text-xs font-mono text-gray-600 dark:text-gray-400">
                            {{ session.visitor_id }}
                        </dd>
                    </div>

                    <div v-if="assignedAgentName">
                        <dt class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">Assigned Agent</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            {{ assignedAgentName }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">Started</dt>
                        <dd class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ formatDate(session.created_at) }}
                        </dd>
                    </div>

                    <div v-if="session.marketplace">
                        <dt class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">Platform</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            {{ session.marketplace.platform }}
                        </dd>
                    </div>
                </dl>

                <!-- Actions -->
                <div class="mt-6 space-y-2">
                    <button
                        v-if="sessionStatus === 'open' || sessionStatus === 'waiting_for_agent'"
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        @click="assignToMe"
                    >
                        Assign to Me
                    </button>

                    <button
                        v-if="isAssignedToMe"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                        @click="releaseConversation"
                    >
                        Release
                    </button>

                    <button
                        v-if="sessionStatus !== 'closed'"
                        class="w-full rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-600 dark:text-red-400 dark:hover:bg-red-900/30"
                        @click="closeConversation"
                    >
                        Close Conversation
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
