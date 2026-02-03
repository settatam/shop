<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import {
    TruckIcon,
    PrinterIcon,
    ArrowUturnLeftIcon,
    MapPinIcon,
    ChevronDownIcon,
} from '@heroicons/vue/20/solid';

interface ShippingLabel {
    id: number;
    tracking_number: string | null;
    carrier: string;
    service_type: string | null;
    status: string;
    shipping_cost: number | null;
    tracking_url: string | null;
    created_at: string;
}

interface ShippingOptions {
    service_types: Record<string, string>;
    packaging_types: Record<string, string>;
    default_package: {
        weight: number;
        length: number;
        width: number;
        height: number;
    };
    is_configured: boolean;
}

interface CustomerAddress {
    id: number;
    full_name: string;
    company: string | null;
    address: string;
    address2: string | null;
    city: string;
    state_id: number | null;
    country_id: number | null;
    zip: string;
    phone: string | null;
    is_default: boolean;
    is_shipping: boolean;
    one_line_address: string;
    is_valid_for_shipping: boolean;
}

interface ShippingAddress {
    id: number;
    full_name: string;
    company: string | null;
    address: string;
    address2: string | null;
    city: string;
    state_id: number | null;
    country_id: number | null;
    zip: string;
    phone: string | null;
    one_line_address: string;
}

interface CustomerInfo {
    full_name: string;
    address: string | null;
    address2: string | null;
    city: string | null;
    zip: string | null;
    phone_number: string | null;
    has_addresses: boolean;
}

interface Props {
    transactionId: number;
    outboundLabel: ShippingLabel | null;
    returnLabel: ShippingLabel | null;
    canCreateOutbound: boolean;
    canCreateReturn: boolean;
    shippingOptions?: ShippingOptions;
    customerAddresses?: CustomerAddress[];
    shippingAddress?: ShippingAddress | null;
    shippingAddressId?: number | null;
    customer?: CustomerInfo | null;
}

const props = defineProps<Props>();

// Loading states
const creatingOutboundLabel = ref(false);
const creatingReturnLabel = ref(false);
const showAddressDropdown = ref(false);

// Modal state and form
const showShippingLabelModal = ref(false);
const shippingLabelType = ref<'outbound' | 'return'>('outbound');

const shippingLabelForm = useForm({
    service_type: 'FEDEX_2_DAY',
    packaging_type: 'FEDEX_ENVELOPE',
    weight: props.shippingOptions?.default_package?.weight || 1,
    length: props.shippingOptions?.default_package?.length || 12,
    width: props.shippingOptions?.default_package?.width || 12,
    height: props.shippingOptions?.default_package?.height || 6,
});

// Computed: get the current shipping address display
const currentShippingAddressDisplay = computed(() => {
    if (props.shippingAddress) {
        return props.shippingAddress.one_line_address;
    }
    // Fall back to customer's direct address fields
    if (props.customer?.address) {
        const parts = [
            props.customer.address,
            props.customer.address2,
            props.customer.city,
            props.customer.zip,
        ].filter(Boolean);
        return parts.join(', ');
    }
    return 'No address set';
});

const hasValidShippingAddress = computed(() => {
    if (props.shippingAddress) {
        return true;
    }
    // Check customer has at least address, city, zip
    return !!(props.customer?.address && props.customer?.city && props.customer?.zip);
});

const hasPhoneNumber = computed(() => {
    // Check shipping address phone first
    if (props.shippingAddress?.phone) {
        return true;
    }
    // Check selected customer address
    if (props.shippingAddressId) {
        const selectedAddress = props.customerAddresses?.find(a => a.id === props.shippingAddressId);
        if (selectedAddress?.phone) {
            return true;
        }
    }
    // Fall back to customer phone
    return !!props.customer?.phone_number;
});

const canCreateLabel = computed(() => {
    return hasValidShippingAddress.value && hasPhoneNumber.value;
});

function selectShippingAddress(addressId: number | null) {
    router.put(`/transactions/${props.transactionId}/shipping-address`, {
        shipping_address_id: addressId,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showAddressDropdown.value = false;
        },
    });
}

function openShippingLabelModal(type: 'outbound' | 'return') {
    shippingLabelType.value = type;
    shippingLabelForm.service_type = 'FEDEX_2_DAY';
    shippingLabelForm.packaging_type = 'FEDEX_ENVELOPE';
    shippingLabelForm.weight = props.shippingOptions?.default_package?.weight || 1;
    shippingLabelForm.length = props.shippingOptions?.default_package?.length || 12;
    shippingLabelForm.width = props.shippingOptions?.default_package?.width || 12;
    shippingLabelForm.height = props.shippingOptions?.default_package?.height || 6;
    showShippingLabelModal.value = true;
}

function submitShippingLabel() {
    const url = shippingLabelType.value === 'outbound'
        ? `/transactions/${props.transactionId}/create-outbound-label`
        : `/transactions/${props.transactionId}/create-return-label`;

    if (shippingLabelType.value === 'outbound') {
        creatingOutboundLabel.value = true;
    } else {
        creatingReturnLabel.value = true;
    }

    shippingLabelForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            showShippingLabelModal.value = false;
        },
        onFinish: () => {
            creatingOutboundLabel.value = false;
            creatingReturnLabel.value = false;
        },
    });
}

