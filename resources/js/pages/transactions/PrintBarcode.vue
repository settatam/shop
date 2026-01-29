<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref, computed } from 'vue';
import { ArrowLeftIcon, PrinterIcon, ComputerDesktopIcon, ExclamationTriangleIcon } from '@heroicons/vue/20/solid';
import JsBarcode from 'jsbarcode';
import { useZebraPrint, ZPL, type PrinterSettings } from '@/composables/useZebraPrint';

interface PrinterSettingOption {
    id: number;
    name: string;
    top_offset: number;
    left_offset: number;
    right_offset: number;
    text_size: number;
    barcode_height: number;
    line_height: number;
    label_width: number;
    label_height: number;
    is_default: boolean;
}

interface Props {
    transaction: {
        id: number;
        transaction_number: string;
        type: string;
        customer: {
            full_name: string;
        } | null;
        created_at: string;
    };
    printerSettings: PrinterSettingOption[];
}

const props = defineProps<Props>();

const barcodeRef = ref<SVGElement | null>(null);
const printMode = ref<'browser' | 'zebra'>('browser');
const copies = ref(1);
const printSuccess = ref(false);
const selectedPrinterSettingId = ref<number | null>(
    props.printerSettings.find(s => s.is_default)?.id || props.printerSettings[0]?.id || null
);

// Zebra Browser Print
const { status: zebraStatus, printing, connect, selectPrinter, print } = useZebraPrint();

const selectedPrinterSetting = computed<PrinterSettings | undefined>(() => {
    const setting = props.printerSettings.find(s => s.id === selectedPrinterSettingId.value);
    if (!setting) return undefined;
    return {
        top_offset: setting.top_offset,
        left_offset: setting.left_offset,
        right_offset: setting.right_offset,
        text_size: setting.text_size,
        barcode_height: setting.barcode_height,
        line_height: setting.line_height,
        label_width: setting.label_width,
        label_height: setting.label_height,
    };
});

