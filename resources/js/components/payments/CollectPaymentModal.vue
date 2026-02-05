<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    BanknotesIcon,
    CreditCardIcon,
    XMarkIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    ComputerDesktopIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';
import axios from 'axios';

// Types
export type PayableModelType = 'memo' | 'repair' | 'order' | 'layaway';

interface PaymentSummary {
    subtotal: number;
    discount_amount: number;
    service_fee_amount: number;
    tax_amount: number;
    shipping_cost: number;
    grand_total: number;
    total_paid: number;
    balance_due: number;
}

interface PayableModel {
    id: number;
    total: number;
    charge_taxes?: boolean;
    tax_rate?: number;
    shipping_cost?: number;
    discount_value?: number;
    discount_unit?: string;
    discount_reason?: string;
    service_fee_value?: number;
    service_fee_unit?: string;
    service_fee_reason?: string;
    tax_type?: string;
    grand_total?: number;
    total_paid?: number;
    balance_due?: number;
}

interface Terminal {
    id: number;
    name: string;
    gateway: string;
    status: string;
    warehouse?: {
        id: number;
        name: string;
    };
}

interface PaymentLine {
    id: number;
    payment_method: string;
    amount: number;
    service_fee_value: number;
    service_fee_unit: 'fixed' | 'percent';
    reference: string;
    terminal_id: number | null;
    notes: string;
}

interface Props {
    show: boolean;
    modelType: PayableModelType;
    model: PayableModel;
    /** Display title - e.g., "Memo #M-0001" or "Repair #R-0001" */
    title?: string;
    /** Subtitle - e.g., customer/vendor name */
    subtitle?: string;
    /** Whether to show adjustments panel (discount, service fee, etc.) */
    showAdjustments?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Collect Payment',
    subtitle: '',
    showAdjustments: true,
});

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'success'): void;
}>();

// API endpoint helpers
const apiBasePath = computed(() => {
    const paths: Record<PayableModelType, string> = {
        memo: `/memos/${props.model.id}/payment`,
        repair: `/repairs/${props.model.id}/payment`,
        order: `/orders/${props.model.id}/payment`,
        layaway: `/layaways/${props.model.id}/payment`,
    };
    return paths[props.modelType];
});

// State
const isLoading = ref(false);
const isProcessing = ref(false);
const error = ref<string | null>(null);
const successMessage = ref<string | null>(null);

// Terminal state
const terminals = ref<Terminal[]>([]);
const isLoadingTerminals = ref(false);
const terminalCheckoutStatus = ref<string | null>(null);
const processingTerminalLineId = ref<number | null>(null);
const currentCheckoutId = ref<number | null>(null);
const isCancelling = ref(false);

// Payment summary from API
const summary = ref<PaymentSummary>({
    subtotal: 0,
    discount_amount: 0,
    service_fee_amount: 0,
    tax_amount: 0,
    shipping_cost: 0,
    grand_total: 0,
    total_paid: 0,
    balance_due: 0,
});

// Form data for adjustments
const adjustments = ref({
    discount_value: 0,
    discount_unit: 'fixed' as 'fixed' | 'percent',
    discount_reason: '',
    service_fee_value: 0,
    service_fee_unit: 'fixed' as 'fixed' | 'percent',
    service_fee_reason: '',
    charge_taxes: false,
    tax_rate: 0,
    tax_type: 'percent' as 'percent' | 'fixed',
    shipping_cost: 0,
});

// Split payments - array of payment lines
let nextLineId = 1;
const paymentLines = ref<PaymentLine[]>([]);

function createPaymentLine(amount: number = 0): PaymentLine {
    return {
        id: nextLineId++,
        payment_method: 'cash',
        amount,
        service_fee_value: 0,
        service_fee_unit: 'fixed',
        reference: '',
        terminal_id: null,
        notes: '',
    };
}

function addPaymentLine() {
    const remainingBalance = remainingToAllocate.value;
    paymentLines.value.push(createPaymentLine(remainingBalance > 0 ? remainingBalance : 0));
}

function removePaymentLine(lineId: number) {
    if (paymentLines.value.length > 1) {
        paymentLines.value = paymentLines.value.filter(line => line.id !== lineId);
    }
}