function printOutboundLabel() {
    window.open(`/transactions/${props.transactionId}/print-outbound-label`, '_blank');
}

function printReturnLabel() {
    window.open(`/transactions/${props.transactionId}/print-return-label`, '_blank');
}

const formatCurrency = (value: number | null) => {
    if (value === null || value === undefined) return '-';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const statusBadgeClass = (status: string) => {
    if (status === 'delivered') {
        return 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-400';
    }
    if (status === 'in_transit') {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
    }
    return 'bg-gray-100 text-gray-800 dark:bg-gray-500/10 dark:text-gray-400';
};
</script>

<template>
    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="px-4 py-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Shipping Labels</h3>

            <!-- Shipping Address Section -->
            <div class="mb-4 p-3 rounded-lg bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-start gap-2 flex-1 min-w-0">
                        <MapPinIcon class="size-5 text-gray-400 shrink-0 mt-0.5" />
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Ship To</p>
                            <p class="text-sm text-gray-900 dark:text-white truncate">
                                {{ currentShippingAddressDisplay }}
                            </p>
                            <p v-if="!hasValidShippingAddress" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                Missing address information
                            </p>
                        </div>
                    </div>

                    <!-- Address Dropdown -->
                    <div v-if="customerAddresses && customerAddresses.length > 0" class="relative">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md bg-white px-2 py-1 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-600 dark:text-gray-200 dark:ring-gray-500"
                            @click="showAddressDropdown = !showAddressDropdown"
                        >
                            Change
                            <ChevronDownIcon class="size-4" />
                        </button>

                        <!-- Dropdown Menu -->
                        <div
                            v-if="showAddressDropdown"
                            class="absolute right-0 z-10 mt-2 w-72 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none dark:bg-gray-700 dark:ring-gray-600"
                        >
                            <div class="py-1">
                                <!-- Use Customer's Default -->
                                <button
                                    v-if="customer?.address"
                                    type="button"
                                    class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                    :class="{ 'bg-indigo-50 dark:bg-indigo-900/30': !shippingAddressId }"
                                    @click="selectShippingAddress(null)"
                                >
                                    <span class="font-medium">Use customer's address</span>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                        {{ customer.address }}, {{ customer.city }}
                                    </p>
                                </button>

                                <!-- Saved Addresses -->
                                <button
                                    v-for="addr in customerAddresses"
                                    :key="addr.id"
                                    type="button"
                                    class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                    :class="{
                                        'bg-indigo-50 dark:bg-indigo-900/30': shippingAddressId === addr.id,
                                        'opacity-50': !addr.is_valid_for_shipping,
                                    }"
                                    @click="selectShippingAddress(addr.id)"
                                >
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ addr.full_name || 'No Name' }}</span>
                                        <span v-if="addr.is_default" class="text-xs px-1.5 py-0.5 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                                            Default
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                        {{ addr.one_line_address }}
                                    </p>
                                    <p v-if="!addr.is_valid_for_shipping" class="text-xs text-amber-600 dark:text-amber-400">
                                        Incomplete address
                                    </p>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Outbound Label Card -->
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <TruckIcon class="size-5 text-indigo-600 dark:text-indigo-400" />
                        <h4 class="text-sm font-medium text-gray-900 dark:text-white">Outbound Label</h4>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Kit shipped to customer</p>

                    <div v-if="outboundLabel" class="space-y-3">
                        <!-- Tracking Info -->
                        <div class="flex items-center justify-between">
                            <a
                                v-if="outboundLabel.tracking_url"
                                :href="outboundLabel.tracking_url"
                                target="_blank"
                                class="text-sm font-mono text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                            >
                                {{ outboundLabel.tracking_number }}
                            </a>
                            <span v-else class="text-sm font-mono text-gray-900 dark:text-white">
                                {{ outboundLabel.tracking_number || 'N/A' }}
                            </span>
                            <span :class="[statusBadgeClass(outboundLabel.status), 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium']">
                                {{ outboundLabel.status }}
                            </span>
                        </div>

                        <!-- Cost -->
                        <p v-if="outboundLabel.shipping_cost" class="text-xs text-gray-500 dark:text-gray-400">
                            Cost: {{ formatCurrency(outboundLabel.shipping_cost) }}
                        </p>

                        <!-- Print Button -->
                        <button
                            type="button"
                            class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-600"
                            @click="printOutboundLabel"
                        >
                            <PrinterIcon class="size-4" />
                            Print Label
                        </button>
                    </div>

                    <!-- Create Button -->
                    <div v-else-if="canCreateOutbound" class="space-y-2">
                        <p class="text-sm text-gray-500 dark:text-gray-400">No label created yet</p>
                        <button
                            type="button"
                            :disabled="creatingOutboundLabel || !canCreateLabel"
                            class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                            @click="openShippingLabelModal('outbound')"
                        >
                            <TruckIcon class="size-4" />
                            {{ creatingOutboundLabel ? 'Creating...' : 'Create Label' }}
                        </button>
                        <p v-if="!hasValidShippingAddress" class="text-xs text-amber-600 dark:text-amber-400">
                            Set shipping address first
                        </p>
                        <p v-else-if="!hasPhoneNumber" class="text-xs text-amber-600 dark:text-amber-400">
                            Phone number required for shipping
                        </p>
                    </div>

                    <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">Not available</p>
                </div>

                <!-- Return Label Card -->
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <ArrowUturnLeftIcon class="size-5 text-amber-600 dark:text-amber-400" />
                        <h4 class="text-sm font-medium text-gray-900 dark:text-white">Return Label</h4>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Customer ships items back</p>

                    <div v-if="returnLabel" class="space-y-3">
                        <!-- Tracking Info -->
                        <div class="flex items-center justify-between">
                            <a
                                v-if="returnLabel.tracking_url"
                                :href="returnLabel.tracking_url"
                                target="_blank"
                                class="text-sm font-mono text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                            >
                                {{ returnLabel.tracking_number }}
                            </a>
                            <span v-else class="text-sm font-mono text-gray-900 dark:text-white">
                                {{ returnLabel.tracking_number || 'N/A' }}
                            </span>
                            <span :class="[statusBadgeClass(returnLabel.status), 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium']">
                                {{ returnLabel.status }}
                            </span>
                        </div>

                        <!-- Cost -->
                        <p v-if="returnLabel.shipping_cost" class="text-xs text-gray-500 dark:text-gray-400">
                            Cost: {{ formatCurrency(returnLabel.shipping_cost) }}
                        </p>

                        <!-- Print Button -->
                        <button
                            type="button"
                            class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-600"
                            @click="printReturnLabel"
                        >
                            <PrinterIcon class="size-4" />
                            Print Label
                        </button>
                    </div>

                    <!-- Create Button -->
                    <div v-else-if="canCreateReturn" class="space-y-2">
                        <p class="text-sm text-gray-500 dark:text-gray-400">No label created yet</p>
                        <button
                            type="button"
                            :disabled="creatingReturnLabel || !canCreateLabel"
                            class="inline-flex items-center gap-x-1.5 rounded-md bg-amber-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-amber-500 disabled:opacity-50"
                            @click="openShippingLabelModal('return')"
                        >
                            <ArrowUturnLeftIcon class="size-4" />
                            {{ creatingReturnLabel ? 'Creating...' : 'Create Return Label' }}
                        </button>
                        <p v-if="!hasValidShippingAddress" class="text-xs text-amber-600 dark:text-amber-400">
                            Set shipping address first
                        </p>
                        <p v-else-if="!hasPhoneNumber" class="text-xs text-amber-600 dark:text-amber-400">
                            Phone number required for shipping
                        </p>
                    </div>

                    <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">Not applicable</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Shipping Label Modal -->
    <Teleport to="body">
        <div v-if="showShippingLabelModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showShippingLabelModal = false" />
                <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        Create {{ shippingLabelType === 'outbound' ? 'Outbound' : 'Return' }} Shipping Label
                    </h3>

                    <form @submit.prevent="submitShippingLabel">
                        <div class="space-y-4">
                            <!-- Service Type -->
                            <div>
                                <label for="service_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Service Type
                                </label>
                                <select
                                    id="service_type"
                                    v-model="shippingLabelForm.service_type"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option v-for="(label, value) in shippingOptions?.service_types" :key="value" :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>

                            <!-- Packaging Type -->
                            <div>
                                <label for="packaging_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Packaging Type
                                </label>
                                <select
                                    id="packaging_type"
                                    v-model="shippingLabelForm.packaging_type"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option v-for="(label, value) in shippingOptions?.packaging_types" :key="value" :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>

                            <!-- Package Dimensions Grid -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="weight" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Weight (lbs)
                                    </label>
                                    <input
                                        id="weight"
                                        v-model.number="shippingLabelForm.weight"
                                        type="number"
                                        step="0.1"
                                        min="0.1"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label for="length" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Length (in)
                                    </label>
                                    <input
                                        id="length"
                                        v-model.number="shippingLabelForm.length"
                                        type="number"
                                        step="1"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label for="width" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Width (in)
                                    </label>
                                    <input
                                        id="width"
                                        v-model.number="shippingLabelForm.width"
                                        type="number"
                                        step="1"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label for="height" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Height (in)
                                    </label>
                                    <input
                                        id="height"
                                        v-model.number="shippingLabelForm.height"
                                        type="number"
                                        step="1"
                                        min="1"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 flex gap-3 justify-end">
                            <button
                                type="button"
                                class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                @click="showShippingLabelModal = false"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="shippingLabelForm.processing"
                                :class="[
                                    shippingLabelType === 'outbound' ? 'bg-indigo-600 hover:bg-indigo-500' : 'bg-amber-600 hover:bg-amber-500',
                                    'rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm disabled:opacity-50'
                                ]"
                            >
                                {{ shippingLabelForm.processing ? 'Creating...' : 'Create Label' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </Teleport>
</template>
