<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { MicrophoneIcon, StopIcon, SpeakerWaveIcon, XMarkIcon, SignalIcon, SignalSlashIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
import { useVoiceGateway } from '@/composables/useVoiceGateway';

const props = defineProps<{
    useGateway?: boolean;
}>();

// Gateway mode state
const gateway = useVoiceGateway({
    onTranscript: (text) => {
        transcript.value = text;
    },
    onResponse: (text) => {
        response.value = text;
    },
    onError: (err) => {
        error.value = err;
    },
});

const isOpen = ref(false);
const isRecording = ref(false);
const isProcessing = ref(false);
const isPlaying = ref(false);
const transcript = ref('');
const response = ref('');
const error = ref('');

// Legacy mode state
let mediaRecorder: MediaRecorder | null = null;
let audioChunks: Blob[] = [];
let audioElement: HTMLAudioElement | null = null;
let recordingStartTime: number = 0;
let currentStream: MediaStream | null = null;

const canRecord = ref(false);
const MIN_RECORDING_MS = 800;

// Use gateway mode if enabled and supported
const useGatewayMode = computed(() => props.useGateway ?? false);

// Sync gateway state with local state
watch(() => gateway.state.value.isProcessing, (val) => {
    if (useGatewayMode.value) {
        isProcessing.value = val;
    }
});

watch(() => gateway.state.value.isPlaying, (val) => {
    if (useGatewayMode.value) {
        isPlaying.value = val;
    }
});

onMounted(async () => {
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        canRecord.value = true;
    }
});

onUnmounted(() => {
    cancelRecording();
    stopAudio();
    if (useGatewayMode.value) {
        gateway.disconnect();
    }
});

function getSupportedMimeType(): string {
    const mimeTypes = [
        'audio/mp4',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/webm;codecs=opus',
    ];

    for (const mimeType of mimeTypes) {
        if (MediaRecorder.isTypeSupported(mimeType)) {
            return mimeType;
        }
    }

    return '';
}

let currentMimeType = '';

async function startRecording() {
    if (!canRecord.value) {
        error.value = 'Your browser does not support audio recording';
        return;
    }

    error.value = '';
    transcript.value = '';
    response.value = '';

    // Use gateway mode if enabled
    if (useGatewayMode.value) {
        isRecording.value = true;
        await gateway.startRecording();
        return;
    }

    // Legacy HTTP mode
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        currentStream = stream;
        currentMimeType = getSupportedMimeType();

        const options: MediaRecorderOptions = {};
        if (currentMimeType) {
            options.mimeType = currentMimeType;
        }

        mediaRecorder = new MediaRecorder(stream, options);
        audioChunks = [];

        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };

        mediaRecorder.onstop = async () => {
            const recordingDuration = Date.now() - recordingStartTime;

            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }

            if (recordingDuration < MIN_RECORDING_MS) {
                error.value = 'Recording too short. Hold the button and speak for at least 1 second.';
                return;
            }

            const blobType = currentMimeType || mediaRecorder?.mimeType || 'audio/webm';
            const audioBlob = new Blob(audioChunks, { type: blobType });
            await processAudio(audioBlob);
        };

        mediaRecorder.start(100);
        recordingStartTime = Date.now();
        isRecording.value = true;
    } catch (err) {
        console.error('Failed to start recording:', err);
        error.value = 'Failed to access microphone. Please check permissions.';
    }
}

function stopRecording() {
    if (useGatewayMode.value) {
        isRecording.value = false;
        isProcessing.value = true;
        gateway.stopRecording();
        return;
    }

    if (mediaRecorder && isRecording.value) {
        mediaRecorder.stop();
        isRecording.value = false;
    }
}

function cancelRecording() {
    if (useGatewayMode.value) {
        gateway.cancelRecording();
        isRecording.value = false;
        return;
    }

    if (mediaRecorder && isRecording.value) {
        mediaRecorder.onstop = null;
        mediaRecorder.stop();
        isRecording.value = false;

        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
    }
}

