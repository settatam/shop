<script setup lang="ts">
import type { ChatMessage } from '@/composables/useChat';
import { computed } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

const props = defineProps<{
    message: ChatMessage;
}>();

const isUser = computed(() => props.message.role === 'user');

const formattedContent = computed(() => {
    // Basic markdown-like formatting for assistant messages
    if (isUser.value) return props.message.content;

    return props.message.content
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/`(.*?)`/g, '<code class="bg-muted px-1 py-0.5 rounded text-sm">$1</code>')
        .replace(/\n/g, '<br>');
});
</script>

<template>
    <div
        :class="
            cn(
                'flex gap-3 px-4 py-3',
                isUser ? 'flex-row-reverse' : 'flex-row'
            )
        "
    >
        <Avatar class="size-8 shrink-0">
            <AvatarFallback
                :class="
                    cn(
                        'text-xs font-medium',
                        isUser ? 'bg-primary text-primary-foreground' : 'bg-secondary'
                    )
                "
            >
                {{ isUser ? 'You' : 'AI' }}
            </AvatarFallback>
        </Avatar>

        <div
            :class="
                cn(
                    'max-w-[80%] rounded-2xl px-4 py-2 text-sm',
                    isUser
                        ? 'bg-primary text-primary-foreground rounded-br-sm'
                        : 'bg-muted rounded-bl-sm'
                )
            "
        >
            <div
                v-if="isUser"
                class="whitespace-pre-wrap"
            >
                {{ message.content }}
            </div>
            <div
                v-else
                class="prose prose-sm dark:prose-invert max-w-none"
                v-html="formattedContent"
            />

            <span
                v-if="message.isStreaming"
                class="inline-block w-2 h-4 ml-0.5 bg-current animate-pulse"
            />
        </div>
    </div>
</template>
