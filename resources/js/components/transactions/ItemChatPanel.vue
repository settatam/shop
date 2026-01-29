<script setup lang="ts">
import { ref, nextTick } from 'vue';
import { PaperAirplaneIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/20/solid';

interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
}

const props = defineProps<{
    transactionId: number;
    itemId: number;
}>();

const expanded = ref(false);
const messages = ref<ChatMessage[]>([]);
const input = ref('');
const sending = ref(false);
const sessionId = ref<string | null>(null);
const streamingContent = ref('');
const chatContainer = ref<HTMLElement | null>(null);

const scrollToBottom = () => {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
};

const sendMessage = async () => {
    const message = input.value.trim();
    if (!message || sending.value) return;

    messages.value.push({ role: 'user', content: message });
    input.value = '';
    sending.value = true;
    streamingContent.value = '';
    scrollToBottom();

    try {
        const response = await fetch(`/transactions/${props.transactionId}/items/${props.itemId}/chat`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Accept': 'text/event-stream',
            },
            body: JSON.stringify({
                message,
                session_id: sessionId.value,
            }),
        });

        const reader = response.body?.getReader();
        const decoder = new TextDecoder();

        if (!reader) return;

        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() || '';

            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                try {
                    const data = JSON.parse(line.slice(6));
                    if (data.type === 'token') {
                        streamingContent.value += data.content;
                        scrollToBottom();
                    } else if (data.type === 'done') {
                        sessionId.value = data.session_id;
                    }
                } catch {
                    // skip malformed lines
                }
            }
        }

        if (streamingContent.value) {
            messages.value.push({ role: 'assistant', content: streamingContent.value });
            streamingContent.value = '';
        }
    } catch {
        messages.value.push({ role: 'assistant', content: 'Sorry, something went wrong. Please try again.' });
    } finally {
        sending.value = false;
        scrollToBottom();
    }
};

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
};
</script>

<template>
    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <button
            type="button"
            class="flex w-full items-center justify-between px-4 py-5 sm:px-6"
            @click="expanded = !expanded"
        >
            <h3 class="text-base font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <ChatBubbleLeftRightIcon class="size-5 text-indigo-500" />
                Ask AI About This Item
            </h3>
        </button>

        <div v-if="expanded" class="border-t border-gray-200 dark:border-gray-700">
            <!-- Messages -->
            <div
                ref="chatContainer"
                class="max-h-80 overflow-y-auto px-4 py-4 space-y-3"
            >
                <div v-if="messages.length === 0" class="py-6 text-center">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Ask questions about this item's value, condition, or market trends.</p>
                </div>

                <div
                    v-for="(msg, i) in messages"
                    :key="i"
                    class="flex"
                    :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
                >
                    <div
                        class="max-w-[80%] rounded-lg px-3 py-2 text-sm"
                        :class="msg.role === 'user'
                            ? 'bg-indigo-600 text-white'
                            : 'bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-white'"
                    >
                        <p class="whitespace-pre-wrap">{{ msg.content }}</p>
                    </div>
                </div>

                <!-- Streaming response -->
                <div v-if="streamingContent" class="flex justify-start">
                    <div class="max-w-[80%] rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-900 dark:bg-gray-700 dark:text-white">
                        <p class="whitespace-pre-wrap">{{ streamingContent }}</p>
                        <span class="inline-block w-1.5 h-4 bg-gray-400 animate-pulse ml-0.5"></span>
                    </div>
                </div>

                <!-- Typing indicator -->
                <div v-if="sending && !streamingContent" class="flex justify-start">
                    <div class="rounded-lg bg-gray-100 px-3 py-2 dark:bg-gray-700">
                        <div class="flex gap-1">
                            <span class="h-2 w-2 rounded-full bg-gray-400 animate-bounce" style="animation-delay: 0ms"></span>
                            <span class="h-2 w-2 rounded-full bg-gray-400 animate-bounce" style="animation-delay: 150ms"></span>
                            <span class="h-2 w-2 rounded-full bg-gray-400 animate-bounce" style="animation-delay: 300ms"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Input -->
            <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="flex gap-2">
                    <input
                        v-model="input"
                        type="text"
                        placeholder="Ask about this item..."
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        :disabled="sending"
                        @keydown="handleKeydown"
                    />
                    <button
                        type="button"
                        :disabled="!input.trim() || sending"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                        @click="sendMessage"
                    >
                        <PaperAirplaneIcon class="size-4" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
