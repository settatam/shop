<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftIcon, PrinterIcon } from '@heroicons/vue/20/solid';

interface Props {
    storeCredit: {
        id: number;
        amount: string;
        balance_after: string;
        payout_method: string | null;
        payout_details: Record<string, any> | null;
        description: string | null;
        created_at: string;
        user_name: string | null;
    };
    customer: {
        id: number;
        full_name: string;
        email: string | null;
        phone_number: string | null;
        address: string | null;
        city: string | null;
        state: string | null;
        zip: string | null;
    };
    store: {
        name: string;
        logo: string | null;
        address: string | null;
        address2: string | null;
        city: string | null;
        state: string | null;
        zip: string | null;
        phone: string | null;
        email: string | null;
    };
}

const props = defineProps<Props>();

const payoutMethodLabels: Record<string, string> = {
    cash: 'Cash',
    check: 'Check',
    paypal: 'PayPal',
    venmo: 'Venmo',
    ach: 'ACH Transfer',
    wire_transfer: 'Wire Transfer',
};

const formatCurrency = (value: string | number | null) => {
    if (value === null || value === undefined) return '$0.00';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(Number(value));
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric',
    });
};

const formatDateTime = () => {
    return new Date().toLocaleString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    });
};

const getPayoutDetails = () => {
    const details = props.storeCredit.payout_details;
    if (!details) return null;

    const method = props.storeCredit.payout_method;

    if (method === 'check') {
        const lines: string[] = [];
        if (details.check_number) lines.push(`Check #${details.check_number}`);
        if (details.check_mailing_address) {
            const addr = details.check_mailing_address;
            if (addr.address) lines.push(addr.address);
            const cityStateZip = [addr.city, addr.state, addr.zip].filter(Boolean).join(', ');
            if (cityStateZip) lines.push(cityStateZip);
        }
        return lines.length > 0 ? lines : null;
    }

    if (method === 'paypal' && details.paypal_email) {
        return [details.paypal_email];
    }

    if (method === 'venmo' && details.venmo_handle) {
        return [`@${details.venmo_handle}`];
    }

    if (method === 'ach' || method === 'wire_transfer') {
        const lines: string[] = [];
        if (details.bank_name) lines.push(details.bank_name);
        if (details.account_holder_name) lines.push(details.account_holder_name);
        if (details.routing_number) lines.push(`Routing: ${details.routing_number}`);
        if (details.account_number) lines.push(`Account: ${details.account_number}`);
        return lines.length > 0 ? lines : null;
    }

    return null;
};

const payoutDetails = getPayoutDetails();

const print = () => {
    window.print();
};
</script>

