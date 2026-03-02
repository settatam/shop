<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    CheckCircleIcon,
    XCircleIcon,
    PencilIcon,
    TrashIcon,
    ClipboardDocumentIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface ChannelConfig {
    id: number;
    channel: string;
    channel_label: string;
    is_active: boolean;
    has_credentials: boolean;
    credentials_summary: Record<string, string>;
}

interface AvailableChannel {
    value: string;
    label: string;
}

interface Props {
    configurations: ChannelConfig[];
    availableChannels: AvailableChannel[];
    webhookBaseUrl: string;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Settings',
        href: '/settings',
    },
    {
        title: 'Integrations',
        href: '/settings/integrations',
    },
];

const showModal = ref(false);
const editingChannel = ref<string | null>(null);
const isSubmitting = ref(false);
const formErrors = ref<Record<string, string>>({});
const copiedWebhook = ref<string | null>(null);

const whatsappForm = ref({
    phone_number_id: '',
    access_token: '',
});

const slackForm = ref({
    bot_token: '',
});

function getConfigByChannel(channel: string): ChannelConfig | undefined {
    return props.configurations.find(c => c.channel === channel);
}

function isConfigured(channel: string): boolean {
    const config = getConfigByChannel(channel);
    return config?.has_credentials ?? false;
}

function isActive(channel: string): boolean {
    const config = getConfigByChannel(channel);
    return config?.is_active ?? false;
}

function getChannelDescription(channel: string): string {
    switch (channel) {
        case 'whatsapp':
            return 'Receive and respond to customer messages via WhatsApp Business';
        case 'slack':
            return 'Receive and respond to messages from your Slack workspace';
        default:
            return '';
    }
}

