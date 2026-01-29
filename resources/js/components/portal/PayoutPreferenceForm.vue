<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface PaymentEntry {
    method: string;
    amount: number;
    details: Record<string, string>;
}

interface Transaction {
    id: number;
    payment_method: string | null;
    payment_details: PaymentEntry[] | null;
}

const props = defineProps<{
    transaction: Transaction;
    editable: boolean;
}>();

const editing = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const payments = ref<PaymentEntry[]>(
    props.transaction.payment_details?.length
        ? props.transaction.payment_details.map((p) => ({ ...p, details: { ...p.details } }))
        : [{ method: 'check', amount: 0, details: {} }]
);

const methodOptions = [
    { value: 'check', label: 'Check' },
    { value: 'paypal', label: 'PayPal' },
    { value: 'venmo', label: 'Venmo' },
    { value: 'ach', label: 'ACH / Bank Transfer' },
];

const methodLabel = computed(() => {
    const map: Record<string, string> = { check: 'Check', paypal: 'PayPal', venmo: 'Venmo', ach: 'ACH / Bank Transfer' };
    return (m: string) => map[m] ?? m;
});

function addPayment() {
    payments.value.push({ method: 'check', amount: 0, details: {} });
}

function removePayment(index: number) {
    payments.value.splice(index, 1);
}

function submit() {
    processing.value = true;
    errors.value = {};

    router.put(`/p/transactions/${props.transaction.id}/payout-preference`, {
        payments: payments.value,
    }, {
        onSuccess: () => {
            editing.value = false;
        },
        onError: (errs) => {
            errors.value = errs;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function cancel() {
    editing.value = false;
    payments.value = props.transaction.payment_details?.length
        ? props.transaction.payment_details.map((p) => ({ ...p, details: { ...p.details } }))
        : [{ method: 'check', amount: 0, details: {} }];
    errors.value = {};
}
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Payout Preference</h2>
            <button
                v-if="editable && !editing"
                @click="editing = true"
                class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
            >
                Edit
            </button>
        </div>

        <!-- Read-only display -->
        <div v-if="!editing" class="mt-4">
            <template v-if="transaction.payment_details?.length">
                <div v-for="(payment, i) in transaction.payment_details" :key="i" class="mb-3 last:mb-0">
                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ methodLabel(payment.method) }}
                        <span v-if="transaction.payment_details!.length > 1" class="text-gray-500 dark:text-gray-400">
                            &mdash; ${{ Number(payment.amount).toFixed(2) }}
                        </span>
                    </div>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        <template v-if="payment.method === 'check'">
                            {{ payment.details?.mailing_name }}, {{ payment.details?.mailing_address }}, {{ payment.details?.mailing_city }}, {{ payment.details?.mailing_state }} {{ payment.details?.mailing_zip }}
                        </template>
                        <template v-else-if="payment.method === 'paypal'">
                            {{ payment.details?.paypal_email }}
                        </template>
                        <template v-else-if="payment.method === 'venmo'">
                            {{ payment.details?.venmo_handle }}
                        </template>
                        <template v-else-if="payment.method === 'ach'">
                            {{ payment.details?.bank_name }} &mdash; {{ payment.details?.account_name }}
                        </template>
                    </div>
                </div>
            </template>
            <p v-else class="text-sm text-gray-500 dark:text-gray-400">
                {{ transaction.payment_method ? `${methodLabel(transaction.payment_method)}` : 'No payout preference set.' }}
            </p>
        </div>

        <!-- Edit form -->
        <div v-else class="mt-4 space-y-6">
            <div
                v-for="(payment, index) in payments"
                :key="index"
                class="rounded-md border border-gray-200 p-4 dark:border-gray-600"
            >
                <div class="flex items-center justify-between">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Method</label>
                    <button
                        v-if="payments.length > 1"
                        @click="removePayment(index)"
                        class="text-xs text-red-600 hover:text-red-500 dark:text-red-400"
                    >
                        Remove
                    </button>
                </div>
                <select
                    v-model="payment.method"
                    class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option v-for="opt in methodOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>

                <!-- Amount (show when split) -->
                <div v-if="payments.length > 1" class="mt-3">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                    <input
                        v-model.number="payment.amount"
                        type="number"
                        step="0.01"
                        min="0"
                        class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <!-- Check fields -->
                <div v-if="payment.method === 'check'" class="mt-3 space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                        <input v-model="payment.details.mailing_name" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address</label>
                        <input v-model="payment.details.mailing_address" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">City</label>
                            <input v-model="payment.details.mailing_city" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">State</label>
                            <input v-model="payment.details.mailing_state" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">ZIP</label>
                            <input v-model="payment.details.mailing_zip" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                        </div>
                    </div>
                </div>

                <!-- PayPal fields -->
                <div v-if="payment.method === 'paypal'" class="mt-3">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">PayPal Email</label>
                    <input v-model="payment.details.paypal_email" type="email" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                </div>

                <!-- Venmo fields -->
                <div v-if="payment.method === 'venmo'" class="mt-3">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Venmo Handle</label>
                    <input v-model="payment.details.venmo_handle" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                </div>

                <!-- ACH fields -->
                <div v-if="payment.method === 'ach'" class="mt-3 space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Bank Name</label>
                        <input v-model="payment.details.bank_name" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Account Name</label>
                        <input v-model="payment.details.account_name" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Account Number</label>
                        <input v-model="payment.details.account_number" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Routing Number</label>
                        <input v-model="payment.details.routing_number" type="text" class="mt-1 block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                    </div>
                </div>
            </div>

            <button
                @click="addPayment"
                type="button"
                class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
            >
                + Add another method
            </button>

            <!-- Error display -->
            <div v-if="Object.keys(errors).length" class="rounded-md bg-red-50 p-3 dark:bg-red-900/20">
                <ul class="list-disc pl-5 text-sm text-red-700 dark:text-red-400">
                    <li v-for="(msg, key) in errors" :key="key">{{ msg }}</li>
                </ul>
            </div>

            <div class="flex gap-3">
                <button
                    @click="submit"
                    :disabled="processing"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ processing ? 'Saving...' : 'Save Preference' }}
                </button>
                <button
                    @click="cancel"
                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
</template>
