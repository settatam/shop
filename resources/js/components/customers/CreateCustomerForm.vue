<script lang="ts">
export interface CustomerFormData {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    company_name?: string;
    lead_source_id?: number | null;
    address: {
        address_line1: string;
        address_line2: string;
        city: string;
        state: string;
        postal_code: string;
        country: string;
    };
}

export function getEmptyCustomerForm(): CustomerFormData {
    return {
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        company_name: '',
        lead_source_id: null,
        address: {
            address_line1: '',
            address_line2: '',
            city: '',
            state: '',
            postal_code: '',
            country: 'US',
        },
    };
}
</script>

<script setup lang="ts">
import { computed } from 'vue';
import LeadSourceSelect from '@/components/customers/LeadSourceSelect.vue';

interface Props {
    modelValue: CustomerFormData;
    showCompanyName?: boolean;
    showLeadSource?: boolean;
    firstNameRequired?: boolean;
    lastNameRequired?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showCompanyName: false,
    showLeadSource: true,
    firstNameRequired: true,
    lastNameRequired: true,
});

const emit = defineEmits<{
    'update:modelValue': [value: CustomerFormData];
}>();

const customer = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

function updateField<K extends keyof CustomerFormData>(field: K, value: CustomerFormData[K]) {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
}

function updateAddressField(field: keyof CustomerFormData['address'], value: string) {
    emit('update:modelValue', {
        ...props.modelValue,
        address: { ...props.modelValue.address, [field]: value },
    });
}
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <!-- First Name -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                First Name <span v-if="firstNameRequired" class="text-red-500">*</span>
            </label>
            <input
                :value="customer.first_name"
                @input="updateField('first_name', ($event.target as HTMLInputElement).value)"
                type="text"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- Last Name -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                Last Name <span v-if="lastNameRequired" class="text-red-500">*</span>
            </label>
            <input
                :value="customer.last_name"
                @input="updateField('last_name', ($event.target as HTMLInputElement).value)"
                type="text"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- Company Name (optional) -->
        <div v-if="showCompanyName" class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Company Name</label>
            <input
                :value="customer.company_name"
                @input="updateField('company_name', ($event.target as HTMLInputElement).value)"
                type="text"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- Email -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
            <input
                :value="customer.email"
                @input="updateField('email', ($event.target as HTMLInputElement).value)"
                type="email"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- Phone -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
            <input
                :value="customer.phone"
                @input="updateField('phone', ($event.target as HTMLInputElement).value)"
                type="tel"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- Lead Source (optional) -->
        <div v-if="showLeadSource" class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Lead Source</label>
            <LeadSourceSelect
                :model-value="customer.lead_source_id"
                @update:model-value="updateField('lead_source_id', $event)"
                placeholder="Select or create lead source..."
                class="mt-1"
            />
        </div>

        <!-- Address Section -->
        <div class="sm:col-span-2 border-t border-gray-200 dark:border-gray-600 pt-4 mt-2">
            <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">Address (Optional)</h4>
        </div>

        <!-- Address Line 1 -->
        <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address Line 1</label>
            <input
                :value="customer.address.address_line1"
                @input="updateAddressField('address_line1', ($event.target as HTMLInputElement).value)"
                type="text"
                placeholder="Street address"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- Address Line 2 -->
        <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address Line 2</label>
            <input
                :value="customer.address.address_line2"
                @input="updateAddressField('address_line2', ($event.target as HTMLInputElement).value)"
                type="text"
                placeholder="Apt, suite, unit, etc."
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- City -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">City</label>
            <input
                :value="customer.address.city"
                @input="updateAddressField('city', ($event.target as HTMLInputElement).value)"
                type="text"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- State -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">State / Province</label>
            <input
                :value="customer.address.state"
                @input="updateAddressField('state', ($event.target as HTMLInputElement).value)"
                type="text"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- Postal Code -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Postal Code</label>
            <input
                :value="customer.address.postal_code"
                @input="updateAddressField('postal_code', ($event.target as HTMLInputElement).value)"
                type="text"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            />
        </div>

        <!-- Country -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Country</label>
            <select
                :value="customer.address.country"
                @change="updateAddressField('country', ($event.target as HTMLSelectElement).value)"
                class="mt-1 block w-full rounded-md border-0 px-2 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
            >
                <option value="US">United States</option>
                <option value="CA">Canada</option>
                <option value="GB">United Kingdom</option>
                <option value="AU">Australia</option>
            </select>
        </div>
    </div>
</template>
