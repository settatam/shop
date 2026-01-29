<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    EnvelopeIcon,
    DevicePhoneMobileIcon,
    GlobeAltIcon,
    CheckCircleIcon,
    XCircleIcon,
    PencilIcon,
    HashtagIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface NotificationChannel {
    id: number;
    type: string;
    name: string | null;
    settings: Record<string, string | number | boolean | null>;
    is_enabled: boolean;
    is_default: boolean;
}

interface Props {
    channels: NotificationChannel[];
    channelTypes: string[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Notifications',
        href: '/settings/notifications',
    },
    {
        title: 'Channels',
        href: '/settings/notifications/channels',
    },
];

const showModal = ref(false);
const editingChannel = ref<string | null>(null);
const isSubmitting = ref(false);
const formErrors = ref<Record<string, string>>({});

// Form data for each channel type
const emailForm = ref({
    from_name: '',
    from_email: '',
    reply_to: '',
});

const smsForm = ref({
    provider: 'twilio',
    account_sid: '',
    auth_token: '',
    from_number: '',
});

const slackForm = ref({
    webhook_url: '',
    channel: '',
    username: '',
});

const webhookForm = ref({
    url: '',
    secret: '',
    headers: '',
});

// Get channel by type
function getChannelByType(type: string): NotificationChannel | undefined {
    return props.channels.find(c => c.type === type);
}

// Check if channel is configured
function isChannelConfigured(type: string): boolean {
    const channel = getChannelByType(type);
    if (!channel) return false;

    switch (type) {
        case 'email':
            return true; // Uses default Laravel mail
        case 'sms':
            return !!channel.settings?.provider;
        case 'slack':
            return !!channel.settings?.webhook_url;
        case 'webhook':
            return !!channel.settings?.url;
        default:
            return false;
    }
}

// Check if channel is enabled
function isChannelEnabled(type: string): boolean {
    const channel = getChannelByType(type);
    return channel?.is_enabled ?? false;
}

// Open edit modal for a channel
function openEditModal(type: string) {
    const channel = getChannelByType(type);
    editingChannel.value = type;
    formErrors.value = {};

    switch (type) {
        case 'email':
            emailForm.value = {
                from_name: channel?.settings?.from_name as string || '',
                from_email: channel?.settings?.from_email as string || '',
                reply_to: channel?.settings?.reply_to as string || '',
            };
            break;
        case 'sms':
            smsForm.value = {
                provider: channel?.settings?.provider as string || 'twilio',
                account_sid: channel?.settings?.account_sid as string || '',
                auth_token: channel?.settings?.auth_token as string || '',
                from_number: channel?.settings?.from_number as string || '',
            };
            break;
        case 'slack':
            slackForm.value = {
                webhook_url: channel?.settings?.webhook_url as string || '',
                channel: channel?.settings?.channel as string || '',
                username: channel?.settings?.username as string || '',
            };
            break;
        case 'webhook':
            webhookForm.value = {
                url: channel?.settings?.url as string || '',
                secret: channel?.settings?.secret as string || '',
                headers: channel?.settings?.headers as string || '',
            };
            break;
    }

    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingChannel.value = null;
    formErrors.value = {};
}

function getFormData() {
    switch (editingChannel.value) {
        case 'email':
            return { type: 'email', settings: emailForm.value };
        case 'sms':
            return { type: 'sms', settings: smsForm.value };
        case 'slack':
            return { type: 'slack', settings: slackForm.value };
        case 'webhook':
            return { type: 'webhook', settings: webhookForm.value };
        default:
            return null;
    }
}

