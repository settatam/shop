<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { US_STATES } from '@/lib/states';
import { formatPhoneNumber } from '@/lib/utils';
import { UserIcon, PencilIcon, BanknotesIcon, ChevronDownIcon, ChevronUpIcon, MapPinIcon, PlusIcon } from '@heroicons/vue/24/outline';

interface LeadSource {
    id: number;
    name: string;
}

interface Address {
    id: number;
    nickname?: string | null;
    type?: string;
    address?: string | null;
    address_line1?: string;
    address2?: string | null;
    address_line2?: string;
    city?: string | null;
    state?: string;
    state_abbreviation?: string | null;
    zip?: string | null;
    postal_code?: string;
    phone?: string | null;
    is_default?: boolean;
    one_line_address?: string;
    formatted_address?: string;
}

interface Customer {
    id: number;
    full_name?: string;
    first_name?: string;
    last_name?: string;
    display_name?: string;
    company_name?: string | null;
    email?: string | null;
    phone?: string | null;
    phone_number?: string | null;
    address?: string | null;
    city?: string | null;
    state?: string | null;
    zip?: string | null;
    primary_address?: { address: string | null; address2?: string | null; city: string | null; state_abbreviation: string | null; zip: string | null; one_line_address: string } | null;
    addresses?: Address[];
    lead_source?: LeadSource | null;
    leadSource?: LeadSource | null;
    store_credit_balance?: number | null;
}

interface Props {
    customer: Customer;
    showEditButton?: boolean;
    showViewLink?: boolean;
    compact?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEditButton: false,
    showViewLink: true,
    compact: false,
});

const emit = defineEmits<{
    edit: [];
}>();

// Addresses expand/collapse
const showAddresses = ref(false);
const expandedAddressId = ref<number | null>(null);
const savingAddressId = ref<number | null>(null);

interface AddressFormData {
    address: string;
    address2: string;
    city: string;
    state: string;
    zip: string;
    phone: string;
    is_default: boolean;
}

const addressForms = ref<Record<number, AddressFormData>>({});

const customerName = computed(() => {
    if (props.customer.full_name) return props.customer.full_name;
    if (props.customer.display_name) return props.customer.display_name;
    const first = props.customer.first_name || '';
    const last = props.customer.last_name || '';
    return `${first} ${last}`.trim() || 'Unknown Customer';
});

const customerPhone = computed(() => {
    return props.customer.phone || props.customer.phone_number || null;
});

const addresses = computed(() => {
    return props.customer.addresses ?? [];
});

const defaultAddress = computed(() => {
    if (addresses.value.length > 0) {
        return addresses.value.find(a => a.is_default) || addresses.value[0];
    }
    return null;
});

const addressLines = computed(() => {
    if (addresses.value.length > 0) {
        const addr = defaultAddress.value;
        if (!addr) return [];
        const street = addr.address || addr.address_line1;
        const cityStateZip = [addr.city, addr.state_abbreviation || addr.state, addr.zip || addr.postal_code].filter(Boolean).join(', ');
        return [street, addr.address2 || addr.address_line2, cityStateZip].filter(Boolean) as string[];
    }
    const pa = props.customer.primary_address;
    if (pa) {
        const cityStateZip = [pa.city, pa.state_abbreviation, pa.zip].filter(Boolean).join(', ');
        return [pa.address, pa.address2, cityStateZip].filter(Boolean) as string[];
    }
    return [];
});

const leadSource = computed(() => {
    return props.customer.leadSource || props.customer.lead_source || null;
});

const storeCreditBalance = computed(() => {
    return props.customer.store_credit_balance ?? 0;
});

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
}

const getAddressLabel = (address: Address): string => {
    if (address.nickname) return address.nickname;
    if (address.type) return address.type.charAt(0).toUpperCase() + address.type.slice(1);
    return 'Address';
};

