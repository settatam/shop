<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { RadioGroup, RadioGroupLabel, RadioGroupOption } from '@headlessui/vue';
import { CheckCircleIcon, PlusIcon, TrashIcon } from '@heroicons/vue/20/solid';
import {
    BanknotesIcon,
    DocumentCheckIcon,
    CreditCardIcon,
    BuildingLibraryIcon,
} from '@heroicons/vue/24/outline';

interface SelectOption {
    value: string;
    label: string;
}

interface Payment {
    id: string;
    method: string;
    amount: number;
    details: Record<string, any>;
}

interface Customer {
    first_name: string;
    last_name: string;
    address?: string;
    city?: string;
    state?: string;
    zip?: string;
}

interface Props {
    payments: Payment[];
    customerNotes: string;
    internalNotes: string;
    paymentMethods: SelectOption[];
    totalAmount: number;
    customer: Customer | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    update: [data: { payments: Payment[]; customer_notes: string; internal_notes: string }]
}>();

const localPayments = ref<Payment[]>([...(props.payments || [])]);
const customerNotes = ref(props.customerNotes || '');
const internalNotes = ref(props.internalNotes || '');

const paymentMethodIcons: Record<string, any> = {
    cash: BanknotesIcon,
    check: DocumentCheckIcon,
    paypal: CreditCardIcon,
    venmo: CreditCardIcon,
    store_credit: CreditCardIcon,
    ach: BuildingLibraryIcon,
    wire_transfer: BuildingLibraryIcon,
};

function getIcon(method: string) {
    return paymentMethodIcons[method] || CreditCardIcon;
}

function getMethodLabel(method: string): string {
    const found = props.paymentMethods.find(m => m.value === method);
    return found?.label || method;
}

const totalPaymentsAmount = computed(() => {
    return localPayments.value.reduce((sum, p) => sum + (p.amount || 0), 0);
});

const remainingBalance = computed(() => {
    return props.totalAmount - totalPaymentsAmount.value;
});

const isBalanced = computed(() => {
    return Math.abs(remainingBalance.value) < 0.01;
});

function addPayment() {
    const newPayment: Payment = {
        id: crypto.randomUUID(),
        method: '',
        amount: remainingBalance.value > 0 ? remainingBalance.value : 0,
        details: {},
    };

    // Initialize check address from customer if available
    if (props.customer) {
        newPayment.details.check_mailing_address = {
            address: props.customer.address || '',
            city: props.customer.city || '',
            state: props.customer.state || '',
            zip: props.customer.zip || '',
        };
    }

    localPayments.value.push(newPayment);
    emitUpdate();
}

function removePayment(paymentId: string) {
    if (localPayments.value.length > 1) {
        localPayments.value = localPayments.value.filter(p => p.id !== paymentId);
        emitUpdate();
    }
}

function updatePaymentMethod(paymentId: string, method: string) {
    const payment = localPayments.value.find(p => p.id === paymentId);
    if (payment) {
        payment.method = method;
        // Reset details when method changes
        payment.details = {};

        // If switching to check, auto-populate address from customer
        if (method === 'check' && props.customer) {
            payment.details.check_mailing_address = {
                address: props.customer.address || '',
                city: props.customer.city || '',
                state: props.customer.state || '',
                zip: props.customer.zip || '',
            };
        }
        emitUpdate();
    }
}

function updatePaymentAmount(paymentId: string, amount: number) {
    const payment = localPayments.value.find(p => p.id === paymentId);
    if (payment) {
        payment.amount = amount;
        emitUpdate();
    }
}

function updatePaymentDetails(paymentId: string, details: Record<string, any>) {
    const payment = localPayments.value.find(p => p.id === paymentId);
    if (payment) {
        payment.details = { ...payment.details, ...details };
        emitUpdate();
    }
}

function emitUpdate() {
    // Create deep copies so Vue's reactivity detects changes in the parent
    emit('update', {
        payments: localPayments.value.map(p => ({
            ...p,
            details: { ...p.details, check_mailing_address: p.details.check_mailing_address ? { ...p.details.check_mailing_address } : undefined },
        })),
        customer_notes: customerNotes.value,
        internal_notes: internalNotes.value,
    });
}

watch([customerNotes, internalNotes], emitUpdate);

function needsPaypalEmail(method: string): boolean {
    return method === 'paypal';
}

function needsVenmoHandle(method: string): boolean {
    return method === 'venmo';
}

function needsCheckAddress(method: string): boolean {
    return method === 'check';
}

function needsBankDetails(method: string): boolean {
    return method === 'ach' || method === 'wire_transfer';
}

function applyRemainingToPayment(paymentId: string) {
    const payment = localPayments.value.find(p => p.id === paymentId);
    if (payment && remainingBalance.value > 0) {
        payment.amount = payment.amount + remainingBalance.value;
        emitUpdate();
    }
}

