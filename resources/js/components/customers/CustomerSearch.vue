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
import LeadSourceSelect from './LeadSourceSelect.vue';

interface Customer {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone_number: string | null;
}

interface Props {
    modelValue: Customer | null;
    placeholder?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: null,
    placeholder: 'Search customers...',
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [customer: Customer | null];
    'create': [];
}>();

const query = ref('');
const customers = ref<Customer[]>([]);
const isLoading = ref(false);
const showCreateModal = ref(false);

// New customer form
const newCustomer = ref({
    first_name: '',
    last_name: '',
    email: '',
    phone_number: '',
    lead_source_id: null as number | null,
});
const createLoading = ref(false);
const createError = ref<string | null>(null);

const selectedCustomer = computed({
    get: () => props.modelValue,
    set: (value) => {
        if (value && 'isCreateOption' in value) {
            // User clicked "Create new customer"
            showCreateModal.value = true;
            // Pre-fill with search query
            const parts = query.value.trim().split(' ');
            newCustomer.value.first_name = parts[0] || '';
            newCustomer.value.last_name = parts.slice(1).join(' ') || '';
        } else {
            emit('update:modelValue', value);
        }
    },
});

const search = useDebounceFn(async (searchQuery: string) => {
    if (!searchQuery || searchQuery.length < 1) {
        customers.value = [];
        return;
    }

    isLoading.value = true;

    try {
        const response = await axios.get('/api/v1/customers', {
            params: { q: searchQuery, limit: 10 },
        });
        customers.value = response.data.data;
    } catch (err) {
        customers.value = [];
    } finally {
        isLoading.value = false;
    }
}, 300);

watch(query, (value) => {
    search(value);
});

const displayValue = (customer: Customer | null): string => {
    if (!customer) return '';
    return customer.full_name || customer.email || '';
};

const clearSelection = () => {
    emit('update:modelValue', null);
    query.value = '';
};

const createNewCustomer = async () => {
    createLoading.value = true;
    createError.value = null;

    try {
        const response = await axios.post('/api/v1/customers', newCustomer.value);
        emit('update:modelValue', response.data.data);
        showCreateModal.value = false;
        resetNewCustomerForm();
    } catch (err: any) {
        createError.value = err.response?.data?.message || 'Failed to create customer.';
    } finally {
        createLoading.value = false;
    }
};

const resetNewCustomerForm = () => {
    newCustomer.value = {
        first_name: '',
        last_name: '',
        email: '',
        phone_number: '',
        lead_source_id: null,
    };
    createError.value = null;
};

const closeCreateModal = () => {
    showCreateModal.value = false;
    resetNewCustomerForm();
};

// Create option marker
const createOption = { isCreateOption: true, full_name: 'Create new customer' };

const filteredOptions = computed(() => {
    const results = [...customers.value];
    // Always show create option at the end
    if (query.value.length > 0) {
        results.push(createOption as any);
    }
    return results;
});
</script>

<template>
    <div class="relative">
        <!-- Selected customer display -->
        <div
            v-if="selectedCustomer && !('isCreateOption' in selectedCustomer)"
            class="flex items-center justify-between rounded-md border border-gray-300 bg-white px-3 py-2 dark:border-gray-600 dark:bg-gray-700"
        >
            <div class="flex items-center gap-2">
                <div class="flex size-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-600">
                    <UserIcon class="size-4 text-gray-500 dark:text-gray-400" />
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ selectedCustomer.full_name }}
                    </p>
                    <p v-if="selectedCustomer.email || selectedCustomer.phone_number" class="text-xs text-gray-500 dark:text-gray-400">
                        {{ selectedCustomer.email || selectedCustomer.phone_number }}
                    </p>
                </div>
            </div>
            <button
                type="button"
                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-600"
                @click="clearSelection"
            >
                <XMarkIcon class="size-4" />
            </button>
        </div>

        <!-- Search input -->
        <Combobox
            v-else
            v-model="selectedCustomer"
            as="div"
            :disabled="disabled"
        >
            <div class="relative">
                <ComboboxInput
                    class="w-full rounded-md border-0 bg-white py-2 pl-10 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                    :placeholder="placeholder"
                    :display-value="displayValue"
                    @change="query = $event.target.value"
                />
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <MagnifyingGlassIcon class="size-5 text-gray-400" aria-hidden="true" />
                </div>
                <ComboboxButton class="absolute inset-y-0 right-0 flex items-center pr-2">
                    <ChevronUpDownIcon class="size-5 text-gray-400" aria-hidden="true" />
                </ComboboxButton>
            </div>

            <ComboboxOptions
                v-if="query.length > 0"
                class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm dark:bg-gray-800 dark:ring-white/10"
            >
                <!-- Loading state -->
                <div v-if="isLoading" class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
                    Searching...
                </div>

                <!-- No results -->
                <div
                    v-else-if="customers.length === 0 && query.length > 0"
                    class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400"
                >
                    No customers found.
                </div>

                <!-- Results -->
                <ComboboxOption
                    v-for="customer in filteredOptions"
                    :key="'isCreateOption' in customer ? 'create' : customer.id"
                    v-slot="{ active, selected }"
                    :value="customer"
                    as="template"
                >
                    <li
                        :class="[
                            'relative cursor-pointer select-none py-2 pl-3 pr-9',
                            active ? 'bg-indigo-600 text-white' : 'text-gray-900 dark:text-white',
                            'isCreateOption' in customer ? 'border-t border-gray-200 dark:border-gray-700' : '',
                        ]"
                    >
                        <!-- Create new option -->
                        <template v-if="'isCreateOption' in customer">
                            <div class="flex items-center gap-2">
                                <PlusIcon class="size-5" />
                                <span class="font-medium">Create new customer</span>
                                <span v-if="query" :class="active ? 'text-indigo-200' : 'text-gray-500 dark:text-gray-400'">
                                    "{{ query }}"
                                </span>
                            </div>
                        </template>

                        <!-- Customer option -->
                        <template v-else>
                            <div class="flex items-center gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-600">
                                    <UserIcon :class="['size-4', active ? 'text-white' : 'text-gray-500 dark:text-gray-400']" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p :class="['truncate font-medium', selected ? 'font-semibold' : '']">
                                        {{ customer.full_name || 'No name' }}
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

        <!-- Create Customer Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="closeCreateModal" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Create New Customer</h3>

                        <div v-if="createError" class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/50 dark:text-red-300">
                            {{ createError }}
                        </div>

                        <form @submit.prevent="createNewCustomer" class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name</label>
                                    <input
                                        id="first_name"
                                        v-model="newCustomer.first_name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name</label>
                                    <input
                                        id="last_name"
                                        v-model="newCustomer.last_name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                <input
                                    id="email"
                                    v-model="newCustomer.email"
                                    type="email"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                <input
                                    id="phone"
                                    v-model="newCustomer.phone_number"
                                    type="tel"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div>
                                <label for="lead_source" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Lead Source</label>
                                <LeadSourceSelect
                                    v-model="newCustomer.lead_source_id"
                                    placeholder="Select or create lead source..."
                                    class="mt-1"
                                />
                            </div>
                            <div class="flex gap-3 justify-end pt-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="closeCreateModal"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="createLoading"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {{ createLoading ? 'Creating...' : 'Create Customer' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
