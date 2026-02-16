import { ref, onUnmounted } from 'vue';
import axios from 'axios';

export interface VoiceGatewayState {
    isConnected: boolean;
    isConnecting: boolean;
    sessionId: string | null;
    transcript: string;
    response: string;
    isProcessing: boolean;
    isPlaying: boolean;
    error: string;
}

export interface VoiceGatewayOptions {
    gatewayUrl?: string;
    onTranscript?: (text: string) => void;
    onResponse?: (text: string) => void;
    onAudio?: (audioData: ArrayBuffer) => void;
    onError?: (error: string) => void;
}

export function useVoiceGateway(options: VoiceGatewayOptions = {}) {
    const state = ref<VoiceGatewayState>({
        isConnected: false,
        isConnecting: false,
        sessionId: null,
        transcript: '',
        response: '',
        isProcessing: false,
        isPlaying: false,
        error: '',
    });

    let ws: WebSocket | null = null;
    let audioContext: AudioContext | null = null;
    let mediaStream: MediaStream | null = null;
    let audioProcessor: ScriptProcessorNode | null = null;
    let audioQueue: AudioBuffer[] = [];
    let isPlayingAudio = false;

    /**
     * Get gateway token from Laravel
     */
    async function getGatewayToken(): Promise<{ token: string; gatewayUrl: string } | null> {
        try {
            const response = await axios.post('/api/v1/voice-gateway/token');
            return {
                token: response.data.token,
                gatewayUrl: response.data.gateway_url,
            };
        } catch (error) {
            console.error('Failed to get gateway token:', error);
            return null;
        }
    }

    /**
     * Connect to voice gateway WebSocket
     */
    async function connect(): Promise<boolean> {
        if (state.value.isConnecting || state.value.isConnected) {
            return state.value.isConnected;
        }

        state.value.isConnecting = true;
        state.value.error = '';

        try {
            // Get token from Laravel
            const tokenData = await getGatewayToken();
            if (!tokenData) {
                throw new Error('Failed to get gateway token');
            }

            const gatewayUrl = options.gatewayUrl || tokenData.gatewayUrl;
            const wsUrl = gatewayUrl.replace(/^http/, 'ws') + '/ws';

            return new Promise((resolve, reject) => {
                ws = new WebSocket(wsUrl);

                ws.onopen = () => {
                    // Send initialization message with token
                    ws?.send(JSON.stringify({
                        type: 'init',
                        token: tokenData.token,
                    }));
                };

                ws.onmessage = async (event) => {
                    // Check if it's binary audio data or JSON message
                    if (event.data instanceof Blob) {
                        const arrayBuffer = await event.data.arrayBuffer();
                        handleAudioData(arrayBuffer);
                    } else if (event.data instanceof ArrayBuffer) {
                        handleAudioData(event.data);
                    } else {
                        try {
                            const message = JSON.parse(event.data);
                            handleMessage(message, resolve, reject);
                        } catch (e) {
                            console.error('Failed to parse WebSocket message:', e);
                        }
                    }
                };

                ws.onerror = (error) => {
                    console.error('WebSocket error:', error);
                    state.value.error = 'Connection error';
                    state.value.isConnecting = false;
                    reject(error);
                };

                ws.onclose = () => {
                    state.value.isConnected = false;
                    state.value.sessionId = null;
                    ws = null;
                };
            });
        } catch (error) {
            state.value.isConnecting = false;
            state.value.error = error instanceof Error ? error.message : 'Connection failed';
            return false;
        }
    }

    /**
     * Handle incoming WebSocket messages
     */
    function handleMessage(
        message: Record<string, unknown>,
        resolve?: (value: boolean) => void,
        reject?: (reason: unknown) => void
    ) {
        switch (message.type) {
            case 'init_success':
                state.value.isConnected = true;
                state.value.isConnecting = false;
                state.value.sessionId = message.sessionId as string;
                resolve?.(true);
                break;

            case 'error':
                state.value.error = message.message as string || 'Unknown error';
                state.value.isConnecting = false;
                reject?.(new Error(state.value.error));
                break;

            case 'transcript':
                state.value.transcript = message.text as string || '';
                options.onTranscript?.(state.value.transcript);
                break;

            case 'response':
                state.value.response = message.text as string || '';
                state.value.isProcessing = false;
                options.onResponse?.(state.value.response);
                break;

            case 'tts_complete':
                state.value.isPlaying = false;
                break;

            case 'barge_in_ack':
                state.value.isPlaying = false;
                audioQueue = [];
                break;

            case 'session_ended':
                state.value.isConnected = false;
                state.value.sessionId = null;
                break;
        }
    }

    /**
     * Handle incoming audio data for TTS playback
     */
    async function handleAudioData(arrayBuffer: ArrayBuffer) {
        if (!audioContext) {
            audioContext = new AudioContext();
        }

        try {
            const audioBuffer = await audioContext.decodeAudioData(arrayBuffer);
            audioQueue.push(audioBuffer);
            options.onAudio?.(arrayBuffer);

            if (!isPlayingAudio) {
                playNextAudio();
            }
        } catch (error) {
            console.error('Failed to decode audio:', error);
        }
    }

    /**
     * Play queued audio buffers
     */
    function playNextAudio() {
        if (audioQueue.length === 0 || !audioContext) {
            isPlayingAudio = false;
            state.value.isPlaying = false;
            return;
        }

        isPlayingAudio = true;
        state.value.isPlaying = true;

        const audioBuffer = audioQueue.shift()!;
        const source = audioContext.createBufferSource();
        source.buffer = audioBuffer;
        source.connect(audioContext.destination);

        source.onended = () => {
            playNextAudio();
        };

        source.start();
    }

    /**
     * Start recording and streaming audio
     */
    async function startRecording(): Promise<void> {
        if (!state.value.isConnected) {
            const connected = await connect();
            if (!connected) return;
        }

        state.value.transcript = '';
        state.value.response = '';
        state.value.error = '';

        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    sampleRate: 16000,
                    channelCount: 1,
                    echoCancellation: true,
                    noiseSuppression: true,
                },
            });

            if (!audioContext) {
                audioContext = new AudioContext({ sampleRate: 16000 });
            }

            const source = audioContext.createMediaStreamSource(mediaStream);

            // Create script processor for audio capture
            audioProcessor = audioContext.createScriptProcessor(4096, 1, 1);

            audioProcessor.onaudioprocess = (event) => {
                const inputData = event.inputBuffer.getChannelData(0);
                const pcmData = float32ToPCM16(inputData);

                // Send audio data to gateway
                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(pcmData);
                }
            };

            source.connect(audioProcessor);
            audioProcessor.connect(audioContext.destination);
        } catch (error) {
            console.error('Failed to start recording:', error);
            state.value.error = 'Failed to access microphone';
        }
    }

    /**
     * Stop recording and process audio
     */
    function stopRecording(): void {
        // Stop media stream
        if (mediaStream) {
            mediaStream.getTracks().forEach(track => track.stop());
            mediaStream = null;
        }

        // Disconnect processor
        if (audioProcessor) {
            audioProcessor.disconnect();
            audioProcessor = null;
        }

        // Signal end of turn to gateway
        if (ws && ws.readyState === WebSocket.OPEN) {
            state.value.isProcessing = true;
            ws.send(JSON.stringify({ type: 'end_turn' }));
        }
    }

    /**
     * Cancel current recording
     */
    function cancelRecording(): void {
        if (mediaStream) {
            mediaStream.getTracks().forEach(track => track.stop());
            mediaStream = null;
        }

        if (audioProcessor) {
            audioProcessor.disconnect();
            audioProcessor = null;
        }
    }

    /**
     * Signal barge-in (user interrupting assistant)
     */
    function bargeIn(): void {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'barge_in' }));
        }

        // Clear audio queue
        audioQueue = [];
        state.value.isPlaying = false;
    }

    /**
     * Disconnect from gateway
     */
    async function disconnect(): Promise<void> {
        cancelRecording();

        if (ws) {
            ws.send(JSON.stringify({ type: 'end_session' }));
            ws.close();
            ws = null;
        }

        if (audioContext) {
            await audioContext.close();
            audioContext = null;
        }

        state.value.isConnected = false;
        state.value.sessionId = null;
    }

    /**
     * Convert Float32Array to PCM16 ArrayBuffer
     */
    function float32ToPCM16(float32Array: Float32Array): ArrayBuffer {
        const buffer = new ArrayBuffer(float32Array.length * 2);
        const view = new DataView(buffer);

        for (let i = 0; i < float32Array.length; i++) {
            let sample = float32Array[i];
            sample = Math.max(-1, Math.min(1, sample));
            const int16 = Math.round(sample * 32767);
            view.setInt16(i * 2, int16, true);
        }

        return buffer;
    }

    // Cleanup on unmount
    onUnmounted(() => {
        disconnect();
    });

    return {
        state,
        connect,
        disconnect,
        startRecording,
        stopRecording,
        cancelRecording,
        bargeIn,
    };
}
