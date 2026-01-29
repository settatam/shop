<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import PortalAuthLayout from '@/layouts/portal/PortalAuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    otpSent?: boolean;
}>();

const mode = ref<'email' | 'phone'>('email');
const otpStep = ref<'phone' | 'code'>('phone');

const emailForm = useForm({
    email: '',
    password: '',
    remember: false,
});

const otpSendForm = useForm({
    phone: '',
});

const otpVerifyForm = useForm({
    phone: '',
    code: '',
});

function loginWithEmail() {
    emailForm.post('/p/login');
}

function sendOtp() {
    otpSendForm.post('/p/otp/send', {
        preserveState: true,
        onSuccess: () => {
            otpVerifyForm.phone = otpSendForm.phone;
            otpStep.value = 'code';
        },
    });
}

function verifyOtp() {
    otpVerifyForm.post('/p/otp/verify');
}
</script>

<template>
    <PortalAuthLayout title="Sign in to your account" description="View your transactions and offers">
        <Head title="Sign in" />

        <!-- Mode toggle -->
        <div class="mb-6 flex rounded-md border border-gray-200 dark:border-gray-700">
            <button
                type="button"
                @click="mode = 'email'"
                :class="[
                    'flex-1 rounded-l-md px-4 py-2 text-sm font-medium transition-colors',
                    mode === 'email'
                        ? 'bg-indigo-600 text-white'
                        : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700',
                ]"
            >
                Email
            </button>
            <button
                type="button"
                @click="mode = 'phone'; otpStep = 'phone'"
                :class="[
                    'flex-1 rounded-r-md px-4 py-2 text-sm font-medium transition-colors',
                    mode === 'phone'
                        ? 'bg-indigo-600 text-white'
                        : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700',
                ]"
            >
                Phone
            </button>
        </div>

        <!-- Email + Password form -->
        <form v-if="mode === 'email'" @submit.prevent="loginWithEmail" class="space-y-6">
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email address</label>
                <div class="mt-2">
                    <input
                        id="email"
                        v-model="emailForm.email"
                        type="email"
                        required
                        autofocus
                        autocomplete="email"
                        placeholder="you@example.com"
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="emailForm.errors.email" class="mt-2" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                <div class="mt-2">
                    <input
                        id="password"
                        v-model="emailForm.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="Password"
                        class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                    />
                </div>
                <InputError :message="emailForm.errors.password" class="mt-2" />
            </div>

            <div class="flex items-center">
                <input
                    id="remember"
                    v-model="emailForm.remember"
                    type="checkbox"
                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-800"
                />
                <label for="remember" class="ml-3 block text-sm text-gray-700 dark:text-gray-300">Remember me</label>
            </div>

            <button
                type="submit"
                :disabled="emailForm.processing"
                class="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <Spinner v-if="emailForm.processing" class="mr-2" />
                Sign in
            </button>
        </form>

        <!-- Phone OTP form -->
        <div v-else>
            <!-- Step 1: Enter phone -->
            <form v-if="otpStep === 'phone'" @submit.prevent="sendOtp" class="space-y-6">
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone number</label>
                    <div class="mt-2">
                        <input
                            id="phone"
                            v-model="otpSendForm.phone"
                            type="tel"
                            required
                            autofocus
                            placeholder="+1 (555) 000-0000"
                            class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                        />
                    </div>
                    <InputError :message="otpSendForm.errors.phone" class="mt-2" />
                </div>

                <button
                    type="submit"
                    :disabled="otpSendForm.processing"
                    class="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Spinner v-if="otpSendForm.processing" class="mr-2" />
                    Send verification code
                </button>
            </form>

            <!-- Step 2: Enter code -->
            <form v-else @submit.prevent="verifyOtp" class="space-y-6">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    We sent a verification code to <strong class="text-gray-900 dark:text-white">{{ otpVerifyForm.phone }}</strong>
                </p>

                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Verification code</label>
                    <div class="mt-2">
                        <input
                            id="code"
                            v-model="otpVerifyForm.code"
                            type="text"
                            required
                            autofocus
                            maxlength="6"
                            placeholder="000000"
                            class="block w-full rounded-md border-0 py-2 px-3 text-center text-lg tracking-widest text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-gray-700 dark:placeholder:text-gray-500 dark:focus:ring-indigo-500"
                        />
                    </div>
                    <InputError :message="otpVerifyForm.errors.code" class="mt-2" />
                </div>

                <button
                    type="submit"
                    :disabled="otpVerifyForm.processing"
                    class="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Spinner v-if="otpVerifyForm.processing" class="mr-2" />
                    Verify code
                </button>

                <button
                    type="button"
                    @click="otpStep = 'phone'"
                    class="w-full text-center text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                >
                    Use a different phone number
                </button>
            </form>
        </div>
    </PortalAuthLayout>
</template>
