import { ref, onMounted, onUnmounted } from 'vue';

interface BarcodeScannerOptions {
    /**
     * Callback when a barcode is scanned
     */
    onScan: (barcode: string) => void;

    /**
     * Maximum time between keystrokes to consider it a barcode scan (ms)
     * Barcode scanners typically type at 10-50ms per character
     */
    maxKeystrokeDelay?: number;

    /**
     * Minimum length of barcode to trigger scan
     */
    minLength?: number;

    /**
     * Whether to prevent default behavior on scan
     */
    preventDefault?: boolean;

    /**
     * Whether the scanner is enabled
     */
    enabled?: boolean;

    /**
     * Element IDs to ignore (don't capture scans when these are focused)
     */
    ignoreInputIds?: string[];
}

export function useBarcodeScanner(options: BarcodeScannerOptions) {
    const {
        onScan,
        maxKeystrokeDelay = 50,
        minLength = 3,
        preventDefault = true,
        enabled = true,
        ignoreInputIds = [],
    } = options;

    const isEnabled = ref(enabled);
    const buffer = ref('');
    const lastKeyTime = ref(0);
    let bufferTimeout: ReturnType<typeof setTimeout> | null = null;
    // Track whether we're in the middle of rapid scanner input
    let isInScannerStream = false;

    function clearBuffer() {
        buffer.value = '';
        isInScannerStream = false;
        if (bufferTimeout) {
            clearTimeout(bufferTimeout);
            bufferTimeout = null;
        }
    }

    function resetBufferTimeout() {
        if (bufferTimeout) {
            clearTimeout(bufferTimeout);
        }
        bufferTimeout = setTimeout(() => {
            // Scanner stopped sending data — finalize whatever is in the buffer
            if (buffer.value.length >= minLength) {
                onScan(buffer.value);
            }
            clearBuffer();
        }, 200);
    }

    function shouldIgnoreInput(): boolean {
        const activeElement = document.activeElement;
        if (!activeElement) return false;

        // Check if it's an input/textarea that we should ignore
        const tagName = activeElement.tagName.toLowerCase();
        if (tagName === 'input' || tagName === 'textarea') {
            const id = activeElement.id;
            // If it's in our ignore list, don't capture
            if (ignoreInputIds.includes(id)) {
                return true;
            }
            // If it's a text input with content being typed slowly, ignore
            // The barcode scanner detection will still work for rapid input
            return false;
        }

        return false;
    }

    function handleKeyDown(event: KeyboardEvent) {
        if (!isEnabled.value || !event.key) return;

        const currentTime = Date.now();
        const timeDiff = currentTime - lastKeyTime.value;

        // Check if this could be a barcode scan (rapid input)
        const isRapidInput = timeDiff < maxKeystrokeDelay || buffer.value === '';

        // Handle Enter key
        if (event.key === 'Enter') {
            if (isInScannerStream && isRapidInput) {
                // Mid-stream Enter during rapid input (e.g. AAMVA \n separators)
                // Treat as data, not as end-of-barcode
                if (preventDefault) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                buffer.value += '\n';
                lastKeyTime.value = currentTime;
                resetBufferTimeout();
                return;
            }

            // Enter after a pause — finalize the barcode
            if (buffer.value.length >= minLength) {
                if (preventDefault) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                onScan(buffer.value);
                clearBuffer();
                return;
            }
            clearBuffer();
            return;
        }

        // Handle Tab key - some scanners use Tab instead of Enter
        if (event.key === 'Tab' && buffer.value.length >= minLength && isRapidInput) {
            if (preventDefault) {
                event.preventDefault();
                event.stopPropagation();
            }
            onScan(buffer.value);
            clearBuffer();
            return;
        }

        // Only capture printable characters
        if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            if (isRapidInput) {
                buffer.value += event.key;
                lastKeyTime.value = currentTime;
                isInScannerStream = true;

                resetBufferTimeout();
            } else {
                // Not rapid input - this is normal typing, clear and start fresh
                clearBuffer();
                buffer.value = event.key;
                lastKeyTime.value = currentTime;
            }
        }
    }

    function enable() {
        isEnabled.value = true;
    }

    function disable() {
        isEnabled.value = false;
        clearBuffer();
    }

    onMounted(() => {
        document.addEventListener('keydown', handleKeyDown, true);
    });

    onUnmounted(() => {
        document.removeEventListener('keydown', handleKeyDown, true);
        clearBuffer();
    });

    return {
        isEnabled,
        enable,
        disable,
        clearBuffer,
    };
}
