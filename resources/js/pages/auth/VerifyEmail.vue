<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <AuthLayout
        title="Verify your email"
        description="Thanks for signing up! Before getting started, please verify your email address by clicking on the link we just sent you."
    >
        <Head title="Email verification" />

        <div class="space-y-6">
            <div
                v-if="status === 'verification-link-sent'"
                class="rounded-md bg-green-50 p-4 text-sm font-medium text-green-700 dark:bg-green-900/20 dark:text-green-400"
            >
                A new verification link has been sent to your email address.
            </div>

            <Form
                v-bind="send.form()"
                class="space-y-4"
                v-slot="{ processing }"
            >
                <button
                    type="submit"
                    :disabled="processing"
                    class="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Spinner v-if="processing" class="mr-2" />
                    Resend verification email
                </button>
            </Form>

            <div class="text-center">
                <TextLink
                    :href="logout()"
                    method="post"
                    as="button"
                    class="text-sm font-medium text-gray-600 hover:text-gray-500 dark:text-gray-400 dark:hover:text-gray-300"
                >
                    Sign out
                </TextLink>
            </div>
        </div>
    </AuthLayout>
</template>
