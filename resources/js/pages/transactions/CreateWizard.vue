<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    UserIcon,
    UserGroupIcon,
    CubeIcon,
    CreditCardIcon,
    CheckCircleIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    PencilIcon,
} from '@heroicons/vue/24/outline';
import SelectUserStep from '@/components/transactions/SelectUserStep.vue';
import CustomerStep from '@/components/transactions/CustomerStep.vue';
import ItemsStep from '@/components/transactions/ItemsStep.vue';
import PaymentStep from '@/components/transactions/PaymentStep.vue';

interface StoreUser {
    id: number;
    name: string;
}

interface Category {
    value: number;
    label: string;
}

interface SelectOption {
    value: string;
    label: string;
}

interface Warehouse {
    value: number;
    label: string;
    tax_rate: number | null;
}

interface Customer {
    id?: number;
    first_name: string;
    last_name: string;
    company_name?: string;
    full_name?: string;
    email?: string;
    phone_number?: string;
    address?: string;
    address2?: string;
    city?: string;
    state?: string;
    state_id?: number;
    zip?: string;
    country_id?: number;
}

interface TransactionItem {
    id: string;
    title: string;
    description?: string;
    category_id?: number;
    precious_metal?: string;
    dwt?: number;
    condition?: string;
    price?: number;
    buy_price: number;
}

interface Payment {
    id: string;
    method: string;
    amount: number;
    details: Record<string, any>;
}

interface Props {
    storeUsers: StoreUser[];
    currentStoreUserId: number | null;
    categories: Category[];
    preciousMetals: SelectOption[];
    conditions: SelectOption[];
    paymentMethods: SelectOption[];
    warehouses: Warehouse[];
    defaultWarehouseId: number | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transactions', href: '/transactions' },
    { title: 'New In-Store Buy', href: '/transactions/buy' },
];

// Wizard state
const currentStep = ref(1);
const totalSteps = 4;
const isSubmitting = ref(false);

// Form data - default store_user_id to current user if they're in the authorized list
const getDefaultStoreUserId = () => {
    if (props.currentStoreUserId && props.storeUsers.some(u => u.id === props.currentStoreUserId)) {
        return props.currentStoreUserId;
    }
    return null;
};

// Get the default warehouse ID
const getDefaultWarehouseId = () => {
    if (props.defaultWarehouseId && props.warehouses.some(w => w.value === props.defaultWarehouseId)) {
        return props.defaultWarehouseId;
    }
    // If there's only one warehouse, auto-select it
    if (props.warehouses.length === 1) {
        return props.warehouses[0].value;
    }
    return null;
};

const formData = ref({
    // Step 1: Store User
    store_user_id: getDefaultStoreUserId(),

    // Step 2: Customer
    customer_id: null as number | null,
    customer: null as Customer | null,

    // Step 3: Items
    items: [] as TransactionItem[],

    // Step 4: Payments (multiple)
    warehouse_id: getDefaultWarehouseId(),
    payments: [] as Payment[],
    customer_notes: '',
    internal_notes: '',
});

const selectedWarehouse = computed(() => {
    return props.warehouses.find(w => w.value === formData.value.warehouse_id);
});

const steps = [
    { number: 1, name: 'Select Employee', icon: UserIcon },
    { number: 2, name: 'Customer', icon: UserGroupIcon },
    { number: 3, name: 'Add Items', icon: CubeIcon },
    { number: 4, name: 'Payment', icon: CreditCardIcon },
];

const canProceed = computed(() => {
    switch (currentStep.value) {
        case 1:
            return formData.value.store_user_id !== null;
        case 2:
            return formData.value.customer_id !== null || (formData.value.customer?.first_name && formData.value.customer?.last_name);
        case 3:
            return formData.value.items.length > 0 && formData.value.items.every(item => item.title && item.buy_price >= 0);
        case 4:
            return validatePaymentStep();
        default:
            return false;
    }
});

function validatePaymentStep(): boolean {
    if (formData.value.payments.length === 0) return false;

    // Check that all payments are valid
    for (const payment of formData.value.payments) {
        if (!payment.method || payment.amount <= 0) return false;
        if (!validatePaymentDetails(payment)) return false;
    }

    // Check that total payments equal the buy price
    const totalPayments = formData.value.payments.reduce((sum, p) => sum + p.amount, 0);
    return Math.abs(totalPayments - totalBuyPrice.value) < 0.01;
}

function validatePaymentDetails(payment: Payment): boolean {
    const method = payment.method;
    const details = payment.details;

    switch (method) {
        case 'paypal':
            return !!details.paypal_email;
        case 'venmo':
            return !!details.venmo_handle;
        case 'check':
            return !!(details.check_mailing_address?.address && details.check_mailing_address?.city && details.check_mailing_address?.state && details.check_mailing_address?.zip);
        case 'ach':
        case 'wire_transfer':
            return !!(details.bank_name && details.account_holder_name && details.account_number && details.routing_number);
        case 'cash':
        case 'store_credit':
            return true;
        default:
            return false;
    }
}