function saveChannel() {
    if (isSubmitting.value || !editingChannel.value) return;

    const data = getFormData();
    if (!data) return;

    isSubmitting.value = true;
    formErrors.value = {};

    const channel = getChannelByType(editingChannel.value);
    const method = channel ? 'put' : 'post';
    const url = channel
        ? `/api/v1/notification-channels/${channel.id}`
        : '/api/v1/notification-channels';

    // For now we'll handle this via router since API may not exist
    // Use a web route approach similar to printer settings
    router.post('/settings/notifications/channels/save', data, {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        },
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function toggleChannel(type: string) {
    const channel = getChannelByType(type);
    if (!channel) return;

    router.post('/settings/notifications/channels/toggle', {
        type: type,
        is_enabled: !channel.is_enabled,
    }, {
        preserveScroll: true,
    });
}

function getChannelIcon(type: string) {
    switch (type) {
        case 'email':
            return EnvelopeIcon;
        case 'sms':
            return DevicePhoneMobileIcon;
        case 'slack':
            return HashtagIcon;
        case 'webhook':
            return GlobeAltIcon;
        default:
            return EnvelopeIcon;
    }
}

function getChannelTitle(type: string): string {
    switch (type) {
        case 'email':
            return 'Email';
        case 'sms':
            return 'SMS';
        case 'slack':
            return 'Slack';
        case 'webhook':
            return 'Webhook';
        case 'push':
            return 'Push Notifications';
        default:
            return type;
    }
}

function getChannelDescription(type: string): string {
    switch (type) {
        case 'email':
            return 'Send email notifications to customers and team members';
        case 'sms':
            return 'Send SMS messages via Twilio or other providers';
        case 'slack':
            return 'Post notifications to Slack channels';
        case 'webhook':
            return 'Send notifications to external URLs';
        case 'push':
            return 'Send browser push notifications';
        default:
            return '';
    }
}

function getChannelBadgeClass(type: string): string {
    switch (type) {
        case 'email':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
        case 'sms':
            return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
        case 'slack':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
        case 'webhook':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
}

const smsProviders = [
    { value: 'twilio', label: 'Twilio' },
    { value: 'vonage', label: 'Vonage (Nexmo)' },
    { value: 'messagebird', label: 'MessageBird' },
];

// Filter out push for now since it requires more setup
const displayChannelTypes = computed(() =>
    props.channelTypes.filter(t => t !== 'push')
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Notification channels" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Notification Channels"
                    description="Configure how notifications are delivered to recipients"
                />

                <!-- Navigation Tabs -->
                <div class="border-b border-gray-200 dark:border-white/10">
                    <nav class="-mb-px flex space-x-8">
                        <Link
                            href="/settings/notifications"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Overview
                        </Link>
                        <Link
                            href="/settings/notifications/templates"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Templates
                        </Link>
                        <Link
                            href="/settings/notifications/subscriptions"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Triggers
                        </Link>
                        <Link
                            href="/settings/notifications/channels"
                            class="border-b-2 border-indigo-500 px-1 pb-4 text-sm font-medium text-indigo-600 dark:text-indigo-400"
                        >
                            Channels
                        </Link>
                        <Link
                            href="/settings/notifications/logs"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Logs
                        </Link>
                    </nav>
                </div>

                <!-- Channel Cards -->
                <div class="grid gap-6 sm:grid-cols-2">
                    <div
                        v-for="type in displayChannelTypes"
                        :key="type"
                        class="relative rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-white/5"
                    >
                        <!-- Status indicator -->
                        <div class="absolute right-4 top-4">
                            <div
                                v-if="isChannelConfigured(type)"
                                class="flex items-center gap-1.5"
                            >
                                <CheckCircleIcon class="h-5 w-5 text-green-500" />
                                <span class="text-xs text-green-600 dark:text-green-400">Configured</span>
                            </div>
                            <div v-else class="flex items-center gap-1.5">
                                <XCircleIcon class="h-5 w-5 text-gray-400" />
                                <span class="text-xs text-gray-500 dark:text-gray-400">Not configured</span>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div :class="['rounded-lg p-3', getChannelBadgeClass(type)]">
                                <component :is="getChannelIcon(type)" class="h-6 w-6" />
                            </div>
                            <div class="flex-1">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                                    {{ getChannelTitle(type) }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ getChannelDescription(type) }}
                                </p>
                            </div>
                        </div>

                        <!-- Channel-specific info -->
                        <div v-if="getChannelByType(type)?.settings" class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            <template v-if="type === 'email'">
                                <p v-if="getChannelByType(type)?.settings?.from_name">
                                    From: {{ getChannelByType(type)?.settings?.from_name }}
                                </p>
                                <p v-if="getChannelByType(type)?.settings?.from_email">
                                    Email: {{ getChannelByType(type)?.settings?.from_email }}
                                </p>
                            </template>
                            <template v-else-if="type === 'sms'">
                                <p v-if="getChannelByType(type)?.settings?.provider">
                                    Provider: {{ getChannelByType(type)?.settings?.provider }}
                                </p>
                                <p v-if="getChannelByType(type)?.settings?.from_number">
                                    From: {{ getChannelByType(type)?.settings?.from_number }}
                                </p>
                            </template>
                            <template v-else-if="type === 'slack'">
                                <p v-if="getChannelByType(type)?.settings?.channel">
                                    Channel: {{ getChannelByType(type)?.settings?.channel }}
                                </p>
                            </template>
                            <template v-else-if="type === 'webhook'">
                                <p v-if="getChannelByType(type)?.settings?.url" class="truncate">
                                    URL: {{ getChannelByType(type)?.settings?.url }}
                                </p>
                            </template>
                        </div>

                        <!-- Actions -->
                        <div class="mt-6 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <button
                                    @click="isChannelConfigured(type) && toggleChannel(type)"
                                    :disabled="!isChannelConfigured(type)"
                                    :class="[
                                        'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2',
                                        isChannelEnabled(type) ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700',
                                        !isChannelConfigured(type) ? 'cursor-not-allowed opacity-50' : '',
                                    ]"
                                >
                                    <span
                                        :class="[
                                            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                            isChannelEnabled(type) ? 'translate-x-5' : 'translate-x-0',
                                        ]"
                                    />
                                </button>
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ isChannelEnabled(type) ? 'Enabled' : 'Disabled' }}
                                </span>
                            </div>
                            <Button variant="outline" size="sm" @click="openEditModal(type)">
                                <PencilIcon class="mr-1.5 h-4 w-4" />
                                Configure
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Help text -->
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">About notification channels</h4>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Each notification template is associated with a specific channel. Configure the channels
                        you want to use, then create templates for each channel type. Triggers will send
                        notifications through the channel specified in the template.
                    </p>
                </div>
            </div>
        </SettingsLayout>

        <!-- Edit Modal -->
        <Teleport to="body">
            <div v-if="showModal && editingChannel" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="closeModal"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all dark:bg-gray-800 sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full" :class="getChannelBadgeClass(editingChannel)">
                                    <component :is="getChannelIcon(editingChannel)" class="h-6 w-6" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Configure {{ getChannelTitle(editingChannel) }}
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        {{ getChannelDescription(editingChannel) }}
                                    </p>
                                </div>

                                <!-- Email Form -->
                                <div v-if="editingChannel === 'email'" class="mt-6 space-y-4">
                                    <div>
                                        <Label for="from_name">From Name</Label>
                                        <Input
                                            id="from_name"
                                            v-model="emailForm.from_name"
                                            type="text"
                                            placeholder="e.g., Your Store"
                                            class="mt-1"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">The name that appears in the "From" field</p>
                                    </div>
                                    <div>
                                        <Label for="from_email">From Email</Label>
                                        <Input
                                            id="from_email"
                                            v-model="emailForm.from_email"
                                            type="email"
                                            placeholder="e.g., notifications@yourstore.com"
                                            class="mt-1"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">Leave empty to use default mail settings</p>
                                    </div>
                                    <div>
                                        <Label for="reply_to">Reply-To Email</Label>
                                        <Input
                                            id="reply_to"
                                            v-model="emailForm.reply_to"
                                            type="email"
                                            placeholder="e.g., support@yourstore.com"
                                            class="mt-1"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">Where replies should be sent (optional)</p>
                                    </div>
                                </div>

                                <!-- SMS Form -->
                                <div v-else-if="editingChannel === 'sms'" class="mt-6 space-y-4">
                                    <div>
                                        <Label for="sms_provider">Provider</Label>
                                        <select
                                            id="sms_provider"
                                            v-model="smsForm.provider"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-900 dark:text-white dark:ring-white/10 sm:text-sm sm:leading-6"
                                        >
                                            <option v-for="provider in smsProviders" :key="provider.value" :value="provider.value">
                                                {{ provider.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <Label for="account_sid">Account SID</Label>
                                        <Input
                                            id="account_sid"
                                            v-model="smsForm.account_sid"
                                            type="text"
                                            placeholder="Your account SID"
                                            class="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label for="auth_token">Auth Token</Label>
                                        <Input
                                            id="auth_token"
                                            v-model="smsForm.auth_token"
                                            type="password"
                                            placeholder="Your auth token"
                                            class="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label for="from_number">From Number</Label>
                                        <Input
                                            id="from_number"
                                            v-model="smsForm.from_number"
                                            type="text"
                                            placeholder="+1234567890"
                                            class="mt-1"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">Your Twilio phone number</p>
                                    </div>
                                </div>

                                <!-- Slack Form -->
                                <div v-else-if="editingChannel === 'slack'" class="mt-6 space-y-4">
                                    <div>
                                        <Label for="webhook_url">Webhook URL</Label>
                                        <Input
                                            id="webhook_url"
                                            v-model="slackForm.webhook_url"
                                            type="url"
                                            placeholder="https://hooks.slack.com/services/..."
                                            class="mt-1"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">
                                            Create an incoming webhook in your Slack workspace
                                        </p>
                                    </div>
                                    <div>
                                        <Label for="slack_channel">Default Channel</Label>
                                        <Input
                                            id="slack_channel"
                                            v-model="slackForm.channel"
                                            type="text"
                                            placeholder="#notifications"
                                            class="mt-1"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">Optional override channel</p>
                                    </div>
                                    <div>
                                        <Label for="slack_username">Bot Username</Label>
                                        <Input
                                            id="slack_username"
                                            v-model="slackForm.username"
                                            type="text"
                                            placeholder="ShopMata Bot"
                                            class="mt-1"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">Name that appears for messages</p>
                                    </div>
                                </div>

                                <!-- Webhook Form -->
                                <div v-else-if="editingChannel === 'webhook'" class="mt-6 space-y-4">
                                    <div>
                                        <Label for="webhook_endpoint">Webhook URL</Label>
                                        <Input
                                            id="webhook_endpoint"
                                            v-model="webhookForm.url"
                                            type="url"
                                            placeholder="https://api.example.com/webhooks"
                                            class="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label for="webhook_secret">Secret Key</Label>
                                        <Input
                                            id="webhook_secret"
                                            v-model="webhookForm.secret"
                                            type="password"
                                            placeholder="For signing requests"
                                            class="mt-1"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">
                                            Used to sign webhook payloads for verification
                                        </p>
                                    </div>
                                    <div>
                                        <Label for="webhook_headers">Custom Headers</Label>
                                        <textarea
                                            id="webhook_headers"
                                            v-model="webhookForm.headers"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-900 dark:text-white dark:ring-white/10 sm:text-sm sm:leading-6"
                                            placeholder="Authorization: Bearer token&#10;X-Custom-Header: value"
                                        ></textarea>
                                        <p class="mt-1 text-xs text-gray-500">
                                            One header per line in format: Header-Name: value
                                        </p>
                                    </div>
                                </div>

                                <!-- Error display -->
                                <div v-if="Object.keys(formErrors).length > 0" class="mt-4">
                                    <div class="rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                                        <div class="text-sm text-red-700 dark:text-red-400">
                                            <ul v-if="Object.keys(formErrors).length > 1" class="list-inside list-disc">
                                                <li v-for="(error, key) in formErrors" :key="key">{{ error }}</li>
                                            </ul>
                                            <p v-else>{{ Object.values(formErrors)[0] }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    @click="saveChannel"
                                    :disabled="isSubmitting"
                                    class="sm:col-start-2"
                                >
                                    {{ isSubmitting ? 'Saving...' : 'Save Configuration' }}
                                </Button>
                                <Button variant="outline" @click="closeModal" class="mt-3 sm:col-start-1 sm:mt-0">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
