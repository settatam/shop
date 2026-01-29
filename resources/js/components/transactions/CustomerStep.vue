<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import {
    Combobox,
    ComboboxInput,
    ComboboxButton,
    ComboboxOptions,
    ComboboxOption,
} from '@headlessui/vue';
import {
    MagnifyingGlassIcon,
    CheckIcon,
    ChevronUpDownIcon,
    PlusIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';
import { UserIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
import LeadSourceSelect from '@/components/customers/LeadSourceSelect.vue';

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
    lead_source_id?: number | null;
}

interface Props {
    customerId: number | null;
    customer: Customer | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    update: [customer: Customer | null, customerId: number | null];
}>();

// State
const mode = ref<'search' | 'create'>('search');
const query = ref('');
const searchResults = ref<Customer[]>([]);
const isLoading = ref(false);
const selectedExisting = ref<Customer | null>(null);

// New customer form
const newCustomer = ref<Customer>({
    first_name: '',
    last_name: '',
    company_name: '',
    email: '',
    phone_number: '',
    address: '',
    address2: '',
    city: '',
    state: '',
    state_id: undefined,
    zip: '',
    country_id: undefined,
    lead_source_id: null,
});

// Initialize from props
if (props.customerId && !props.customer) {
    // Existing customer selected - we'd need to fetch it
    mode.value = 'search';
} else if (props.customer && !props.customer.id) {
    // New customer being created
    mode.value = 'create';
    newCustomer.value = { ...props.customer };
}

const search = useDebounceFn(async (searchQuery: string) => {
    if (!searchQuery || searchQuery.length < 1) {
        searchResults.value = [];
        return;
    }

    isLoading.value = true;

    try {
        const response = await axios.get('/api/v1/customers', {
            params: { q: searchQuery, limit: 10 },
        });
        searchResults.value = response.data.data;
    } catch (err) {
        searchResults.value = [];
    } finally {
        isLoading.value = false;
    }
}, 300);

watch(query, (value) => {
    search(value);
});

const displayValue = (customer: Customer | null): string => {
    if (!customer) return '';
    return customer.full_name || `${customer.first_name} ${customer.last_name}`.trim() || customer.email || '';
};

function selectCustomer(customer: Customer | null) {
    if (customer && 'isCreateOption' in customer) {
        // Switch to create mode
        mode.value = 'create';
        const parts = query.value.trim().split(' ');
        newCustomer.value.first_name = parts[0] || '';
        newCustomer.value.last_name = parts.slice(1).join(' ') || '';
        emit('update', newCustomer.value, null);
    } else if (customer) {
        selectedExisting.value = customer;
        // Pass both the customer object and ID so address data is available for prefilling
        emit('update', customer, customer.id!);
    }
}

function clearSelection() {
    selectedExisting.value = null;
    query.value = '';
    emit('update', null, null);
}

function switchToCreate() {
    mode.value = 'create';
    selectedExisting.value = null;
    emit('update', newCustomer.value, null);
}

function switchToSearch() {
    mode.value = 'search';
    newCustomer.value = {
        first_name: '',
        last_name: '',
        company_name: '',
        email: '',
        phone_number: '',
        address: '',
        address2: '',
        city: '',
        state: '',
        zip: '',
        lead_source_id: null,
    };
    emit('update', null, null);
}

function updateNewCustomer() {
    emit('update', newCustomer.value, null);
}

// Create option marker
const createOption = { isCreateOption: true, full_name: 'Create new customer' };

const filteredOptions = computed(() => {
    const results = [...searchResults.value];
    if (query.value.length > 0) {
        results.push(createOption as any);
    }
    return results;
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                    Customer Information
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Search for an existing customer or create a new one.
                </p>
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    @click="switchToSearch"
                    :class="[
                        'rounded-md px-3 py-1.5 text-sm font-medium',
                        mode === 'search'
                            ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300'
                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300',
                    ]"
                >
                    Search
                </button>
                <button
                    type="button"
                    @click="switchToCreate"
                    :class="[
                        'rounded-md px-3 py-1.5 text-sm font-medium',
                        mode === 'create'
                            ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300'
                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300',
                    ]"
                >
                    Create New
                </button>
            </div>
        </div>

        <!-- Search Mode -->
        <template v-if="mode === 'search'">
            <!-- Selected customer display -->
            <div
                v-if="selectedExisting"
                class="flex items-center justify-between rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-700"
            >
                <div class="flex items-center gap-3">
                    <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                        <UserIcon class="size-6 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <div>
                        <p class="text-base font-medium text-gray-900 dark:text-white">
                            {{ selectedExisting.full_name || `${selectedExisting.first_name} ${selectedExisting.last_name}` }}
                        </p>
                        <p v-if="selectedExisting.email || selectedExisting.phone_number" class="text-sm text-gray-500 dark:text-gray-400">
                            {{ selectedExisting.email || selectedExisting.phone_number }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-600"
                    @click="clearSelection"
                >
                    <XMarkIcon class="size-5" />
                </button>
            </div>

            <!-- Search input -->
            <Combobox v-else v-model="selectedExisting" @update:model-value="selectCustomer" as="div" class="relative">
                    <ComboboxInput
                        class="w-full rounded-lg border-0 bg-white py-3 pl-12 pr-10 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                        placeholder="Search by name, email, or phone..."
                        :display-value="displayValue"
                        @change="query = $event.target.value"
                    />
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                        <MagnifyingGlassIcon class="size-5 text-gray-400" aria-hidden="true" />
                    </div>
                    <ComboboxButton class="absolute inset-y-0 right-0 flex items-center pr-3">
                        <ChevronUpDownIcon class="size-5 text-gray-400" aria-hidden="true" />
                    </ComboboxButton>

                <ComboboxOptions
                    v-if="query.length > 0"
                    class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm dark:bg-gray-800 dark:ring-white/10"
                >
                    <div v-if="isLoading" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                        Searching...
                    </div>

                    <div
                        v-else-if="searchResults.length === 0 && query.length > 0"
                        class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400"
                    >
                        No customers found.
                    </div>

                    <ComboboxOption
                        v-for="customer in filteredOptions"
                        :key="'isCreateOption' in customer ? 'create' : customer.id"
                        v-slot="{ active, selected }"
                        :value="customer"
                        as="template"
                    >
                        <li
                            :class="[
                                'relative cursor-pointer select-none py-3 pl-4 pr-9',
                                active ? 'bg-indigo-600 text-white' : 'text-gray-900 dark:text-white',
                                'isCreateOption' in customer ? 'border-t border-gray-200 dark:border-gray-700' : '',
                            ]"
                        >
                            <template v-if="'isCreateOption' in customer">
                                <div class="flex items-center gap-2">
                                    <PlusIcon class="size-5" />
                                    <span class="font-medium">Create new customer</span>
                                    <span v-if="query" :class="active ? 'text-indigo-200' : 'text-gray-500 dark:text-gray-400'">
                                        "{{ query }}"
                                    </span>
                                </div>
                            </template>

                            <template v-else>
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-600">
                                        <UserIcon :class="['size-5', active ? 'text-white' : 'text-gray-500 dark:text-gray-400']" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p :class="['truncate font-medium', selected ? 'font-semibold' : '']">
                                            {{ customer.full_name || `${customer.first_name} ${customer.last_name}` }}
                                        </p>
                                        <p :class="['truncate text-sm', active ? 'text-indigo-200' : 'text-gray-500 dark:text-gray-400']">
                                            {{ customer.email || customer.phone_number || 'No contact info' }}
                                        </p>
                                    </div>
                                </div>
                                <span
                                    v-if="selected"
                                    :class="[
                                        'absolute inset-y-0 right-0 flex items-center pr-4',
                                        active ? 'text-white' : 'text-indigo-600',
                                    ]"
                                >
                                    <CheckIcon class="size-5" aria-hidden="true" />
                                </span>
                            </template>
                        </li>
                    </ComboboxOption>
                </ComboboxOptions>
            </Combobox>
        </template>

        <!-- Create Mode -->
        <template v-else>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        First Name <span class="text-red-500">*</span>
                    </label>
                    <input
                        id="first_name"
                        v-model="newCustomer.first_name"
                        type="text"
                        @input="updateNewCustomer"
                        class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div>
                    <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Last Name <span class="text-red-500">*</span>
                    </label>
                    <input
                        id="last_name"
                        v-model="newCustomer.last_name"
                        type="text"
                        @input="updateNewCustomer"
                        class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label for="company_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Company Name
                    </label>
                    <input
                        id="company_name"
                        v-model="newCustomer.company_name"
                        type="text"
                        @input="updateNewCustomer"
                        class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Email
                    </label>
                    <input
                        id="email"
                        v-model="newCustomer.email"
                        type="email"
                        @input="updateNewCustomer"
                        class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Phone
                    </label>
                    <input
                        id="phone"
                        v-model="newCustomer.phone_number"
                        type="tel"
                        @input="updateNewCustomer"
                        class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label for="lead_source" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Lead Source
                    </label>
                    <LeadSourceSelect
                        v-model="newCustomer.lead_source_id"
                        placeholder="Select or create lead source..."
                        class="mt-1"
                        @update:model-value="updateNewCustomer"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Street Address
                    </label>
                    <input
                        id="address"
                        v-model="newCustomer.address"
                        type="text"
                        @input="updateNewCustomer"
                        class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label for="address2" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Apartment, suite, etc.
                    </label>
                    <input
                        id="address2"
                        v-model="newCustomer.address2"
                        type="text"
                        @input="updateNewCustomer"
                        class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div>
                    <label for="city" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        City
                    </label>
                    <input
                        id="city"
                        v-model="newCustomer.city"
                        type="text"
                        @input="updateNewCustomer"
                        class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="state" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            State
                        </label>
                        <input
                            id="state"
                            v-model="newCustomer.state"
                            type="text"
                            @input="updateNewCustomer"
                            class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                            placeholder="CA"
                        />
                    </div>

                    <div>
                        <label for="zip" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            ZIP / Postal Code
                        </label>
                        <input
                            id="zip"
                            v-model="newCustomer.zip"
                            type="text"
                            @input="updateNewCustomer"
                            class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
