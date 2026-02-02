<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
    RadioGroup,
    RadioGroupLabel,
    RadioGroupOption,
} from '@headlessui/vue';
import {
    TruckIcon,
    XMarkIcon,
    DocumentTextIcon,
    ArrowTopRightOnSquareIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';
import axios from 'axios';

interface Order {
    id: number;
    order_id: string;
    tracking_number?: string;
    shipping_carrier?: string;
    shipping_address?: {
        name?: string;
        street1?: string;
        street2?: string;
        city?: string;
        state?: string;
        postal_code?: string;
        country?: string;
    };
}

interface Props {
    show: boolean;
    order: Order;
    fedexConfigured?: boolean;
    shipstationConfigured?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    fedexConfigured: false,
    shipstationConfigured: false,
});

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'success'): void;
}>();

type ShippingMethod = 'manual' | 'fedex' | 'shipstation';

const shippingMethod = ref<ShippingMethod>('manual');
const isProcessing = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

// Manual tracking form
const trackingNumber = ref('');
const carrier = ref('fedex');

// FedEx options
const fedexServiceType = ref('FEDEX_GROUND');
const fedexPackagingType = ref('YOUR_PACKAGING');
const packageWeight = ref(1);
const packageLength = ref(12);
const packageWidth = ref(12);
const packageHeight = ref(6);

const carriers = [
    { value: 'fedex', label: 'FedEx' },
    { value: 'ups', label: 'UPS' },
    { value: 'usps', label: 'USPS' },
    { value: 'dhl', label: 'DHL' },
    { value: 'other', label: 'Other' },
];

const fedexServices = [
    { value: 'FEDEX_GROUND', label: 'FedEx Ground' },
    { value: 'FEDEX_2_DAY', label: 'FedEx 2 Day' },
    { value: 'FEDEX_EXPRESS_SAVER', label: 'FedEx Express Saver' },
    { value: 'STANDARD_OVERNIGHT', label: 'Standard Overnight' },
    { value: 'PRIORITY_OVERNIGHT', label: 'Priority Overnight' },
];

const fedexPackaging = [
    { value: 'YOUR_PACKAGING', label: 'Your Packaging' },
    { value: 'FEDEX_ENVELOPE', label: 'FedEx Envelope' },
    { value: 'FEDEX_PAK', label: 'FedEx Pak' },
    { value: 'FEDEX_BOX', label: 'FedEx Box' },
    { value: 'FEDEX_TUBE', label: 'FedEx Tube' },
];

const shippingOptions = computed(() => {
    const options: Array<{ value: ShippingMethod; label: string; description: string; available: boolean }> = [
        {
            value: 'manual',
            label: 'Enter Tracking Number',
            description: 'Manually enter a tracking number from any carrier',
            available: true,
        },
    ];

    if (props.fedexConfigured) {
        options.push({
            value: 'fedex',
            label: 'Create FedEx Label',
            description: 'Generate a FedEx shipping label directly',
            available: true,
        });
    }

    if (props.shipstationConfigured) {
        options.push({
            value: 'shipstation',
            label: 'Push to ShipStation',
            description: 'Send order to ShipStation for fulfillment',
            available: true,
        });
    }

    return options;
});

const hasShippingAddress = computed(() => {
    const addr = props.order.shipping_address;
    return addr && addr.street1 && addr.city && addr.state && addr.postal_code;
});

const canSubmit = computed(() => {
    if (isProcessing.value) return false;

    if (shippingMethod.value === 'manual') {
        return trackingNumber.value.trim().length > 0;
    }

    if (shippingMethod.value === 'fedex') {
        return hasShippingAddress.value && packageWeight.value > 0;
    }

    if (shippingMethod.value === 'shipstation') {
        return true;
    }

    return false;
});

