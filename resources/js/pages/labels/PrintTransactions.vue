<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    PrinterIcon,
    CheckIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useZebraPrint, type ZebraPrinter } from '@/composables/useZebraPrint';
import { type BreadcrumbItem } from '@/types';

interface LabelTemplate {
    id: number;
    name: string;
    is_default: boolean;
    canvas_width: number;
    canvas_height: number;
}

interface TransactionData {
    transaction: Record<string, string | null>;
    customer: Record<string, string | null>;
}

interface Props {
    templates: LabelTemplate[];
    transactions: TransactionData[];
    selectedTransactionIds: number[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Labels', href: '/labels' },
    { title: 'Print transaction labels', href: '#' },
];

// Zebra print composable
const { status, printing, connect, selectPrinter, print } = useZebraPrint();

// Form state
const selectedTemplateId = ref<number | null>(
    props.templates.find(t => t.is_default)?.id || props.templates[0]?.id || null
);
const quantity = ref(1);
const printMode = ref<'zebra' | 'browser'>('zebra');

// Generated ZPL
const generatedZpl = ref<string | null>(null);
const isGenerating = ref(false);
const generateError = ref<string | null>(null);

// Connect to Zebra on mount
onMounted(async () => {
    await connect();
});

// Selected template
const selectedTemplate = computed(() => {
    return props.templates.find(t => t.id === selectedTemplateId.value) || null;
});

// Generate ZPL
async function generateZpl() {
    if (!selectedTemplateId.value || props.selectedTransactionIds.length === 0) return;

    isGenerating.value = true;
    generateError.value = null;
    generatedZpl.value = null;

    try {
        const response = await fetch('/print-labels/transactions/zpl', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                template_id: selectedTemplateId.value,
                transaction_ids: props.selectedTransactionIds,
                quantity: quantity.value,
            }),
        });

        if (!response.ok) {
            throw new Error('Failed to generate labels');
        }

        const data = await response.json();
        generatedZpl.value = data.zpl;
    } catch (err) {
        generateError.value = 'Failed to generate labels. Please try again.';
    } finally {
        isGenerating.value = false;
    }
}

// Print to Zebra
async function printToZebra() {
    if (!generatedZpl.value) {
        await generateZpl();
    }

    if (!generatedZpl.value) return;

    const success = await print(generatedZpl.value);
    if (success) {
        // Could show success message or redirect
    }
}

// Print to browser (opens print dialog)
function printToBrowser() {
    if (!generatedZpl.value) return;

    const printWindow = window.open('', '_blank');
    if (!printWindow) return;

    printWindow.document.write(`
        <html>
            <head>
                <title>Print Labels</title>
                <style>
                    body { font-family: monospace; white-space: pre; padding: 20px; }
                    .label { border: 1px solid #ccc; padding: 10px; margin: 10px 0; background: #fff; }
                    @media print {
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="no-print">
                    <p>Note: For best results with Zebra printers, use the direct print option.</p>
                    <p>This browser preview shows the ZPL code that would be sent to the printer.</p>
                    <button onclick="window.print()">Print</button>
                    <hr>
                </div>
                <div class="label">
                    ${generatedZpl.value.replace(/\n/g, '<br>')}
                </div>
            </body>
        </html>
    `);
    printWindow.document.close();
}