const paymentMethods = [
    { value: 'cash', label: 'Cash', icon: BanknotesIcon },
    { value: 'terminal', label: 'Card Terminal', icon: ComputerDesktopIcon, requiresTerminal: true },
    { value: 'card', label: 'Card (Offline)', icon: CreditCardIcon },
    { value: 'check', label: 'Check', icon: BanknotesIcon },
    { value: 'bank_transfer', label: 'Bank Transfer', icon: BanknotesIcon },
    { value: 'external', label: 'External/Other', icon: BanknotesIcon },
];

// Computed for split payments
const totalPaymentAmount = computed(() => {
    return paymentLines.value.reduce((sum, line) => sum + (line.amount || 0), 0);
});

const totalServiceFees = computed(() => {
    return paymentLines.value.reduce((sum, line) => {
        return sum + getLineServiceFeeAmount(line);
    }, 0);
});

const grandTotalWithFees = computed(() => {
    return totalPaymentAmount.value + totalServiceFees.value;
});

const remainingToAllocate = computed(() => {
    return Math.max(0, summary.value.balance_due - totalPaymentAmount.value);
});

// Subtotal after discount - the base for service fee calculation
const subtotalAfterDiscount = computed(() => {
    return Math.max(0, summary.value.subtotal - summary.value.discount_amount);
});

function getLineServiceFeeAmount(line: PaymentLine): number {
    if (line.service_fee_value <= 0) return 0;

    // Service fee is only calculated on subtotal (cost of items), not shipping/taxes
    // For partial payments, it's based on payment amount but capped at subtotal
    const subtotal = subtotalAfterDiscount.value;

    if (line.service_fee_unit === 'percent') {
        // Calculate on the lesser of payment amount or subtotal
        const serviceFeeBase = Math.min(line.amount, subtotal);
        return serviceFeeBase * line.service_fee_value / 100;
    }

    // For fixed fee, if partial payment under subtotal, prorate it
    if (line.amount >= subtotal) {
        return line.service_fee_value;
    }
    return subtotal > 0 ? line.service_fee_value * (line.amount / subtotal) : 0;
}

// Get service fee calculation explanation for percentage fees
function getServiceFeeExplanation(line: PaymentLine): string | null {
    if (line.service_fee_value <= 0 || line.service_fee_unit !== 'percent') return null;

    const subtotal = subtotalAfterDiscount.value;
    const base = Math.min(line.amount, subtotal);
    const fee = getLineServiceFeeAmount(line);

    if (line.amount <= subtotal) {
        return `${line.service_fee_value}% of ${formatCurrency(base)} = ${formatCurrency(fee)}`;
    }
    return `${line.service_fee_value}% of ${formatCurrency(subtotal)} (items) = ${formatCurrency(fee)}`;
}

function getLineTotalWithFee(line: PaymentLine): number {
    return line.amount + getLineServiceFeeAmount(line);
}

function requiresReference(method: string): boolean {
    return ['card', 'check', 'bank_transfer', 'external'].includes(method);
}

function isTerminalPayment(method: string): boolean {
    return method === 'terminal';
}

// Check if any line has cash payment over $9,999
const hasLargeCashPayment = computed(() => {
    return paymentLines.value.some(line =>
        line.payment_method === 'cash' && line.amount > 9999
    );
});

const irsAcknowledged = ref(false);

// Computed
const canSubmitPayment = computed(() => {
    // Must have at least one payment with amount
    const hasValidPayments = paymentLines.value.some(line => line.amount > 0);
    if (!hasValidPayments) return false;

    // Total shouldn't exceed balance due (allow small floating point tolerance)
    if (totalPaymentAmount.value > summary.value.balance_due + 0.01) return false;

    // IRS acknowledgment check
    if (hasLargeCashPayment.value && !irsAcknowledged.value) return false;

    // All terminal payments must have terminal selected
    const terminalLinesValid = paymentLines.value
        .filter(line => line.payment_method === 'terminal' && line.amount > 0)
        .every(line => line.terminal_id !== null);
    if (!terminalLinesValid) return false;

    return true;
});

const isFullPayment = computed(() => {
    return Math.abs(totalPaymentAmount.value - summary.value.balance_due) < 0.01;
});

// Methods
function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