async function submit() {
    if (!canSubmit.value) return;

    isProcessing.value = true;
    error.value = null;
    success.value = null;

    try {
        if (shippingMethod.value === 'manual') {
            await axios.post(`/orders/${props.order.id}/ship`, {
                tracking_number: trackingNumber.value.trim(),
                carrier: carrier.value,
            });
            success.value = 'Order marked as shipped with tracking number.';
        } else if (shippingMethod.value === 'fedex') {
            const response = await axios.post(`/orders/${props.order.id}/create-shipping-label`, {
                carrier: 'fedex',
                service_type: fedexServiceType.value,
                packaging_type: fedexPackagingType.value,
                weight: packageWeight.value,
                length: packageLength.value,
                width: packageWidth.value,
                height: packageHeight.value,
            });
            success.value = `FedEx label created. Tracking: ${response.data.tracking_number}`;
        } else if (shippingMethod.value === 'shipstation') {
            await axios.post(`/orders/${props.order.id}/push-to-shipstation`);
            success.value = 'Order pushed to ShipStation successfully.';
        }

        setTimeout(() => {
            emit('success');
        }, 1500);
    } catch (err: any) {
        error.value = err.response?.data?.message || err.response?.data?.error || 'Failed to process shipping request.';
        isProcessing.value = false;
    }
}

function close() {
    if (!isProcessing.value) {
        emit('close');
    }
}