// Handle printer selection
function handlePrinterSelect(event: Event) {
    const select = event.target as HTMLSelectElement;
    selectPrinter(select.value);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Print transaction labels" />

        <div class="mx-auto max-w-4xl space-y-6">
            <HeadingSmall
                title="Print transaction labels"
                :description="`Print labels for ${selectedTransactionIds.length} selected transaction${selectedTransactionIds.length === 1 ? '' : 's'}`"
            />

            <!-- No templates warning -->
            <div v-if="templates.length === 0" class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-500/20 dark:bg-yellow-500/10">
                <div class="flex items-center gap-3">
                    <ExclamationTriangleIcon class="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                    <div>
                        <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">No label templates</p>
                        <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                            You need to create a transaction label template first.
                            <a href="/labels/create" class="font-medium underline">Create template</a>
                        </p>
                    </div>
                </div>
            </div>

            <template v-else>
                <!-- Print settings -->
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Print Settings</h3>

                    <div class="grid gap-6 md:grid-cols-2">
                        <!-- Template selection -->
                        <div>
                            <Label for="template">Label Template</Label>
                            <select
                                id="template"
                                v-model="selectedTemplateId"
                                class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-white/10"
                            >
                                <option v-for="t in templates" :key="t.id" :value="t.id">
                                    {{ t.name }} {{ t.is_default ? '(Default)' : '' }}
                                </option>
                            </select>
                            <p v-if="selectedTemplate" class="mt-1 text-xs text-gray-500">
                                {{ Math.round(selectedTemplate.canvas_width / 203 * 100) / 100 }}" x {{ Math.round(selectedTemplate.canvas_height / 203 * 100) / 100 }}"
                            </p>
                        </div>

                        <!-- Quantity -->
                        <div>
                            <Label for="quantity">Copies per item</Label>
                            <Input
                                id="quantity"
                                v-model.number="quantity"
                                type="number"
                                min="1"
                                max="100"
                                class="mt-1"
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Total labels: {{ selectedTransactionIds.length * quantity }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Printer connection -->
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Printer</h3>

                    <!-- Print mode tabs -->
                    <div class="mb-4 flex gap-2">
                        <button
                            @click="printMode = 'zebra'"
                            :class="[
                                'rounded-md px-3 py-1.5 text-sm font-medium',
                                printMode === 'zebra'
                                    ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400'
                                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300',
                            ]"
                        >
                            Zebra Direct Print
                        </button>
                        <button
                            @click="printMode = 'browser'"
                            :class="[
                                'rounded-md px-3 py-1.5 text-sm font-medium',
                                printMode === 'browser'
                                    ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400'
                                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300',
                            ]"
                        >
                            Browser Print
                        </button>
                    </div>

                    <!-- Zebra mode -->
                    <div v-if="printMode === 'zebra'" class="space-y-4">
                        <div v-if="status.loading" class="text-sm text-gray-500">
                            Connecting to Zebra Browser Print...
                        </div>

                        <div v-else-if="status.error" class="rounded-md bg-red-50 p-4 dark:bg-red-500/10">
                            <p class="text-sm text-red-800 dark:text-red-200">{{ status.error }}</p>
                            <p class="mt-2 text-xs text-red-600 dark:text-red-300">
                                Make sure Zebra Browser Print is installed and running.
                                <a
                                    href="https://www.zebra.com/us/en/support-downloads/software/printer-software/browser-print.html"
                                    target="_blank"
                                    class="underline"
                                >
                                    Download here
                                </a>
                            </p>
                            <Button variant="outline" size="sm" class="mt-2" @click="connect">
                                Retry connection
                            </Button>
                        </div>

                        <div v-else-if="status.connected">
                            <div class="flex items-center gap-2 text-sm text-green-600 dark:text-green-400">
                                <CheckIcon class="h-4 w-4" />
                                Connected to Zebra Browser Print
                            </div>

                            <div v-if="status.printers.length > 0" class="mt-4">
                                <Label for="printer">Select Printer</Label>
                                <select
                                    id="printer"
                                    :value="status.selectedPrinter?.uid"
                                    @change="handlePrinterSelect"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-white/10"
                                >
                                    <option v-for="printer in status.printers" :key="printer.uid" :value="printer.uid">
                                        {{ printer.name }}
                                    </option>
                                </select>
                            </div>

                            <p v-else class="mt-2 text-sm text-gray-500">
                                No printers found. Make sure your Zebra printer is connected.
                            </p>
                        </div>
                    </div>

                    <!-- Browser mode -->
                    <div v-else class="text-sm text-gray-500">
                        Labels will be generated and opened in a new window for printing through your browser.
                    </div>
                </div>

                <!-- Preview section -->
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Items to Print</h3>

                    <div class="max-h-60 overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Transaction #</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Customer</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Bin</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                <tr v-for="(t, index) in transactions" :key="index">
                                    <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">{{ t.transaction.transaction_number }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{{ t.transaction.type }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{{ t.customer.full_name || '-' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">{{ t.transaction.bin_location || '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Error message -->
                <div v-if="generateError" class="rounded-md bg-red-50 p-4 dark:bg-red-500/10">
                    <p class="text-sm text-red-800 dark:text-red-200">{{ generateError }}</p>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4">
                    <Button variant="outline" as="a" href="/transactions">
                        Cancel
                    </Button>

                    <Button
                        v-if="printMode === 'zebra'"
                        @click="printToZebra"
                        :disabled="!status.connected || !status.selectedPrinter || isGenerating || printing"
                    >
                        <PrinterIcon class="mr-2 h-4 w-4" />
                        {{ isGenerating ? 'Generating...' : printing ? 'Printing...' : 'Print Labels' }}
                    </Button>

                    <Button
                        v-else
                        @click="generateZpl().then(() => printToBrowser())"
                        :disabled="isGenerating"
                    >
                        <PrinterIcon class="mr-2 h-4 w-4" />
                        {{ isGenerating ? 'Generating...' : 'Preview & Print' }}
                    </Button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