function getFileExtension(mimeType: string): string {
    const mimeToExt: Record<string, string> = {
        'audio/mp4': 'mp4',
        'audio/m4a': 'm4a',
        'audio/webm': 'webm',
        'audio/ogg': 'ogg',
        'audio/mpeg': 'mp3',
        'audio/wav': 'wav',
    };

    const baseMime = mimeType.split(';')[0];
    return mimeToExt[baseMime] || 'webm';
}

async function processAudio(audioBlob: Blob) {
    isProcessing.value = true;
    error.value = '';

    try {
        const extension = getFileExtension(audioBlob.type);
        const formData = new FormData();
        formData.append('audio', audioBlob, `recording.${extension}`);

        const res = await axios.post('/api/v1/voice/query', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        const data = res.data;

        if (data.success) {
            transcript.value = data.transcript || '';
            response.value = data.response || '';

            if (data.audio_url) {
                playAudio(data.audio_url);
            }
        } else {
            error.value = data.error || 'Failed to process voice query';
        }
    } catch (err: unknown) {
        console.error('Failed to process audio:', err);
        const axiosError = err as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error || 'Failed to process voice query';
    } finally {
        isProcessing.value = false;
    }
}

function playAudio(url: string) {
    stopAudio();

    audioElement = new Audio(url);
    audioElement.volume = 1.0;

    audioElement.onplay = () => {
        isPlaying.value = true;
    };

    audioElement.onended = () => {
        isPlaying.value = false;
    };

    audioElement.onerror = () => {
        isPlaying.value = false;
        console.error('Failed to play audio response');
    };

    audioElement.play().catch(err => {
        console.error('Failed to play audio:', err);
        isPlaying.value = false;
    });
}

function stopAudio() {
    if (useGatewayMode.value && isPlaying.value) {
        gateway.bargeIn();
        return;
    }

    if (audioElement) {
        audioElement.pause();
        audioElement.currentTime = 0;
        audioElement = null;
        isPlaying.value = false;
    }
}

function togglePanel() {
    isOpen.value = !isOpen.value;
    if (!isOpen.value) {
        cancelRecording();
        stopAudio();
    }
}

function close() {
    isOpen.value = false;
    cancelRecording();
    stopAudio();
}

const statusText = computed(() => {
    if (isRecording.value) return 'Listening...';
    if (isProcessing.value) return 'Processing...';
    if (isPlaying.value) return 'Speaking...';
    return 'Tap to speak';
});

const connectionStatus = computed(() => {
    if (!useGatewayMode.value) return null;
    return gateway.state.value.isConnected ? 'connected' : 'disconnected';
});
</script>

<template>
    <!-- Floating Button -->
    <button
        v-if="!isOpen"
        type="button"
        class="fixed bottom-24 right-6 z-40 flex items-center justify-center size-12 rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-500 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        @click="togglePanel"
    >
        <MicrophoneIcon class="size-6" />
        <span class="sr-only">Voice Assistant</span>
    </button>

    <!-- Voice Panel -->
    <Transition
        enter-active-class="transition ease-out duration-200"
        enter-from-class="translate-y-4 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition ease-in duration-150"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-4 opacity-0"
    >
        <div
            v-if="isOpen"
            class="fixed bottom-24 right-6 z-40 w-80 rounded-2xl bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
        >
            <!-- Header -->
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <MicrophoneIcon class="size-5 text-indigo-600" />
                    <div>
                        <span class="font-medium text-gray-900 dark:text-white">Sales Manager</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Voice Reports</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Connection status indicator -->
                    <div
                        v-if="useGatewayMode"
                        class="flex items-center gap-1"
                        :title="connectionStatus === 'connected' ? 'Connected to voice gateway' : 'Disconnected'"
                    >
                        <SignalIcon v-if="connectionStatus === 'connected'" class="size-4 text-green-500" />
                        <SignalSlashIcon v-else class="size-4 text-gray-400" />
                    </div>
                    <button
                        type="button"
                        class="p-1 text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                        @click="close"
                    >
                        <XMarkIcon class="size-5" />
                    </button>
                </div>
            </div>

            <!-- Content -->
            <div class="p-4 space-y-4">
                <!-- Microphone Button -->
                <div class="flex flex-col items-center gap-3">
                    <button
                        type="button"
                        class="relative flex items-center justify-center size-20 rounded-full transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2"
                        :class="[
                            isRecording
                                ? 'bg-red-500 hover:bg-red-600 focus:ring-red-500 animate-pulse'
                                : isProcessing
                                    ? 'bg-yellow-500 cursor-wait'
                                    : 'bg-indigo-600 hover:bg-indigo-500 focus:ring-indigo-500'
                        ]"
                        :disabled="isProcessing"
                        @mousedown="startRecording"
                        @mouseup="stopRecording"
                        @mouseleave="stopRecording"
                        @touchstart.prevent="startRecording"
                        @touchend.prevent="stopRecording"
                    >
                        <MicrophoneIcon v-if="!isRecording && !isProcessing" class="size-8 text-white" />
                        <StopIcon v-else-if="isRecording" class="size-8 text-white" />
                        <svg v-else class="size-8 text-white animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>

                        <!-- Recording indicator rings -->
                        <span
                            v-if="isRecording"
                            class="absolute inset-0 rounded-full animate-ping bg-red-400 opacity-75"
                        ></span>
                    </button>

                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ statusText }}
                    </span>
                </div>

                <!-- Error -->
                <div
                    v-if="error"
                    class="p-3 rounded-lg bg-red-50 text-red-700 text-sm dark:bg-red-900/20 dark:text-red-400"
                >
                    {{ error }}
                </div>

                <!-- Transcript -->
                <div v-if="transcript" class="space-y-2">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">You said:</div>
                    <div class="p-3 rounded-lg bg-gray-50 text-gray-700 text-sm dark:bg-gray-700 dark:text-gray-300">
                        "{{ transcript }}"
                    </div>
                </div>

                <!-- Response -->
                <div v-if="response" class="space-y-2">
                    <div class="flex items-center gap-2">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Response:</div>
                        <button
                            v-if="isPlaying"
                            type="button"
                            class="p-1 text-indigo-600 hover:text-indigo-500"
                            @click="stopAudio"
                            title="Stop playback"
                        >
                            <StopIcon class="size-4" />
                        </button>
                        <SpeakerWaveIcon v-if="isPlaying" class="size-4 text-indigo-600 animate-pulse" />
                    </div>
                    <div class="p-3 rounded-lg bg-indigo-50 text-gray-700 text-sm dark:bg-indigo-900/20 dark:text-gray-300 max-h-40 overflow-y-auto">
                        {{ response }}
                    </div>
                </div>

                <!-- Suggestions -->
                <div v-if="!transcript && !error" class="space-y-3">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Try saying:</div>
                    <div class="space-y-2 text-xs text-gray-600 dark:text-gray-400">
                        <div class="flex items-center gap-2">
                            <span class="w-16 text-gray-400">Open:</span>
                            <span>"Morning briefing"</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-16 text-gray-400">Reports:</span>
                            <span>"How'd we do today?"</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-16 text-gray-400">Pricing:</span>
                            <span>"What's 30 grams of 14k worth?"</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-16 text-gray-400">Buying:</span>
                            <span>"Help me price this gold chain"</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-16 text-gray-400">Memory:</span>
                            <span>"Remember that Mike prefers cash"</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-16 text-gray-400">Remind:</span>
                            <span>"Remind me to call Mike tomorrow"</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-16 text-gray-400">Close:</span>
                            <span>"End of day report"</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>
