import { ref, computed } from 'vue';
import axios from 'axios';

export interface ChatMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    created_at: string;
    isStreaming?: boolean;
}

export interface ChatSession {
    id: string;
    title: string;
    last_message_at: string | null;
    created_at: string;
}

export interface ToolUseEvent {
    tool: string;
    status: string;
}

export function useChat() {
    const messages = ref<ChatMessage[]>([]);
    const sessions = ref<ChatSession[]>([]);
    const currentSessionId = ref<string | null>(null);
    const isLoading = ref(false);
    const isStreaming = ref(false);
    const error = ref<string | null>(null);
    const toolStatus = ref<string | null>(null);

    let abortController: AbortController | null = null;

    const hasMessages = computed(() => messages.value.length > 0);

    /**
     * Load recent chat sessions.
     */
    async function loadSessions(limit = 10): Promise<void> {
        try {
            const response = await axios.get<{ data: ChatSession[] }>('/api/v1/chat/sessions', {
                params: { limit },
            });
            sessions.value = response.data.data;
        } catch (err) {
            console.error('Failed to load sessions:', err);
        }
    }

    /**
     * Load a specific session with its messages.
     */
    async function loadSession(sessionId: string): Promise<void> {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await axios.get<{
                data: ChatSession & { messages: ChatMessage[] };
            }>(`/api/v1/chat/sessions/${sessionId}`);

            currentSessionId.value = sessionId;
            messages.value = response.data.data.messages;
        } catch (err) {
            error.value = 'Failed to load session';
            console.error('Failed to load session:', err);
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Start a new chat session.
     */
    function newSession(): void {
        currentSessionId.value = null;
        messages.value = [];
        error.value = null;
    }

    /**
     * Delete a chat session.
     */
    async function deleteSession(sessionId: string): Promise<void> {
        try {
            await axios.delete(`/api/v1/chat/sessions/${sessionId}`);
            sessions.value = sessions.value.filter((s) => s.id !== sessionId);

            if (currentSessionId.value === sessionId) {
                newSession();
            }
        } catch (err) {
            console.error('Failed to delete session:', err);
        }
    }

    /**
     * Send a message and stream the response.
     */
    async function sendMessage(content: string): Promise<void> {
        if (!content.trim() || isStreaming.value) {
            return;
        }

        // Cancel any existing request
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        error.value = null;
        isStreaming.value = true;
        toolStatus.value = null;

        // Add user message immediately
        const userMessage: ChatMessage = {
            id: `temp-user-${Date.now()}`,
            role: 'user',
            content: content.trim(),
            created_at: new Date().toISOString(),
        };
        messages.value.push(userMessage);

        // Add placeholder for assistant response
        const assistantMessage: ChatMessage = {
            id: `temp-assistant-${Date.now()}`,
            role: 'assistant',
            content: '',
            created_at: new Date().toISOString(),
            isStreaming: true,
        };
        messages.value.push(assistantMessage);

        try {
            // Get CSRF token from meta tag or cookie
            const csrfToken =
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            const response = await fetch('/api/v1/chat/message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    message: content.trim(),
                    session_id: currentSessionId.value,
                }),
                signal: abortController.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const reader = response.body?.getReader();
            if (!reader) {
                throw new Error('No response body');
            }

            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                // Process complete SSE events
                const events = buffer.split('\n\n');
                buffer = events.pop() || '';

                for (const event of events) {
                    processSSEEvent(event, assistantMessage);
                }
            }

            // Process any remaining buffer
            if (buffer.trim()) {
                processSSEEvent(buffer, assistantMessage);
            }
        } catch (err) {
            if ((err as Error).name === 'AbortError') {
                return;
            }

            error.value = 'Failed to send message. Please try again.';
            console.error('Chat error:', err);

            // Remove the placeholder assistant message on error
            messages.value = messages.value.filter((m) => m.id !== assistantMessage.id);
        } finally {
            isStreaming.value = false;
            toolStatus.value = null;

            // Mark assistant message as no longer streaming
            const lastMessage = messages.value[messages.value.length - 1];
            if (lastMessage?.role === 'assistant') {
                lastMessage.isStreaming = false;
            }

            abortController = null;
        }
    }

    /**
     * Process an SSE event.
     */
    function processSSEEvent(eventString: string, assistantMessage: ChatMessage): void {
        const lines = eventString.split('\n');
        let eventType = '';
        let data = '';

        for (const line of lines) {
            if (line.startsWith('event: ')) {
                eventType = line.slice(7).trim();
            } else if (line.startsWith('data: ')) {
                data = line.slice(6);
            }
        }

        if (!data) return;

        try {
            const parsed = JSON.parse(data);

            switch (eventType) {
                case 'token':
                    assistantMessage.content += parsed.content || '';
                    break;

                case 'tool_use':
                    toolStatus.value = parsed.status || `Using ${parsed.tool}...`;
                    break;

                case 'tool_result':
                    toolStatus.value = null;
                    break;

                case 'done':
                    if (parsed.session_id) {
                        currentSessionId.value = parsed.session_id;
                    }
                    break;

                case 'error':
                    error.value = parsed.message || 'An error occurred';
                    break;
            }
        } catch {
            // Ignore parsing errors for malformed events
        }
    }

    /**
     * Cancel the current streaming request.
     */
    function cancelStream(): void {
        if (abortController) {
            abortController.abort();
            abortController = null;
        }
        isStreaming.value = false;
        toolStatus.value = null;
    }

    return {
        // State
        messages,
        sessions,
        currentSessionId,
        isLoading,
        isStreaming,
        error,
        toolStatus,
        hasMessages,

        // Actions
        loadSessions,
        loadSession,
        newSession,
        deleteSession,
        sendMessage,
        cancelStream,
    };
}