const totalBuyPrice = computed(() => {
    return formData.value.items.reduce((sum, item) => sum + (item.buy_price || 0), 0);
});

const totalPaymentsAmount = computed(() => {
    return formData.value.payments.reduce((sum, p) => sum + (p.amount || 0), 0);
});

const remainingBalance = computed(() => {
    return totalBuyPrice.value - totalPaymentsAmount.value;
});

const selectedStoreUser = computed(() => {
    return props.storeUsers.find(u => u.id === formData.value.store_user_id);
});

const customerDisplayName = computed(() => {
    if (formData.value.customer) {
        return `${formData.value.customer.first_name} ${formData.value.customer.last_name}`.trim();
    }
    return null;
});

function getPaymentMethodLabel(method: string): string {
    const found = props.paymentMethods.find(m => m.value === method);
    return found?.label || method;
}

function nextStep() {
    if (currentStep.value < totalSteps && canProceed.value) {
        currentStep.value++;
    }
}

function prevStep() {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
}

function goToStep(step: number) {
    // Allow going to any completed step or the current step
    if (step <= currentStep.value) {
        currentStep.value = step;
    }
}

function handleStoreUserSelect(id: number) {
    formData.value.store_user_id = id;
}

function handleCustomerSelect(customer: Customer | null, customerId: number | null) {
    formData.value.customer = customer;
    formData.value.customer_id = customerId;
}

function handleItemsUpdate(items: TransactionItem[]) {
    formData.value.items = items;
}

function handlePaymentUpdate(data: { payments: Payment[]; customer_notes: string; internal_notes: string }) {
    formData.value.payments = data.payments;
    formData.value.customer_notes = data.customer_notes;
    formData.value.internal_notes = data.internal_notes;
}