// Initialize with one payment if empty (must be in onMounted so emit reaches parent)
onMounted(() => {
    if (localPayments.value.length === 0) {
        addPayment();
    }
});
</script>

<template>
    <div class="space-y-8">
        <div>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                Payment Details
            </h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Add one or more payments. The total must equal the buy price.
            </p>
        </div>

        <!-- Total Amount & Balance -->
        <div class="rounded-lg bg-indigo-50 p-4 dark:bg-indigo-900/20">
            <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                    <span class="block text-sm font-medium text-indigo-700 dark:text-indigo-300">Buy Price</span>
                    <span class="text-xl font-bold text-indigo-900 dark:text-indigo-100">${{ totalAmount.toFixed(2) }}</span>
                </div>
                <div>
                    <span class="block text-sm font-medium text-indigo-700 dark:text-indigo-300">Total Payments</span>
                    <span class="text-xl font-bold text-indigo-900 dark:text-indigo-100">${{ totalPaymentsAmount.toFixed(2) }}</span>
                </div>
                <div>
                    <span class="block text-sm font-medium" :class="isBalanced ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300'">
                        {{ remainingBalance >= 0 ? 'Remaining' : 'Over' }}
                    </span>
                    <span class="text-xl font-bold" :class="isBalanced ? 'text-green-900 dark:text-green-100' : 'text-red-900 dark:text-red-100'">
                        ${{ Math.abs(remainingBalance).toFixed(2) }}
                    </span>
                </div>
            </div>
            <p v-if="!isBalanced" class="mt-2 text-center text-sm" :class="remainingBalance > 0 ? 'text-red-600' : 'text-red-600'">
                {{ remainingBalance > 0 ? 'Add more payments or adjust amounts to match the buy price.' : 'Total payments exceed the buy price.' }}
            </p>
            <p v-else class="mt-2 text-center text-sm text-green-600 dark:text-green-400">
                Payments are balanced.
            </p>
        </div>

        <!-- Payments List -->
        <div class="space-y-6">
            <div
                v-for="(payment, index) in localPayments"
                :key="payment.id"
                class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                        Payment {{ index + 1 }}
                    </h3>
                    <button
                        v-if="localPayments.length > 1"
                        type="button"
                        @click="removePayment(payment.id)"
                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700"
                        title="Remove payment"
                    >
                        <TrashIcon class="size-4" />
                    </button>
                </div>

                <!-- Amount -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Amount <span class="text-red-500">*</span>
                    </label>
                    <div class="mt-1 flex items-center gap-2">
                        <div class="relative flex-1">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
                            </div>
                            <input
                                type="number"
                                :value="payment.amount"
                                @input="updatePaymentAmount(payment.id, parseFloat(($event.target as HTMLInputElement).value) || 0)"
                                step="0.01"
                                min="0"
                                class="block w-full rounded-md border-0 py-2 pl-7 pr-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                placeholder="0.00"
                            />
                        </div>
                        <button
                            v-if="remainingBalance > 0"
                            type="button"
                            @click="applyRemainingToPayment(payment.id)"
                            class="shrink-0 rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            + ${{ remainingBalance.toFixed(2) }}
                        </button>
                    </div>
                </div>

                <!-- Payment Method Selection -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Payment Method <span class="text-red-500">*</span>
                    </label>
                    <RadioGroup :model-value="payment.method" @update:model-value="updatePaymentMethod(payment.id, $event)">
                        <RadioGroupLabel class="sr-only">Select payment method</RadioGroupLabel>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <RadioGroupOption
                                v-for="method in paymentMethods"
                                :key="method.value"
                                :value="method.value"
                                v-slot="{ checked, active }"
                                as="template"
                            >
                                <div
                                    :class="[
                                        'relative flex cursor-pointer flex-col rounded-lg border p-3 shadow-sm focus:outline-none',
                                        checked
                                            ? 'border-indigo-600 ring-2 ring-indigo-600'
                                            : 'border-gray-300 dark:border-gray-600',
                                        active ? 'border-indigo-600 ring-2 ring-indigo-600' : '',
                                    ]"
                                >
                                    <div class="flex items-center justify-between">
                                        <component
                                            :is="getIcon(method.value)"
                                            :class="[
                                                'size-5',
                                                checked ? 'text-indigo-600' : 'text-gray-400',
                                            ]"
                                        />
                                        <CheckCircleIcon
                                            v-if="checked"
                                            class="size-4 text-indigo-600"
                                        />
                                    </div>
                                    <RadioGroupLabel
                                        as="span"
                                        :class="[
                                            'mt-1 block text-xs font-medium',
                                            checked ? 'text-indigo-900 dark:text-indigo-100' : 'text-gray-900 dark:text-white',
                                        ]"
                                    >
                                        {{ method.label }}
                                    </RadioGroupLabel>
                                </div>
                            </RadioGroupOption>
                        </div>
                    </RadioGroup>
                </div>

                <!-- Method-specific details -->
                <div v-if="payment.method" class="space-y-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <!-- PayPal Details -->
                    <div v-if="needsPaypalEmail(payment.method)">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            PayPal Email <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="email"
                            :value="payment.details.paypal_email || ''"
                            @input="updatePaymentDetails(payment.id, { paypal_email: ($event.target as HTMLInputElement).value })"
                            class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            placeholder="customer@email.com"
                        />
                    </div>

                    <!-- Venmo Details -->
                    <div v-if="needsVenmoHandle(payment.method)">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Venmo Handle <span class="text-red-500">*</span>
                        </label>
                        <div class="relative mt-1">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">@</span>
                            </div>
                            <input
                                type="text"
                                :value="payment.details.venmo_handle || ''"
                                @input="updatePaymentDetails(payment.id, { venmo_handle: ($event.target as HTMLInputElement).value })"
                                class="block w-full rounded-md border-0 py-2 pl-7 pr-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                placeholder="username"
                            />
                        </div>
                    </div>

                    <!-- Check Mailing Address -->
                    <div v-if="needsCheckAddress(payment.method)" class="space-y-3">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Mailing Address</p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <input
                                    type="text"
                                    :value="payment.details.check_mailing_address?.address || ''"
                                    @input="updatePaymentDetails(payment.id, { check_mailing_address: { ...payment.details.check_mailing_address, address: ($event.target as HTMLInputElement).value } })"
                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Street Address"
                                />
                            </div>
                            <div>
                                <input
                                    type="text"
                                    :value="payment.details.check_mailing_address?.city || ''"
                                    @input="updatePaymentDetails(payment.id, { check_mailing_address: { ...payment.details.check_mailing_address, city: ($event.target as HTMLInputElement).value } })"
                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="City"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <input
                                    type="text"
                                    :value="payment.details.check_mailing_address?.state || ''"
                                    @input="updatePaymentDetails(payment.id, { check_mailing_address: { ...payment.details.check_mailing_address, state: ($event.target as HTMLInputElement).value } })"
                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="State"
                                />
                                <input
                                    type="text"
                                    :value="payment.details.check_mailing_address?.zip || ''"
                                    @input="updatePaymentDetails(payment.id, { check_mailing_address: { ...payment.details.check_mailing_address, zip: ($event.target as HTMLInputElement).value } })"
                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="ZIP"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Bank Details (ACH / Wire Transfer) -->
                    <div v-if="needsBankDetails(payment.method)" class="space-y-3">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Bank Information</p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <input
                                    type="text"
                                    :value="payment.details.bank_name || ''"
                                    @input="updatePaymentDetails(payment.id, { bank_name: ($event.target as HTMLInputElement).value })"
                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Bank Name"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <input
                                    type="text"
                                    :value="payment.details.account_holder_name || ''"
                                    @input="updatePaymentDetails(payment.id, { account_holder_name: ($event.target as HTMLInputElement).value })"
                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Account Holder Name"
                                />
                            </div>
                            <div>
                                <input
                                    type="text"
                                    :value="payment.details.routing_number || ''"
                                    @input="updatePaymentDetails(payment.id, { routing_number: ($event.target as HTMLInputElement).value })"
                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Routing Number"
                                />
                            </div>
                            <div>
                                <input
                                    type="text"
                                    :value="payment.details.account_number || ''"
                                    @input="updatePaymentDetails(payment.id, { account_number: ($event.target as HTMLInputElement).value })"
                                    class="block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Account Number"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Payment Button -->
        <button
            type="button"
            @click="addPayment"
            class="inline-flex w-full items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-3 text-sm font-medium text-gray-600 hover:border-gray-400 hover:text-gray-700 dark:border-gray-600 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-gray-300"
        >
            <PlusIcon class="size-5" />
            Add Another Payment Method
        </button>

        <!-- Notes -->
        <div class="space-y-4 border-t border-gray-200 pt-6 dark:border-gray-700">
            <h3 class="text-sm font-medium text-gray-900 dark:text-white">Notes (Optional)</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="customer_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Customer Notes
                    </label>
                    <textarea
                        id="customer_notes"
                        v-model="customerNotes"
                        rows="3"
                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        placeholder="Notes visible to customer..."
                    />
                </div>
                <div>
                    <label for="internal_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Internal Notes
                    </label>
                    <textarea
                        id="internal_notes"
                        v-model="internalNotes"
                        rows="3"
                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        placeholder="Internal notes (not visible to customer)..."
                    />
                </div>
            </div>
        </div>
    </div>
</template>
