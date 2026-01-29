<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { SendHorizontalIcon, StopCircleIcon } from 'lucide-vue-next';

const props = defineProps<{
    isStreaming?: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    submit: [message: string];
    cancel: [];
}>();

const message = ref('');
const textareaRef = ref<HTMLTextAreaElement | null>(null);

function handleSubmit() {
    if (!message.value.trim() || props.isStreaming) return;

    emit('submit', message.value);
    message.value = '';

    // Reset textarea height
    if (textareaRef.value) {
        textareaRef.value.style.height = 'auto';
    }
}

function handleKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        handleSubmit();
    }
}

function handleCancel() {
    emit('cancel');
}

// Auto-resize textarea
watch(message, () => {
    if (textareaRef.value) {
        textareaRef.value.style.height = 'auto';
        textareaRef.value.style.height = `${Math.min(textareaRef.value.scrollHeight, 120)}px`;
    }
});
</script>

<template>
    <div class="border-t bg-background p-4">
        <form
            @submit.prevent="handleSubmit"
            class="flex items-end gap-2"
        >
            <div class="relative flex-1">
                <textarea
                    ref="textareaRef"
                    v-model="message"
                    :disabled="disabled"
                    placeholder="Ask me anything about your store..."
                    rows="1"
                    class="w-full resize-none rounded-xl border bg-muted/50 px-4 py-3 pr-12 text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:opacity-50 placeholder:text-muted-foreground"
                    @keydown="handleKeydown"
                />
            </div>

            <Button
                v-if="isStreaming"
                type="button"
                variant="outline"
                size="icon"
                class="shrink-0 rounded-xl h-11 w-11"
                @click="handleCancel"
            >
                <StopCircleIcon class="size-5" />
                <span class="sr-only">Stop</span>
            </Button>

            <Button
                v-else
                type="submit"
                size="icon"
                class="shrink-0 rounded-xl h-11 w-11"
                :disabled="!message.trim() || disabled"
            >
                <Spinner
                    v-if="disabled"
                    class="size-5"
                />
                <SendHorizontalIcon
                    v-else
                    class="size-5"
                />
                <span class="sr-only">Send</span>
            </Button>
        </form>

        <p class="mt-2 text-xs text-center text-muted-foreground">
            AI responses may not always be accurate. Verify important data.
        </p>
    </div>
</template>