function submitTransaction() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;

    // For backward compatibility, if there's only one payment, use the old format
    // Otherwise, send payments array
    const payload: Record<string, any> = {
        store_user_id: formData.value.store_user_id,
        customer_id: formData.value.customer_id,
        customer: formData.value.customer_id ? null : formData.value.customer,
        items: formData.value.items.map(item => ({
            title: item.title,
            description: item.description,
            category_id: item.category_id,
            precious_metal: item.precious_metal,
            dwt: item.dwt,
            condition: item.condition,
            price: item.price,
            buy_price: item.buy_price,
        })),
        warehouse_id: formData.value.warehouse_id,
        customer_notes: formData.value.customer_notes,
        internal_notes: formData.value.internal_notes,
    };

    // Send payments array
    payload.payments = formData.value.payments.map(p => ({
        method: p.method,
        amount: p.amount,
        details: p.details,
    }));

    router.post('/transactions/buy', payload, {
        preserveState: false,
        preserveScroll: false,
        onFinish: () => {
            isSubmitting.value = false;
        },
        onError: () => {
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <Head title="New In-Store Buy" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-6xl">
                <!-- Progress steps -->
                <nav aria-label="Progress" class="mb-8">
                    <ol role="list" class="flex items-center justify-between">
                        <li v-for="(step, stepIdx) in steps" :key="step.name" class="relative flex-1">
                            <template v-if="currentStep > step.number">
                                <!-- Completed step -->
                                <button
                                    type="button"
                                    @click="goToStep(step.number)"
                                    class="group flex w-full items-center"
                                >
                                    <span class="flex items-center px-6 py-4 text-sm font-medium">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-600 group-hover:bg-indigo-700">
                                            <CheckCircleIcon class="size-6 text-white" />
                                        </span>
                                        <span class="ml-4 text-sm font-medium text-gray-900 dark:text-white">{{ step.name }}</span>
                                    </span>
                                </button>
                                <div v-if="stepIdx !== steps.length - 1" class="absolute right-0 top-0 hidden h-full w-5 md:block" aria-hidden="true">
                                    <svg class="size-full text-gray-300 dark:text-gray-600" viewBox="0 0 22 80" fill="none" preserveAspectRatio="none">
                                        <path d="M0 -2L20 40L0 82" vector-effect="non-scaling-stroke" stroke="currentcolor" stroke-linejoin="round" />
                                    </svg>
                                </div>
                            </template>
                            <template v-else-if="currentStep === step.number">
                                <!-- Current step -->
                                <div class="flex items-center px-6 py-4 text-sm font-medium" aria-current="step">
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-indigo-600">
                                        <component :is="step.icon" class="size-5 text-indigo-600" />
                                    </span>
                                    <span class="ml-4 text-sm font-medium text-indigo-600">{{ step.name }}</span>
                                </div>
                                <div v-if="stepIdx !== steps.length - 1" class="absolute right-0 top-0 hidden h-full w-5 md:block" aria-hidden="true">
                                    <svg class="size-full text-gray-300 dark:text-gray-600" viewBox="0 0 22 80" fill="none" preserveAspectRatio="none">
                                        <path d="M0 -2L20 40L0 82" vector-effect="non-scaling-stroke" stroke="currentcolor" stroke-linejoin="round" />
                                    </svg>
                                </div>
                            </template>
                            <template v-else>
                                <!-- Upcoming step -->
                                <div class="group flex items-center">
                                    <span class="flex items-center px-6 py-4 text-sm font-medium">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 dark:border-gray-600">
                                            <component :is="step.icon" class="size-5 text-gray-500 dark:text-gray-400" />
                                        </span>
                                        <span class="ml-4 text-sm font-medium text-gray-500 dark:text-gray-400">{{ step.name }}</span>
                                    </span>
                                </div>
                                <div v-if="stepIdx !== steps.length - 1" class="absolute right-0 top-0 hidden h-full w-5 md:block" aria-hidden="true">
                                    <svg class="size-full text-gray-300 dark:text-gray-600" viewBox="0 0 22 80" fill="none" preserveAspectRatio="none">
                                        <path d="M0 -2L20 40L0 82" vector-effect="non-scaling-stroke" stroke="currentcolor" stroke-linejoin="round" />
                                    </svg>
                                </div>
                            </template>
                        </li>
                    </ol>
                </nav>

                <!-- Main content area with summary sidebar -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Summary Sidebar -->
                    <div class="lg:col-span-1">
                        <div class="sticky top-4 space-y-4">
                            <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Transaction Summary</h3>

                                <!-- Employee Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Employee</span>
                                        <button
                                            v-if="selectedStoreUser && currentStep !== 1"
                                            type="button"
                                            @click="goToStep(1)"
                                            class="text-indigo-600 hover:text-indigo-500"
                                        >
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <p v-if="selectedStoreUser" class="mt-1 text-sm text-gray-900 dark:text-white">
                                        {{ selectedStoreUser.name }}
                                    </p>
                                    <p v-else class="mt-1 text-sm text-gray-400 dark:text-gray-500 italic">
                                        Not selected
                                    </p>
                                </div>

                                <!-- Customer Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Customer</span>
                                        <button
                                            v-if="(customerDisplayName || formData.customer_id) && currentStep !== 2"
                                            type="button"
                                            @click="goToStep(2)"
                                            class="text-indigo-600 hover:text-indigo-500"
                                        >
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="customerDisplayName || formData.customer_id" class="mt-1">
                                        <p class="text-sm text-gray-900 dark:text-white">
                                            {{ customerDisplayName || 'Existing Customer' }}
                                        </p>
                                        <p v-if="formData.customer?.company_name" class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ formData.customer.company_name }}
                                        </p>
                                        <p v-if="formData.customer?.email" class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ formData.customer.email }}
                                        </p>
                                    </div>
                                    <p v-else class="mt-1 text-sm text-gray-400 dark:text-gray-500 italic">
                                        Not selected
                                    </p>
                                </div>

                                <!-- Items Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Items</span>
                                        <button
                                            v-if="formData.items.length > 0 && currentStep !== 3"
                                            type="button"
                                            @click="goToStep(3)"
                                            class="text-indigo-600 hover:text-indigo-500"
                                        >
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="formData.items.length > 0" class="mt-2 space-y-1">
                                        <div
                                            v-for="item in formData.items.slice(0, 3)"
                                            :key="item.id"
                                            class="flex items-center justify-between text-sm"
                                        >
                                            <span class="truncate text-gray-700 dark:text-gray-300">{{ item.title }}</span>
                                            <span class="ml-2 font-medium text-gray-900 dark:text-white">${{ item.buy_price.toFixed(2) }}</span>
                                        </div>
                                        <p v-if="formData.items.length > 3" class="text-xs text-gray-500 dark:text-gray-400">
                                            +{{ formData.items.length - 3 }} more items
                                        </p>
                                        <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 dark:border-gray-700">
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Total</span>
                                            <span class="text-sm font-bold text-gray-900 dark:text-white">${{ totalBuyPrice.toFixed(2) }}</span>
                                        </div>
                                    </div>
                                    <p v-else class="mt-1 text-sm text-gray-400 dark:text-gray-500 italic">
                                        No items added
                                    </p>
                                </div>

                                <!-- Payments Summary -->
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Payments</span>
                                        <button
                                            v-if="formData.payments.length > 0 && currentStep !== 4"
                                            type="button"
                                            @click="goToStep(4)"
                                            class="text-indigo-600 hover:text-indigo-500"
                                        >
                                            <PencilIcon class="size-3.5" />
                                        </button>
                                    </div>
                                    <div v-if="formData.payments.length > 0" class="mt-2 space-y-1">
                                        <div
                                            v-for="payment in formData.payments"
                                            :key="payment.id"
                                            class="flex items-center justify-between text-sm"
                                        >
                                            <span class="text-gray-700 dark:text-gray-300">{{ getPaymentMethodLabel(payment.method) }}</span>
                                            <span class="font-medium text-gray-900 dark:text-white">${{ payment.amount.toFixed(2) }}</span>
                                        </div>
                                        <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 dark:border-gray-700">
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Total Paid</span>
                                            <span class="text-sm font-bold text-gray-900 dark:text-white">${{ totalPaymentsAmount.toFixed(2) }}</span>
                                        </div>
                                        <div v-if="Math.abs(remainingBalance) > 0.01" class="flex items-center justify-between">
                                            <span class="text-sm font-medium" :class="remainingBalance > 0 ? 'text-red-600' : 'text-green-600'">
                                                {{ remainingBalance > 0 ? 'Remaining' : 'Over' }}
                                            </span>
                                            <span class="text-sm font-bold" :class="remainingBalance > 0 ? 'text-red-600' : 'text-green-600'">
                                                ${{ Math.abs(remainingBalance).toFixed(2) }}
                                            </span>
                                        </div>
                                    </div>
                                    <p v-else class="mt-1 text-sm text-gray-400 dark:text-gray-500 italic">
                                        No payments added
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step content -->
                    <div class="lg:col-span-2">
                        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
                            <div class="px-6 py-8">
                                <!-- Step 1: Select Employee -->
                                <SelectUserStep
                                    v-if="currentStep === 1"
                                    :store-users="storeUsers"
                                    :selected-id="formData.store_user_id"
                                    @select="handleStoreUserSelect"
                                />

                                <!-- Step 2: Customer -->
                                <CustomerStep
                                    v-else-if="currentStep === 2"
                                    :customer-id="formData.customer_id"
                                    :customer="formData.customer"
                                    @update="handleCustomerSelect"
                                />

                                <!-- Step 3: Items -->
                                <ItemsStep
                                    v-else-if="currentStep === 3"
                                    :items="formData.items"
                                    :categories="categories"
                                    :precious-metals="preciousMetals"
                                    :conditions="conditions"
                                    @update="handleItemsUpdate"
                                />

                                <!-- Step 4: Payment -->
                                <div v-else-if="currentStep === 4" class="space-y-6">
                                    <!-- Warehouse Selection -->
                                    <div v-if="warehouses.length > 0" class="rounded-lg border border-gray-200 p-4 dark:border-gray-600">
                                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Transaction Location</h3>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Warehouse / Location</label>
                                            <select
                                                v-model="formData.warehouse_id"
                                                class="mt-1 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option :value="null">No warehouse (use store default)</option>
                                                <option v-for="wh in warehouses" :key="wh.value" :value="wh.value">{{ wh.label }}</option>
                                            </select>
                                            <p v-if="selectedWarehouse?.tax_rate !== null && selectedWarehouse?.tax_rate !== undefined" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                Tax rate: {{ (selectedWarehouse.tax_rate * 100).toFixed(2) }}%
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Payment Step -->
                                    <PaymentStep
                                        :payments="formData.payments"
                                        :customer-notes="formData.customer_notes"
                                        :internal-notes="formData.internal_notes"
                                        :payment-methods="paymentMethods"
                                        :total-amount="totalBuyPrice"
                                        :customer="formData.customer"
                                        @update="handlePaymentUpdate"
                                    />
                                </div>
                            </div>

                            <!-- Footer with navigation -->
                            <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <button
                                        v-if="currentStep > 1"
                                        type="button"
                                        @click="prevStep"
                                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                                    >
                                        <ChevronLeftIcon class="size-4" />
                                        Back
                                    </button>
                                    <div v-else></div>

                                    <div class="flex items-center gap-4">
                                        <span v-if="currentStep === 3 || currentStep === 4" class="text-sm text-gray-500 dark:text-gray-400">
                                            Total: <span class="font-semibold text-gray-900 dark:text-white">${{ totalBuyPrice.toFixed(2) }}</span>
                                        </span>

                                        <button
                                            v-if="currentStep < totalSteps"
                                            type="button"
                                            @click="nextStep"
                                            :disabled="!canProceed"
                                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            Continue
                                            <ChevronRightIcon class="size-4" />
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            @click="submitTransaction"
                                            :disabled="!canProceed || isSubmitting"
                                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ isSubmitting ? 'Creating...' : 'Create Transaction' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
