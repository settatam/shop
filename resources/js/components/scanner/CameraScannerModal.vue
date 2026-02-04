<script setup lang="ts">
import { ref, watch, nextTick } from 'vue';
import { XMarkIcon, CameraIcon, ArrowPathIcon } from '@heroicons/vue/24/outline';
import { useCameraScanner } from '@/composables/useCameraScanner';

const props = defineProps<{
    show: boolean;
}>();

const emit = defineEmits<{
    close: [];
    scan: [barcode: string];
}>();

const scannerElementId = 'camera-scanner-reader';
const lastScanFeedback = ref<{ barcode: string; timestamp: number } | null>(null);

const {
    isScanning,
    hasCamera,
    errorMessage,
    startScanning,
    stopScanning,
} = useCameraScanner({
    onScan: (barcode) => {
        lastScanFeedback.value = { barcode, timestamp: Date.now() };
        emit('scan', barcode);

        // Clear feedback after a short delay
        setTimeout(() => {
            lastScanFeedback.value = null;
        }, 2000);
    },
    onError: (error) => {
        console.error('Scanner error:', error);
    },
    stopOnScan: false, // Keep scanning for multiple items
});

watch(() => props.show, async (show) => {
    if (show) {
        // Wait for the modal to render
        await nextTick();
        // Small delay to ensure DOM is ready
        setTimeout(() => {
            startScanning(scannerElementId);
        }, 100);
    } else {
        stopScanning();
    }
});

function handleClose() {
    stopScanning();
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="relative z-50">
            <!-- Backdrop -->
            <div
                class="fixed inset-0 bg-black/80 transition-opacity"
                @click="handleClose"
            />

            <!-- Modal -->
            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="relative w-full max-w-lg transform overflow-hidden rounded-2xl bg-gray-900 shadow-2xl transition-all">
                        <!-- Header -->
                        <div class="flex items-center justify-between border-b border-gray-700 px-4 py-3">
                            <div class="flex items-center gap-2">
                                <CameraIcon class="size-5 text-indigo-400" />
                                <h3 class="text-lg font-semibold text-white">
                                    Scan Barcode
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

                        <!-- Scanner Area -->
                        <div class="relative">
                            <!-- Camera Preview -->
                            <div
                                :id="scannerElementId"
                                class="aspect-[4/3] w-full bg-black"
                            />

                            <!-- Scanning Overlay -->
                            <div
                                v-if="isScanning"
                                class="pointer-events-none absolute inset-0 flex items-center justify-center"
                            >
                                <!-- Scan Frame -->
                                <div class="relative h-32 w-64">
                                    <!-- Corner markers -->
                                    <div class="absolute left-0 top-0 h-6 w-6 border-l-4 border-t-4 border-indigo-500" />
                                    <div class="absolute right-0 top-0 h-6 w-6 border-r-4 border-t-4 border-indigo-500" />
                                    <div class="absolute bottom-0 left-0 h-6 w-6 border-b-4 border-l-4 border-indigo-500" />
                                    <div class="absolute bottom-0 right-0 h-6 w-6 border-b-4 border-r-4 border-indigo-500" />

                                    <!-- Scanning line animation -->
                                    <div class="absolute inset-x-0 top-0 h-0.5 animate-scan bg-gradient-to-r from-transparent via-indigo-500 to-transparent" />
                                </div>
                            </div>

                            <!-- Success Feedback -->
                            <div
                                v-if="lastScanFeedback"
                                class="absolute inset-x-4 bottom-4 rounded-lg bg-green-600 px-4 py-3 text-center text-white shadow-lg"
                            >
                                <p class="text-sm font-medium">Scanned!</p>
                                <p class="font-mono text-lg">{{ lastScanFeedback.barcode }}</p>
                            </div>

                            <!-- Error State -->
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
                                    @click="startScanning(scannerElementId)"
                                >
                                    <ArrowPathIcon class="size-4" />
                                    Try Again
                                </button>
                            </div>

                            <!-- Loading State -->
                            <div
                                v-if="!isScanning && !errorMessage"
                                class="absolute inset-0 flex flex-col items-center justify-center bg-gray-900"
                            >
                                <div class="mb-4 size-12 animate-spin rounded-full border-4 border-gray-700 border-t-indigo-500" />
                                <p class="text-sm text-gray-400">Starting camera...</p>
                            </div>
                        </div>

                        <!-- Footer Instructions -->
                        <div class="border-t border-gray-700 bg-gray-800 px-4 py-3 text-center">
                            <p class="text-sm text-gray-400">
                                Position the barcode within the frame
                            </p>
                            <p class="mt-1 text-xs text-gray-500">
                                Supports UPC, EAN, Code 128, Code 39, QR codes
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
@keyframes scan {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(7.5rem);
    }
}

.animate-scan {
    animation: scan 2s ease-in-out infinite;
}
</style>
