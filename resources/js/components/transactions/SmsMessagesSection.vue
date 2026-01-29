<script setup lang="ts">
import { ref, computed, nextTick, onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    PaperAirplaneIcon,
    ChatBubbleLeftEllipsisIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    ClockIcon,
    CheckIcon,
} from '@heroicons/vue/20/solid';

interface SmsMessage {
    id: number;
    content: string;
    channel: string;
    direction: 'inbound' | 'outbound';
    status: string;
    recipient: string;
    sent_at: string | null;
    delivered_at: string | null;
    created_at: string;
}

interface Props {
    messages: SmsMessage[];
    transactionId: number;
    customerPhone: string | null;
}

const props = defineProps<Props>();

// SMS character limit (standard SMS)
const SMS_CHAR_LIMIT = 160;
const SMS_EXTENDED_LIMIT = 1600;

// Compose form
const smsForm = useForm({
    message: '',
});

const messageList = ref<HTMLElement | null>(null);
const characterCount = computed(() => smsForm.message.length);
const segmentCount = computed(() => Math.ceil(smsForm.message.length / SMS_CHAR_LIMIT) || 0);

const canSendSms = computed(() => {
    return (
        props.customerPhone &&
        smsForm.message.trim().length > 0 &&
        smsForm.message.length <= SMS_EXTENDED_LIMIT
    );
});

// Sort messages chronologically (oldest first for conversation flow)
const sortedMessages = computed(() => {
    return [...props.messages].sort((a, b) => {
        const dateA = new Date(a.sent_at || a.created_at).getTime();
        const dateB = new Date(b.sent_at || b.created_at).getTime();
        return dateA - dateB;
    });
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

function sendSms() {
    if (!canSendSms.value) return;

    smsForm.post(`/transactions/${props.transactionId}/send-sms`, {
        preserveScroll: true,
        onSuccess: () => {
            smsForm.reset();
            scrollToBottom();
        },
    });
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

    for (const message of sortedMessages.value) {
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

const hasMessages = computed(() => props.messages && props.messages.length > 0);
</script>

<template>
    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="px-4 py-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Messages</h3>
                <span v-if="customerPhone" class="text-xs text-gray-500 dark:text-gray-400">
                    {{ customerPhone }}
                </span>
            </div>

            <!-- Messages List (iPhone style) -->
            <div
                ref="messageList"
                class="h-80 overflow-y-auto px-2 py-2 bg-gray-50 dark:bg-gray-900/50 rounded-lg mb-4"
            >
                <div v-if="!hasMessages" class="flex flex-col items-center justify-center h-full">
                    <ChatBubbleLeftEllipsisIcon class="h-8 w-8 text-gray-400" />
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No messages yet</p>
                </div>

                <template v-for="group in groupedMessages" :key="group.date">
                    <!-- Date Separator -->
                    <div class="flex justify-center my-3">
                        <span class="px-3 py-1 text-xs text-gray-500 dark:text-gray-400 bg-gray-200/70 dark:bg-gray-700/70 rounded-full">
                            {{ group.date }}
                        </span>
                    </div>

                    <!-- Messages in group -->
                    <div
                        v-for="message in group.messages"
                        :key="message.id"
                        class="mb-2"
                        :class="message.direction === 'outbound' ? 'flex justify-end' : 'flex justify-start'"
                    >
                        <div
                            class="max-w-[80%] px-3 py-2 rounded-2xl"
                            :class="[
                                message.direction === 'outbound'
                                    ? 'bg-blue-500 text-white rounded-br-md'
                                    : 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-bl-md'
                            ]"
                        >
                            <!-- Message Content -->
                            <p class="text-sm whitespace-pre-wrap break-words">
                                {{ message.content }}
                            </p>

                            <!-- Message Meta -->
                            <div
                                class="mt-1 flex items-center gap-1 text-[10px]"
                                :class="message.direction === 'outbound' ? 'justify-end text-blue-100' : 'text-gray-500 dark:text-gray-400'"
                            >
                                <span>{{ formatTime(message.sent_at || message.created_at) }}</span>
                                <!-- Status indicator for outbound messages -->
                                <component
                                    v-if="getStatusIcon(message)"
                                    :is="getStatusIcon(message)?.icon"
                                    class="size-3"
                                    :class="message.direction === 'outbound' ? 'text-blue-200' : getStatusIcon(message)?.class"
                                />
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Compose Area -->
            <div v-if="customerPhone" class="border-t border-gray-200 dark:border-gray-700 pt-4">
                <form @submit.prevent="sendSms" class="flex items-end gap-2">
                    <div class="flex-1">
                        <label for="sms-message" class="sr-only">Message</label>
                        <textarea
                            id="sms-message"
                            v-model="smsForm.message"
                            rows="2"
                            maxlength="1600"
                            class="block w-full rounded-xl border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-500 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400 resize-none"
                            placeholder="Text message"
                            @keydown.enter.exact.prevent="sendSms"
                            @keydown.enter.shift="() => {}"
                        ></textarea>
                        <div class="mt-1 flex items-center justify-between px-1">
                            <span class="text-[10px] text-gray-400">
                                {{ characterCount }}/{{ SMS_EXTENDED_LIMIT }}
                                <span v-if="segmentCount > 1">({{ segmentCount }} segments)</span>
                            </span>
                            <span v-if="smsForm.errors.message" class="text-[10px] text-red-500">
                                {{ smsForm.errors.message }}
                            </span>
                        </div>
                    </div>
                    <button
                        type="submit"
                        :disabled="!canSendSms || smsForm.processing"
                        class="flex-shrink-0 rounded-full bg-blue-500 p-2 text-white shadow-sm hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        <PaperAirplaneIcon class="size-5" />
                    </button>
                </form>
            </div>

            <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic text-center py-4 border-t border-gray-200 dark:border-gray-700 mt-4">
                No phone number on file for this customer.
            </p>
        </div>
    </div>
</template>
