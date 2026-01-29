<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { email } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <AuthLayout
        title="Forgot your password?"
        description="No problem. Enter your email and we'll send you a reset link."
    >
        <Head title="Forgot password" />

        <div class="space-y-6">
            <div
                v-if="status"
                class="rounded-md bg-green-50 p-4 text-sm font-medium text-green-700 dark:bg-green-900/20 dark:text-green-400"
            >
                {{ status }}
            </div>

            <Form v-bind="email.form()" v-slot="{ errors, processing }" class="space-y-6">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Email address
                    </label>
                    <div class="mt-2">
                        <input
                            id="email"
                            type="email"
                            name="email"
                            autocomplete="email"
                            autofocus
                            placeholder="you@example.com"
                            class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                        />
                    </div>
                    <InputError :message="errors.email" class="mt-2" />
                </div>

                <button
                    type="submit"
                    :disabled="processing"
                    class="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" class="mr-2" />
                    Send reset link
                </button>
            </Form>

            <p class="text-center text-sm text-gray-600 dark:text-gray-400">
                Remember your password?
                <TextLink
                    :href="login()"
                    class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                >
                    Sign in
                </TextLink>
            </p>
        </div>
    </AuthLayout>
</template>
