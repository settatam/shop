<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import PortalLayout from '@/layouts/portal/PortalLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    customer: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
        phone_number: string | null;
    };
}>();

const form = useForm({
    first_name: props.customer.first_name,
    last_name: props.customer.last_name,
    email: props.customer.email,
    phone_number: props.customer.phone_number ?? '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put('/p/account', {
        onSuccess: () => {
            form.password = '';
            form.password_confirmation = '';
        },
    });
}
</script>

<template>
    <PortalLayout title="Account">
        <Head title="Account" />

        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Account Settings</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Update your personal information</p>

        <form @submit.prevent="submit" class="mt-8 max-w-lg space-y-6">
            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">First name</label>
                    <div class="mt-2">
                        <input
                            id="first_name"
                            v-model="form.first_name"
                            type="text"
                            required
                            class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                        />
                    </div>
                    <InputError :message="form.errors.first_name" class="mt-2" />
                </div>

                <div>
                    <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last name</label>
                    <div class="mt-2">
                        <input
                            id="last_name"
                            v-model="form.last_name"
                            type="text"
                            required
                            class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                        />
                    </div>
                    <InputError :message="form.errors.last_name" class="mt-2" />
                </div>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                <div class="mt-2">
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="form.errors.email" class="mt-2" />
            </div>

            <div>
                <label for="phone_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone number</label>
                <div class="mt-2">
                    <input
                        id="phone_number"
                        v-model="form.phone_number"
                        type="tel"
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="form.errors.phone_number" class="mt-2" />
            </div>

            <div class="border-t border-gray-200 pt-6 dark:border-gray-700">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white">Change password</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Leave blank to keep current password</p>

                <div class="mt-4 space-y-4">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">New password</label>
                        <div class="mt-2">
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                autocomplete="new-password"
                                class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                            />
                        </div>
                        <InputError :message="form.errors.password" class="mt-2" />
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm new password</label>
                        <div class="mt-2">
                            <input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Spinner v-if="form.processing" class="mr-2" />
                    Save changes
                </button>
            </div>
        </form>
    </PortalLayout>
</template>
