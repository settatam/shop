<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
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
} from '@heroicons/vue/24/outline';
import axios from 'axios';

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

interface Memo {
    id: number;
    memo_number: string;
    total: number;
    charge_taxes: boolean;
    tax_rate: number;
    shipping_cost: number;
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
    vendor?: {
        id: number;
        name: string;
        display_name?: string;
    };
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

interface Props {
    show: boolean;
    memo: Memo;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'success'): void;
}>();

// State
const isLoading = ref(false);
const isProcessing = ref(false);
const error = ref<string | null>(null);
const successMessage = ref<string | null>(null);

// Terminal state
const terminals = ref<Terminal[]>([]);
const selectedTerminalId = ref<number | null>(null);
const isLoadingTerminals = ref(false);
const terminalCheckoutStatus = ref<string | null>(null);

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

// Payment form
const paymentForm = ref({
    payment_method: 'cash',
    amount: 0,
    reference: '',
    notes: '',
});

const paymentMethods = [
    { value: 'cash', label: 'Cash', icon: BanknotesIcon },
    { value: 'terminal', label: 'Card Terminal', icon: CreditCardIcon, requiresTerminal: true },
    { value: 'card', label: 'Card (Offline)', icon: CreditCardIcon },
    { value: 'check', label: 'Check', icon: BanknotesIcon },
    { value: 'bank_transfer', label: 'Bank Transfer', icon: BanknotesIcon },
    { value: 'external', label: 'External/Other', icon: BanknotesIcon },
];

const isTerminalPayment = computed(() => paymentForm.value.payment_method === 'terminal');

const selectedTerminal = computed(() => {
    if (!selectedTerminalId.value) return null;
    return terminals.value.find(t => t.id === selectedTerminalId.value) || null;
});

// Computed
const canSubmitPayment = computed(() => {
    const validAmount = paymentForm.value.amount > 0 && paymentForm.value.amount <= summary.value.balance_due;
    const irsOk = !showIrsWarning.value || irsAcknowledged.value;
    const terminalOk = !isTerminalPayment.value || selectedTerminalId.value !== null;
    return validAmount && irsOk && terminalOk;
});

const isFullPayment = computed(() => {
    return Math.abs(paymentForm.value.amount - summary.value.balance_due) < 0.01;
});

const requiresReference = computed(() => {
    return ['card', 'check', 'bank_transfer', 'external'].includes(paymentForm.value.payment_method);
});

const showIrsWarning = computed(() => {
    return paymentForm.value.payment_method === 'cash' && paymentForm.value.amount > 9999;
});

const irsAcknowledged = ref(false);

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
        const response = await axios.get(`/memos/${props.memo.id}/payment/summary`, {
            params: adjustments.value,
        });
        summary.value = response.data.summary;

        // Set default payment amount to balance due
        if (paymentForm.value.amount === 0) {
            paymentForm.value.amount = summary.value.balance_due;
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
        const response = await axios.post(`/memos/${props.memo.id}/payment/adjustments`, adjustments.value);
        summary.value = response.data.summary;
        paymentForm.value.amount = summary.value.balance_due;
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to update adjustments.';
    } finally {
        isLoading.value = false;
    }
}

