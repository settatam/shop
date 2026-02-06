<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { MicrophoneIcon, StopIcon, SpeakerWaveIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const isOpen = ref(false);
const isRecording = ref(false);
const isProcessing = ref(false);
const isPlaying = ref(false);
const transcript = ref('');
const response = ref('');
const error = ref('');

let mediaRecorder: MediaRecorder | null = null;
let audioChunks: Blob[] = [];
let audioElement: HTMLAudioElement | null = null;

const canRecord = ref(false);

onMounted(async () => {
    // Check if browser supports audio recording
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        canRecord.value = true;
    }
});

onUnmounted(() => {
    stopRecording();
    stopAudio();
});

async function startRecording() {
    if (!canRecord.value) {
        error.value = 'Your browser does not support audio recording';
        return;
    }

    error.value = '';
    transcript.value = '';
    response.value = '';

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream, {
            mimeType: 'audio/webm;codecs=opus'
        });
        audioChunks = [];

        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };

        mediaRecorder.onstop = async () => {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            await processAudio(audioBlob);

            // Stop all tracks
            stream.getTracks().forEach(track => track.stop());
        };

        mediaRecorder.start();
        isRecording.value = true;
    } catch (err) {
        console.error('Failed to start recording:', err);
        error.value = 'Failed to access microphone. Please check permissions.';
    }
}

function stopRecording() {
    if (mediaRecorder && isRecording.value) {
        mediaRecorder.stop();
        isRecording.value = false;
    }
}

async function processAudio(audioBlob: Blob) {
    isProcessing.value = true;
    error.value = '';

    try {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'recording.webm');

        const res = await fetch('/api/v1/voice/query', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
            },
            credentials: 'include',
        });

        const data = await res.json();

        if (data.success) {
            transcript.value = data.transcript || '';
            response.value = data.response || '';

            // Play audio response if available
            if (data.audio_url) {
                playAudio(data.audio_url);
            }
        } else {
            error.value = data.error || 'Failed to process voice query';
        }
    } catch (err) {
        console.error('Failed to process audio:', err);
        error.value = 'Failed to process voice query';
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
        stopRecording();
        stopAudio();
    }
}

function close() {
    isOpen.value = false;
    stopRecording();
    stopAudio();
}

const statusText = computed(() => {
    if (isRecording.value) return 'Listening...';
    if (isProcessing.value) return 'Processing...';
    if (isPlaying.value) return 'Speaking...';
    return 'Tap to speak';
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
                <button
                    type="button"
                    class="p-1 text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                    @click="close"
                >
                    <XMarkIcon class="size-5" />
                </button>
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
                            <span class="w-16 text-gray-400">Customer:</span>
                            <span>"Tell me about John Smith"</span>
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
