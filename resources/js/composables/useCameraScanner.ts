import { ref, onUnmounted } from 'vue';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';

interface CameraScannerOptions {
    /**
     * Callback when a barcode is scanned
     */
    onScan: (barcode: string) => void;

    /**
     * Callback when an error occurs
     */
    onError?: (error: string) => void;

    /**
     * Whether to stop scanning after first successful scan
     */
    stopOnScan?: boolean;

    /**
     * Supported barcode formats
     */
    formats?: Html5QrcodeSupportedFormats[];
}

export function useCameraScanner(options: CameraScannerOptions) {
    const {
        onScan,
        onError,
        stopOnScan = true,
        formats = [
            Html5QrcodeSupportedFormats.UPC_A,
            Html5QrcodeSupportedFormats.UPC_E,
            Html5QrcodeSupportedFormats.EAN_13,
            Html5QrcodeSupportedFormats.EAN_8,
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.CODE_39,
            Html5QrcodeSupportedFormats.CODE_93,
            Html5QrcodeSupportedFormats.QR_CODE,
            Html5QrcodeSupportedFormats.ITF,
        ],
    } = options;

    const isScanning = ref(false);
    const hasCamera = ref(true);
    const errorMessage = ref<string | null>(null);
    const lastScannedCode = ref<string | null>(null);

    let html5QrCode: Html5Qrcode | null = null;

    async function startScanning(elementId: string) {
        if (isScanning.value) return;

        errorMessage.value = null;
        lastScannedCode.value = null;

        try {
            html5QrCode = new Html5Qrcode(elementId);

            const config = {
                fps: 10,
                qrbox: { width: 250, height: 150 },
                aspectRatio: 1.777778, // 16:9
                formatsToSupport: formats,
            };

            await html5QrCode.start(
                { facingMode: 'environment' }, // Use back camera
                config,
                (decodedText) => {
                    // Prevent duplicate scans of the same code
                    if (decodedText === lastScannedCode.value) return;

                    lastScannedCode.value = decodedText;
                    onScan(decodedText);

                    if (stopOnScan) {
                        stopScanning();
                    }
                },
                () => {
                    // QR code scan error (no code found in frame) - ignore these
                }
            );

            isScanning.value = true;
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Failed to start camera';
            errorMessage.value = message;

            if (message.includes('NotAllowedError') || message.includes('Permission')) {
                errorMessage.value = 'Camera permission denied. Please allow camera access and try again.';
            } else if (message.includes('NotFoundError') || message.includes('no camera')) {
                hasCamera.value = false;
                errorMessage.value = 'No camera found on this device.';
            }

            onError?.(errorMessage.value);
        }
    }

    async function stopScanning() {
        if (!html5QrCode || !isScanning.value) return;

        try {
            await html5QrCode.stop();
            html5QrCode.clear();
        } catch (error) {
            console.error('Error stopping scanner:', error);
        } finally {
            isScanning.value = false;
            html5QrCode = null;
        }
    }

    async function switchCamera() {
        if (!html5QrCode || !isScanning.value) return;

        try {
            const cameras = await Html5Qrcode.getCameras();
            if (cameras.length < 2) return;

            // Get current camera and switch to the other one
            // This is a simplified implementation - could be enhanced
            await stopScanning();
            // Would need to track current camera and switch
        } catch (error) {
            console.error('Error switching camera:', error);
        }
    }

    onUnmounted(() => {
        stopScanning();
    });

    return {
        isScanning,
        hasCamera,
        errorMessage,
        lastScannedCode,
        startScanning,
        stopScanning,
        switchCamera,
    };
}