// Reset form when modal opens
watch(() => props.show, (newVal) => {
    if (newVal) {
        shippingMethod.value = 'manual';
        trackingNumber.value = props.order.tracking_number || '';
        carrier.value = props.order.shipping_carrier || 'fedex';
        error.value = null;
        success.value = null;
        isProcessing.value = false;
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
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg dark:bg-gray-800">
                            <!-- Header -->
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-purple-100 dark:bg-purple-900">
                                        <TruckIcon class="size-5 text-purple-600 dark:text-purple-400" />
                                    </div>
                                    <div>
                                        <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white">
                                            Ship Order
                                        </DialogTitle>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ order.order_id }}
                                        </p>
                                    </div>
                                </div>
                                <button type="button" @click="close" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700">
                                    <XMarkIcon class="size-5" />
                                </button>
                            </div>

                            <!-- Content -->
                            <div class="px-6 py-4">
                                <!-- Error message -->
                                <div v-if="error" class="mb-4 rounded-md bg-red-50 p-4 dark:bg-red-900/50">
                                    <div class="flex">
                                        <ExclamationTriangleIcon class="size-5 text-red-400" />
                                        <p class="ml-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>
                                    </div>
                                </div>

                                <!-- Success message -->
                                <div v-if="success" class="mb-4 rounded-md bg-green-50 p-4 dark:bg-green-900/50">
                                    <div class="flex">
                                        <CheckCircleIcon class="size-5 text-green-400" />
                                        <p class="ml-3 text-sm text-green-700 dark:text-green-300">{{ success }}</p>
                                    </div>
                                </div>

                                <!-- Shipping Method Selection -->
                                <div v-if="!success" class="space-y-4">
                                    <RadioGroup v-model="shippingMethod">
                                        <RadioGroupLabel class="text-sm font-medium text-gray-900 dark:text-white">
                                            Shipping Method
                                        </RadioGroupLabel>
                                        <div class="mt-2 space-y-2">
                                            <RadioGroupOption
                                                v-for="option in shippingOptions"
                                                :key="option.value"
                                                :value="option.value"
                                                :disabled="!option.available"
                                                v-slot="{ checked, disabled }"
                                            >
                                                <div
                                                    :class="[
                                                        'relative flex cursor-pointer rounded-lg border p-4 focus:outline-none',
                                                        checked
                                                            ? 'border-purple-600 bg-purple-50 dark:border-purple-500 dark:bg-purple-900/30'
                                                            : 'border-gray-200 dark:border-gray-700',
                                                        disabled ? 'cursor-not-allowed opacity-50' : 'hover:border-gray-300 dark:hover:border-gray-600',
                                                    ]"
                                                >
                                                    <div class="flex w-full items-center justify-between">
                                                        <div class="flex items-center">
                                                            <div class="text-sm">
                                                                <p :class="[
                                                                    'font-medium',
                                                                    checked ? 'text-purple-900 dark:text-purple-100' : 'text-gray-900 dark:text-white'
                                                                ]">
                                                                    {{ option.label }}
                                                                </p>
                                                                <p :class="[
                                                                    'text-xs',
                                                                    checked ? 'text-purple-700 dark:text-purple-300' : 'text-gray-500 dark:text-gray-400'
                                                                ]">
                                                                    {{ option.description }}
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div v-if="checked" class="shrink-0 text-purple-600 dark:text-purple-400">
                                                            <CheckCircleIcon class="size-5" />
                                                        </div>
                                                    </div>
                                                </div>
                                            </RadioGroupOption>
                                        </div>
                                    </RadioGroup>

                                    <!-- Manual Tracking Form -->
                                    <div v-if="shippingMethod === 'manual'" class="mt-4 space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Carrier</label>
                                            <select
                                                v-model="carrier"
                                                class="mt-1 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option v-for="c in carriers" :key="c.value" :value="c.value">
                                                    {{ c.label }}
                                                </option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tracking Number</label>
                                            <input
                                                v-model="trackingNumber"
                                                type="text"
                                                placeholder="Enter tracking number"
                                                class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                    </div>

                                    <!-- FedEx Label Form -->
                                    <div v-if="shippingMethod === 'fedex'" class="mt-4 space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                        <div v-if="!hasShippingAddress" class="rounded-md bg-yellow-50 p-3 dark:bg-yellow-900/30">
                                            <p class="text-sm text-yellow-700 dark:text-yellow-300">
                                                This order does not have a shipping address. Please add a shipping address before creating a label.
                                            </p>
                                        </div>

                                        <template v-if="hasShippingAddress">
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Service Type</label>
                                                    <select
                                                        v-model="fedexServiceType"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    >
                                                        <option v-for="s in fedexServices" :key="s.value" :value="s.value">
                                                            {{ s.label }}
                                                        </option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Packaging</label>
                                                    <select
                                                        v-model="fedexPackagingType"
                                                        class="mt-1 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    >
                                                        <option v-for="p in fedexPackaging" :key="p.value" :value="p.value">
                                                            {{ p.label }}
                                                        </option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-4 gap-3">
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Weight (lbs)</label>
                                                    <input
                                                        v-model.number="packageWeight"
                                                        type="number"
                                                        min="0.1"
                                                        step="0.1"
                                                        class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Length (in)</label>
                                                    <input
                                                        v-model.number="packageLength"
                                                        type="number"
                                                        min="1"
                                                        class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Width (in)</label>
                                                    <input
                                                        v-model.number="packageWidth"
                                                        type="number"
                                                        min="1"
                                                        class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Height (in)</label>
                                                    <input
                                                        v-model.number="packageHeight"
                                                        type="number"
                                                        min="1"
                                                        class="mt-1 block w-full rounded-md border-0 px-2 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- ShipStation Info -->
                                    <div v-if="shippingMethod === 'shipstation'" class="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                        <div class="flex items-start gap-3">
                                            <ArrowTopRightOnSquareIcon class="size-5 text-gray-400" />
                                            <div>
                                                <p class="text-sm text-gray-700 dark:text-gray-300">
                                                    This will send the order to your ShipStation account for fulfillment.
                                                </p>
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    You can then create shipping labels and manage shipments directly in ShipStation.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                                <button
                                    type="button"
                                    @click="close"
                                    :disabled="isProcessing"
                                    class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                >
                                    Cancel
                                </button>
                                <button
                                    v-if="!success"
                                    type="button"
                                    @click="submit"
                                    :disabled="!canSubmit || isProcessing"
                                    class="inline-flex items-center gap-2 rounded-md bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-500 disabled:opacity-50"
                                >
                                    <TruckIcon v-if="!isProcessing" class="size-4" />
                                    <svg v-else class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{
                                        isProcessing ? 'Processing...' :
                                        shippingMethod === 'manual' ? 'Mark Shipped' :
                                        shippingMethod === 'fedex' ? 'Create Label' :
                                        'Push to ShipStation'
                                    }}
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
