import { ref, onMounted } from 'vue';

export interface ZebraPrinter {
    name: string;
    uid: string;
    connection: string;
    deviceType: string;
    provider: string;
    manufacturer: string;
    version: number;
}

export interface ZebraPrintStatus {
    connected: boolean;
    printers: ZebraPrinter[];
    selectedPrinter: ZebraPrinter | null;
    error: string | null;
    loading: boolean;
}

const ZEBRA_BROWSER_PRINT_URL = 'http://localhost:9100';

/**
 * Composable for Zebra Browser Print integration.
 * Requires Zebra Browser Print app to be installed and running.
 * Download from: https://www.zebra.com/us/en/support-downloads/software/printer-software/browser-print.html
 */
export function useZebraPrint() {
    const status = ref<ZebraPrintStatus>({
        connected: false,
        printers: [],
        selectedPrinter: null,
        error: null,
        loading: false,
    });

    const printing = ref(false);

    /**
     * Check if Zebra Browser Print is available and get printers
     */
    const connect = async (): Promise<boolean> => {
        status.value.loading = true;
        status.value.error = null;

        try {
            // Get available printers
            const response = await fetch(`${ZEBRA_BROWSER_PRINT_URL}/available`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'text/plain',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to connect to Zebra Browser Print');
            }

            const data = await response.json();
            status.value.printers = data.printer || [];
            status.value.connected = true;

            // Try to get default printer
            try {
                const defaultResponse = await fetch(`${ZEBRA_BROWSER_PRINT_URL}/default?type=printer`, {
                    method: 'GET',
                });
                if (defaultResponse.ok) {
                    const defaultPrinter = await defaultResponse.json();
                    if (defaultPrinter) {
                        status.value.selectedPrinter = defaultPrinter;
                    }
                }
            } catch {
                // Default printer not available, user will select manually
            }

            // If no default, select first available
            if (!status.value.selectedPrinter && status.value.printers.length > 0) {
                status.value.selectedPrinter = status.value.printers[0];
            }

            return true;
        } catch (err) {
            status.value.connected = false;
            status.value.error = 'Zebra Browser Print not detected. Please ensure the app is installed and running.';
            return false;
        } finally {
            status.value.loading = false;
        }
    };

    /**
     * Select a printer by UID
     */
    const selectPrinter = (uid: string) => {
        const printer = status.value.printers.find(p => p.uid === uid);
        if (printer) {
            status.value.selectedPrinter = printer;
        }
    };

    /**
     * Send ZPL commands to the selected printer
     */
    const print = async (zpl: string): Promise<boolean> => {
        if (!status.value.selectedPrinter) {
            status.value.error = 'No printer selected';
            return false;
        }

        printing.value = true;
        status.value.error = null;

        try {
            const response = await fetch(`${ZEBRA_BROWSER_PRINT_URL}/write`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'text/plain',
                },
                body: JSON.stringify({
                    device: status.value.selectedPrinter,
                    data: zpl,
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to print');
            }

            return true;
        } catch (err) {
            status.value.error = 'Failed to send print job. Check printer connection.';
            return false;
        } finally {
            printing.value = false;
        }
    };

    /**
     * Read printer status/configuration
     */
    const readPrinterStatus = async (): Promise<string | null> => {
        if (!status.value.selectedPrinter) {
            return null;
        }

        try {
            const response = await fetch(`${ZEBRA_BROWSER_PRINT_URL}/read`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'text/plain',
                },
                body: JSON.stringify({
                    device: status.value.selectedPrinter,
                }),
            });

            if (response.ok) {
                return await response.text();
            }
            return null;
        } catch {
            return null;
        }
    };

    /**
     * Send ZPL to network printer via server (for iPad/mobile)
     */
    const networkPrint = async (printerId: number, zpl: string): Promise<boolean> => {
        printing.value = true;
        status.value.error = null;

        try {
            const response = await fetch(`/settings/printers/${printerId}/network-print`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ zpl }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Failed to print');
            }

            return true;
        } catch (err) {
            status.value.error = err instanceof Error ? err.message : 'Failed to send print job via network.';
            return false;
        } finally {
            printing.value = false;
        }
    };

    return {
        status,
        printing,
        connect,
        selectPrinter,
        print,
        networkPrint,
        readPrinterStatus,
    };
}