const getAddressSummary = (address: Address): string => {
    if (address.one_line_address) return address.one_line_address;
    if (address.formatted_address) return address.formatted_address.replace(/\n/g, ', ');
    const parts = [
        address.address || address.address_line1,
        address.city,
        address.state_abbreviation || address.state,
        address.zip || address.postal_code,
    ].filter(Boolean);
    return parts.join(', ') || 'Address';
};

const toggleAddresses = () => {
    showAddresses.value = !showAddresses.value;
    if (showAddresses.value) {
        initAddressForms();
    }
};

const toggleAddress = (addressId: number) => {
    if (expandedAddressId.value === addressId) {
        expandedAddressId.value = null;
    } else {
        expandedAddressId.value = addressId;
        initAddressForms();
    }
};

const initAddressForms = () => {
    const forms: Record<number, AddressFormData> = {};
    for (const addr of addresses.value) {
        forms[addr.id] = {
            address: addr.address || addr.address_line1 || '',
            address2: addr.address2 || addr.address_line2 || '',
            city: addr.city || '',
            state: addr.state_abbreviation || addr.state || '',
            zip: addr.zip || addr.postal_code || '',
            phone: addr.phone || '',
            is_default: addr.is_default || false,
        };
    }
    addressForms.value = forms;
};

const saveAddress = (address: Address) => {
    const addrForm = addressForms.value[address.id];
    if (!addrForm) return;

    savingAddressId.value = address.id;

    router.put(`/customers/${props.customer.id}/addresses/${address.id}`, {
        nickname: address.nickname || '',
        first_name: props.customer.first_name || '',
        last_name: props.customer.last_name || '',
        address: addrForm.address,
        address2: addrForm.address2,
        city: addrForm.city,
        state: addrForm.state,
        zip: addrForm.zip,
        phone: addrForm.phone,
        type: address.type || 'home',
        is_default: addrForm.is_default,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            savingAddressId.value = null;
            expandedAddressId.value = null;
        },
        onError: () => {
            savingAddressId.value = null;
        },
    });
};

// New address form
const showNewAddressForm = ref(false);
const savingNewAddress = ref(false);
const newAddressForm = ref<AddressFormData>({
    address: '',
    address2: '',
    city: '',
    state: '',
    zip: '',
    phone: '',
    is_default: false,
});

const resetNewAddressForm = () => {
    newAddressForm.value = {
        address: '',
        address2: '',
        city: '',
        state: '',
        zip: '',
        phone: '',
        is_default: false,
    };
};

const openNewAddressForm = () => {
    showAddresses.value = true;
    expandedAddressId.value = null;
    resetNewAddressForm();
    // Auto-set as default if no addresses exist
    if (addresses.value.length === 0) {
        newAddressForm.value.is_default = true;
    }
    showNewAddressForm.value = true;
};

const saveNewAddress = () => {
    savingNewAddress.value = true;

    router.post(`/customers/${props.customer.id}/addresses`, {
        first_name: props.customer.first_name || '',
        last_name: props.customer.last_name || '',
        address: newAddressForm.value.address,
        address2: newAddressForm.value.address2,
        city: newAddressForm.value.city,
        state: newAddressForm.value.state,
        zip: newAddressForm.value.zip,
        phone: newAddressForm.value.phone,
        type: 'home',
        is_default: newAddressForm.value.is_default,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            savingNewAddress.value = false;
            showNewAddressForm.value = false;
            resetNewAddressForm();
        },
        onError: () => {
            savingNewAddress.value = false;
        },
    });
};
</script>

