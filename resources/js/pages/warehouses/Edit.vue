<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';

interface Warehouse {
    id: number;
    name: string;
    code: string;
    description: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    contact_name: string | null;
    is_default: boolean;
    is_active: boolean;
    accepts_transfers: boolean;
    fulfills_orders: boolean;
    priority: number;
    tax_rate: number | null;
}

interface Props {
    warehouse: Warehouse;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Warehouses', href: '/warehouses' },
    { title: props.warehouse.name, href: `/warehouses/${props.warehouse.id}/edit` },
];

const form = useForm({
    name: props.warehouse.name,
    code: props.warehouse.code,
    description: props.warehouse.description || '',
    address_line1: props.warehouse.address_line1 || '',
    address_line2: props.warehouse.address_line2 || '',
    city: props.warehouse.city || '',
    state: props.warehouse.state || '',
    postal_code: props.warehouse.postal_code || '',
    country: props.warehouse.country || '',
    phone: props.warehouse.phone || '',
    email: props.warehouse.email || '',
    contact_name: props.warehouse.contact_name || '',
    is_default: props.warehouse.is_default,
    is_active: props.warehouse.is_active,
    accepts_transfers: props.warehouse.accepts_transfers,
    fulfills_orders: props.warehouse.fulfills_orders,
    priority: props.warehouse.priority,
    tax_rate: props.warehouse.tax_rate,
});

function submit() {
    form.put(`/warehouses/${props.warehouse.id}`);
}
</script>

<template>
    <Head :title="`Edit ${warehouse.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Header -->
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Edit Warehouse</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Update warehouse details and settings
                        </p>
                    </div>
                    <div class="flex gap-3">
                        <button
                            type="button"
                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                            @click="router.visit('/warehouses')"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Main Content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Basic Info -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Basic Information</h3>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Name <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="name"
                                            v-model="form.name"
                                            type="text"
                                            required
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                                    </div>

                                    <div>
                                        <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Code <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            id="code"
                                            v-model="form.code"
                                            type="text"
                                            required
                                            maxlength="50"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Unique identifier for this warehouse
                                        </p>
                                        <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">{{ form.errors.code }}</p>
                                    </div>

                                    <div>
                                        <label for="priority" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Priority
                                        </label>
                                        <input
                                            id="priority"
                                            v-model.number="form.priority"
                                            type="number"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Lower numbers = higher priority for order fulfillment
                                        </p>
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Description
                                        </label>
                                        <textarea
                                            id="description"
                                            v-model="form.description"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Address</h3>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label for="address_line1" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Address Line 1
                                        </label>
                                        <input
                                            id="address_line1"
                                            v-model="form.address_line1"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label for="address_line2" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Address Line 2
                                        </label>
                                        <input
                                            id="address_line2"
                                            v-model="form.address_line2"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="city" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            City
                                        </label>
                                        <input
                                            id="city"
                                            v-model="form.city"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="state" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            State / Province
                                        </label>
                                        <input
                                            id="state"
                                            v-model="form.state"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="postal_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Postal Code
                                        </label>
                                        <input
                                            id="postal_code"
                                            v-model="form.postal_code"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="country" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Country
                                        </label>
                                        <input
                                            id="country"
                                            v-model="form.country"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Info -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Contact Information</h3>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label for="contact_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Contact Name
                                        </label>
                                        <input
                                            id="contact_name"
                                            v-model="form.contact_name"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Phone
                                        </label>
                                        <input
                                            id="phone"
                                            v-model="form.phone"
                                            type="tel"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Email
                                        </label>
                                        <input
                                            id="email"
                                            v-model="form.email"
                                            type="email"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Status & Settings -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Settings</h3>
                                <div class="space-y-4">
                                    <label class="flex items-center gap-3">
                                        <input
                                            v-model="form.is_active"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <div>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Active</span>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                Warehouse is operational
                                            </p>
                                        </div>
                                    </label>

                                    <label class="flex items-center gap-3">
                                        <input
                                            v-model="form.is_default"
                                            type="checkbox"
                                            :disabled="warehouse.is_default"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700 disabled:opacity-50"
                                        />
                                        <div>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Default Warehouse</span>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ warehouse.is_default ? 'This is the current default' : 'Primary warehouse for new inventory' }}
                                            </p>
                                        </div>
                                    </label>

                                    <label class="flex items-center gap-3">
                                        <input
                                            v-model="form.fulfills_orders"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <div>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Fulfills Orders</span>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                Can ship orders to customers
                                            </p>
                                        </div>
                                    </label>

                                    <label class="flex items-center gap-3">
                                        <input
                                            v-model="form.accepts_transfers"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <div>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Accepts Transfers</span>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                Can receive inventory transfers
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Tax Settings -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Tax Settings</h3>
                                <div>
                                    <label for="tax_rate" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Tax Rate Override (%)
                                    </label>
                                    <input
                                        id="tax_rate"
                                        v-model.number="form.tax_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        placeholder="Leave empty to use store default"
                                        class="mt-1 block w-full rounded-md border-0 bg-white px-2 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Override the store's default tax rate for transactions from this location
                                    </p>
                                    <p v-if="form.errors.tax_rate" class="mt-1 text-sm text-red-600">{{ form.errors.tax_rate }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
