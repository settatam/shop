<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import LeadSourceSelect from './LeadSourceSelect.vue';

interface Address {
    id: number;
    label?: string;
    address_line1?: string;
    address_line2?: string;
    city?: string;
    state?: string;
    postal_code?: string;
    country?: string;
    is_default?: boolean;
    formatted_address?: string;
}

interface Customer {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone_number: string | null;
    company_name?: string | null;
    address?: string | null;
    address2?: string | null;
    city?: string | null;
    state?: string | null;
    zip?: string | null;
    lead_source_id?: number | null;
    addresses?: Address[];
}

interface Props {
    show: boolean;
    customer: Customer;
    selectedAddressId?: number | null;
    entityType?: 'transaction' | 'memo' | 'repair' | 'order';
    entityId?: number;
}

const props = withDefaults(defineProps<Props>(), {
    show: false,
    selectedAddressId: null,
    entityType: undefined,
    entityId: undefined,
});

const emit = defineEmits<{
    close: [];
    saved: [customer: Customer];
}>();

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone_number: '',
    company_name: '',
    lead_source_id: null as number | null,
    address: '',
    address2: '',
    city: '',
    state: '',
    zip: '',
});

const selectedAddress = ref<number | null>(null);

// Initialize form when customer changes or modal opens
watch(
    () => [props.show, props.customer],
    () => {
        if (props.show && props.customer) {
            form.first_name = props.customer.first_name || '';
            form.last_name = props.customer.last_name || '';
            form.email = props.customer.email || '';
            form.phone_number = props.customer.phone_number || '';
            form.company_name = props.customer.company_name || '';
            form.lead_source_id = props.customer.lead_source_id || null;
            form.address = props.customer.address || '';
            form.address2 = props.customer.address2 || '';
            form.city = props.customer.city || '';
            form.state = props.customer.state || '';
            form.zip = props.customer.zip || '';
            selectedAddress.value = props.selectedAddressId || null;
        }
    },
    { immediate: true }
);

const hasAddresses = computed(() => {
    return props.customer?.addresses && props.customer.addresses.length > 0;
});

const showAddressSelector = computed(() => {
    return hasAddresses.value && props.entityType && props.entityId;
});

const formatAddress = (address: Address): string => {
    if (address.formatted_address) {
        return address.formatted_address;
    }
    const parts = [
        address.address_line1,
        address.address_line2,
        address.city,
        address.state,
        address.postal_code,
    ].filter(Boolean);
    return parts.join(', ') || address.label || 'Address';
};

const close = () => {
    emit('close');
};

const save = () => {
    // Build the URL based on whether we're also updating an entity's address
    let url = `/customers/${props.customer.id}`;
    const data: Record<string, any> = { ...form.data() };

    // If we have entity context and address selector, include the address update
    if (showAddressSelector.value && selectedAddress.value !== props.selectedAddressId) {
        data._update_entity_address = true;
        data._entity_type = props.entityType;
        data._entity_id = props.entityId;
        data._selected_address_id = selectedAddress.value;
    }

    form
        .transform(() => data)
        .put(url, {
            preserveScroll: true,
            onSuccess: () => {
                emit('saved', props.customer);
                close();
            },
        });
};
</script>

