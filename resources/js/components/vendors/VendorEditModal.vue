<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';

interface Vendor {
    id: number;
    name: string;
    code?: string | null;
    company_name?: string | null;
    display_name?: string;
    email?: string | null;
    phone?: string | null;
    website?: string | null;
    address_line1?: string | null;
    address_line2?: string | null;
    city?: string | null;
    state?: string | null;
    postal_code?: string | null;
    country?: string | null;
    contact_name?: string | null;
    contact_email?: string | null;
    contact_phone?: string | null;
    payment_terms?: string | null;
    notes?: string | null;
}

interface Props {
    show: boolean;
    vendor: Vendor;
}

const props = withDefaults(defineProps<Props>(), {
    show: false,
});

const emit = defineEmits<{
    close: [];
    saved: [vendor: Vendor];
}>();

const form = useForm({
    name: '',
    code: '',
    company_name: '',
    email: '',
    phone: '',
    website: '',
    address_line1: '',
    address_line2: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    payment_terms: '',
    notes: '',
});

const paymentTermsOptions = [
    { value: '', label: 'Select payment terms' },
    { value: 'due_on_receipt', label: 'Due on Receipt' },
    { value: 'prepaid', label: 'Prepaid' },
    { value: 'net_15', label: 'Net 15' },
    { value: 'net_30', label: 'Net 30' },
    { value: 'net_45', label: 'Net 45' },
    { value: 'net_60', label: 'Net 60' },
];

// Initialize form when vendor changes or modal opens
watch(
    () => [props.show, props.vendor],
    () => {
        if (props.show && props.vendor) {
            form.name = props.vendor.name || '';
            form.code = props.vendor.code || '';
            form.company_name = props.vendor.company_name || '';
            form.email = props.vendor.email || '';
            form.phone = props.vendor.phone || '';
            form.website = props.vendor.website || '';
            form.address_line1 = props.vendor.address_line1 || '';
            form.address_line2 = props.vendor.address_line2 || '';
            form.city = props.vendor.city || '';
            form.state = props.vendor.state || '';
            form.postal_code = props.vendor.postal_code || '';
            form.country = props.vendor.country || '';
            form.contact_name = props.vendor.contact_name || '';
            form.contact_email = props.vendor.contact_email || '';
            form.contact_phone = props.vendor.contact_phone || '';
            form.payment_terms = props.vendor.payment_terms || '';
            form.notes = props.vendor.notes || '';
        }
    },
    { immediate: true }
);

const close = () => {
    emit('close');
};

const save = () => {
    form.put(`/vendors/${props.vendor.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved', props.vendor);
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
                            class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6 dark:bg-gray-800"
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

                            <div class="max-h-[80vh] overflow-y-auto">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Edit Vendor</h3>

                                <form @submit.prevent="save" class="space-y-6">
                                    <!-- Basic Info -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Basic Information</h4>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label
                                                    for="edit_vendor_name"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Name <span class="text-red-500">*</span>
                                                </label>
                                                <input
                                                    id="edit_vendor_name"
                                                    v-model="form.name"
                                                    type="text"
                                                    required
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                    {{ form.errors.name }}
                                                </p>
                                            </div>
                                            <div>
                                                <label
                                                    for="edit_vendor_code"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Code
                                                </label>
                                                <input
                                                    id="edit_vendor_code"
                                                    v-model="form.code"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div class="col-span-2">
                                                <label
                                                    for="edit_vendor_company"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Company Name
                                                </label>
                                                <input
                                                    id="edit_vendor_company"
                                                    v-model="form.company_name"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Contact Info -->
                                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Contact Information</h4>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label
                                                    for="edit_vendor_email"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Email
                                                </label>
                                                <input
                                                    id="edit_vendor_email"
                                                    v-model="form.email"
                                                    type="email"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label
                                                    for="edit_vendor_phone"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Phone
                                                </label>
                                                <input
                                                    id="edit_vendor_phone"
                                                    v-model="form.phone"
                                                    type="tel"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div class="col-span-2">
                                                <label
                                                    for="edit_vendor_website"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Website
                                                </label>
                                                <input
                                                    id="edit_vendor_website"
                                                    v-model="form.website"
                                                    type="url"
                                                    placeholder="https://"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Address -->
                                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Address</h4>
                                        <div class="space-y-4">
                                            <div>
                                                <label
                                                    for="edit_vendor_address1"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Address Line 1
                                                </label>
                                                <input
                                                    id="edit_vendor_address1"
                                                    v-model="form.address_line1"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label
                                                    for="edit_vendor_address2"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Address Line 2
                                                </label>
                                                <input
                                                    id="edit_vendor_address2"
                                                    v-model="form.address_line2"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label
                                                        for="edit_vendor_city"
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >
                                                        City
                                                    </label>
                                                    <input
                                                        id="edit_vendor_city"
                                                        v-model="form.city"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label
                                                        for="edit_vendor_state"
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >
                                                        State
                                                    </label>
                                                    <input
                                                        id="edit_vendor_state"
                                                        v-model="form.state"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label
                                                        for="edit_vendor_postal"
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >
                                                        Postal Code
                                                    </label>
                                                    <input
                                                        id="edit_vendor_postal"
                                                        v-model="form.postal_code"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                                <div>
                                                    <label
                                                        for="edit_vendor_country"
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                    >
                                                        Country
                                                    </label>
                                                    <input
                                                        id="edit_vendor_country"
                                                        v-model="form.country"
                                                        type="text"
                                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Primary Contact -->
                                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Primary Contact</h4>
                                        <div class="grid grid-cols-3 gap-4">
                                            <div>
                                                <label
                                                    for="edit_vendor_contact_name"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Name
                                                </label>
                                                <input
                                                    id="edit_vendor_contact_name"
                                                    v-model="form.contact_name"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label
                                                    for="edit_vendor_contact_email"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Email
                                                </label>
                                                <input
                                                    id="edit_vendor_contact_email"
                                                    v-model="form.contact_email"
                                                    type="email"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label
                                                    for="edit_vendor_contact_phone"
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                                >
                                                    Phone
                                                </label>
                                                <input
                                                    id="edit_vendor_contact_phone"
                                                    v-model="form.contact_phone"
                                                    type="tel"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Payment Terms -->
                                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Business Terms</h4>
                                        <div>
                                            <label
                                                for="edit_vendor_payment_terms"
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                            >
                                                Payment Terms
                                            </label>
                                            <select
                                                id="edit_vendor_payment_terms"
                                                v-model="form.payment_terms"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option v-for="option in paymentTermsOptions" :key="option.value" :value="option.value">
                                                    {{ option.label }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                        <label for="edit_vendor_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Notes
                                        </label>
                                        <textarea
                                            id="edit_vendor_notes"
                                            v-model="form.notes"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex gap-3 justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
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