async function fetchSummary() {
    isLoading.value = true;
    error.value = null;

    try {
        const response = await axios.get(`${apiBasePath.value}/summary`, {
            params: adjustments.value,
        });
        summary.value = response.data.summary;

        // Update payment line amount when there's only one line (user hasn't split payment)
        if (paymentLines.value.length === 0) {
            paymentLines.value = [createPaymentLine(summary.value.balance_due)];
        } else if (paymentLines.value.length === 1) {
            // Update the single payment line to match new balance
            paymentLines.value[0].amount = summary.value.balance_due;
        }
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to load payment summary.';
    } finally {
        isLoading.value = false;
    }
}

async function updateAdjustments() {
    isLoading.value = true;
    error.value = null;

    try {
        const response = await axios.post(`${apiBasePath.value}/adjustments`, adjustments.value);
        summary.value = response.data.summary;
        // Update first payment line to reflect new balance
        if (paymentLines.value.length === 1) {
            paymentLines.value[0].amount = summary.value.balance_due;
        }
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to update adjustments.';
    } finally {
        isLoading.value = false;
    }
}

// Debounce utility
let debounceTimer: ReturnType<typeof setTimeout> | null = null;
function debouncedFetchSummary() {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(() => {
        fetchSummary();
    }, 300);
}

// Watch adjustments and auto-update summary
watch(
    () => adjustments.value,
    () => {
        if (props.show) {
            debouncedFetchSummary();
        }
    },
    { deep: true }
);

async function fetchTerminals() {
    isLoadingTerminals.value = true;
    try {
        const response = await axios.get('/api/v1/terminals', {
            params: { status: 'active' },
        });
        terminals.value = response.data.data || response.data.terminals || [];
    } catch (err: any) {
        console.error('Failed to load terminals:', err);
        terminals.value = [];
    } finally {
        isLoadingTerminals.value = false;
    }
}

async function submitPayment() {
    if (isProcessing.value || !canSubmitPayment.value) return;

    isProcessing.value = true;
    error.value = null;

    // Filter out empty payment lines
    const validPayments = paymentLines.value.filter(line => line.amount > 0);

    // Check if there are terminal payments - process them separately
    const terminalPayments = validPayments.filter(line => line.payment_method === 'terminal');
    const regularPayments = validPayments.filter(line => line.payment_method !== 'terminal');

    try {
        // Process regular payments first
        if (regularPayments.length > 0) {
            const payments = regularPayments.map(line => ({
                payment_method: line.payment_method,
                amount: line.amount,
                service_fee_value: line.service_fee_value || null,
                service_fee_unit: line.service_fee_value > 0 ? line.service_fee_unit : null,
                reference: line.reference || null,
                notes: line.notes || null,
            }));

            const response = await axios.post(`${apiBasePath.value}/process`, {
                payments,
            });

            // Update summary after regular payments
            const modelData = response.data.memo || response.data.repair || response.data.order;
            if (modelData) {
                summary.value = {
                    ...summary.value,
                    total_paid: modelData.total_paid,
                    balance_due: modelData.balance_due,
                };
            }
        }

        // Process terminal payments sequentially
        if (terminalPayments.length > 0) {
            for (const line of terminalPayments) {
                if (line.terminal_id) {
                    await processTerminalPayment(line);
                }
            }
        } else {
            // No terminal payments - close modal immediately
            isProcessing.value = false;
            emit('success');
        }
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to process payment.';
        isProcessing.value = false;
    }
}

async function processTerminalPayment(line: PaymentLine) {
    if (!line.terminal_id) return;

    processingTerminalLineId.value = line.id;
    terminalCheckoutStatus.value = 'initiating';

    try {
        const response = await axios.post(`${apiBasePath.value}/terminal-checkout`, {
            terminal_id: line.terminal_id,
            amount: line.amount,
            service_fee_value: line.service_fee_value || null,
            service_fee_unit: line.service_fee_value > 0 ? line.service_fee_unit : null,
            notes: line.notes || null,
        });

        const checkoutId = response.data.checkout_id;
        currentCheckoutId.value = checkoutId;
        terminalCheckoutStatus.value = 'waiting';
        successMessage.value = 'Payment initiated. Please complete the transaction on the terminal.';

        // Poll for checkout completion
        await pollCheckoutStatus(checkoutId);

    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to initiate terminal payment.';
        terminalCheckoutStatus.value = null;
        processingTerminalLineId.value = null;
        isProcessing.value = false;
    }
}