function getChannelBadgeClass(channel: string): string {
    switch (channel) {
        case 'whatsapp':
            return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
        case 'slack':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
}

function getWebhookUrl(channel: string): string {
    return `${props.webhookBaseUrl}/${channel}`;
}

function openEditModal(channel: string) {
    editingChannel.value = channel;
    formErrors.value = {};

    // Reset forms — don't populate with masked values
    whatsappForm.value = { phone_number_id: '', access_token: '' };
    slackForm.value = { bot_token: '' };

    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingChannel.value = null;
    formErrors.value = {};
}

function saveChannel() {
    if (isSubmitting.value || !editingChannel.value) return;

    const credentials = editingChannel.value === 'whatsapp'
        ? whatsappForm.value
        : slackForm.value;

    isSubmitting.value = true;
    formErrors.value = {};

    router.post('/settings/integrations', {
        channel: editingChannel.value,
        credentials,
    }, {
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

function toggleChannel(channel: string) {
    const config = getConfigByChannel(channel);
    if (!config) return;

    router.post('/settings/integrations/toggle', {
        channel: channel,
        is_active: !config.is_active,
    }, {
        preserveScroll: true,
    });
}

function deleteChannel(channel: string) {
    const config = getConfigByChannel(channel);
    if (!config) return;

    if (!confirm('Are you sure you want to remove this integration? This will delete your stored credentials.')) {
        return;
    }

    router.delete(`/settings/integrations/${config.id}`, {
        preserveScroll: true,
    });
}

function copyWebhookUrl(channel: string) {
    navigator.clipboard.writeText(getWebhookUrl(channel));
    copiedWebhook.value = channel;
    setTimeout(() => {
        copiedWebhook.value = null;
    }, 2000);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Integrations" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Channel Integrations"
                    description="Connect messaging platforms to receive and respond to customer conversations"
                />

                <!-- Channel Cards -->
                <div class="grid gap-6 sm:grid-cols-2">
                    <div
                        v-for="channel in availableChannels"
                        :key="channel.value"
                        class="relative rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-white/5"
                    >
                        <!-- Status indicator -->
                        <div class="absolute right-4 top-4">
                            <div
                                v-if="isConfigured(channel.value)"
                                class="flex items-center gap-1.5"
                            >
                                <CheckCircleIcon class="h-5 w-5 text-green-500" />
                                <span class="text-xs text-green-600 dark:text-green-400">Connected</span>
                            </div>
                            <div v-else class="flex items-center gap-1.5">
                                <XCircleIcon class="h-5 w-5 text-gray-400" />
                                <span class="text-xs text-gray-500 dark:text-gray-400">Not configured</span>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div :class="['rounded-lg p-3', getChannelBadgeClass(channel.value)]">
                                <!-- WhatsApp icon -->
                                <svg v-if="channel.value === 'whatsapp'" class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                                </svg>
                                <!-- Slack icon -->
                                <svg v-else-if="channel.value === 'slack'" class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/>
                                </svg>
                            </div>
                            <div class="flex-1 pr-16">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                                    {{ channel.label }}
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ getChannelDescription(channel.value) }}
                                </p>
                            </div>
                        </div>

                        <!-- Credential summary -->
                        <div
                            v-if="isConfigured(channel.value)"
                            class="mt-4 space-y-1 text-sm text-gray-600 dark:text-gray-400"
                        >
                            <p
                                v-for="(value, key) in getConfigByChannel(channel.value)?.credentials_summary"
                                :key="key"
                            >
                                {{ String(key).replace(/_/g, ' ') }}: <span class="font-mono text-xs">{{ value }}</span>
                            </p>
                        </div>

                        <!-- Webhook URL -->
                        <div class="mt-4 rounded-md bg-gray-50 p-3 dark:bg-white/5">
                            <div class="flex items-center justify-between">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Webhook URL</p>
                                    <p class="truncate font-mono text-xs text-gray-700 dark:text-gray-300">
                                        {{ getWebhookUrl(channel.value) }}
                                    </p>
                                </div>
                                <button
                                    @click="copyWebhookUrl(channel.value)"
                                    class="ml-2 shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                    title="Copy webhook URL"
                                >
                                    <ClipboardDocumentIcon class="h-4 w-4" />
                                </button>
                            </div>
                            <p v-if="copiedWebhook === channel.value" class="mt-1 text-xs text-green-600 dark:text-green-400">
                                Copied!
                            </p>
                        </div>

                        <!-- Actions -->
                        <div class="mt-6 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <button
                                    @click="isConfigured(channel.value) && toggleChannel(channel.value)"
                                    :disabled="!isConfigured(channel.value)"
                                    :class="[
                                        'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2',
                                        isActive(channel.value) ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700',
                                        !isConfigured(channel.value) ? 'cursor-not-allowed opacity-50' : '',
                                    ]"
                                >
                                    <span
                                        :class="[
                                            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                            isActive(channel.value) ? 'translate-x-5' : 'translate-x-0',
                                        ]"
                                    />
                                </button>
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ isActive(channel.value) ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button
                                    v-if="isConfigured(channel.value)"
                                    variant="ghost"
                                    size="sm"
                                    @click="deleteChannel(channel.value)"
                                >
                                    <TrashIcon class="h-4 w-4 text-red-500" />
                                </Button>
                                <Button variant="outline" size="sm" @click="openEditModal(channel.value)">
                                    <PencilIcon class="mr-1.5 h-4 w-4" />
                                    {{ isConfigured(channel.value) ? 'Edit' : 'Configure' }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help text -->
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">Setting up integrations</h4>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Enter your channel credentials and copy the webhook URL above into your provider's webhook settings.
                        Once configured, incoming messages will be routed to your AI assistant and can be escalated to agents.
                    </p>
                </div>
            </div>
        </SettingsLayout>

        <!-- Configure Modal -->
        <Dialog :open="showModal" @update:open="(val: boolean) => { if (!val) closeModal() }">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Configure {{ editingChannel === 'whatsapp' ? 'WhatsApp' : 'Slack' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ editingChannel ? getChannelDescription(editingChannel) : '' }}
                    </DialogDescription>
                </DialogHeader>

                <!-- WhatsApp Form -->
                <div v-if="editingChannel === 'whatsapp'" class="space-y-4">
                    <div>
                        <Label for="phone_number_id">Phone Number ID</Label>
                        <Input
                            id="phone_number_id"
                            v-model="whatsappForm.phone_number_id"
                            type="text"
                            placeholder="e.g., 123456789012345"
                            class="mt-1"
                        />
                        <p v-if="formErrors['credentials.phone_number_id']" class="mt-1 text-xs text-red-600">
                            {{ formErrors['credentials.phone_number_id'] }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500">From your Meta Business Suite WhatsApp settings</p>
                    </div>
                    <div>
                        <Label for="access_token">Access Token</Label>
                        <Input
                            id="access_token"
                            v-model="whatsappForm.access_token"
                            type="password"
                            placeholder="Your permanent access token"
                            class="mt-1"
                        />
                        <p v-if="formErrors['credentials.access_token']" class="mt-1 text-xs text-red-600">
                            {{ formErrors['credentials.access_token'] }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500">Generate a permanent token in Meta Developer Console</p>
                    </div>
                </div>

                <!-- Slack Form -->
                <div v-else-if="editingChannel === 'slack'" class="space-y-4">
                    <div>
                        <Label for="bot_token">Bot Token</Label>
                        <Input
                            id="bot_token"
                            v-model="slackForm.bot_token"
                            type="password"
                            placeholder="xoxb-..."
                            class="mt-1"
                        />
                        <p v-if="formErrors['credentials.bot_token']" class="mt-1 text-xs text-red-600">
                            {{ formErrors['credentials.bot_token'] }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500">From your Slack app's OAuth & Permissions page</p>
                    </div>
                </div>

                <!-- Error display -->
                <div v-if="Object.keys(formErrors).length > 0 && !formErrors['credentials.phone_number_id'] && !formErrors['credentials.access_token'] && !formErrors['credentials.bot_token']" class="rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                    <div class="text-sm text-red-700 dark:text-red-400">
                        <ul v-if="Object.keys(formErrors).length > 1" class="list-inside list-disc">
                            <li v-for="(error, key) in formErrors" :key="key">{{ error }}</li>
                        </ul>
                        <p v-else>{{ Object.values(formErrors)[0] }}</p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="closeModal">Cancel</Button>
                    <Button @click="saveChannel" :disabled="isSubmitting">
                        {{ isSubmitting ? 'Saving...' : 'Save' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
