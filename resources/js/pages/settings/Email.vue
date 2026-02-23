<script setup lang="ts">
import EmailSettingsController from '@/actions/App/Http/Controllers/Settings/EmailSettingsController';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Store {
    id: number;
    name: string;
    email_from_address: string | null;
    email_from_name: string | null;
    email_reply_to_address: string | null;
}

interface Props {
    store: Store;
    mailProvider: string;
    sesConfigured: boolean;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Email settings',
        href: EmailSettingsController.index.url(),
    },
];

// Test email state
const testEmail = ref('');
const testSending = ref(false);
const testMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null);

async function sendTestEmail() {
    if (!testEmail.value) {
        testMessage.value = { type: 'error', text: 'Please enter an email address' };
        return;
    }

    testSending.value = true;
    testMessage.value = null;

    try {
        const response = await fetch(EmailSettingsController.sendTest.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ test_email: testEmail.value }),
        });

        const data = await response.json();

        if (data.success) {
            testMessage.value = { type: 'success', text: data.message };
        } else {
            testMessage.value = { type: 'error', text: data.message };
        }
    } catch {
        testMessage.value = { type: 'error', text: 'Failed to send test email' };
    } finally {
        testSending.value = false;
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Email settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-8">
                <!-- Mail Provider Status -->
                <div>
                    <HeadingSmall
                        title="Email Provider"
                        description="Current email sending configuration"
                    />

                    <div class="mt-6 rounded-lg border p-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full"
                                :class="sesConfigured ? 'bg-green-100 dark:bg-green-900' : 'bg-yellow-100 dark:bg-yellow-900'"
                            >
                                <svg
                                    v-if="sesConfigured"
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5 text-green-600 dark:text-green-400"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                                <svg
                                    v-else
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5 text-yellow-600 dark:text-yellow-400"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                            </div>
                            <div>
                                <p class="font-medium">
                                    {{ mailProvider === 'ses' ? 'Amazon SES' : mailProvider.charAt(0).toUpperCase() + mailProvider.slice(1) }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    <span v-if="sesConfigured">Configured and ready to send emails</span>
                                    <span v-else-if="mailProvider === 'ses'">SES credentials not configured</span>
                                    <span v-else>Using {{ mailProvider }} mail driver</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Email Settings Form -->
                <div>
                    <HeadingSmall
                        title="Email Settings"
                        description="Configure the from address and reply-to address for outgoing emails"
                    />

                    <Form
                        v-bind="EmailSettingsController.update.form()"
                        class="mt-6 space-y-6"
                        #default="{ errors, processing, recentlySuccessful }"
                    >
                        <!-- From Address -->
                        <div class="grid gap-2">
                            <Label for="email_from_address">From Email Address</Label>
                            <Input
                                id="email_from_address"
                                type="email"
                                name="email_from_address"
                                :default-value="store.email_from_address ?? ''"
                                placeholder="noreply@yourstore.com"
                            />
                            <InputError :message="errors.email_from_address" />
                            <p class="text-sm text-muted-foreground">
                                The email address that will appear in the "From" field. Must be verified with your email provider.
                            </p>
                        </div>

                        <!-- From Name -->
                        <div class="grid gap-2">
                            <Label for="email_from_name">From Name</Label>
                            <Input
                                id="email_from_name"
                                name="email_from_name"
                                :default-value="store.email_from_name ?? ''"
                                :placeholder="store.name"
                            />
                            <InputError :message="errors.email_from_name" />
                            <p class="text-sm text-muted-foreground">
                                The name that will appear in the "From" field. Defaults to your store name if not set.
                            </p>
                        </div>

                        <!-- Reply-To Address -->
                        <div class="grid gap-2">
                            <Label for="email_reply_to_address">Reply-To Address</Label>
                            <Input
                                id="email_reply_to_address"
                                type="email"
                                name="email_reply_to_address"
                                :default-value="store.email_reply_to_address ?? ''"
                                placeholder="support@yourstore.com"
                            />
                            <InputError :message="errors.email_reply_to_address" />
                            <p class="text-sm text-muted-foreground">
                                When recipients reply to your emails, their replies will go to this address.
                            </p>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex items-center gap-4">
                            <Button :disabled="processing">
                                {{ processing ? 'Saving...' : 'Save Settings' }}
                            </Button>

                            <Transition
                                enter-active-class="transition ease-in-out"
                                enter-from-class="opacity-0"
                                leave-active-class="transition ease-in-out"
                                leave-to-class="opacity-0"
                            >
                                <p v-show="recentlySuccessful" class="text-sm text-green-600">
                                    Saved successfully.
                                </p>
                            </Transition>
                        </div>
                    </Form>
                </div>

                <!-- Test Email -->
                <div>
                    <HeadingSmall
                        title="Test Email"
                        description="Send a test email to verify your configuration"
                    />

                    <div class="mt-6 space-y-4">
                        <div class="grid gap-2">
                            <Label for="test_email">Test Email Address</Label>
                            <div class="flex gap-3">
                                <Input
                                    id="test_email"
                                    v-model="testEmail"
                                    type="email"
                                    placeholder="test@example.com"
                                    class="flex-1"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="testSending"
                                    @click="sendTestEmail"
                                >
                                    {{ testSending ? 'Sending...' : 'Send Test' }}
                                </Button>
                            </div>
                        </div>

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <div
                                v-if="testMessage"
                                class="rounded-md p-3"
                                :class="testMessage.type === 'success' ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400'"
                            >
                                <p class="text-sm">{{ testMessage.text }}</p>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
