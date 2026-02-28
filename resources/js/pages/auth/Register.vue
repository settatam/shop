<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { Form, Head } from '@inertiajs/vue3';
</script>

<template>
    <AuthBase
        title="Create your account"
        description="Start your free trial today"
    >
        <Head title="Create account" />

        <Form
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="space-y-6"
        >
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Your name
                </label>
                <div class="mt-2">
                    <input
                        id="name"
                        type="text"
                        name="name"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="name"
                        placeholder="John Smith"
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="errors.name" class="mt-2" />
            </div>

            <div>
                <label for="store_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Store name
                </label>
                <div class="mt-2">
                    <input
                        id="store_name"
                        type="text"
                        name="store_name"
                        required
                        :tabindex="2"
                        placeholder="My Jewelry Store"
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="errors.store_name" class="mt-2" />
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Email address
                </label>
                <div class="mt-2">
                    <input
                        id="email"
                        type="email"
                        name="email"
                        required
                        :tabindex="3"
                        autocomplete="email"
                        placeholder="you@example.com"
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="errors.email" class="mt-2" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Password
                </label>
                <div class="mt-2">
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        placeholder="Password"
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="errors.password" class="mt-2" />
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Confirm password
                </label>
                <div class="mt-2">
                    <input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        required
                        :tabindex="5"
                        autocomplete="new-password"
                        placeholder="Confirm password"
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="errors.password_confirmation" class="mt-2" />
            </div>

            <div>
                <label class="flex items-start gap-2">
                    <input
                        id="terms_accepted"
                        type="checkbox"
                        name="terms_accepted"
                        value="1"
                        required
                        :tabindex="6"
                        class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-800 dark:focus:ring-indigo-500"
                    />
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        I agree to the
                        <a href="/terms" target="_blank" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">Terms of Service</a>
                        and
                        <a href="/privacy" target="_blank" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">Privacy Policy</a>
                    </span>
                </label>
                <InputError :message="errors.terms_accepted" class="mt-2" />
            </div>

            <div>
                <button
                    type="submit"
                    :tabindex="7"
                    :disabled="processing"
                    class="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" class="mr-2" />
                    Create account
                </button>
            </div>

            <p class="text-center text-sm text-gray-600 dark:text-gray-400">
                Already have an account?
                <TextLink
                    :href="login()"
                    :tabindex="8"
                    class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                >
                    Sign in
                </TextLink>
            </p>
        </Form>
    </AuthBase>
</template>