async function pollCheckoutStatus(checkoutId: number, maxAttempts = 60) {
    let attempts = 0;

    const poll = async () => {
        if (attempts >= maxAttempts) {
            error.value = 'Terminal payment timed out. Please try again.';
            terminalCheckoutStatus.value = null;
            processingTerminalLineId.value = null;
            isProcessing.value = false;
            return;
        }

        attempts++;

        try {
            const response = await axios.get(`/api/v1/terminal-checkouts/${checkoutId}`);
            const status = response.data.status;

            if (status === 'completed') {
                terminalCheckoutStatus.value = 'completed';
                processingTerminalLineId.value = null;
                emit('success');
                return;
            } else if (status === 'failed' || status === 'cancelled') {
                error.value = `Terminal payment ${status}. Please try again.`;
                terminalCheckoutStatus.value = null;
                processingTerminalLineId.value = null;
                isProcessing.value = false;
                return;
            }

            // Continue polling
            setTimeout(poll, 2000);
        } catch (err: any) {
            error.value = 'Failed to check payment status.';
            terminalCheckoutStatus.value = null;
            processingTerminalLineId.value = null;
            isProcessing.value = false;
        }
    };

    await poll();
}

async function cancelTerminalCheckout() {
    if (!currentCheckoutId.value || isCancelling.value) return;

    isCancelling.value = true;
    error.value = null;

    try {
        await axios.post(`/api/v1/terminal-checkouts/${currentCheckoutId.value}/cancel`);
        terminalCheckoutStatus.value = null;
        currentCheckoutId.value = null;
        processingTerminalLineId.value = null;
        isProcessing.value = false;
        successMessage.value = null;
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to cancel terminal payment.';
    } finally {
        isCancelling.value = false;
    }
}

function setFullPayment() {
    // Set all amount to first line
    if (paymentLines.value.length === 1) {
        paymentLines.value[0].amount = summary.value.balance_due;
    } else {
        // Reset to single full payment
        paymentLines.value = [createPaymentLine(summary.value.balance_due)];
    }
}

function close() {
    if (!isProcessing.value) {
        emit('close');
    }
}

// Initialize when modal opens
watch(() => props.show, (newVal) => {
    if (newVal) {
        // Reset form
        error.value = null;
        successMessage.value = null;
        terminalCheckoutStatus.value = null;
        processingTerminalLineId.value = null;
        currentCheckoutId.value = null;
        isCancelling.value = false;

        // Initialize adjustments from model
        adjustments.value = {
            discount_value: props.model.discount_value || 0,
            discount_unit: (props.model.discount_unit as 'fixed' | 'percent') || 'fixed',
            discount_reason: props.model.discount_reason || '',
            service_fee_value: props.model.service_fee_value || 0,
            service_fee_unit: (props.model.service_fee_unit as 'fixed' | 'percent') || 'fixed',
            service_fee_reason: props.model.service_fee_reason || '',
            charge_taxes: props.model.charge_taxes || false,
            tax_rate: props.model.tax_rate || 0,
            tax_type: (props.model.tax_type as 'percent' | 'fixed') || 'percent',
            shipping_cost: props.model.shipping_cost || 0,
        };

        // Reset payment lines
        nextLineId = 1;
        paymentLines.value = [createPaymentLine(0)];

        irsAcknowledged.value = false;
        terminals.value = [];

        fetchSummary();
        fetchTerminals();
    }
});
</script>

