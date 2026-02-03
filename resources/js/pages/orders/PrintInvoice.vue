<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftIcon, PrinterIcon } from '@heroicons/vue/20/solid';

interface OrderItem {
    id: number;
    sku: string | null;
    title: string;
    quantity: number;
    price: number;
    discount: number;
    tax: number | null;
    line_total: number;
    category: string | null;
    product: {
        id: number;
        title: string;
        images: { url: string }[];
    } | null;
}

interface Payment {
    id: number;
    payment_method: string;
    amount: number;
    status: string;
    reference: string | null;
    paid_at: string | null;
}

interface Props {
    order: {
        id: number;
        order_id: string | null;
        invoice_number: string | null;
        status: string;
        sub_total: number;
        sales_tax: number;
        tax_rate: number;
        shipping_cost: number;
        discount_cost: number;
        trade_in_credit: number;
        service_fee_value: number | null;
        service_fee_unit: string | null;
        total: number;
        total_paid: number | null;
        balance_due: number | null;
        notes: string | null;
        date_of_purchase: string | null;
        created_at: string;
        shipping_address: Record<string, string> | null;
        billing_address: Record<string, string> | null;
        customer: {
            id: number;
            full_name: string;
            first_name: string | null;
            last_name: string | null;
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
        warehouse: {
            id: number;
            name: string;
        } | null;
        items: OrderItem[];
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
    credit_card: 'Credit Card',
    debit_card: 'Debit Card',
    check: 'Check',
    store_credit: 'Store Credit',
    gift_card: 'Gift Card',
    trade_in: 'Trade-In',
    wire: 'Wire Transfer',
    ach: 'ACH Transfer',
    paypal: 'PayPal',
    venmo: 'Venmo',
    external: 'External',
};

const formatCurrency = (value: number | null | undefined) => {
    if (value === null || value === undefined) return '0.00';
    return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric',
    });
};

const orderNumber = props.order.invoice_number || props.order.order_id || `#${props.order.id}`;
const orderDate = props.order.date_of_purchase || props.order.created_at;

// Calculate service fee
const serviceFee = (() => {
    const value = props.order.service_fee_value ?? 0;
    if (value <= 0) return 0;
    if (props.order.service_fee_unit === 'percent') {
        const subtotalAfterDiscount = props.order.sub_total - (props.order.discount_cost ?? 0);
        return subtotalAfterDiscount * value / 100;
    }
    return value;
})();

// Get primary payment method for display
const primaryPaymentMethod = props.order.payments?.[0]?.payment_method || null;

const print = () => {
    window.print();
};
</script>

