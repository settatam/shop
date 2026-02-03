<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, watch } from 'vue';
import {
    ArrowLeftIcon,
    PaperAirplaneIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    ClockIcon,
    CheckIcon,
    UserIcon,
    ChatBubbleLeftEllipsisIcon,
} from '@heroicons/vue/20/solid';

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

interface Props {
    message: SmsMessage;
    conversation: SmsMessage[];
    templates: Template[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Messages', href: '/messages' },
    { title: props.message.customer_name || 'Conversation', href: `/messages/${props.message.id}` },
];

// SMS character limit
const SMS_CHAR_LIMIT = 160;
const SMS_EXTENDED_LIMIT = 1600;

// Compose form
const smsForm = useForm({
    message: '',
});

const messageList = ref<HTMLElement | null>(null);
const showTemplates = ref(false);
const characterCount = computed(() => smsForm.message.length);
const segmentCount = computed(() => Math.ceil(smsForm.message.length / SMS_CHAR_LIMIT) || 0);

const canSendSms = computed(() => {
    return (
        props.message.customer_phone &&
        props.message.transaction_id &&
        smsForm.message.trim().length > 0 &&
        smsForm.message.length <= SMS_EXTENDED_LIMIT
    );
});

function scrollToBottom() {
    nextTick(() => {
        if (messageList.value) {
            messageList.value.scrollTop = messageList.value.scrollHeight;
        }
    });
}

onMounted(() => {
    scrollToBottom();
});

watch(() => props.conversation, () => {
    scrollToBottom();
}, { deep: true });

function sendSms() {
    if (!canSendSms.value || !props.message.transaction_id) return;

    smsForm.post(`/transactions/${props.message.transaction_id}/send-sms`, {
        preserveScroll: true,
        onSuccess: () => {
            smsForm.reset();
            scrollToBottom();
        },
    });
}

function useTemplate(template: Template) {
    smsForm.message = template.content;
    showTemplates.value = false;
}

const formatTime = (dateString: string | null) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
    });
};

const formatDate = (dateString: string | null) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    } else if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: date.getFullYear() !== today.getFullYear() ? 'numeric' : undefined,
    });
};

// Group messages by date
const groupedMessages = computed(() => {
    const groups: { date: string; messages: SmsMessage[] }[] = [];
    let currentDate = '';

    for (const message of props.conversation) {
        const msgDate = formatDate(message.sent_at || message.created_at);
        if (msgDate !== currentDate) {
            currentDate = msgDate;
            groups.push({ date: msgDate, messages: [] });
        }
        groups[groups.length - 1].messages.push(message);
    }

    return groups;
});

const getStatusIcon = (message: SmsMessage) => {
    if (message.direction === 'inbound') return null;

    switch (message.status) {
        case 'delivered':
            return { icon: CheckCircleIcon, class: 'text-blue-400' };
        case 'sent':
            return { icon: CheckIcon, class: 'text-gray-400' };
        case 'failed':
        case 'error':
            return { icon: ExclamationCircleIcon, class: 'text-red-400' };
        case 'pending':
        case 'queued':
        default:
            return { icon: ClockIcon, class: 'text-gray-400' };
    }
};

// Group templates by category
const groupedTemplates = computed(() => {
    const groups: Record<string, Template[]> = {};
    for (const template of props.templates) {
        const category = template.category || 'General';
        if (!groups[category]) {
            groups[category] = [];
        }
        groups[category].push(template);
    }
    return groups;
});
</script>