async function fetchTerminals() {
    isLoadingTerminals.value = true;
    try {
        const response = await axios.get('/api/v1/terminals', {
            params: { status: 'active' },
        });
        terminals.value = response.data.data || response.data.terminals || [];
        // Auto-select if only one terminal
        if (terminals.value.length === 1) {
            selectedTerminalId.value = terminals.value[0].id;
        }
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

    try {
        // Handle terminal payments differently
        if (isTerminalPayment.value && selectedTerminalId.value) {
            await processTerminalPayment();
            return;
        }

        const response = await axios.post(`/memos/${props.memo.id}/payment/process`, {
            payment_method: paymentForm.value.payment_method,
            amount: paymentForm.value.amount,
            reference: paymentForm.value.reference || null,
            notes: paymentForm.value.notes || null,
        });

        if (response.data.is_fully_paid) {
            successMessage.value = 'Payment completed! Memo has been fully paid.';
            setTimeout(() => {
                emit('success');
            }, 1500);
        } else {
            // Refresh summary for partial payment
            summary.value = {
                ...summary.value,
                total_paid: response.data.memo.total_paid,
                balance_due: response.data.memo.balance_due,
            };
            paymentForm.value.amount = summary.value.balance_due;
            paymentForm.value.reference = '';
            paymentForm.value.notes = '';
            successMessage.value = `Payment of ${formatCurrency(response.data.payment.amount)} recorded. Remaining balance: ${formatCurrency(summary.value.balance_due)}`;
            setTimeout(() => {
                successMessage.value = null;
            }, 3000);
        }
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to process payment.';
    } finally {
        isProcessing.value = false;
    }
}

async function processTerminalPayment() {
    if (!selectedTerminalId.value) return;

    terminalCheckoutStatus.value = 'initiating';

    try {
        // Create terminal checkout - this will be implemented as an API endpoint
        const response = await axios.post(`/memos/${props.memo.id}/payment/terminal-checkout`, {
            terminal_id: selectedTerminalId.value,
            amount: paymentForm.value.amount,
            notes: paymentForm.value.notes || null,
        });

        const checkoutId = response.data.checkout_id;
        terminalCheckoutStatus.value = 'waiting';
        successMessage.value = 'Payment initiated. Please complete the transaction on the terminal.';

        // Poll for checkout completion
        await pollCheckoutStatus(checkoutId);

    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to initiate terminal payment.';
        terminalCheckoutStatus.value = null;
        isProcessing.value = false;
    }
}

async function pollCheckoutStatus(checkoutId: number, maxAttempts = 60) {
    let attempts = 0;

    const poll = async () => {
        if (attempts >= maxAttempts) {
            error.value = 'Terminal payment timed out. Please try again.';
            terminalCheckoutStatus.value = null;
            isProcessing.value = false;
            return;
        }

        attempts++;

        try {
            const response = await axios.get(`/api/v1/checkouts/${checkoutId}`);
            const status = response.data.status;

            if (status === 'completed') {
                terminalCheckoutStatus.value = 'completed';
                successMessage.value = 'Payment completed successfully!';
                setTimeout(() => {
                    emit('success');
                }, 1500);
                return;
            } else if (status === 'failed' || status === 'cancelled') {
                error.value = `Terminal payment ${status}. Please try again.`;
                terminalCheckoutStatus.value = null;
                isProcessing.value = false;
                return;
            }

            // Continue polling
            setTimeout(poll, 2000);
        } catch (err: any) {
            error.value = 'Failed to check payment status.';
            terminalCheckoutStatus.value = null;
            isProcessing.value = false;
        }
    };

    await poll();
}

function setFullPayment() {
    paymentForm.value.amount = summary.value.balance_due;
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

        // Initialize adjustments from memo
        adjustments.value = {
            discount_value: props.memo.discount_value || 0,
            discount_unit: (props.memo.discount_unit as 'fixed' | 'percent') || 'fixed',
            discount_reason: props.memo.discount_reason || '',
            service_fee_value: props.memo.service_fee_value || 0,
            service_fee_unit: (props.memo.service_fee_unit as 'fixed' | 'percent') || 'fixed',
            service_fee_reason: props.memo.service_fee_reason || '',
            charge_taxes: props.memo.charge_taxes || false,
            tax_rate: props.memo.tax_rate || 0,
            tax_type: (props.memo.tax_type as 'percent' | 'fixed') || 'percent',
            shipping_cost: props.memo.shipping_cost || 0,
        };

        paymentForm.value = {
            payment_method: 'cash',
            amount: 0,
            reference: '',
            notes: '',
        };

        irsAcknowledged.value = false;

        fetchSummary();
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
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ memo.memo_number }} - {{ memo.vendor?.display_name || memo.vendor?.name }}
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

                                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                                    <!-- Left column: Adjustments -->
                                    <div class="space-y-5">
                                        <h4 class="font-medium text-gray-900 dark:text-white">Payment Adjustments</h4>

                                        <!-- Discount -->
                                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Discount</label>
                                            <div class="mt-2 flex gap-2">
                                                <div class="relative flex-1">
                                                    <span v-if="adjustments.discount_unit === 'fixed'" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                                    <input
                                                        v-model.number="adjustments.discount_value"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        :class="[
                                                            'block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600',
                                                            adjustments.discount_unit === 'fixed' ? 'pl-7 pr-2' : 'px-2'
                                                        ]"
                                                    />
                                                    <span v-if="adjustments.discount_unit === 'percent'" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500">%</span>
                                                </div>
                                                <select
                                                    v-model="adjustments.discount_unit"
                                                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-8 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option value="fixed">$</option>
                                                    <option value="percent">%</option>
                                                </select>
                                            </div>
                                            <input
                                                v-model="adjustments.discount_reason"
                                                type="text"
                                                placeholder="Reason (optional)"
                                                class="mt-2 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>

                                        <!-- Service Fee -->
                                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Service Fee</label>
                                            <div class="mt-2 flex gap-2">
                                                <div class="relative flex-1">
                                                    <span v-if="adjustments.service_fee_unit === 'fixed'" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                                    <input
                                                        v-model.number="adjustments.service_fee_value"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        :class="[
                                                            'block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600',
                                                            adjustments.service_fee_unit === 'fixed' ? 'pl-7 pr-2' : 'px-2'
                                                        ]"
                                                    />
                                                    <span v-if="adjustments.service_fee_unit === 'percent'" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500">%</span>
                                                </div>
                                                <select
                                                    v-model="adjustments.service_fee_unit"
                                                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-8 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option value="fixed">$</option>
                                                    <option value="percent">%</option>
                                                </select>
                                            </div>
                                            <input
                                                v-model="adjustments.service_fee_reason"
                                                type="text"
                                                placeholder="Reason (optional)"
                                                class="mt-2 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>

                                        <!-- Tax -->
                                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                            <div class="flex items-center justify-between">
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tax</label>
                                                <label class="flex items-center gap-2">
                                                    <input
                                                        v-model="adjustments.charge_taxes"
                                                        type="checkbox"
                                                        class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                    />
                                                    <span class="text-sm text-gray-600 dark:text-gray-400">Enable</span>
                                                </label>
                                            </div>
                                            <div v-if="adjustments.charge_taxes" class="mt-2 flex gap-2">
                                                <div class="relative flex-1">
                                                    <span v-if="adjustments.tax_type === 'fixed'" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                                    <input
                                                        v-model.number="adjustments.tax_rate"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        :class="[
                                                            'block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600',
                                                            adjustments.tax_type === 'fixed' ? 'pl-7 pr-2' : 'px-2'
                                                        ]"
                                                    />
                                                    <span v-if="adjustments.tax_type === 'percent'" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500">%</span>
                                                </div>
                                                <select
                                                    v-model="adjustments.tax_type"
                                                    class="rounded-md border-0 bg-white py-1.5 pl-3 pr-8 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                >
                                                    <option value="percent">%</option>
                                                    <option value="fixed">$</option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Shipping -->
                                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Shipping Cost</label>
                                            <div class="relative mt-2">
                                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                                <input
                                                    v-model.number="adjustments.shipping_cost"
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    class="block w-full rounded-md border-0 py-1.5 pl-7 pr-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            @click="updateAdjustments"
                                            :disabled="isLoading"
                                            class="w-full rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                        >
                                            {{ isLoading ? 'Updating...' : 'Update Totals' }}
                                        </button>
                                    </div>

                                    <!-- Right column: Summary & Payment -->
                                    <div class="space-y-5">
                                        <!-- Summary -->
                                        <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-900">
                                            <h4 class="mb-3 font-medium text-gray-900 dark:text-white">Payment Summary</h4>
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
                                                <div v-if="summary.tax_amount > 0" class="flex justify-between">
                                                    <dt class="text-gray-500 dark:text-gray-400">Tax</dt>
                                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(summary.tax_amount) }}</dd>
                                                </div>
                                                <div v-if="summary.shipping_cost > 0" class="flex justify-between">
                                                    <dt class="text-gray-500 dark:text-gray-400">Shipping</dt>
                                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(summary.shipping_cost) }}</dd>
                                                </div>
                                                <div class="flex justify-between border-t border-gray-200 pt-2 font-semibold dark:border-gray-700">
                                                    <dt class="text-gray-900 dark:text-white">Grand Total</dt>
                                                    <dd class="text-gray-900 dark:text-white">{{ formatCurrency(summary.grand_total) }}</dd>
                                                </div>
                                                <div v-if="summary.total_paid > 0" class="flex justify-between text-green-600 dark:text-green-400">
                                                    <dt>Amount Paid</dt>
                                                    <dd>-{{ formatCurrency(summary.total_paid) }}</dd>
                                                </div>
                                                <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-bold dark:border-gray-700">
                                                    <dt class="text-gray-900 dark:text-white">Balance Due</dt>
                                                    <dd class="text-indigo-600 dark:text-indigo-400">{{ formatCurrency(summary.balance_due) }}</dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <!-- Payment Method -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Method</label>
                                            <div class="mt-2 grid grid-cols-2 gap-2">
                                                <button
                                                    v-for="method in paymentMethods"
                                                    :key="method.value"
                                                    type="button"
                                                    @click="paymentForm.payment_method = method.value"
                                                    :class="[
                                                        'flex items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium ring-1 ring-inset transition-colors',
                                                        paymentForm.payment_method === method.value
                                                            ? 'bg-indigo-600 text-white ring-indigo-600'
                                                            : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-600'
                                                    ]"
                                                >
                                                    <component :is="method.icon" class="size-4" />
                                                    {{ method.label }}
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Payment Amount -->
                                        <div>
                                            <div class="flex items-center justify-between">
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Amount</label>
                                                <button
                                                    type="button"
                                                    @click="setFullPayment"
                                                    class="text-xs text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                                >
                                                    Pay Full Balance
                                                </button>
                                            </div>
                                            <div class="relative mt-1">
                                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                                <input
                                                    v-model.number="paymentForm.amount"
                                                    type="number"
                                                    min="0.01"
                                                    :max="summary.balance_due"
                                                    step="0.01"
                                                    class="block w-full rounded-md border-0 py-2 pl-7 pr-2 text-lg font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <p v-if="paymentForm.amount > summary.balance_due" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                Amount exceeds balance due
                                            </p>
                                        </div>

                                        <!-- Reference Number (for card, check, etc.) -->
                                        <div v-if="requiresReference">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                {{ paymentForm.payment_method === 'card' ? 'Transaction ID / Last 4 Digits' : 'Reference Number' }}
                                            </label>
                                            <input
                                                v-model="paymentForm.reference"
                                                type="text"
                                                :placeholder="paymentForm.payment_method === 'card' ? 'e.g., xxxx-1234 or TXN123456' : 'Reference number'"
                                                class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>

                                        <!-- Notes -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (optional)</label>
                                            <textarea
                                                v-model="paymentForm.notes"
                                                rows="2"
                                                placeholder="Add any notes about this payment..."
                                                class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            ></textarea>
                                        </div>

                                        <!-- IRS Form 8300 Warning for cash > $9,999 -->
                                        <div v-if="showIrsWarning" class="rounded-lg border border-red-300 bg-red-50 p-4 dark:border-red-700 dark:bg-red-900/30">
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
                                        {{ isProcessing ? 'Processing...' : `Record ${formatCurrency(paymentForm.amount)}` }}
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
