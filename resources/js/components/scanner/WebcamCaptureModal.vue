<script setup lang="ts">
import { ref, watch, onUnmounted } from 'vue';
import { XMarkIcon, CameraIcon, ArrowPathIcon, CheckIcon } from '@heroicons/vue/24/outline';

const props = defineProps<{
    show: boolean;
    title?: string;
}>();

const emit = defineEmits<{
    close: [];
    captured: [file: File];
}>();

const videoRef = ref<HTMLVideoElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);
const stream = ref<MediaStream | null>(null);
const capturedImage = ref<string | null>(null);
const isStarting = ref(false);
const errorMessage = ref<string | null>(null);

async function startCamera() {
    isStarting.value = true;
    errorMessage.value = null;
    capturedImage.value = null;

    try {
        const mediaStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false,
        });
        stream.value = mediaStream;

        if (videoRef.value) {
            videoRef.value.srcObject = mediaStream;
            await videoRef.value.play();
        }
    } catch (err: any) {
        errorMessage.value = err.name === 'NotAllowedError'
            ? 'Camera access denied. Please allow camera access in your browser settings.'
            : 'Could not access camera. Please check that a camera is connected.';
    } finally {
        isStarting.value = false;
    }
}

function stopCamera() {
    if (stream.value) {
        stream.value.getTracks().forEach((track) => track.stop());
        stream.value = null;
    }
    if (videoRef.value) {
        videoRef.value.srcObject = null;
    }
}

function capturePhoto() {
    if (!videoRef.value || !canvasRef.value) return;

    const video = videoRef.value;
    const canvas = canvasRef.value;

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    ctx.drawImage(video, 0, 0);
    capturedImage.value = canvas.toDataURL('image/jpeg', 0.9);
    stopCamera();
}

function retake() {
    capturedImage.value = null;
    startCamera();
}

function confirmPhoto() {
    if (!capturedImage.value || !canvasRef.value) return;

    canvasRef.value.toBlob(
        (blob) => {
            if (blob) {
                const file = new File([blob], `customer-photo-${Date.now()}.jpg`, { type: 'image/jpeg' });
                emit('captured', file);
                handleClose();
            }
        },
        'image/jpeg',
        0.9,
    );
}

function handleClose() {
    stopCamera();
    capturedImage.value = null;
    errorMessage.value = null;
    emit('close');
}

watch(
    () => props.show,
    (show) => {
        if (show) {
            startCamera();
        } else {
            stopCamera();
            capturedImage.value = null;
        }
    },
);

onUnmounted(() => {
    stopCamera();
});
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="relative z-50">
            <div class="fixed inset-0 bg-black/80 transition-opacity" @click="handleClose" />

            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div
                        class="relative w-full max-w-lg transform overflow-hidden rounded-2xl bg-gray-900 shadow-2xl transition-all"
                    >
                        <!-- Header -->
                        <div class="flex items-center justify-between border-b border-gray-700 px-4 py-3">
                            <div class="flex items-center gap-2">
                                <CameraIcon class="size-5 text-indigo-400" />
                                <h3 class="text-lg font-semibold text-white">
                                    {{ title || 'Take Photo' }}
                                </h3>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg p-2 text-gray-400 hover:bg-gray-700 hover:text-white"
                                @click="handleClose"
                            >
                                <XMarkIcon class="size-5" />
                            </button>
                        </div>

                        <!-- Camera / Capture Area -->
                        <div class="relative aspect-[4/3] w-full bg-black">
                            <!-- Live video preview -->
                            <video
                                v-show="!capturedImage && !errorMessage && !isStarting"
                                ref="videoRef"
                                autoplay
                                playsinline
                                muted
                                class="size-full object-cover"
                                style="transform: scaleX(-1)"
                            />

                            <!-- Captured image preview -->
                            <img
                                v-if="capturedImage"
                                :src="capturedImage"
                                alt="Captured photo"
                                class="size-full object-cover"
                                style="transform: scaleX(-1)"
                            />

                            <!-- Loading -->
                            <div
                                v-if="isStarting"
                                class="absolute inset-0 flex flex-col items-center justify-center bg-gray-900"
                            >
                                <div
                                    class="mb-4 size-12 animate-spin rounded-full border-4 border-gray-700 border-t-indigo-500"
                                />
                                <p class="text-sm text-gray-400">Starting camera...</p>
                            </div>

                            <!-- Error -->
                            <div
                                v-if="errorMessage"
                                class="absolute inset-0 flex flex-col items-center justify-center bg-gray-900 p-6 text-center"
                            >
                                <CameraIcon class="mb-4 size-16 text-gray-600" />
                                <p class="mb-2 text-lg font-medium text-white">Camera Unavailable</p>
                                <p class="text-sm text-gray-400">{{ errorMessage }}</p>
                                <button
                                    type="button"
                                    class="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                                    @click="startCamera"
                                >
                                    <ArrowPathIcon class="size-4" />
                                    Try Again
                                </button>
                            </div>

                            <!-- Hidden canvas for capture -->
                            <canvas ref="canvasRef" class="hidden" />
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center justify-center gap-3 border-t border-gray-700 bg-gray-800 px-4 py-4">
                            <template v-if="capturedImage">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg bg-gray-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-gray-500"
                                    @click="retake"
                                >
                                    <ArrowPathIcon class="size-4" />
                                    Retake
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-500"
                                    @click="confirmPhoto"
                                >
                                    <CheckIcon class="size-4" />
                                    Use Photo
                                </button>
                            </template>
                            <template v-else-if="!errorMessage && !isStarting">
                                <button
                                    type="button"
                                    class="inline-flex size-16 items-center justify-center rounded-full bg-white text-gray-900 shadow-lg transition-transform hover:scale-105 active:scale-95"
                                    @click="capturePhoto"
                                >
                                    <CameraIcon class="size-8" />
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
