<script setup lang="ts">
import type { ChatMessage as ChatMessageType } from '@/composables/useChat';
import { ref, watch, nextTick } from 'vue';
import ChatMessage from './ChatMessage.vue';
import ChatTypingIndicator from './ChatTypingIndicator.vue';

const props = defineProps<{
    messages: ChatMessageType[];
    toolStatus: string | null;
}>();

const messagesContainer = ref<HTMLElement | null>(null);

// Auto-scroll to bottom when new messages arrive
watch(
    () => props.messages.length,
    async () => {
        await nextTick();
        scrollToBottom();
    }
);

watch(
    () => props.toolStatus,
    async () => {
        await nextTick();
        scrollToBottom();
    }
);

function scrollToBottom() {
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
}

defineExpose({ scrollToBottom });
</script>

<template>
    <div
        ref="messagesContainer"
        class="flex-1 overflow-y-auto"
    >
        <div
            v-if="messages.length === 0"
            class="flex flex-col items-center justify-center h-full px-6 text-center"
        >
            <div class="rounded-full bg-primary/10 p-4 mb-4">
                <svg
                    class="size-8 text-primary"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.5"
                        d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                    />
                </svg>
            </div>
            <h3 class="font-semibold text-foreground">How can I help you today?</h3>
            <p class="text-sm text-muted-foreground mt-1 max-w-xs">
                Ask me about your sales, orders, inventory, or anything else about your store.
            </p>
            <div class="mt-6 space-y-2 w-full max-w-xs">
                <p class="text-xs text-muted-foreground font-medium uppercase tracking-wide">
                    Try asking:
                </p>
                <div class="space-y-1.5">
                    <div class="text-sm text-muted-foreground bg-muted/50 rounded-lg px-3 py-2">
                        "How did we do today?"
                    </div>
                    <div class="text-sm text-muted-foreground bg-muted/50 rounded-lg px-3 py-2">
                        "What were our sales last week?"
                    </div>
                </div>
            </div>
        </div>

        <div
            v-else
            class="py-4"
        >
            <ChatMessage
                v-for="message in messages"
                :key="message.id"
                :message="message"
            />

            <ChatTypingIndicator
                v-if="toolStatus"
                :status="toolStatus"
            />
        </div>
    </div>
</template>