<template>
    <Head :title="`Message - ${message.customer_name || 'Conversation'}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="mb-4 flex items-center gap-4">
                <Link
                    href="/messages"
                    class="inline-flex items-center gap-x-1 text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                >
                    <ArrowLeftIcon class="size-4" />
                    Back to Messages
                </Link>
            </div>

            <div class="flex flex-1 gap-6">
                <!-- Conversation Panel -->
                <div class="flex-1 flex flex-col rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <!-- Customer Header -->
                    <div class="flex items-center gap-4 border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                        <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/30">
                            <UserIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div class="flex-1">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                {{ message.customer_name || 'Unknown Customer' }}
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ message.customer_phone || 'No phone number' }}
                            </p>
                        </div>
                        <div v-if="message.transaction_id" class="text-right">
                            <Link
                                :href="`/transactions/${message.transaction_id}`"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                            >
                                View Transaction {{ message.transaction_number || `#${message.transaction_id}` }}
                            </Link>
                        </div>
                    </div>

                    <!-- Messages List (iPhone style) -->
                    <div
                        ref="messageList"
                        class="flex-1 overflow-y-auto px-4 py-4 bg-gray-50 dark:bg-gray-900/30"
                    >
                        <div v-if="conversation.length === 0" class="flex flex-col items-center justify-center h-full">
                            <ChatBubbleLeftEllipsisIcon class="h-12 w-12 text-gray-400" />
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No messages in this conversation</p>
                        </div>

                        <template v-for="group in groupedMessages" :key="group.date">
                            <!-- Date Separator -->
                            <div class="flex justify-center my-4">
                                <span class="px-3 py-1 text-xs text-gray-500 dark:text-gray-400 bg-gray-200/70 dark:bg-gray-700/70 rounded-full">
                                    {{ group.date }}
                                </span>
                            </div>

                            <!-- Messages in group -->
                            <div
                                v-for="msg in group.messages"
                                :key="msg.id"
                                class="mb-3"
                                :class="msg.direction === 'outbound' ? 'flex justify-end' : 'flex justify-start'"
                            >
                                <div
                                    class="max-w-[75%] px-4 py-2.5 rounded-2xl"
                                    :class="[
                                        msg.direction === 'outbound'
                                            ? 'bg-blue-500 text-white rounded-br-md'
                                            : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white rounded-bl-md shadow-sm'
                                    ]"
                                >
                                    <!-- Message Content -->
                                    <p class="text-sm whitespace-pre-wrap break-words">
                                        {{ msg.content }}
                                    </p>

                                    <!-- Message Meta -->
                                    <div
                                        class="mt-1 flex items-center gap-1 text-[10px]"
                                        :class="msg.direction === 'outbound' ? 'justify-end text-blue-100' : 'text-gray-500 dark:text-gray-400'"
                                    >
                                        <span>{{ formatTime(msg.sent_at || msg.created_at) }}</span>
                                        <!-- Status indicator for outbound messages -->
                                        <component
                                            v-if="getStatusIcon(msg)"
                                            :is="getStatusIcon(msg)?.icon"
                                            class="size-3"
                                            :class="msg.direction === 'outbound' ? 'text-blue-200' : getStatusIcon(msg)?.class"
                                        />
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Compose Area -->
                    <div class="border-t border-gray-200 px-4 py-4 dark:border-gray-700">
                        <form v-if="message.transaction_id && message.customer_phone" @submit.prevent="sendSms" class="flex items-end gap-3">
                            <div class="flex-1">
                                <div class="relative">
                                    <textarea
                                        v-model="smsForm.message"
                                        rows="3"
                                        maxlength="1600"
                                        class="block w-full rounded-xl border-0 py-3 px-4 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-500 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400 resize-none"
                                        placeholder="Type a message..."
                                        @keydown.enter.exact.prevent="sendSms"
                                    ></textarea>
                                </div>
                                <div class="mt-1 flex items-center justify-between px-1">
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="text-xs text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                            @click="showTemplates = !showTemplates"
                                        >
                                            {{ showTemplates ? 'Hide Templates' : 'Use Template' }}
                                        </button>
                                    </div>
                                    <span class="text-[10px] text-gray-400">
                                        {{ characterCount }}/{{ SMS_EXTENDED_LIMIT }}
                                        <span v-if="segmentCount > 1">({{ segmentCount }} segments)</span>
                                    </span>
                                </div>
                            </div>
                            <button
                                type="submit"
                                :disabled="!canSendSms || smsForm.processing"
                                class="flex-shrink-0 rounded-full bg-blue-500 p-3 text-white shadow-sm hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                <PaperAirplaneIcon class="size-5" />
                            </button>
                        </form>

                        <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic text-center py-4">
                            {{ !message.transaction_id ? 'No transaction linked to send a reply.' : 'No phone number on file.' }}
                        </p>
                    </div>
                </div>

                <!-- Templates Sidebar -->
                <div
                    v-if="showTemplates && templates.length > 0"
                    class="w-80 rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 overflow-hidden"
                >
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Message Templates</h3>
                    </div>
                    <div class="max-h-96 overflow-y-auto">
                        <div v-for="(categoryTemplates, category) in groupedTemplates" :key="category" class="border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                            <div class="px-4 py-2 bg-gray-50 dark:bg-gray-900/50">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ category }}</span>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                                <button
                                    v-for="template in categoryTemplates"
                                    :key="template.id"
                                    type="button"
                                    class="w-full px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                    @click="useTemplate(template)"
                                >
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ template.name }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400 line-clamp-2">{{ template.content }}</div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