const typeLabels: Record<string, string> = {
    in_house: 'In-House Buy',
    mail_in: 'Mail-In Buy',
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

onMounted(async () => {
    // Generate browser barcode
    if (barcodeRef.value) {
        JsBarcode(barcodeRef.value, props.transaction.transaction_number, {
            format: 'CODE128',
            width: 2,
            height: 60,
            displayValue: true,
            fontSize: 14,
            margin: 10,
        });
    }

    // Try to connect to Zebra Browser Print
    await connect();
});

const browserPrint = () => {
    window.print();
};

const zebraPrint = async () => {
    printSuccess.value = false;

    // Generate ZPL for the label
    const zpl = ZPL.transactionLabel({
        transactionNumber: props.transaction.transaction_number,
        type: typeLabels[props.transaction.type] || props.transaction.type,
        customerName: props.transaction.customer?.full_name,
        date: formatDate(props.transaction.created_at),
        settings: selectedPrinterSetting.value,
    });

    // Print multiple copies
    const labels = Array(copies.value).fill(zpl);
    const success = await print(ZPL.batch(labels));

    if (success) {
        printSuccess.value = true;
        setTimeout(() => {
            printSuccess.value = false;
        }, 3000);
    }
};

const handlePrint = () => {
    if (printMode.value === 'zebra') {
        zebraPrint();
    } else {
        browserPrint();
    }
};
</script>

<template>
    <Head :title="`Print Barcode - ${transaction.transaction_number}`" />

    <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
        <!-- Header (hidden when printing) -->
        <div class="print:hidden bg-white shadow dark:bg-gray-800">
            <div class="mx-auto max-w-3xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="`/transactions/${transaction.id}`"
                            class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                        >
                            <ArrowLeftIcon class="size-5" />
                        </Link>
                        <h1 class="text-lg font-semibold text-gray-900 dark:text-white">
                            Print Barcode
                        </h1>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                        :disabled="printing || (printMode === 'zebra' && !zebraStatus.selectedPrinter)"
                        @click="handlePrint"
                    >
                        <PrinterIcon class="-ml-0.5 size-5" />
                        {{ printing ? 'Printing...' : 'Print' }}
                    </button>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8 print:p-0 print:max-w-none">
            <!-- Print Mode Selection (hidden when printing) -->
            <div class="print:hidden mb-6 bg-white rounded-lg shadow dark:bg-gray-800 p-4">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white mb-3">Print Method</h3>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Browser Print -->
                    <button
                        type="button"
                        :class="[
                            'flex items-center gap-3 p-3 rounded-lg border-2 text-left transition-colors',
                            printMode === 'browser'
                                ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                                : 'border-gray-200 hover:border-gray-300 dark:border-gray-600 dark:hover:border-gray-500',
                        ]"
                        @click="printMode = 'browser'"
                    >
                        <ComputerDesktopIcon class="size-6 text-gray-400" />
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Browser Print</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Use system print dialog</p>
                        </div>
                    </button>

                    <!-- Zebra Print -->
                    <button
                        type="button"
                        :class="[
                            'flex items-center gap-3 p-3 rounded-lg border-2 text-left transition-colors',
                            printMode === 'zebra'
                                ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                                : 'border-gray-200 hover:border-gray-300 dark:border-gray-600 dark:hover:border-gray-500',
                        ]"
                        @click="printMode = 'zebra'"
                    >
                        <PrinterIcon class="size-6 text-gray-400" />
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Zebra Printer</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Direct label printing</p>
                        </div>
                    </button>
                </div>

                <!-- Zebra Printer Options -->
                <div v-if="printMode === 'zebra'" class="mt-4 space-y-3">
                    <!-- Connection Status -->
                    <div v-if="zebraStatus.loading" class="flex items-center gap-2 text-sm text-gray-500">
                        <svg class="animate-spin size-4" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        Connecting to Zebra Browser Print...
                    </div>

                    <div v-else-if="zebraStatus.error" class="flex items-start gap-2 p-3 rounded-md bg-yellow-50 dark:bg-yellow-900/20">
                        <ExclamationTriangleIcon class="size-5 text-yellow-600 shrink-0 mt-0.5" />
                        <div>
                            <p class="text-sm text-yellow-800 dark:text-yellow-200">{{ zebraStatus.error }}</p>
                            <a
                                href="https://www.zebra.com/us/en/support-downloads/software/printer-software/browser-print.html"
                                target="_blank"
                                class="text-xs text-yellow-700 dark:text-yellow-300 underline hover:no-underline"
                            >
                                Download Zebra Browser Print
                            </a>
                        </div>
                    </div>

                    <div v-else-if="zebraStatus.connected" class="space-y-3">
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Printer Selection -->
                            <div>
                                <label for="printer" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Select Printer
                                </label>
                                <select
                                    id="printer"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    :value="zebraStatus.selectedPrinter?.uid"
                                    @change="selectPrinter(($event.target as HTMLSelectElement).value)"
                                >
                                    <option v-for="printer in zebraStatus.printers" :key="printer.uid" :value="printer.uid">
                                        {{ printer.name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Number of Copies -->
                            <div>
                                <label for="copies" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Number of Copies
                                </label>
                                <input
                                    id="copies"
                                    v-model.number="copies"
                                    type="number"
                                    min="1"
                                    max="100"
                                    class="mt-1 block w-24 rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                        </div>

                        <!-- Label Settings -->
                        <div v-if="printerSettings.length > 0">
                            <label for="printerSetting" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Label Settings
                            </label>
                            <select
                                id="printerSetting"
                                v-model="selectedPrinterSettingId"
                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            >
                                <option v-for="setting in printerSettings" :key="setting.id" :value="setting.id">
                                    {{ setting.name }}{{ setting.is_default ? ' (Default)' : '' }}
                                </option>
                            </select>
                        </div>
                        <div v-else class="text-sm text-gray-500 dark:text-gray-400">
                            <a href="/settings/printers" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                Configure label settings
                            </a>
                            for better control over label printing.
                        </div>
                    </div>

                    <!-- Success Message -->
                    <div v-if="printSuccess" class="p-3 rounded-md bg-green-50 dark:bg-green-900/20">
                        <p class="text-sm text-green-800 dark:text-green-200">
                            Label{{ copies > 1 ? 's' : '' }} sent to printer successfully!
                        </p>
                    </div>
                </div>
            </div>

            <!-- Barcode Preview -->
            <div class="bg-white rounded-lg shadow print:shadow-none print:rounded-none">
                <div class="p-6 print:p-4">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-4 print:hidden">Label Preview</h3>

                    <!-- Label content -->
                    <div class="barcode-label mx-auto max-w-sm border border-gray-200 p-4 print:border-0 print:max-w-none">
                        <div class="text-center">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">
                                {{ typeLabels[transaction.type] || transaction.type }}
                            </p>
                            <svg ref="barcodeRef" class="mx-auto mt-2"></svg>
                            <div class="mt-2 space-y-1">
                                <p v-if="transaction.customer" class="text-sm font-medium text-gray-900">
                                    {{ transaction.customer.full_name }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ formatDate(transaction.created_at) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Help Text -->
            <div class="mt-6 print:hidden text-center">
                <p v-if="printMode === 'browser'" class="text-sm text-gray-500 dark:text-gray-400">
                    Use the browser's print dialog to adjust the number of copies.
                </p>
                <p v-else class="text-sm text-gray-500 dark:text-gray-400">
                    Labels will be sent directly to your Zebra printer.
                </p>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    @page {
        size: auto;
        margin: 0.5cm;
    }

    body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .barcode-label {
        page-break-inside: avoid;
    }
}
</style>