export interface PrinterSettings {
    top_offset: number;
    left_offset: number;
    right_offset: number;
    text_size: number;
    barcode_height: number;
    line_height: number;
    label_width: number;
    label_height: number;
}

const defaultSettings: PrinterSettings = {
    top_offset: 30,
    left_offset: 0,
    right_offset: 0,
    text_size: 20,
    barcode_height: 50,
    line_height: 25,
    label_width: 406,
    label_height: 203,
};

/**
 * ZPL Label Generator utilities
 */
export const ZPL = {
    /**
     * Generate ZPL for a simple barcode label
     */
    barcodeLabel(options: {
        barcode: string;
        title?: string;
        subtitle?: string;
        price?: string;
        settings?: Partial<PrinterSettings>;
    }): string {
        const settings = { ...defaultSettings, ...options.settings };
        const {
            barcode,
            title = '',
            subtitle = '',
            price = '',
        } = options;

        const {
            top_offset,
            left_offset,
            text_size,
            barcode_height,
            line_height,
            label_width,
            label_height,
        } = settings;

        let zpl = '^XA'; // Start format
        zpl += `^PW${label_width}`; // Print width
        zpl += `^LL${label_height}`; // Label length

        let currentY = top_offset;

        // Title (centered at top)
        if (title) {
            const titleTrunc = title.substring(0, 30); // Limit title length
            zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${text_size},${text_size}^FD${titleTrunc}^FS`;
            currentY += line_height;
        }

        // Subtitle (variant info)
        if (subtitle) {
            const subtitleSize = Math.floor(text_size * 0.8);
            zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${subtitleSize},${subtitleSize}^FD${subtitle}^FS`;
            currentY += Math.floor(line_height * 0.8);
        }

        // Barcode (Code 128, centered)
        const barcodeWidth = Math.min(label_width - 40 - left_offset, 300);
        const barcodeX = Math.floor((label_width - barcodeWidth) / 2) + left_offset;
        zpl += `^FO${barcodeX},${currentY}^BY2,2,${barcode_height}^BCN,,Y,N,N^FD${barcode}^FS`;
        currentY += barcode_height + 15;

        // Price (centered at bottom)
        if (price) {
            const priceSize = Math.floor(text_size * 1.2);
            zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${priceSize},${priceSize}^FD${price}^FS`;
        }

        zpl += '^XZ'; // End format

        return zpl;
    },

    /**
     * Generate ZPL for a barcode label with configurable lines
     * Format: Code at top, barcode underneath, then list of values (vertical)
     */
    barcodeLabelWithLines(options: {
        barcode: string;
        lines: string[];
        settings?: Partial<PrinterSettings>;
    }): string {
        const settings = { ...defaultSettings, ...options.settings };
        const { barcode, lines } = options;

        const {
            top_offset,
            left_offset,
            text_size,
            barcode_height,
            line_height,
            label_width,
            label_height,
        } = settings;

        let zpl = '^XA'; // Start format
        zpl += `^PW${label_width}`; // Print width
        zpl += `^LL${label_height}`; // Label length

        let currentY = top_offset;

        // Barcode value as text at top (centered)
        const codeSize = Math.floor(text_size * 0.9);
        zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${codeSize},${codeSize}^FD${barcode}^FS`;
        currentY += Math.floor(line_height * 0.9);

        // Barcode (Code 128, centered)
        const barcodeWidth = Math.min(label_width - 40 - left_offset, 300);
        const barcodeX = Math.floor((label_width - barcodeWidth) / 2) + left_offset;
        zpl += `^FO${barcodeX},${currentY}^BY2,2,${barcode_height}^BCN,,N,N,N^FD${barcode}^FS`;
        currentY += barcode_height + 8;

        // Additional lines (attribute values)
        const attrSize = Math.floor(text_size * 0.85);
        const attrLineHeight = Math.floor(line_height * 0.85);

        for (const line of lines) {
            if (line && currentY + attrLineHeight <= label_height) {
                const lineTrunc = line.substring(0, 35); // Limit line length
                zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${attrSize},${attrSize}^FD${lineTrunc}^FS`;
                currentY += attrLineHeight;
            }
        }

        zpl += '^XZ'; // End format

        return zpl;
    },

    /**
     * Generate ZPL for a barcode label with attributes on a single horizontal line
     * Format: SKU/code at top, barcode underneath, then single line with all attribute values
     */
    barcodeLabelWithAttributes(options: {
        barcode: string;
        attributeLine: string;
        settings?: Partial<PrinterSettings>;
    }): string {
        const settings = { ...defaultSettings, ...options.settings };
        const { barcode, attributeLine } = options;

        const {
            top_offset,
            left_offset,
            text_size,
            barcode_height,
            line_height,
            label_width,
            label_height,
        } = settings;

        let zpl = '^XA'; // Start format
        zpl += `^PW${label_width}`; // Print width
        zpl += `^LL${label_height}`; // Label length

        let currentY = top_offset;

        // SKU/code text at top (centered)
        const codeSize = Math.floor(text_size * 0.9);
        zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${codeSize},${codeSize}^FD${barcode}^FS`;
        currentY += Math.floor(line_height * 0.9);

        // Barcode (Code 128, centered) without human-readable text
        const barcodeWidth = Math.min(label_width - 40 - left_offset, 300);
        const barcodeX = Math.floor((label_width - barcodeWidth) / 2) + left_offset;
        zpl += `^FO${barcodeX},${currentY}^BY2,2,${barcode_height}^BCN,,N,N,N^FD${barcode}^FS`;
        currentY += barcode_height + 8;

        // Single line with all attribute values (centered)
        if (attributeLine) {
            const attrSize = Math.floor(text_size * 0.85);
            const lineTrunc = attributeLine.substring(0, 50); // Limit line length
            zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${attrSize},${attrSize}^FD${lineTrunc}^FS`;
        }

        zpl += '^XZ'; // End format

        return zpl;
    },

    /**
     * Generate ZPL for a transaction/buy label
     */
    transactionLabel(options: {
        transactionNumber: string;
        type: string;
        customerName?: string;
        date?: string;
        settings?: Partial<PrinterSettings>;
    }): string {
        const settings = { ...defaultSettings, ...options.settings };
        const {
            transactionNumber,
            type,
            customerName = '',
            date = '',
        } = options;

        const {
            top_offset,
            left_offset,
            text_size,
            barcode_height,
            line_height,
            label_width,
            label_height,
        } = settings;

        let zpl = '^XA';
        zpl += `^PW${label_width}`;
        zpl += `^LL${label_height}`;

        let currentY = top_offset;

        // Type label at top
        const typeSize = Math.floor(text_size * 0.9);
        zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${typeSize},${typeSize}^FD${type.toUpperCase()}^FS`;
        currentY += line_height;

        // Barcode (transaction number)
        const barcodeWidth = Math.min(label_width - 40 - left_offset, 280);
        const barcodeX = Math.floor((label_width - barcodeWidth) / 2) + left_offset;
        zpl += `^FO${barcodeX},${currentY}^BY2,2,${barcode_height}^BCN,,Y,N,N^FD${transactionNumber}^FS`;
        currentY += barcode_height + 15;

        // Customer name
        if (customerName) {
            zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${text_size},${text_size}^FD${customerName.substring(0, 25)}^FS`;
            currentY += line_height;
        }

        // Date
        if (date) {
            const dateSize = Math.floor(text_size * 0.9);
            zpl += `^FO${left_offset},${currentY}^FB${label_width - left_offset},1,0,C,0^A0N,${dateSize},${dateSize}^FD${date}^FS`;
        }

        zpl += '^XZ';

        return zpl;
    },

    /**
     * Combine multiple labels into a single print job
     */
    batch(labels: string[]): string {
        return labels.join('\n');
    },
};