<template>
    <TransitionRoot as="template" :show="show">
        <Dialog class="relative z-50" @close="close">
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

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
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
                        <DialogPanel
                            class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800"
                        >
                            <div class="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:bg-gray-800 dark:hover:text-gray-300"
                                    @click="close"
                                >
                                    <span class="sr-only">Close</span>
                                    <XMarkIcon class="size-6" />
                                </button>
                            </div>

                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Edit Customer</h3>

                                <form @submit.prevent="save" class="space-y-4">
                                    <!-- Name fields -->
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label
                                                for="edit_customer_first_name"
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                            >
                                                First Name
                                            </label>
                                            <input
                                                id="edit_customer_first_name"
                                                v-model="form.first_name"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <p v-if="form.errors.first_name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ form.errors.first_name }}
                                            </p>
                                        </div>
                                        <div>
                                            <label
                                                for="edit_customer_last_name"
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                            >
                                                Last Name
                                            </label>
                                            <input
                                                id="edit_customer_last_name"
                                                v-model="form.last_name"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <p v-if="form.errors.last_name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ form.errors.last_name }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Email -->
                                    <div>
                                        <label
                                            for="edit_customer_email"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Email
                                        </label>
                                        <input
                                            id="edit_customer_email"
                                            v-model="form.email"
                                            type="email"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.email }}
                                        </p>
                                    </div>

                                    <!-- Phone -->
                                    <div>
                                        <label
                                            for="edit_customer_phone"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Phone
                                        </label>
                                        <input
                                            id="edit_customer_phone"
                                            v-model="form.phone_number"
                                            type="tel"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.phone_number" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.phone_number }}
                                        </p>
                                    </div>

                                    <!-- Company -->
                                    <div>
                                        <label
                                            for="edit_customer_company"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Company
                                        </label>
                                        <input
                                            id="edit_customer_company"
                                            v-model="form.company_name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.company_name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.company_name }}
                                        </p>
                                    </div>

                                    <!-- Lead Source -->
                                    <div>
                                        <label
                                            for="edit_customer_lead_source"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Lead Source
                                        </label>
                                        <LeadSourceSelect
                                            v-model="form.lead_source_id"
                                            placeholder="Select or create lead source..."
                                            class="mt-1"
                                        />
                                        <p v-if="form.errors.lead_source_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.lead_source_id }}
                                        </p>
                                    </div>

                                    <!-- Address Selection (if entity context provided) -->
                                    <div v-if="showAddressSelector" class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            Address for this {{ entityType }}
                                        </label>
                                        <div class="space-y-2">
                                            <label
                                                v-for="address in customer.addresses"
                                                :key="address.id"
                                                class="flex items-start gap-3 p-3 rounded-md border cursor-pointer transition-colors"
                                                :class="
                                                    selectedAddress === address.id
                                                        ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20 dark:border-indigo-400'
                                                        : 'border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/50'
                                                "
                                            >
                                                <input
                                                    type="radio"
                                                    :value="address.id"
                                                    v-model="selectedAddress"
                                                    class="mt-1 h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                        {{ address.label || 'Address' }}
                                                        <span
                                                            v-if="address.is_default"
                                                            class="ml-2 inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                                        >
                                                            Default
                                                        </span>
                                                    </p>
                                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                                        {{ formatAddress(address) }}
                                                    </p>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Primary Address (shown when no addresses or no entity context) -->
                                    <div v-if="!showAddressSelector" class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Primary Address</h4>

                                        <div class="space-y-4">
                                            <div>
                                                <label
                                                    for="edit_customer_address"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Address
                                                </label>
                                                <input
                                                    id="edit_customer_address"
                                                    v-model="form.address"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>

                                            <div>
                                                <label
                                                    for="edit_customer_address2"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Address Line 2
                                                </label>
                                                <input
                                                    id="edit_customer_address2"
                                                    v-model="form.address2"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>

                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label
                                                        for="edit_customer_city"
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >
                                                        City
                                                    </label>
                                                    <input
                                                        id="edit_customer_city"
                                                        v-model="form.city"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label
                                                        for="edit_customer_state"
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >
                                                        State
                                                    </label>
                                                    <input
                                                        id="edit_customer_state"
                                                        v-model="form.state"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>

                                            <div>
                                                <label
                                                    for="edit_customer_zip"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Postal Code
                                                </label>
                                                <input
                                                    id="edit_customer_zip"
                                                    v-model="form.zip"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex gap-3 justify-end pt-4">
                                        <button
                                            type="button"
                                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            @click="close"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="form.processing"
                                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                        >
                                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