<template>
    <TransitionRoot as="template" :show="show">
        <Dialog as="div" class="relative z-50" @close="close">
            <TransitionChild
                as="template"
                enter="ease-out duration-300"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-200"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    >
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl dark:bg-gray-800">
                            <!-- Header -->
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                        <BanknotesIcon class="size-5 text-green-600 dark:text-green-400" />
                                    </div>
                                    <div>
                                        <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white">
                                            Collect Payment
                                        </DialogTitle>
                                        <p v-if="title || subtitle" class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ title }}<span v-if="title && subtitle"> - </span>{{ subtitle }}
                                        </p>
                                    </div>
                                </div>
                                <button type="button" @click="close" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700">
                                    <XMarkIcon class="size-5" />
                                </button>
                            </div>

                            <!-- Content -->
                            <div class="max-h-[70vh] overflow-y-auto px-6 py-4">
                                <!-- Error message -->
                                <div v-if="error" class="mb-4 rounded-md bg-red-50 p-4 dark:bg-red-900/50">
                                    <div class="flex">
                                        <ExclamationTriangleIcon class="size-5 text-red-400" />
                                        <p class="ml-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>
                                    </div>
                                </div>

                                <!-- Success message -->
                                <div v-if="successMessage" class="mb-4 rounded-md bg-green-50 p-4 dark:bg-green-900/50">
                                    <div class="flex">
                                        <CheckCircleIcon class="size-5 text-green-400" />
                                        <p class="ml-3 text-sm text-green-700 dark:text-green-300">{{ successMessage }}</p>
                                    </div>
                                </div>

                                <!-- Terminal checkout status -->
                                <div v-if="terminalCheckoutStatus === 'waiting'" class="mb-4 rounded-md bg-blue-50 p-4 dark:bg-blue-900/50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="animate-pulse">
                                                <ComputerDesktopIcon class="size-6 text-blue-500" />
                                            </div>
                                            <div>
                                                <p class="font-medium text-blue-800 dark:text-blue-300">Waiting for terminal...</p>
                                                <p class="text-sm text-blue-600 dark:text-blue-400">Please complete the payment on the terminal device.</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            @click="cancelTerminalCheckout"
                                            :disabled="isCancelling"
                                            class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-600"
                                        >
                                            {{ isCancelling ? 'Cancelling...' : 'Cancel' }}
                                        </button>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
                                    <!-- Left column: Summary (2 cols) -->
                                    <div class="lg:col-span-2 space-y-4">
                                        <!-- Payment Summary -->
                                        <div class="rounded-lg bg-indigo-50 p-4 dark:bg-indigo-900/30">
                                            <h4 class="mb-3 font-medium text-gray-900 dark:text-white">Order Summary</h4>
                                            <dl class="space-y-2 text-sm">
                                                <div class="flex justify-between">
                                                    <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
                                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(summary.subtotal) }}</dd>
                                                </div>
                                                <div v-if="summary.discount_amount > 0" class="flex justify-between text-green-600 dark:text-green-400">
                                                    <dt>Discount</dt>
                                                    <dd>-{{ formatCurrency(summary.discount_amount) }}</dd>
                                                </div>
                                                <div v-if="summary.service_fee_amount > 0" class="flex justify-between">
                                                    <dt class="text-gray-500 dark:text-gray-400">Service Fee</dt>
                                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(summary.service_fee_amount) }}</dd>
                                                </div>
                                                <div v-if="summary.tax_amount > 0 || adjustments.charge_taxes" class="flex justify-between">
                                                    <dt class="text-gray-500 dark:text-gray-400">
                                                        Tax
                                                        <span v-if="adjustments.charge_taxes && adjustments.tax_type === 'percent'" class="text-xs">({{ adjustments.tax_rate }}%)</span>
                                                    </dt>
                                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(summary.tax_amount) }}</dd>
                                                </div>
                                                <div v-if="summary.shipping_cost > 0" class="flex justify-between">
                                                    <dt class="text-gray-500 dark:text-gray-400">Shipping</dt>
                                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(summary.shipping_cost) }}</dd>
                                                </div>
                                                <div v-if="totalServiceFees > 0" class="flex justify-between text-amber-600 dark:text-amber-400">
                                                    <dt>Payment Processing Fee</dt>
                                                    <dd>{{ formatCurrency(totalServiceFees) }}</dd>
                                                </div>
                                                <div class="flex justify-between border-t border-indigo-200 pt-2 font-semibold dark:border-indigo-700">
                                                    <dt class="text-gray-900 dark:text-white">Grand Total</dt>
                                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(summary.grand_total + totalServiceFees) }}</dd>
                                                </div>
                                                <div v-if="summary.total_paid > 0" class="flex justify-between text-green-600 dark:text-green-400">
                                                    <dt>Amount Paid</dt>
                                                    <dd>-{{ formatCurrency(summary.total_paid) }}</dd>
                                                </div>
                                                <div class="flex justify-between border-t border-indigo-200 pt-2 dark:border-indigo-700">
                                                    <dt class="text-lg font-bold text-gray-900 dark:text-white">Payment Due</dt>
                                                    <dd class="text-lg font-bold text-indigo-600 dark:text-indigo-400">{{ formatCurrency(summary.balance_due + totalServiceFees) }}</dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <!-- Adjustments (collapsible, deemphasized) -->
                                        <details v-if="showAdjustments" class="rounded-lg border border-gray-200 dark:border-gray-700">
                                            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                                Adjustments (Discount, Tax, Shipping)
                                            </summary>
                                            <div class="space-y-4 border-t border-gray-200 p-4 dark:border-gray-700">
                                                <!-- Discount -->
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Discount</label>
                                                    <div class="mt-1 flex gap-2">
                                                        <div class="relative flex-1">
                                                            <span v-if="adjustments.discount_unit === 'fixed'" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-500 text-sm">$</span>
                                                            <input
                                                                v-model.number="adjustments.discount_value"
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                :class="[
                                                                    'block w-full rounded-md border-0 py-1.5 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600',
                                                                    adjustments.discount_unit === 'fixed' ? 'pl-6 pr-2' : 'px-2'
                                                                ]"
                                                            />
                                                            <span v-if="adjustments.discount_unit === 'percent'" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2 text-gray-500 text-sm">%</span>
                                                        </div>
                                                        <select
                                                            v-model="adjustments.discount_unit"
                                                            class="rounded-md border-0 bg-white py-1.5 pl-2 pr-6 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        >
                                                            <option value="fixed">$</option>
                                                            <option value="percent">%</option>
                                                        </select>
                                                    </div>
                                                    <input
                                                        v-model="adjustments.discount_reason"
                                                        type="text"
                                                        placeholder="Reason (optional)"
                                                        class="mt-1 block w-full rounded-md border-0 px-2 py-1 text-xs text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>

                                                <!-- Tax -->
                                                <div>
                                                    <div class="flex items-center justify-between">
                                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Tax</label>
                                                        <label class="flex items-center gap-2">
                                                            <input
                                                                v-model="adjustments.charge_taxes"
                                                                type="checkbox"
                                                                class="size-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                            />
                                                            <span class="text-xs text-gray-600 dark:text-gray-400">Enable</span>
                                                        </label>
                                                    </div>
                                                    <div v-if="adjustments.charge_taxes" class="mt-1 flex gap-2">
                                                        <div class="relative flex-1">
                                                            <span v-if="adjustments.tax_type === 'fixed'" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-500 text-sm">$</span>
                                                            <input
                                                                v-model.number="adjustments.tax_rate"
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                :class="[
                                                                    'block w-full rounded-md border-0 py-1.5 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600',
                                                                    adjustments.tax_type === 'fixed' ? 'pl-6 pr-2' : 'px-2'
                                                                ]"
                                                            />
                                                            <span v-if="adjustments.tax_type === 'percent'" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2 text-gray-500 text-sm">%</span>
                                                        </div>
                                                        <select
                                                            v-model="adjustments.tax_type"
                                                            class="rounded-md border-0 bg-white py-1.5 pl-2 pr-6 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        >
                                                            <option value="percent">%</option>
                                                            <option value="fixed">$</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <!-- Shipping -->
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Shipping Cost</label>
                                                    <div class="relative mt-1">
                                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-500 text-sm">$</span>
                                                        <input
                                                            v-model.number="adjustments.shipping_cost"
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            class="block w-full rounded-md border-0 py-1.5 pl-6 pr-2 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    </div>

                                    <!-- Right column: Payment Methods (3 cols, emphasized) -->
                                    <div class="lg:col-span-3 space-y-5">
                                        <!-- Payment Lines Header -->
                                        <div class="flex items-center justify-between">
                                            <h4 class="font-medium text-gray-900 dark:text-white">Payment Methods</h4>
                                            <div class="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    @click="setFullPayment"
                                                    class="text-xs text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                                >
                                                    Reset to Full
                                                </button>
                                                <button
                                                    type="button"
                                                    @click="addPaymentLine"
                                                    :disabled="remainingToAllocate <= 0"
                                                    class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-500 disabled:cursor-not-allowed disabled:opacity-50 dark:text-indigo-400"
                                                >
                                                    <PlusIcon class="size-3.5" />
                                                    Split Payment
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Payment Lines -->
                                        <div class="space-y-4">
                                            <div
                                                v-for="(line, index) in paymentLines"
                                                :key="line.id"
                                                class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                                            >
                                                <!-- Line Header -->
                                                <div class="mb-3 flex items-center justify-between">
                                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                                        Payment {{ paymentLines.length > 1 ? index + 1 : '' }}
                                                    </span>
                                                    <button
                                                        v-if="paymentLines.length > 1"
                                                        type="button"
                                                        @click="removePaymentLine(line.id)"
                                                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-500 dark:hover:bg-gray-700"
                                                    >
                                                        <TrashIcon class="size-4" />
                                                    </button>
                                                </div>

                                                <!-- Payment Method Selection -->
                                                <div class="grid grid-cols-3 gap-1.5">
                                                    <button
                                                        v-for="method in paymentMethods"
                                                        :key="method.value"
                                                        type="button"
                                                        @click="line.payment_method = method.value"
                                                        :class="[
                                                            'flex items-center justify-center gap-1.5 rounded px-2 py-1.5 text-xs font-medium ring-1 ring-inset transition-colors',
                                                            line.payment_method === method.value
                                                                ? 'bg-indigo-600 text-white ring-indigo-600'
                                                                : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-600'
                                                        ]"
                                                    >
                                                        <component :is="method.icon" class="size-3.5" />
                                                        {{ method.label }}
                                                    </button>
                                                </div>

                                                <!-- Terminal Selection (when terminal payment selected) -->
                                                <div v-if="isTerminalPayment(line.payment_method)" class="mt-3">
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Select Terminal</label>
                                                    <div v-if="isLoadingTerminals" class="mt-1 text-xs text-gray-500">Loading...</div>
                                                    <div v-else-if="terminals.length === 0" class="mt-1 rounded bg-yellow-50 p-2 text-xs text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300">
                                                        No active terminals available.
                                                    </div>
                                                    <select
                                                        v-else
                                                        v-model="line.terminal_id"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-8 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    >
                                                        <option :value="null">Select terminal...</option>
                                                        <option v-for="terminal in terminals" :key="terminal.id" :value="terminal.id">
                                                            {{ terminal.name }}
                                                            <span v-if="terminal.warehouse"> ({{ terminal.warehouse.name }})</span>
                                                        </option>
                                                    </select>
                                                </div>

                                                <!-- Amount & Service Fee Row -->
                                                <div class="mt-3 grid grid-cols-2 gap-3">
                                                    <!-- Amount -->
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Amount</label>
                                                        <div class="relative mt-1">
                                                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-500 text-sm">$</span>
                                                            <input
                                                                v-model.number="line.amount"
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                class="block w-full rounded-md border-0 py-1.5 pl-6 pr-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                            />
                                                        </div>
                                                    </div>

                                                    <!-- Service Fee -->
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Service Fee</label>
                                                        <div class="mt-1 flex gap-1">
                                                            <div class="relative flex-1">
                                                                <span v-if="line.service_fee_unit === 'fixed'" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2 text-gray-500 text-sm">$</span>
                                                                <input
                                                                    v-model.number="line.service_fee_value"
                                                                    type="number"
                                                                    min="0"
                                                                    step="0.01"
                                                                    :class="[
                                                                        'block w-full rounded-md border-0 py-1.5 pr-2 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600',
                                                                        line.service_fee_unit === 'fixed' ? 'pl-6' : 'pl-2'
                                                                    ]"
                                                                />
                                                                <span v-if="line.service_fee_unit === 'percent'" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2 text-gray-500 text-sm">%</span>
                                                            </div>
                                                            <select
                                                                v-model="line.service_fee_unit"
                                                                class="rounded-md border-0 bg-white py-1.5 pl-2 pr-6 text-xs text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                            >
                                                                <option value="fixed">$</option>
                                                                <option value="percent">%</option>
                                                            </select>
                                                        </div>
                                                        <!-- Service fee calculation note -->
                                                        <p v-if="getServiceFeeExplanation(line)" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                            {{ getServiceFeeExplanation(line) }}
                                                        </p>
                                                    </div>
                                                </div>

                                                <!-- Reference Number (for card, check, etc.) -->
                                                <div v-if="requiresReference(line.payment_method) && !isTerminalPayment(line.payment_method)" class="mt-3">
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                                        {{ line.payment_method === 'card' ? 'Transaction ID / Last 4' : line.payment_method === 'check' ? 'Check Number' : 'Reference' }}
                                                    </label>
                                                    <input
                                                        v-model="line.reference"
                                                        type="text"
                                                        :placeholder="line.payment_method === 'check' ? 'Enter check number' : 'Reference number'"
                                                        class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>

                                                <!-- Line Summary -->
                                                <div v-if="getLineServiceFeeAmount(line) > 0" class="mt-3 flex items-center justify-between border-t border-gray-100 pt-2 text-xs dark:border-gray-700">
                                                    <span class="text-gray-500 dark:text-gray-400">
                                                        {{ formatCurrency(line.amount) }} + {{ formatCurrency(getLineServiceFeeAmount(line)) }} fee
                                                    </span>
                                                    <span class="font-semibold text-gray-900 dark:text-white">
                                                        = {{ formatCurrency(getLineTotalWithFee(line)) }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Payment Totals -->
                                        <div class="rounded-lg bg-gray-100 p-3 dark:bg-gray-700">
                                            <div class="space-y-1 text-sm">
                                                <div class="flex justify-between">
                                                    <span class="text-gray-600 dark:text-gray-400">Allocated</span>
                                                    <span class="text-gray-900 dark:text-white">{{ formatCurrency(totalPaymentAmount) }}</span>
                                                </div>
                                                <div v-if="totalServiceFees > 0" class="flex justify-between">
                                                    <span class="text-gray-600 dark:text-gray-400">Service Fees</span>
                                                    <span class="text-gray-900 dark:text-white">{{ formatCurrency(totalServiceFees) }}</span>
                                                </div>
                                                <div v-if="remainingToAllocate > 0" class="flex justify-between text-amber-600 dark:text-amber-400">
                                                    <span>Remaining</span>
                                                    <span>{{ formatCurrency(remainingToAllocate) }}</span>
                                                </div>
                                                <div class="flex justify-between border-t border-gray-200 pt-1 font-semibold dark:border-gray-600">
                                                    <span class="text-gray-900 dark:text-white">Customer Pays</span>
                                                    <span class="text-indigo-600 dark:text-indigo-400">{{ formatCurrency(grandTotalWithFees) }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Amount exceeds warning -->
                                        <p v-if="totalPaymentAmount > summary.balance_due + 0.01" class="text-sm text-red-600 dark:text-red-400">
                                            Total allocated exceeds balance due
                                        </p>

                                        <!-- IRS Form 8300 Warning for cash > $9,999 -->
                                        <div v-if="hasLargeCashPayment" class="rounded-lg border border-red-300 bg-red-50 p-4 dark:border-red-700 dark:bg-red-900/30">
                                            <div class="flex items-start gap-3">
                                                <ExclamationTriangleIcon class="size-5 shrink-0 text-red-600 dark:text-red-400" />
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium text-red-800 dark:text-red-300">
                                                        IRS Form 8300 Required
                                                    </p>
                                                    <p class="mt-1 text-sm text-red-700 dark:text-red-400">
                                                        Cash transactions over $10,000 require IRS Form 8300 to be filed within 15 days.
                                                    </p>
                                                    <label class="mt-3 flex items-center gap-2">
                                                        <input
                                                            v-model="irsAcknowledged"
                                                            type="checkbox"
                                                            class="size-4 rounded border-red-300 text-red-600 focus:ring-red-600"
                                                        />
                                                        <span class="text-sm font-medium text-red-800 dark:text-red-300">
                                                            I acknowledge Form 8300 requirements
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="flex items-center justify-between border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    <span v-if="isFullPayment" class="text-green-600 dark:text-green-400">Full payment</span>
                                    <span v-else-if="paymentLines.length > 1" class="text-indigo-600 dark:text-indigo-400">{{ paymentLines.length }} split payments</span>
                                    <span v-else>Partial payment</span>
                                </div>
                                <div class="flex gap-3">
                                    <button
                                        type="button"
                                        @click="close"
                                        :disabled="isProcessing"
                                        class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        @click="submitPayment"
                                        :disabled="isProcessing || !canSubmitPayment"
                                        class="inline-flex items-center gap-2 rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50"
                                    >
                                        <BanknotesIcon class="size-4" />
                                        {{ isProcessing ? 'Processing...' : `Record ${formatCurrency(grandTotalWithFees)}` }}
                                    </button>
                                </div>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
