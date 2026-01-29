<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { useChat } from '@/composables/useChat';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { MoreVerticalIcon, PlusIcon, HistoryIcon, Trash2Icon } from 'lucide-vue-next';
import ChatMessages from './ChatMessages.vue';
import ChatInput from './ChatInput.vue';

const open = defineModel<boolean>('open', { default: false });

const {
    messages,
    sessions,
    currentSessionId,
    isLoading,
    isStreaming,
    error,
    toolStatus,
    loadSessions,
    loadSession,
    newSession,
    deleteSession,
    sendMessage,
    cancelStream,
} = useChat();

// Load sessions when panel opens
watch(open, (isOpen) => {
    if (isOpen) {
        loadSessions();
    }
});

onMounted(() => {
    if (open.value) {
        loadSessions();
    }
});

function handleSendMessage(content: string) {
    sendMessage(content);
}

function handleNewSession() {
    newSession();
}

function handleLoadSession(sessionId: string) {
    loadSession(sessionId);
}

function handleDeleteSession(sessionId: string) {
    deleteSession(sessionId);
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="w-full sm:max-w-md p-0 flex flex-col"
        >
            <SheetHeader class="px-4 py-3 border-b shrink-0">
                <div class="flex items-center justify-between">
                    <SheetTitle class="flex items-center gap-2">
                        <svg
                            class="size-5 text-primary"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611l-2.253.375A9.037 9.037 0 0112 21a9.037 9.037 0 01-5.882-.812l-2.253-.375c-1.717-.293-2.3-2.379-1.067-3.611L5 14.5"
                            />
                        </svg>
                        Ask AI
                    </SheetTitle>

                    <div class="flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            title="New chat"
                            @click="handleNewSession"
                        >
                            <PlusIcon class="size-4" />
                        </Button>

                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                >
                                    <MoreVerticalIcon class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    v-if="sessions.length > 0"
                                    disabled
                                    class="text-xs text-muted-foreground"
                                >
                                    <HistoryIcon class="size-4 mr-2" />
                                    Recent Chats
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-for="session in sessions.slice(0, 5)"
                                    :key="session.id"
                                    @click="handleLoadSession(session.id)"
                                >
                                    <span class="truncate">{{ session.title }}</span>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator v-if="currentSessionId && sessions.length > 0" />
                                <DropdownMenuItem
                                    v-if="currentSessionId"
                                    class="text-destructive focus:text-destructive"
                                    @click="handleDeleteSession(currentSessionId)"
                                >
                                    <Trash2Icon class="size-4 mr-2" />
                                    Delete this chat
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </SheetHeader>

            <div
                v-if="error"
                class="mx-4 mt-4 rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
            >
                {{ error }}
            </div>

            <ChatMessages
                :messages="messages"
                :tool-status="toolStatus"
                class="flex-1 min-h-0"
            />

            <ChatInput
                :is-streaming="isStreaming"
                :disabled="isLoading"
                @submit="handleSendMessage"
                @cancel="cancelStream"
            />
        </SheetContent>
    </Sheet>
</template>