<template>
    <Head :title="`Cash Out Receipt - ${storeCredit.id}`" />

    <div class="min-h-screen bg-gray-900">
        <!-- Header (hidden when printing) -->
        <div class="print:hidden bg-white shadow">
            <div class="mx-auto max-w-5xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="`/customers/${customer.id}/store-credits`"
                            class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500"
                        >
                            <ArrowLeftIcon class="size-5" />
                        </Link>
                        <h1 class="text-lg font-semibold text-gray-900">Cash Out Receipt</h1>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="print"
                    >
                        <PrinterIcon class="-ml-0.5 size-5" />
                        Print
                    </button>
                </div>
            </div>
        </div>

        <!-- Receipt -->
        <div class="mx-auto max-w-5xl py-4 bg-white print:max-w-none print:py-0">
            <article class="overflow-hidden">
                <div class="bg-white rounded-b-md">
                    <!-- Store Header -->
                    <div class="p-9 flex justify-between w-full">
                        <div class="text-slate-700 mb-2 flex flex-col">
                            <img
                                v-if="store.logo"
                                class="object-contain h-20 w-auto mb-2"
                                :src="store.logo"
                                :alt="store.name"
                            />
                            <div v-else class="text-xl font-bold mb-2">{{ store.name }}</div>
                            <div class="text-center text-xs">{{ store.address }} {{ store.address2 }}</div>
                            <div class="text-center text-xs">{{ store.city }}, {{ store.state }} {{ store.zip }}</div>
                            <div class="text-center text-xs">{{ store.phone }}</div>
                            <div class="text-center text-xs">{{ store.email }}</div>
                        </div>
                    </div>

                    <!-- Title -->
                    <div class="px-9 pb-4">
                        <h2 class="text-center text-lg font-bold text-slate-800 uppercase tracking-wide">
                            Store Credit Cash Out Receipt
                        </h2>
                    </div>

                    <!-- Receipt Details Grid -->
                    <div class="px-9 py-4">
                        <div class="grid grid-cols-3 gap-12">
                            <!-- Receipt Info -->
                            <div class="text-sm font-light text-slate-500">
                                <p class="text-sm font-normal text-slate-700">Receipt Details</p>
                                <p>Receipt #{{ storeCredit.id }}</p>
                                <p>Date: {{ formatDate(storeCredit.created_at) }}</p>
                                <p v-if="storeCredit.user_name">Processed by: {{ storeCredit.user_name }}</p>
                            </div>

                            <!-- Customer Info -->
                            <div class="text-sm font-light text-slate-500">
                                <p class="text-sm font-normal text-slate-700">Customer</p>
                                <p>{{ customer.full_name }}</p>
                                <p v-if="customer.address">{{ customer.address }}</p>
                                <p v-if="customer.city || customer.state || customer.zip">
                                    {{ [customer.city, customer.state, customer.zip].filter(Boolean).join(', ') }}
                                </p>
                                <p v-if="customer.phone_number">{{ customer.phone_number }}</p>
                                <p v-if="customer.email">{{ customer.email }}</p>
                            </div>

                            <!-- Payout Method -->
                            <div class="text-sm font-light text-slate-500">
                                <p class="text-sm font-normal text-slate-700">Payout Method</p>
                                <p>{{ payoutMethodLabels[storeCredit.payout_method || ''] || storeCredit.payout_method || '-' }}</p>
                                <template v-if="payoutDetails">
                                    <p v-for="(line, index) in payoutDetails" :key="index" class="text-xs">
                                        {{ line }}
                                    </p>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Cash Out Summary -->
                    <div class="p-9">
                        <table class="min-w-full divide-y divide-slate-500">
                            <thead>
                                <tr>
                                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-normal text-slate-700 md:pl-0">
                                        Description
                                    </th>
                                    <th scope="col" class="py-3.5 px-3 text-right text-sm font-normal text-slate-700">
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-b border-slate-200">
                                    <td class="py-4 pl-4 pr-3 text-sm text-slate-700 md:pl-0">
                                        Store Credit Cash Out
                                        <span v-if="storeCredit.description" class="block text-xs text-slate-500 mt-1">
                                            {{ storeCredit.description }}
                                        </span>
                                    </td>
                                    <td class="py-4 px-3 text-sm text-right font-medium text-slate-700">
                                        {{ formatCurrency(storeCredit.amount) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row" class="pt-6 pl-4 pr-3 text-sm font-normal text-right text-slate-700 md:pl-0">
                                        Cash Out Amount
                                    </th>
                                    <td class="pt-6 px-3 text-sm font-bold text-right text-slate-700">
                                        {{ formatCurrency(storeCredit.amount) }}
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" class="pt-2 pl-4 pr-3 text-sm font-normal text-right text-slate-500 md:pl-0">
                                        Balance After Cash Out
                                    </th>
                                    <td class="pt-2 px-3 text-sm text-right text-slate-500">
                                        {{ formatCurrency(storeCredit.balance_after) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Signature Section -->
                    <div class="mt-32 px-9 pt-2">
                        <div class="border-t pt-9 border-slate-200">
                            <div class="text-sm font-light text-slate-700">
                                <table class="w-full">
                                    <tr>
                                        <td class="text-center w-1/3">Customer Signature</td>
                                        <td class="text-center w-1/3">Date</td>
                                        <td class="text-center w-1/3">Processed By</td>
                                    </tr>
                                    <tr>
                                        <td class="h-[120px] border-b border-slate-300"></td>
                                        <td class="text-center p-[5px] align-top">{{ formatDateTime() }}</td>
                                        <td class="text-center p-[5px] align-top">{{ storeCredit.user_name || '-' }}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Acknowledgment -->
                    <div class="px-9 py-6">
                        <p class="text-xs text-slate-500 text-center">
                            By signing above, the customer acknowledges receipt of the cash out amount specified in this receipt.
                        </p>
                    </div>
                </div>
            </article>
        </div>
    </div>
</template>

<style>
@media print {
    @page {
        size: letter;
        margin: 1cm;
    }

    body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .print-page {
        page-break-after: always;
    }
}
</style>
