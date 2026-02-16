<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { ArrowLeftIcon, PrinterIcon } from '@heroicons/vue/20/solid';

type InvoiceType = 'customer' | 'store';

interface TransactionItem {
    id: number;
    title: string;
    description: string | null;
    category: { id: number; name: string } | null;
    quantity: number;
    price: number | null; // Estimated value
    buy_price: number | null; // Actual purchase price
    images: { url: string }[];
}

interface PaymentDetails {
    // Check payment details
    check_name?: string;
    check_address?: string;
    check_address_2?: string;
    check_city?: string;
    check_state?: string;
    check_zip?: string;
    // Bank/ACH/Wire payment details
    bank_name?: string;
    routing_number?: string;
    account_number?: string;
    account_name?: string;
    account_type?: string;
    bank_address?: string;
    bank_city?: string;
    bank_state?: string;
    bank_zip?: string;
    // PayPal
    paypal_email?: string;
    // Venmo
    venmo_handle?: string;
    // Amount (if split payment)
    amount?: number | string;
}

interface Payment {
    id: number;
    payment_method: string;
    amount: number | string;
    details?: PaymentDetails;
}

interface Props {
    transaction: {
        id: number;
        transaction_number: string;
        status: string;
        type: string;
        preliminary_offer: number | null;
        final_offer: number | null;
        payment_method: string | null;
        customer_notes: string | null;
        created_at: string;
        total_buy_price: number;
        customer: {
            id: number;
            full_name: string;
            company_name: string | null;
            email: string | null;
            phone: string | null;
            address: string | null;
            address2: string | null;
            city: string | null;
            state: string | null;
            zip: string | null;
        } | null;
        user: {
            id: number;
            name: string;
        } | null;
        items: TransactionItem[];
        payments: Payment[];
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
    barcode: string | null;
}

const props = defineProps<Props>();

const paymentMethodLabels: Record<string, string> = {
    cash: 'Cash',
    check: 'Check',
    store_credit: 'Store Credit',
    ach: 'ACH Transfer',
    paypal: 'PayPal',
    venmo: 'Venmo',
    card: 'Credit/Debit Card',
    bank_transfer: 'Bank Transfer',
    external: 'External',
};

const formatCurrency = (value: number | null) => {
    if (value === null || value === undefined) return '$0.00';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
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

const total = props.transaction.final_offer || props.transaction.total_buy_price || 0;

const primaryPayment = props.transaction.payments?.[0];
const primaryPaymentMethod = primaryPayment?.payment_method || props.transaction.payment_method;
const primaryPaymentDetails = primaryPayment?.details;

// Format payment address for display
const getPaymentAddress = () => {
    if (!primaryPaymentDetails) return null;

    // Check payment address
    if (primaryPaymentMethod === 'check') {
        const parts = [];
        if (primaryPaymentDetails.check_name) parts.push(primaryPaymentDetails.check_name);
        if (primaryPaymentDetails.check_address) parts.push(primaryPaymentDetails.check_address);
        if (primaryPaymentDetails.check_address_2) parts.push(primaryPaymentDetails.check_address_2);
        const cityStateZip = [
            primaryPaymentDetails.check_city,
            primaryPaymentDetails.check_state,
            primaryPaymentDetails.check_zip
        ].filter(Boolean).join(', ');
        if (cityStateZip) parts.push(cityStateZip);
        return parts.length > 0 ? parts : null;
    }

    // Bank/ACH/Wire address
    if (['ach', 'wire_transfer', 'bank_transfer'].includes(primaryPaymentMethod || '')) {
        const parts = [];
        if (primaryPaymentDetails.bank_name) parts.push(primaryPaymentDetails.bank_name);
        if (primaryPaymentDetails.account_name) parts.push(`Account: ${primaryPaymentDetails.account_name}`);
        if (primaryPaymentDetails.bank_address) parts.push(primaryPaymentDetails.bank_address);
        const cityStateZip = [
            primaryPaymentDetails.bank_city,
            primaryPaymentDetails.bank_state,
            primaryPaymentDetails.bank_zip
        ].filter(Boolean).join(', ');
        if (cityStateZip) parts.push(cityStateZip);
        return parts.length > 0 ? parts : null;
    }

    // PayPal email
    if (primaryPaymentMethod === 'paypal' && primaryPaymentDetails.paypal_email) {
        return [primaryPaymentDetails.paypal_email];
    }

    // Venmo handle
    if (primaryPaymentMethod === 'venmo' && primaryPaymentDetails.venmo_handle) {
        return [primaryPaymentDetails.venmo_handle];
    }

    return null;
};

const paymentAddress = getPaymentAddress();

// Invoice type toggle (customer vs store)
// Read initial type from URL query parameter
const getInitialInvoiceType = (): InvoiceType => {
    const params = new URLSearchParams(window.location.search);
    const type = params.get('type');
    return type === 'store' ? 'store' : 'customer';
};
const invoiceType = ref<InvoiceType>(getInitialInvoiceType());

// Calculate total estimated value
const totalEstimatedValue = computed(() => {
    return props.transaction.items.reduce((sum, item) => {
        return sum + ((item.price || 0) * (item.quantity || 1));
    }, 0);
});

// Calculate total profit (estimated value - buy price)
const totalProfit = computed(() => {
    return totalEstimatedValue.value - total;
});

// Get item profit
const getItemProfit = (item: TransactionItem) => {
    const estValue = (item.price || 0) * (item.quantity || 1);
    const buyPrice = (item.buy_price || 0) * (item.quantity || 1);
    return estValue - buyPrice;
};

const print = () => {
    window.print();
};
</script>

<template>
    <Head :title="`Invoice - ${transaction.transaction_number}`" />

    <div class="min-h-screen bg-gray-900">
        <!-- Header (hidden when printing) -->
        <div class="print:hidden bg-white shadow">
            <div class="mx-auto max-w-5xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="`/transactions/${transaction.id}`"
                            class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500"
                        >
                            <ArrowLeftIcon class="size-5" />
                        </Link>
                        <h1 class="text-lg font-semibold text-gray-900">Print Invoice</h1>
                    </div>
                    <div class="flex items-center gap-4">
                        <!-- Invoice Type Toggle -->
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-500">Type:</span>
                            <div class="inline-flex rounded-md shadow-sm">
                                <button
                                    type="button"
                                    :class="[
                                        'px-3 py-1.5 text-sm font-medium rounded-l-md border',
                                        invoiceType === 'customer'
                                            ? 'bg-indigo-600 text-white border-indigo-600'
                                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                    ]"
                                    @click="invoiceType = 'customer'"
                                >
                                    Customer
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        'px-3 py-1.5 text-sm font-medium rounded-r-md border-t border-b border-r',
                                        invoiceType === 'store'
                                            ? 'bg-indigo-600 text-white border-indigo-600'
                                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                    ]"
                                    @click="invoiceType = 'store'"
                                >
                                    Store
                                </button>
                            </div>
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
        </div>

        <!-- Invoice -->
        <div class="mx-auto max-w-5xl py-4 bg-white print:max-w-none print:py-0">
            <article class="overflow-hidden">
                <div class="bg-white rounded-b-md">
                    <!-- Header with Logo and Barcode -->
                    <div class="p-9 flex justify-between w-full">
                        <div class="text-slate-700 mb-2 flex flex-col">
                            <img
                                v-if="store.logo"
                                class="object-cover h-12 mb-2"
                                :src="store.logo"
                                :alt="store.name"
                            />
                            <div v-else class="text-xl font-bold mb-2">{{ store.name }}</div>
                            <div class="text-center text-xs">{{ store.address }} {{ store.address2 }}</div>
                            <div class="text-center text-xs">{{ store.city }}, {{ store.state }} {{ store.zip }}</div>
                            <div class="text-center text-xs">{{ store.phone }}</div>
                            <div class="text-center text-xs">{{ store.email }}</div>
                        </div>

                        <div class="w-[200px]">
                            <img
                                v-if="barcode"
                                :src="barcode"
                                class="h-[50px]"
                                alt="Barcode"
                            />
                        </div>
                    </div>

                    <!-- Invoice Details Grid -->
                    <div class="px-9 py-4">
                        <div class="flex w-full">
                            <div class="grid grid-cols-4 gap-12 w-full">
                                <!-- Invoice Detail -->
                                <div class="text-sm font-light text-slate-500">
                                    <p class="text-sm font-normal text-slate-700">Invoice Detail:</p>
                                    <p>{{ store.name }}</p>
                                    <p>{{ store.address }} {{ store.address2 }}</p>
                                    <p>{{ store.city }}</p>
                                    <p>{{ store.state }} {{ store.zip }}</p>
                                </div>

                                <!-- Purchased From -->
                                <div class="text-sm font-light text-slate-500">
                                    <p class="text-sm font-normal text-slate-700">Purchased From</p>
                                    <template v-if="transaction.customer">
                                        <p>{{ transaction.customer.company_name || transaction.customer.full_name }}</p>
                                        <p v-if="transaction.customer.address">
                                            {{ transaction.customer.address }} {{ transaction.customer.address2 }}
                                        </p>
                                        <p v-if="transaction.customer.city">{{ transaction.customer.city }}</p>
                                        <p v-if="transaction.customer.state || transaction.customer.zip">
                                            {{ transaction.customer.state }} {{ transaction.customer.zip }}
                                        </p>
                                        <p v-if="transaction.customer.phone" class="mt-1">{{ transaction.customer.phone }}</p>
                                        <p v-if="transaction.customer.email">{{ transaction.customer.email }}</p>
                                    </template>
                                    <p v-else class="italic">Walk-in customer</p>
                                </div>

                                <!-- Invoice Number -->
                                <div class="text-sm font-light text-slate-500">
                                    <p class="text-sm font-normal text-slate-700">Invoice Number</p>
                                    <p>Buy: {{ transaction.transaction_number }}</p>
                                    <p class="mt-2 text-sm font-normal text-slate-700">Date of Issue</p>
                                    <p>{{ formatDate(transaction.created_at) }}</p>
                                </div>

                                <!-- Payment Method -->
                                <div class="text-sm font-light text-slate-500">
                                    <p class="text-sm font-normal text-slate-700">Payment Method</p>
                                    <p>{{ paymentMethodLabels[primaryPaymentMethod] || primaryPaymentMethod || '-' }}</p>
                                    <template v-if="paymentAddress">
                                        <p v-for="(line, index) in paymentAddress" :key="index" class="text-xs">
                                            {{ line }}
                                        </p>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="p-9">
                        <div class="flex flex-col mx-0 mt-8">
                            <table class="min-w-full divide-y divide-slate-500">
                                <thead>
                                    <tr>
                                        <th scope="col" class="hidden py-3.5 px-3 text-right text-sm font-normal text-slate-700 sm:table-cell"></th>
                                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-normal text-slate-700 sm:pl-6 md:pl-0">
                                            Title
                                        </th>
                                        <th scope="col" class="hidden py-3.5 px-3 text-right text-sm font-normal text-slate-700 sm:table-cell">
                                            Type
                                        </th>
                                        <th scope="col" class="hidden py-3.5 px-3 text-right text-sm font-normal text-slate-700 sm:table-cell">
                                            Quantity
                                        </th>
                                        <!-- Store Invoice Only: Est Value -->
                                        <th v-if="invoiceType === 'store'" scope="col" class="hidden py-3.5 px-3 text-right text-sm font-normal text-slate-700 sm:table-cell">
                                            Est. Value
                                        </th>
                                        <th scope="col" class="py-3.5 pl-3 pr-4 text-right text-sm font-normal text-slate-700 sm:pr-6 md:pr-0">
                                            {{ invoiceType === 'store' ? 'Buy Price' : 'Amount' }}
                                        </th>
                                        <!-- Store Invoice Only: Est Profit -->
                                        <th v-if="invoiceType === 'store'" scope="col" class="hidden py-3.5 px-3 text-right text-sm font-normal text-slate-700 sm:table-cell">
                                            Est. Profit
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in transaction.items" :key="item.id" class="border-b border-slate-200">
                                        <td class="p-1">
                                            <img
                                                v-if="item.images && item.images.length > 0"
                                                :src="item.images[0].url"
                                                class="w-16 h-16 object-cover"
                                            />
                                        </td>
                                        <td class="p-3">
                                            <div class="font-medium text-slate-700">{{ item.title || 'Untitled Item' }}</div>
                                        </td>
                                        <td class="hidden px-3 py-4 text-sm text-right text-slate-500 sm:table-cell">
                                            {{ item.category?.name || '-' }}
                                        </td>
                                        <td class="hidden px-3 py-4 text-sm text-right text-slate-500 sm:table-cell">
                                            {{ item.quantity || 1 }}
                                        </td>
                                        <!-- Store Invoice Only: Est Value -->
                                        <td v-if="invoiceType === 'store'" class="hidden px-3 py-4 text-sm text-right text-slate-500 sm:table-cell">
                                            {{ formatCurrency((item.price || 0) * (item.quantity || 1)) }}
                                        </td>
                                        <td class="py-4 pl-3 pr-4 text-sm text-right text-slate-500 sm:pr-6 md:pr-0">
                                            {{ formatCurrency((item.buy_price || 0) * (item.quantity || 1)) }}
                                        </td>
                                        <!-- Store Invoice Only: Est Profit -->
                                        <td v-if="invoiceType === 'store'" class="hidden px-3 py-4 text-sm text-right sm:table-cell" :class="getItemProfit(item) >= 0 ? 'text-green-600' : 'text-red-600'">
                                            {{ formatCurrency(getItemProfit(item)) }}
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <!-- Payment Modes (if multiple) -->
                                    <tr v-if="transaction.payments && transaction.payments.length > 1">
                                        <th scope="row" :colspan="invoiceType === 'store' ? 6 : 4" class="hidden pt-6 pl-6 pr-3 text-sm font-light text-right text-slate-500 sm:table-cell md:pl-0">
                                            Payment Modes
                                        </th>
                                        <td class="pt-4 pl-3 pr-4 text-right text-slate-500 sm:pr-6 md:pr-0">
                                            <table class="w-full">
                                                <tr v-for="payment in transaction.payments" :key="payment.id">
                                                    <td class="text-xs">{{ paymentMethodLabels[payment.payment_method] || payment.payment_method }}</td>
                                                    <td class="text-xs text-right">{{ formatCurrency(payment.amount) }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                        <td v-if="invoiceType === 'store'"></td>
                                    </tr>

                                    <!-- Subtotal / Totals Row -->
                                    <tr>
                                        <th scope="row" :colspan="invoiceType === 'store' ? 4 : 4" class="hidden pt-6 pl-6 pr-3 text-sm font-light text-right text-slate-500 sm:table-cell md:pl-0">
                                            {{ invoiceType === 'store' ? 'Totals' : 'Subtotal' }}
                                        </th>
                                        <th scope="row" class="pt-6 pl-4 pr-3 text-sm font-light text-left text-slate-500 sm:hidden">
                                            {{ invoiceType === 'store' ? 'Totals' : 'Subtotal' }}
                                        </th>
                                        <!-- Store Invoice: Est Value Total -->
                                        <td v-if="invoiceType === 'store'" class="hidden pt-6 pl-3 pr-4 text-sm text-right text-slate-500 sm:table-cell">
                                            {{ formatCurrency(totalEstimatedValue) }}
                                        </td>
                                        <td class="pt-6 pl-3 pr-4 text-sm text-right text-slate-500 sm:pr-6 md:pr-0">
                                            {{ formatCurrency(total) }}
                                        </td>
                                        <!-- Store Invoice: Est Profit Total -->
                                        <td v-if="invoiceType === 'store'" class="hidden pt-6 pl-3 pr-4 text-sm font-semibold text-right sm:table-cell" :class="totalProfit >= 0 ? 'text-green-600' : 'text-red-600'">
                                            {{ formatCurrency(totalProfit) }}
                                        </td>
                                    </tr>

                                    <!-- Total (Customer Invoice Only) -->
                                    <tr v-if="invoiceType === 'customer'">
                                        <th scope="row" colspan="4" class="hidden pt-4 pl-6 pr-3 text-sm font-normal text-right text-slate-700 sm:table-cell md:pl-0">
                                            Total
                                        </th>
                                        <th scope="row" class="pt-4 pl-4 pr-3 text-sm font-normal text-left text-slate-700 sm:hidden">
                                            Total
                                        </th>
                                        <td class="pt-4 pl-3 pr-4 text-sm font-normal text-right text-slate-700 sm:pr-6 md:pr-0">
                                            {{ formatCurrency(total) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Signature Section -->
                    <div class="mt-48 px-9 pt-2">
                        <div class="border-t pt-9 border-slate-200">
                            <div class="text-sm font-light text-slate-700">
                                <table class="w-full">
                                    <tr>
                                        <td class="text-center w-1/4">Fingerprint</td>
                                        <td class="text-center w-1/4">Signature</td>
                                        <td class="text-center w-1/4">Date</td>
                                        <td class="text-center w-1/4">Sales Person</td>
                                    </tr>
                                    <tr>
                                        <td class="h-[200px]"></td>
                                        <td></td>
                                        <td class="text-center p-[5px] align-top">{{ formatDateTime() }}</td>
                                        <td class="text-center p-[5px] align-top">{{ transaction.user?.name || '-' }}</td>
                                    </tr>
                                </table>

                                <p class="text-center text-xl font-bold mb-4 mt-8">Disclaimer</p>
                                <p class="mb-4">1. Sellers of Merchandise warrant that he or she is the legal owner of any and all items presented for sale. The seller agrees to transfer the full title of said items to {{ store.name }} (hereafter referred to as "REB") upon acceptance of any form of payment and upon execution of this agreement. Sellers further certifies that the presented goods are genuine and not misrepresented in any way, shape, or form.</p>
                                <p class="mb-4">2. You consent to the law and jurisdiction of any court within the State of Pennsylvania for action arising from this transaction. You agree to pay all costs, including attorney's fees and expenses and court costs, incurred by REB or its assigns in enforcing any part of this contract.</p>
                                <p class="mb-4">3. The price for which each item is sold represents the price that REB has offered, and you have paid, independent of any description by REB. The condition, description or grade of any item sold represents the opinion of REB and is not a warranty of any kind. REB disclaims all warranties, expressed or implied, including warranties of merchantability.</p>
                                <p class="mb-4">4. REB's sole liability for any claim shall be no greater than the purchase price of the merchandise with respect to which a claim is made after such merchandise is returned to REB. Such liability shall not include consequential damages.</p>
                                <p class="mb-4">Consignment – Memo</p>
                                <p class="mb-4">5. The merchandise described on the front side of this invoice remains property of REB and shall be returned to us on demand until payment is made in full and is received by REB. No power is given to you to sell, pledge, hypothecate or otherwise dispose of this merchandise until paid in full.</p>
                                <p class="mb-4">6. For Consignment and Memos, you will bear all risk of loss from all hazards for this merchandise from its delivery to you until its returned to REB or paid in full. A finance charge of 3% per month (36% annually) will be applied to any balance remaining unpaid 30 days after the date of this sale order.</p>
                            </div>
                        </div>
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