<template>
    <div>
        <div class="flex items-start gap-3">
            <!-- Avatar -->
            <div
                :class="[
                    'flex shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900',
                    compact ? 'size-10' : 'size-12'
                ]"
            >
                <UserIcon :class="[compact ? 'size-5' : 'size-6', 'text-indigo-600 dark:text-indigo-400']" />
            </div>

            <!-- Customer Info -->
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <Link
                        v-if="showViewLink"
                        :href="`/customers/${customer.id}`"
                        class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400 truncate"
                    >
                        {{ customerName }}
                    </Link>
                    <span v-else class="font-medium text-gray-900 dark:text-white truncate">
                        {{ customerName }}
                    </span>

                    <button
                        v-if="showEditButton"
                        type="button"
                        class="ml-auto shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                        @click="emit('edit')"
                    >
                        <PencilIcon class="size-4" />
                    </button>
                </div>

                <p v-if="customer.company_name" class="text-sm text-gray-500 dark:text-gray-400 truncate">
                    {{ customer.company_name }}
                </p>
                <div v-if="addressLines.length" class="text-sm text-gray-500 dark:text-gray-400">
                    <p v-for="(line, i) in addressLines" :key="i">{{ line }}</p>
                </div>
                <p v-if="customerPhone" class="text-sm text-gray-500 dark:text-gray-400">
                    {{ customerPhone }}
                </p>
                <p v-if="customer.email" class="text-sm text-gray-500 dark:text-gray-400 truncate">
                    {{ customer.email }}
                </p>

                <!-- Lead Source -->
                <div v-if="!compact" class="mt-2 flex items-center gap-2">
                    <span class="text-xs text-gray-500 dark:text-gray-400">Lead Source:</span>
                    <span
                        v-if="leadSource"
                        class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30"
                    >
                        {{ leadSource.name }}
                    </span>
                    <span v-else class="text-xs text-gray-400 dark:text-gray-500 italic">
                        Unknown
                    </span>
                </div>

                <!-- Store Credit -->
                <div v-if="!compact && storeCreditBalance > 0" class="mt-2">
                    <Link
                        :href="`/customers/${customer.id}/store-credits`"
                        class="inline-flex items-center gap-1.5 rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 hover:bg-green-100 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/20 dark:hover:bg-green-400/20"
                    >
                        <BanknotesIcon class="size-3.5" />
                        Store Credit: {{ formatCurrency(storeCreditBalance) }}
                    </Link>
                </div>
            </div>
        </div>

        <!-- Expandable Addresses Section -->
        <div v-if="!compact" class="mt-3">
            <div class="flex items-center">
                <button
                    v-if="addresses.length > 0"
                    type="button"
                    class="flex flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                    @click="toggleAddresses"
                >
                    <MapPinIcon class="size-3.5" />
                    <span>{{ addresses.length }} {{ addresses.length === 1 ? 'Address' : 'Addresses' }}</span>
                    <ChevronUpIcon v-if="showAddresses" class="ml-auto size-3.5" />
                    <ChevronDownIcon v-else class="ml-auto size-3.5" />
                </button>
                <button
                    type="button"
                    class="shrink-0 rounded-md px-2 py-1.5 text-xs font-medium text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-900/30"
                    @click="openNewAddressForm"
                >
                    <PlusIcon class="inline size-3.5 -mt-0.5" />
                    Add Address
                </button>
            </div>

            <div v-if="showAddresses" class="mt-2 space-y-2">
                <!-- Existing addresses -->
                <div
                    v-for="address in addresses"
                    :key="address.id"
                    class="rounded-md border transition-colors"
                    :class="address.is_default
                        ? 'border-indigo-200 dark:border-indigo-800'
                        : 'border-gray-200 dark:border-gray-700'"
                >
                    <!-- Address header row -->
                    <div
                        class="flex cursor-pointer items-center gap-2 px-3 py-2"
                        @click="toggleAddress(address.id)"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium text-gray-900 dark:text-white">
                                    {{ getAddressLabel(address) }}
                                </span>
                                <span
                                    v-if="address.is_default"
                                    class="inline-flex items-center rounded-full bg-green-50 px-1.5 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                >
                                    Default
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                {{ getAddressSummary(address) }}
                            </p>
                        </div>
                        <ChevronUpIcon v-if="expandedAddressId === address.id" class="size-3.5 shrink-0 text-gray-400" />
                        <ChevronDownIcon v-else class="size-3.5 shrink-0 text-gray-400" />
                    </div>

                    <!-- Expanded edit form -->
                    <div
                        v-if="expandedAddressId === address.id && addressForms[address.id]"
                        class="border-t border-gray-200 px-3 pb-3 pt-3 space-y-3 dark:border-gray-700"
                    >
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Address</label>
                            <input
                                v-model="addressForms[address.id].address"
                                type="text"
                                class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Address Line 2</label>
                            <input
                                v-model="addressForms[address.id].address2"
                                type="text"
                                class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                        <div class="grid grid-cols-6 gap-2">
                            <div class="col-span-3">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">City</label>
                                <input
                                    v-model="addressForms[address.id].city"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="col-span-1">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">State</label>
                                <select
                                    v-model="addressForms[address.id].state"
                                    class="mt-1 block w-full rounded-md border-0 px-1 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option value="">-</option>
                                    <option v-for="s in US_STATES" :key="s.value" :value="s.value">{{ s.label }}</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">ZIP</label>
                                <input
                                    v-model="addressForms[address.id].zip"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Phone</label>
                            <input
                                :value="addressForms[address.id].phone"
                                type="tel"
                                placeholder="(555) 123-4567"
                                @input="addressForms[address.id].phone = formatPhoneNumber(($event.target as HTMLInputElement).value)"
                                class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                        <div class="flex items-center justify-between pt-1">
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="addressForms[address.id].is_default"
                                    type="checkbox"
                                    class="size-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                />
                                <span class="text-xs text-gray-700 dark:text-gray-300">Default address</span>
                            </label>
                            <button
                                type="button"
                                :disabled="savingAddressId === address.id"
                                class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                @click="saveAddress(address)"
                            >
                                {{ savingAddressId === address.id ? 'Saving...' : 'Update' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- New address form -->
                <div
                    v-if="showNewAddressForm"
                    class="rounded-md border border-dashed border-indigo-300 dark:border-indigo-700"
                >
                    <div class="px-3 py-2">
                        <span class="text-xs font-medium text-indigo-600 dark:text-indigo-400">New Address</span>
                    </div>
                    <div class="border-t border-gray-200 px-3 pb-3 pt-3 space-y-3 dark:border-gray-700">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Address *</label>
                            <input
                                v-model="newAddressForm.address"
                                type="text"
                                class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Address Line 2</label>
                            <input
                                v-model="newAddressForm.address2"
                                type="text"
                                class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                        <div class="grid grid-cols-6 gap-2">
                            <div class="col-span-3">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">City *</label>
                                <input
                                    v-model="newAddressForm.city"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="col-span-1">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">State</label>
                                <select
                                    v-model="newAddressForm.state"
                                    class="mt-1 block w-full rounded-md border-0 px-1 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option value="">-</option>
                                    <option v-for="s in US_STATES" :key="s.value" :value="s.value">{{ s.label }}</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">ZIP *</label>
                                <input
                                    v-model="newAddressForm.zip"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">Phone</label>
                            <input
                                :value="newAddressForm.phone"
                                type="tel"
                                placeholder="(555) 123-4567"
                                @input="newAddressForm.phone = formatPhoneNumber(($event.target as HTMLInputElement).value)"
                                class="mt-1 block w-full rounded-md border-0 px-2.5 py-1 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            />
                        </div>
                        <div class="flex items-center justify-between pt-1">
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="newAddressForm.is_default"
                                    type="checkbox"
                                    class="size-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                />
                                <span class="text-xs text-gray-700 dark:text-gray-300">Default address</span>
                            </label>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="rounded-md px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                                    @click="showNewAddressForm = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="savingNewAddress || !newAddressForm.address || !newAddressForm.city || !newAddressForm.zip"
                                    class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                    @click="saveNewAddress"
                                >
                                    {{ savingNewAddress ? 'Saving...' : 'Add' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