<template>
    <Head :title="`Invoice - ${orderNumber}`" />

    <div class="min-h-screen bg-gray-900">
        <!-- Header (hidden when printing) -->
        <div class="print:hidden bg-white shadow">
            <div class="mx-auto max-w-5xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="`/orders/${order.id}`"
                            class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500"
                        >
                            <ArrowLeftIcon class="size-5" />
                        </Link>
                        <h1 class="text-lg font-semibold text-gray-900">Print Invoice</h1>
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

        <!-- Invoice -->
        <section class="py-2 bg-black print:bg-white print:py-0">
            <div class="max-w-5xl mx-auto py-2 bg-white print:max-w-none">
                <article class="overflow-hidden">
                    <div class="bg-white rounded-b-md">
                        <!-- Header with Logo and Barcode -->
                        <div class="px-9 py-3 flex justify-between w-full">
                            <div class="text-slate-700 mb-2 flex flex-col">
                                <img
                                    v-if="store.logo"
                                    class="object-cover h-12"
                                    :src="store.logo"
                                    :alt="store.name"
                                />
                                <div v-else class="text-xl font-bold mb-2">{{ store.name }}</div>

                                <div class="text-center text-xs">{{ store.address }} {{ store.address2 }}</div>
                                <div class="text-center text-xs">{{ store.city }}, {{ store.state }}</div>
                                <div class="text-center text-xs">{{ store.zip }}</div>
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

                        <!-- Invoice Meta -->
                        <div class="p-9">
                            <div class="flex w-full">
                                <div class="grid grid-cols-5 gap-12">
                                    <!-- Invoice Detail -->
                                    <div class="text-xs font-light text-slate-500">
                                        <p class="text-xs font-normal text-slate-700">Invoice Detail:</p>
                                        <p class="text-xs">{{ store.name }}</p>
                                        <p class="text-xs">{{ store.address }} {{ store.address2 }}</p>
                                        <p class="text-xs">{{ store.city }}</p>
                                        <p class="text-xs">{{ store.state }} {{ store.zip }}</p>
                                    </div>

                                    <!-- Billed To -->
                                    <div class="text-xs font-light text-slate-500">
                                        <p class="text-xs font-normal text-slate-700">Billed To</p>
                                        <template v-if="order.customer">
                                            <p v-if="order.customer.company_name" class="text-xs">{{ order.customer.company_name }}</p>
                                            <p class="text-xs">{{ order.customer.full_name }}</p>
                                            <p v-if="order.customer.address" class="text-xs">{{ order.customer.address }} {{ order.customer.address2 }}</p>
                                            <p v-if="order.customer.city" class="text-xs">{{ order.customer.city }}</p>
                                            <p v-if="order.customer.state || order.customer.zip" class="text-xs">{{ order.customer.state }} {{ order.customer.zip }}</p>
                                        </template>
                                        <p v-else class="text-xs italic">Walk-in customer</p>
                                    </div>

                                    <!-- Invoice Number & Date -->
                                    <div class="text-xs font-light text-slate-500">
                                        <p class="text-xs font-normal text-slate-700">Invoice Number</p>
                                        <p class="text-xs">{{ orderNumber }}</p>
                                        <p class="mt-2 text-xs font-normal text-slate-700">Date of Issue</p>
                                        <p class="text-xs">{{ formatDate(orderDate) }}</p>
                                    </div>

                                    <!-- Payment Method -->
                                    <div class="text-xs font-light text-slate-500">
                                        <p class="text-xs font-normal text-slate-700">Payment Method</p>
                                        <p class="text-xs">{{ paymentMethodLabels[primaryPaymentMethod ?? ''] || primaryPaymentMethod || '-' }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="px-9 py-2">
                            <!-- Items Table -->
                            <div class="flex flex-col mx-0 mt-1">
                                <table class="min-w-full divide-y divide-slate-500">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="hidden py-3.5 px-3 text-left text-sm font-normal text-slate-700 sm:table-cell">
                                                &nbsp;
                                            </th>
                                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-normal text-slate-700 sm:pl-6 md:pl-0">
                                                Description
                                            </th>
                                            <th scope="col" class="py-3.5 px-3 text-left text-sm font-normal text-slate-700 sm:table-cell">
                                                Type
                                            </th>
                                            <th scope="col" class="hidden py-3.5 px-3 text-right text-sm font-normal text-slate-700 sm:table-cell">
                                                Quantity
                                            </th>
                                            <th scope="col" class="py-3.5 pl-3 pr-4 text-right text-sm font-normal text-slate-700 sm:pr-6 md:pr-0">
                                                Amount
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <tr v-for="item in order.items" :key="item.id" class="border-b border-slate-200">
                                            <td class="hidden sm:table-cell py-2 px-3">
                                                <div>
                                                    <img
                                                        v-if="item.product?.images && item.product.images.length > 0"
                                                        :src="item.product.images[0].url"
                                                        class="w-10 h-10 object-cover"
                                                    />
                                                </div>
                                            </td>

                                            <td class="py-2 pl-4 pr-3 text-left sm:pl-6 md:pl-0">
                                                <div class="font-medium text-slate-700 text-xs">
                                                    {{ item.title }}
                                                    <span v-if="item.sku"> - {{ item.sku }}</span>
                                                </div>
                                                <div class="mt-0.5 text-slate-500 text-xs"></div>
                                            </td>

                                            <td class="hidden px-3 py-2 text-xs text-left text-slate-500 sm:table-cell">
                                                {{ item.category || '-' }}
                                            </td>

                                            <td class="hidden px-3 py-2 text-xs text-right text-slate-500 sm:table-cell">
                                                {{ item.quantity }}
                                            </td>

                                            <td class="py-2 pl-3 pr-4 text-xs text-right text-slate-500 sm:pr-6 md:pr-0">
                                                ${{ formatCurrency(item.line_total) }}
                                            </td>
                                        </tr>
                                    </tbody>

                                    <tfoot>
                                        <!-- Subtotal -->
                                        <tr>
                                            <th scope="row" colspan="4" class="hidden pt-6 pl-6 pr-3 text-xs font-light text-right text-slate-500 sm:table-cell md:pl-0">
                                                Subtotal
                                            </th>
                                            <th scope="row" class="pt-6 pl-4 pr-3 text-xs font-light text-left text-slate-500 sm:hidden">
                                                Subtotal
                                            </th>
                                            <td class="pt-6 pl-3 pr-4 text-xs text-right text-slate-500 sm:pr-6 md:pr-0">
                                                ${{ formatCurrency(order.sub_total) }}
                                            </td>
                                        </tr>

                                        <!-- Discount -->
                                        <tr v-if="order.discount_cost > 0">
                                            <th scope="row" colspan="4" class="hidden pt-2 pl-6 pr-3 text-xs font-light text-right text-slate-500 sm:table-cell md:pl-0">
                                                Discount
                                            </th>
                                            <th scope="row" class="pt-2 pl-4 pr-3 text-xs font-light text-left text-slate-500 sm:hidden">
                                                Discount
                                            </th>
                                            <td class="pt-2 pl-3 pr-4 text-xs text-right text-slate-500 sm:pr-6 md:pr-0">
                                                -${{ formatCurrency(order.discount_cost) }}
                                            </td>
                                        </tr>

                                        <!-- Delivery/Shipping -->
                                        <tr v-if="order.shipping_cost > 0">
                                            <th scope="row" colspan="4" class="hidden pt-4 pl-6 pr-3 text-xs font-light text-right text-slate-500 sm:table-cell md:pl-0">
                                                Delivery
                                            </th>
                                            <th scope="row" class="pt-4 pl-4 pr-3 text-xs font-light text-left text-slate-500 sm:hidden">
                                                Delivery
                                            </th>
                                            <td class="pt-4 pl-3 pr-4 text-xs text-right text-slate-500 sm:pr-6 md:pr-0">
                                                ${{ formatCurrency(order.shipping_cost) }}
                                            </td>
                                        </tr>

                                        <!-- Service Fee (e.g., 3% CC Fee) -->
                                        <tr v-if="serviceFee > 0">
                                            <th scope="row" colspan="4" class="hidden pt-4 pl-6 pr-3 text-xs font-light text-right text-slate-500 sm:table-cell md:pl-0">
                                                {{ order.service_fee_unit === 'percent' ? `${order.service_fee_value}% CC Fee` : 'Service Fee' }}
                                            </th>
                                            <th scope="row" class="pt-4 pl-4 pr-3 text-xs font-light text-left text-slate-500 sm:hidden">
                                                Service Fee
                                            </th>
                                            <td class="pt-4 pl-3 pr-4 text-xs text-right text-slate-500 sm:pr-6 md:pr-0">
                                                ${{ formatCurrency(serviceFee) }}
                                            </td>
                                        </tr>

                                        <!-- Sales Tax -->
                                        <tr v-if="order.sales_tax > 0">
                                            <th scope="row" colspan="4" class="hidden pt-4 pl-6 pr-3 text-xs font-light text-right text-slate-500 sm:table-cell md:pl-0">
                                                Sales Tax
                                            </th>
                                            <th scope="row" class="pt-4 pl-4 pr-3 text-xs font-light text-left text-slate-500 sm:hidden">
                                                Sales Tax
                                            </th>
                                            <td class="pt-4 pl-3 pr-4 text-xs text-right text-slate-500 sm:pr-6 md:pr-0">
                                                ${{ formatCurrency(order.sales_tax) }}
                                            </td>
                                        </tr>

                                        <!-- Payment Modes (if multiple) -->
                                        <tr v-if="order.payments && order.payments.length > 1">
                                            <th scope="row" colspan="4" class="hidden pt-4 pl-6 pr-3 text-xs font-light text-right text-slate-500 sm:table-cell md:pl-0">
                                                Payment Modes
                                            </th>
                                            <th scope="row" class="pt-4 pl-4 pr-3 text-xs font-light text-left text-slate-500 sm:hidden">
                                                Payment Modes
                                            </th>
                                            <td class="pt-4 pl-3 pr-4 text-xs text-right text-slate-500 sm:pr-6 md:pr-0">
                                                <table class="w-full">
                                                    <tr v-for="payment in order.payments" :key="payment.id">
                                                        <td class="text-xs text-left">{{ paymentMethodLabels[payment.payment_method] || payment.payment_method }}</td>
                                                        <td class="text-xs text-right">
                                                            <template v-if="payment.payment_method === 'store_credit'">
                                                                (${{ formatCurrency(payment.amount) }})
                                                            </template>
                                                            <template v-else>
                                                                ${{ formatCurrency(payment.amount) }}
                                                            </template>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>

                                        <!-- Total -->
                                        <tr>
                                            <th scope="row" colspan="4" class="hidden pt-4 pl-6 pr-3 text-sm font-normal text-right text-slate-700 sm:table-cell md:pl-0">
                                                Total
                                            </th>
                                            <th scope="row" class="pt-4 pl-4 pr-3 text-sm font-normal text-left text-slate-700 sm:hidden">
                                                Total
                                            </th>
                                            <td class="pt-4 pl-3 pr-4 text-sm font-normal text-right text-slate-700 sm:pr-6 md:pr-0">
                                                ${{ formatCurrency(order.total) }}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Footer / Disclaimer -->
                        <div class="mt-48 p-9">
                            <div class="border-t pt-9 border-slate-200">
                                <div class="text-sm font-light text-slate-700 print-page">
                                    <table class="w-full">
                                        <tr>
                                            <td class="text-center w-1/4">Fingerprint</td>
                                            <td class="text-center w-1/4">Signature</td>
                                            <td class="text-center w-1/4">Date</td>
                                            <td class="text-center w-1/4">Sales Person</td>
                                        </tr>
                                        <tr>
                                            <td class="w-1/4 h-[200px]"></td>
                                            <td></td>
                                            <td class="text-center p-[5px] align-top">{{ formatDate(orderDate) }}</td>
                                            <td class="text-center p-[5px] align-top">{{ order.user?.name || '-' }}</td>
                                        </tr>
                                    </table>

                                    <p class="text-center text-xl font-bold mb-4">Disclaimer</p>
                                    <p class="mb-4">1. Sellers of Merchandise warrant that he or she is the legal owner of any and all items presented for sale. The seller agrees to transfer the full title of said items to {{ store.name }} (hereafter referred to as "REB") upon acceptance of any form of payment and upon execution of this agreement. Sellers further certifies that the presented goods are genuine and not misrepresented in any way, shape, or form.</p>
                                    <p class="mb-4">2. You consent to the law and jurisdiction of any court within the State of Pennsylvania for action arising from this transaction. You agree to pay all costs, including attorney's fees and expenses and court costs, incurred by REB or its assigns in enforcing any part of this contract.</p>
                                    <p class="mb-4">3. The price for which each item is sold represents the price that REB has offered, and you have paid, independent of any description by REB. The condition, description or grade of any item sold represents the opinion of REB and is not a warranty of any kind. REB disclaims all warranties, expressed or implied, including warranties of merchantability.</p>
                                    <p class="mb-4">4. REB's sole liability for any claim shall be no greater than the purchase price of the merchandise with respect to which a claim is made after such merchandise is returned to REB. Such liability shall not include consequential damages.</p>
                                    <p class="mb-4">Consignment - Memo</p>
                                    <p class="mb-4">5. The merchandise described on the front side of this invoice remains property of REB and shall be returned to us on demand until payment is made in full and is received by REB. No power is given to you to sell, pledge, hypothecate or otherwise dispose of this merchandise until paid in full.</p>
                                    <p class="mb-4">6. For Consignment and Memos, you will bear all risk of loss from all hazards for this merchandise from its delivery to you until its returned to REB or paid in full. A finance charge of 3% per month (36% annually) will be applied to any balance remaining unpaid 30 days after the date of this sale order.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>

<style>
.print-page { page-break-after: always; }
.barcode { width: 200px; }
.barcode img { height: 50px; }

table { border-collapse: collapse; width: 100%; }
th, td { vertical-align: top; }

@media print {
    @page {
        size: letter;
        margin: 1cm;
    }

    body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
