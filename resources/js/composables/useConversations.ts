import { ref, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

interface ConversationEvent {
    id: string;
    status?: string;
    assigned_agent_id?: number | null;
    assigned_agent_name?: string | null;
    visitor_id?: string;
    channel?: string;
    title?: string | null;
    first_message?: string | null;
    created_at?: string;
    closed_at?: string | null;
}

interface MessageEvent {
    id: string;
    session_id: string;
    role: string;
    content: string;
    agent_id: number | null;
    agent_name: string | null;
    created_at: string;
}

export function useConversations(storeId: number) {
    const newConversations = ref<ConversationEvent[]>([]);
    const statusChanges = ref<ConversationEvent[]>([]);
    const echoChannel = ref<unknown>(null);

    function listen(): void {
        if (typeof window === 'undefined' || !(window as any).Echo) {
            return;
        }

        const channel = (window as any).Echo.private(`store.${storeId}.conversations`);
        echoChannel.value = channel;

        channel.listen('.NewConversation', (event: ConversationEvent) => {
            newConversations.value.push(event);
        });

        channel.listen('.ConversationStatusChanged', (event: ConversationEvent) => {
            statusChanges.value.push(event);
        });
    }

    function stopListening(): void {
        if (echoChannel.value && typeof window !== 'undefined' && (window as any).Echo) {
            (window as any).Echo.leave(`store.${storeId}.conversations`);
        }
    }

    onMounted(() => {
        listen();
    });

    onUnmounted(() => {
        stopListening();
    });

    return {
        newConversations,
        statusChanges,
    };
}

export function useConversationMessages(sessionId: string) {
    const incomingMessages = ref<MessageEvent[]>([]);
    const echoChannel = ref<unknown>(null);

    function listen(): void {
        if (typeof window === 'undefined' || !(window as any).Echo) {
            return;
        }

        const channel = (window as any).Echo.private(`conversation.${sessionId}`);
        echoChannel.value = channel;

        channel.listen('.NewChatMessage', (event: MessageEvent) => {
            incomingMessages.value.push(event);
        });

        channel.listen('.ConversationStatusChanged', (event: ConversationEvent) => {
            // Status changed on this specific conversation
        });
    }

    function stopListening(): void {
        if (echoChannel.value && typeof window !== 'undefined' && (window as any).Echo) {
            (window as any).Echo.leave(`conversation.${sessionId}`);
        }
    }

    onMounted(() => {
        listen();
    });

    onUnmounted(() => {
        stopListening();
    });

    return {
        incomingMessages,
    };
}
