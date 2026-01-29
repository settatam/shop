<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    ArrowsUpDownIcon,
    BoltIcon,
    ArrowRightIcon,
    BellIcon,
    GlobeAltIcon,
    CommandLineIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Transition {
    id: number;
    to_status_id: number;
    to_status_name: string;
    to_status_slug: string;
    name: string | null;
    is_enabled: boolean;
}

interface Automation {
    id: number;
    trigger: string;
    action_type: string;
    action_config: Record<string, unknown>;
    is_enabled: boolean;
    sort_order: number;
}

interface Status {
    id: number;
    name: string;
    slug: string;
    entity_type: string;
    color: string;
    icon: string | null;
    description: string | null;
    is_default: boolean;
    is_final: boolean;
    is_system: boolean;
    sort_order: number;
    behavior: Record<string, boolean>;
    transitions: Transition[];
    automations: Automation[];
    automations_count: number;
}

interface EntityType {
    value: string;
    label: string;
    plural_label: string;
}

interface SelectOption {
    value: string;
    label: string;
}

interface NotificationTemplate {
    id: number;
    name: string;
    event_type: string;
}

interface AutomationOptions {
    triggers: SelectOption[];
    action_types: SelectOption[];
    recipients: SelectOption[];
    custom_actions: SelectOption[];
    webhook_methods: SelectOption[];
}

interface Props {
    statuses: Record<string, Status[]>;
    entityTypes: EntityType[];
    behaviorFlags: Record<string, string>;
    notificationTemplates: NotificationTemplate[];
    automationOptions: AutomationOptions;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Statuses',
        href: '/settings/statuses',
    },
];

// Active tab
const activeTab = ref(props.entityTypes[0]?.value || 'transaction');

// Modal states
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const showTransitionsModal = ref(false);
const showAutomationsModal = ref(false);
const showAutomationFormModal = ref(false);

// Form state
const selectedStatus = ref<Status | null>(null);
const selectedAutomation = ref<Automation | null>(null);
const formData = ref({
    name: '',
    slug: '',
    entity_type: '',
    color: '#3b82f6',
    icon: '',
    description: '',
    is_default: false,
    is_final: false,
    behavior: {} as Record<string, boolean>,
});

const automationFormData = ref({
    trigger: 'on_enter',
    action_type: 'notification',
    is_enabled: true,
    // Notification config
    template_id: null as number | null,
    recipients: [] as string[],
    // Webhook config
    webhook_url: '',
    webhook_method: 'POST',
    webhook_headers: '',
    // Custom action config
    custom_action: '',
    custom_params: '',
});

const formErrors = ref<Record<string, string>>({});
const automationFormErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

// Color presets
const colorPresets = [
    '#6b7280', '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16',
    '#22c55e', '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9', '#3b82f6',
    '#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899', '#f43f5e',
];

const currentTabStatuses = computed(() => {
    return props.statuses[activeTab.value] || [];
});

function openCreateModal() {
    formErrors.value = {};
    formData.value = {
        name: '',
        slug: '',
        entity_type: activeTab.value,
        color: '#3b82f6',
        icon: '',
        description: '',
        is_default: false,
        is_final: false,
        behavior: {},
    };
    showCreateModal.value = true;
}

function openEditModal(status: Status) {
    selectedStatus.value = status;
    formData.value = {
        name: status.name,
        slug: status.slug,
        entity_type: status.entity_type,
        color: status.color,
        icon: status.icon || '',
        description: status.description || '',
        is_default: status.is_default,
        is_final: status.is_final,
        behavior: { ...status.behavior },
    };
    formErrors.value = {};
    showEditModal.value = true;
}

function openDeleteModal(status: Status) {
    selectedStatus.value = status;
    showDeleteModal.value = true;
}

function openTransitionsModal(status: Status) {
    selectedStatus.value = status;
    showTransitionsModal.value = true;
}

function openAutomationsModal(status: Status) {
    selectedStatus.value = status;
    showAutomationsModal.value = true;
}

function openAutomationForm(automation?: Automation) {
    selectedAutomation.value = automation || null;
    automationFormErrors.value = {};

    if (automation) {
        automationFormData.value = {
            trigger: automation.trigger,
            action_type: automation.action_type,
            is_enabled: automation.is_enabled,
            template_id: (automation.action_config.template_id as number) || null,
            recipients: (automation.action_config.recipients as string[]) || [],
            webhook_url: (automation.action_config.url as string) || '',
            webhook_method: (automation.action_config.method as string) || 'POST',
            webhook_headers: automation.action_config.headers
                ? JSON.stringify(automation.action_config.headers, null, 2)
                : '',
            custom_action: (automation.action_config.action as string) || '',
            custom_params: automation.action_config.params
                ? JSON.stringify(automation.action_config.params, null, 2)
                : '',
        };
    } else {
        automationFormData.value = {
            trigger: 'on_enter',
            action_type: 'notification',
            is_enabled: true,
            template_id: null,
            recipients: [],
            webhook_url: '',
            webhook_method: 'POST',
            webhook_headers: '',
            custom_action: '',
            custom_params: '',
        };
    }

    showAutomationFormModal.value = true;
}

function closeModals() {
    showCreateModal.value = false;
    showEditModal.value = false;
    showDeleteModal.value = false;
    showTransitionsModal.value = false;
    showAutomationsModal.value = false;
    showAutomationFormModal.value = false;
    selectedStatus.value = null;
    selectedAutomation.value = null;
    formErrors.value = {};
    automationFormErrors.value = {};
}

function closeAutomationForm() {
    showAutomationFormModal.value = false;
    selectedAutomation.value = null;
    automationFormErrors.value = {};
}

function generateSlug(name: string): string {
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
}

function onNameChange(e: Event) {
    const name = (e.target as HTMLInputElement).value;
    formData.value.name = name;
    if (!selectedStatus.value) {
        formData.value.slug = generateSlug(name);
    }
}

function toggleBehavior(key: string) {
    if (formData.value.behavior[key]) {
        delete formData.value.behavior[key];
    } else {
        formData.value.behavior[key] = true;
    }
}

function toggleRecipient(recipient: string) {
    const index = automationFormData.value.recipients.indexOf(recipient);
    if (index === -1) {
        automationFormData.value.recipients.push(recipient);
    } else {
        automationFormData.value.recipients.splice(index, 1);
    }
}

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find(row => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] || ''
    );
}

async function createStatus() {
    if (isSubmitting.value) return;
    isSubmitting.value = true;
    formErrors.value = {};

    try {
        const response = await fetch('/api/v1/statuses', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'include',
            body: JSON.stringify(formData.value),
        });

        const data = await response.json();

        if (!response.ok) {
            formErrors.value = response.status === 422
                ? (data.errors || { name: data.message })
                : { name: data.message || 'Failed to create status' };
            return;
        }

        closeModals();
        router.reload({ only: ['statuses'] });
    } catch {
        formErrors.value = { name: 'An error occurred. Please try again.' };
    } finally {
        isSubmitting.value = false;
    }
}

async function updateStatus() {
    if (!selectedStatus.value || isSubmitting.value) return;
    isSubmitting.value = true;
    formErrors.value = {};

    try {
        const response = await fetch(`/api/v1/statuses/${selectedStatus.value.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'include',
            body: JSON.stringify({
                name: formData.value.name,
                color: formData.value.color,
                icon: formData.value.icon || null,
                description: formData.value.description || null,
                is_default: formData.value.is_default,
                is_final: formData.value.is_final,
                behavior: formData.value.behavior,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            formErrors.value = response.status === 422
                ? (data.errors || { name: data.message })
                : { name: data.message || 'Failed to update status' };
            return;
        }

        closeModals();
        router.reload({ only: ['statuses'] });
    } catch {
        formErrors.value = { name: 'An error occurred. Please try again.' };
    } finally {
        isSubmitting.value = false;
    }
}

async function deleteStatus() {
    if (!selectedStatus.value || isSubmitting.value) return;
    isSubmitting.value = true;

    try {
        const response = await fetch(`/api/v1/statuses/${selectedStatus.value.id}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            credentials: 'include',
        });

        if (!response.ok) {
            const data = await response.json();
            alert(data.message || 'Failed to delete status');
            return;
        }

        closeModals();
        router.reload({ only: ['statuses'] });
    } catch {
        alert('An error occurred. Please try again.');
    } finally {
        isSubmitting.value = false;
    }
}

async function toggleTransition(statusId: number, toStatusId: number, currentlyEnabled: boolean) {
    const method = currentlyEnabled ? 'DELETE' : 'POST';
    const url = currentlyEnabled
        ? `/api/v1/status-transitions/${statusId}-${toStatusId}`
        : `/api/v1/statuses/${statusId}/transitions`;

    try {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'include',
            body: method === 'POST' ? JSON.stringify({ to_status_id: toStatusId }) : undefined,
        });

        if (!response.ok) {
            const data = await response.json();
            alert(data.message || 'Failed to update transition');
            return;
        }

        router.reload({ only: ['statuses'] });
    } catch {
        alert('An error occurred. Please try again.');
    }
}

function buildActionConfig(): Record<string, unknown> {
    const { action_type } = automationFormData.value;

    if (action_type === 'notification') {
        return {
            template_id: automationFormData.value.template_id,
            recipients: automationFormData.value.recipients,
        };
    }

    if (action_type === 'webhook') {
        let headers = {};
        if (automationFormData.value.webhook_headers.trim()) {
            try {
                headers = JSON.parse(automationFormData.value.webhook_headers);
            } catch {
                automationFormErrors.value = { webhook_headers: 'Invalid JSON for headers' };
                throw new Error('Invalid headers JSON');
            }
        }
        return {
            url: automationFormData.value.webhook_url,
            method: automationFormData.value.webhook_method,
            headers,
        };
    }

    if (action_type === 'custom') {
        let params = {};
        if (automationFormData.value.custom_params.trim()) {
            try {
                params = JSON.parse(automationFormData.value.custom_params);
            } catch {
                automationFormErrors.value = { custom_params: 'Invalid JSON for parameters' };
                throw new Error('Invalid params JSON');
            }
        }
        return {
            action: automationFormData.value.custom_action,
            params,
        };
    }

    return {};
}

async function saveAutomation() {
    if (!selectedStatus.value || isSubmitting.value) return;
    isSubmitting.value = true;
    automationFormErrors.value = {};

    try {
        const actionConfig = buildActionConfig();
        const payload = {
            trigger: automationFormData.value.trigger,
            action_type: automationFormData.value.action_type,
            action_config: actionConfig,
            is_enabled: automationFormData.value.is_enabled,
        };

        const url = selectedAutomation.value
            ? `/api/v1/status-automations/${selectedAutomation.value.id}`
            : `/api/v1/statuses/${selectedStatus.value.id}/automations`;

        const response = await fetch(url, {
            method: selectedAutomation.value ? 'PATCH' : 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'include',
            body: JSON.stringify(payload),
        });

        const data = await response.json();

        if (!response.ok) {
            automationFormErrors.value = response.status === 422
                ? (data.errors || { trigger: data.message })
                : { trigger: data.message || 'Failed to save automation' };
            return;
        }

        closeAutomationForm();
        router.reload({ only: ['statuses'] });
    } catch (e) {
        if (!automationFormErrors.value.webhook_headers && !automationFormErrors.value.custom_params) {
            automationFormErrors.value = { trigger: 'An error occurred. Please try again.' };
        }
    } finally {
        isSubmitting.value = false;
    }
}

async function deleteAutomation(automationId: number) {
    if (!confirm('Are you sure you want to delete this automation?')) return;

    try {
        const response = await fetch(`/api/v1/status-automations/${automationId}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            credentials: 'include',
        });

        if (!response.ok) {
            const data = await response.json();
            alert(data.message || 'Failed to delete automation');
            return;
        }

        router.reload({ only: ['statuses'] });
    } catch {
        alert('An error occurred. Please try again.');
    }
}

function getAvailableTransitionTargets(status: Status): Status[] {
    return currentTabStatuses.value.filter(s => s.id !== status.id);
}

function hasTransitionTo(status: Status, targetId: number): boolean {
    return status.transitions.some(t => t.to_status_id === targetId && t.is_enabled);
}

function getActionTypeIcon(actionType: string) {
    switch (actionType) {
        case 'notification': return BellIcon;
        case 'webhook': return GlobeAltIcon;
        case 'custom': return CommandLineIcon;
        default: return BoltIcon;
    }
}

function getActionTypeLabel(actionType: string): string {
    return props.automationOptions.action_types.find(a => a.value === actionType)?.label || actionType;
}

function getTriggerLabel(trigger: string): string {
    return props.automationOptions.triggers.find(t => t.value === trigger)?.label || trigger;
}

function getAutomationDescription(automation: Automation): string {
    const config = automation.action_config;

    if (automation.action_type === 'notification') {
        const template = props.notificationTemplates.find(t => t.id === config.template_id);
        const templateName = template?.name || 'Unknown template';
        const recipientCount = (config.recipients as string[])?.length || 0;
        return `${templateName} to ${recipientCount} recipient${recipientCount !== 1 ? 's' : ''}`;
    }

    if (automation.action_type === 'webhook') {
        return `${config.method || 'POST'} ${config.url || 'No URL'}`;
    }

    if (automation.action_type === 'custom') {
        const action = props.automationOptions.custom_actions.find(a => a.value === config.action);
        return action?.label || (config.action as string) || 'Unknown action';
    }

    return 'Unknown automation';
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Status Management" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Status Management"
                        description="Configure custom statuses and workflows for your orders, transactions, repairs, and memos"
                    />
                    <Button @click="openCreateModal()" size="sm">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Create status
                    </Button>
                </div>

                <!-- Entity type tabs -->
                <div class="border-b border-gray-200 dark:border-white/10">
                    <nav class="-mb-px flex space-x-8">
                        <button
                            v-for="entityType in entityTypes"
                            :key="entityType.value"
                            @click="activeTab = entityType.value"
                            :class="[
                                activeTab === entityType.value
                                    ? 'border-indigo-500 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300',
                                'whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium'
                            ]"
                        >
                            {{ entityType.plural_label }}
                            <span
                                :class="[
                                    activeTab === entityType.value
                                        ? 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-400'
                                        : 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-gray-300',
                                    'ml-2 hidden rounded-full px-2.5 py-0.5 text-xs font-medium md:inline-block'
                                ]"
                            >
                                {{ (statuses[entityType.value] || []).length }}
                            </span>
                        </button>
                    </nav>
                </div>

                <!-- Status list -->
                <div class="space-y-3">
                    <div
                        v-for="status in currentTabStatuses"
                        :key="status.id"
                        class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex items-start gap-3">
                                <div
                                    class="mt-1 h-4 w-4 shrink-0 rounded-full"
                                    :style="{ backgroundColor: status.color }"
                                ></div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                                            {{ status.name }}
                                        </h3>
                                        <span
                                            v-if="status.is_system"
                                            class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 ring-1 ring-indigo-700/10 ring-inset dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20"
                                        >
                                            System
                                        </span>
                                        <span
                                            v-if="status.is_default"
                                            class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-700/10 ring-inset dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20"
                                        >
                                            Default
                                        </span>
                                        <span
                                            v-if="status.is_final"
                                            class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-700/10 ring-inset dark:bg-white/5 dark:text-gray-400 dark:ring-white/10"
                                        >
                                            Final
                                        </span>
                                    </div>
                                    <p v-if="status.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        {{ status.description }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-400 dark:text-gray-500">
                                        <span>Slug: {{ status.slug }}</span>
                                        <span v-if="status.transitions.length > 0" class="flex items-center gap-1">
                                            <ArrowRightIcon class="h-3 w-3" />
                                            {{ status.transitions.length }} transition{{ status.transitions.length !== 1 ? 's' : '' }}
                                        </span>
                                        <span v-if="status.automations_count > 0" class="flex items-center gap-1">
                                            <BoltIcon class="h-3 w-3" />
                                            {{ status.automations_count }} automation{{ status.automations_count !== 1 ? 's' : '' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openAutomationsModal(status)"
                                    title="Configure automations"
                                >
                                    <BoltIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openTransitionsModal(status)"
                                    title="Configure transitions"
                                >
                                    <ArrowsUpDownIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openEditModal(status)"
                                    title="Edit status"
                                >
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    v-if="!status.is_system"
                                    variant="ghost"
                                    size="sm"
                                    @click="openDeleteModal(status)"
                                    title="Delete status"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="currentTabStatuses.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No statuses configured for this entity type yet.
                </p>
            </div>
        </SettingsLayout>

        <!-- Create Status Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Create new status</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Add a new status for {{ entityTypes.find(e => e.value === activeTab)?.plural_label.toLowerCase() }}
                                </p>
                                <div class="mt-6 space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label for="create-name">Status name</Label>
                                            <Input id="create-name" :value="formData.name" @input="onNameChange" type="text" placeholder="e.g., Awaiting Approval" class="mt-1" />
                                            <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formErrors.name }}</p>
                                        </div>
                                        <div>
                                            <Label for="create-slug">Slug</Label>
                                            <Input id="create-slug" v-model="formData.slug" type="text" placeholder="e.g., awaiting_approval" class="mt-1" />
                                            <p v-if="formErrors.slug" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formErrors.slug }}</p>
                                        </div>
                                    </div>
                                    <div>
                                        <Label for="create-description">Description</Label>
                                        <Input id="create-description" v-model="formData.description" type="text" placeholder="What this status means..." class="mt-1" />
                                    </div>
                                    <div>
                                        <Label>Color</Label>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                v-for="color in colorPresets"
                                                :key="color"
                                                type="button"
                                                @click="formData.color = color"
                                                :class="['h-6 w-6 rounded-full transition-all', formData.color === color ? 'ring-2 ring-offset-2 ring-indigo-500 dark:ring-offset-gray-800' : '']"
                                                :style="{ backgroundColor: color }"
                                            ></button>
                                        </div>
                                    </div>
                                    <div>
                                        <Label>Behavior</Label>
                                        <div class="mt-2 space-y-2">
                                            <label v-for="(label, key) in behaviorFlags" :key="key" class="flex items-center gap-2">
                                                <input type="checkbox" :checked="formData.behavior[key]" @change="toggleBehavior(key)" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5" />
                                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ label }}</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" v-model="formData.is_default" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5" />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Default status</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" v-model="formData.is_final" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5" />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Final status</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button @click="createStatus" :disabled="!formData.name || !formData.slug || isSubmitting" class="w-full sm:ml-3 sm:w-auto">
                                    {{ isSubmitting ? 'Creating...' : 'Create status' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 w-full sm:mt-0 sm:w-auto">Cancel</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Edit Status Modal -->
        <Teleport to="body">
            <div v-if="showEditModal && selectedStatus" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Edit status: {{ selectedStatus.name }}</h3>
                                <div class="mt-6 space-y-4">
                                    <div>
                                        <Label for="edit-name">Status name</Label>
                                        <Input id="edit-name" v-model="formData.name" type="text" :disabled="selectedStatus.is_system" class="mt-1" />
                                        <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ formErrors.name }}</p>
                                    </div>
                                    <div>
                                        <Label for="edit-description">Description</Label>
                                        <Input id="edit-description" v-model="formData.description" type="text" class="mt-1" />
                                    </div>
                                    <div>
                                        <Label>Color</Label>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                v-for="color in colorPresets"
                                                :key="color"
                                                type="button"
                                                @click="formData.color = color"
                                                :class="['h-6 w-6 rounded-full transition-all', formData.color === color ? 'ring-2 ring-offset-2 ring-indigo-500 dark:ring-offset-gray-800' : '']"
                                                :style="{ backgroundColor: color }"
                                            ></button>
                                        </div>
                                    </div>
                                    <div>
                                        <Label>Behavior</Label>
                                        <div class="mt-2 space-y-2">
                                            <label v-for="(label, key) in behaviorFlags" :key="key" class="flex items-center gap-2">
                                                <input type="checkbox" :checked="formData.behavior[key]" @change="toggleBehavior(key)" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5" />
                                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ label }}</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" v-model="formData.is_default" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5" />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Default status</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" v-model="formData.is_final" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5" />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Final status</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button @click="updateStatus" :disabled="!formData.name || isSubmitting" class="w-full sm:ml-3 sm:w-auto">
                                    {{ isSubmitting ? 'Saving...' : 'Save changes' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 w-full sm:mt-0 sm:w-auto">Cancel</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Status Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedStatus" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Delete status</h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete <span class="font-medium">{{ selectedStatus.name }}</span>? This cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button variant="destructive" @click="deleteStatus" :disabled="isSubmitting" class="sm:col-start-2">
                                    {{ isSubmitting ? 'Deleting...' : 'Delete' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 sm:col-start-1 sm:mt-0">Cancel</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Transitions Modal -->
        <Teleport to="body">
            <div v-if="showTransitionsModal && selectedStatus" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Transitions from: {{ selectedStatus.name }}</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Configure which statuses can be reached from this status</p>
                                <div class="mt-6 space-y-2">
                                    <div
                                        v-for="targetStatus in getAvailableTransitionTargets(selectedStatus)"
                                        :key="targetStatus.id"
                                        class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div class="h-3 w-3 rounded-full" :style="{ backgroundColor: targetStatus.color }"></div>
                                            <div>
                                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ targetStatus.name }}</span>
                                                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">({{ targetStatus.slug }})</span>
                                            </div>
                                        </div>
                                        <button
                                            @click="toggleTransition(selectedStatus.id, targetStatus.id, hasTransitionTo(selectedStatus, targetStatus.id))"
                                            :class="[
                                                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800',
                                                hasTransitionTo(selectedStatus, targetStatus.id) ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-white/10'
                                            ]"
                                        >
                                            <span :class="['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out', hasTransitionTo(selectedStatus, targetStatus.id) ? 'translate-x-5' : 'translate-x-0']"></span>
                                        </button>
                                    </div>
                                </div>
                                <div v-if="getAvailableTransitionTargets(selectedStatus).length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No other statuses available to transition to.
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:px-6">
                                <Button variant="outline" @click="closeModals" class="w-full">Done</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Automations Modal -->
        <Teleport to="body">
            <div v-if="showAutomationsModal && selectedStatus" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-xl">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Automations for: {{ selectedStatus.name }}</h3>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Configure actions that run when entering or leaving this status</p>
                                    </div>
                                    <Button size="sm" @click="openAutomationForm()">
                                        <PlusIcon class="mr-1 h-4 w-4" />
                                        Add
                                    </Button>
                                </div>

                                <div class="mt-6 space-y-3">
                                    <div
                                        v-for="automation in selectedStatus.automations"
                                        :key="automation.id"
                                        class="flex items-start justify-between rounded-lg border border-gray-200 p-4 dark:border-white/10"
                                    >
                                        <div class="flex items-start gap-3">
                                            <div class="mt-0.5 flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 dark:bg-white/10">
                                                <component :is="getActionTypeIcon(automation.action_type)" class="h-4 w-4 text-gray-600 dark:text-gray-400" />
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                                        {{ getActionTypeLabel(automation.action_type) }}
                                                    </span>
                                                    <span
                                                        v-if="!automation.is_enabled"
                                                        class="inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-400"
                                                    >
                                                        Disabled
                                                    </span>
                                                </div>
                                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                    {{ getTriggerLabel(automation.trigger) }}
                                                </p>
                                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                                    {{ getAutomationDescription(automation) }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <Button variant="ghost" size="sm" @click="openAutomationForm(automation)" title="Edit automation">
                                                <PencilSquareIcon class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                @click="deleteAutomation(automation.id)"
                                                title="Delete automation"
                                                class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                <TrashIcon class="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    <div v-if="selectedStatus.automations.length === 0" class="py-8 text-center">
                                        <BoltIcon class="mx-auto h-8 w-8 text-gray-400" />
                                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No automations configured</p>
                                        <Button size="sm" variant="outline" @click="openAutomationForm()" class="mt-3">
                                            <PlusIcon class="mr-1 h-4 w-4" />
                                            Add automation
                                        </Button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:px-6">
                                <Button variant="outline" @click="closeModals" class="w-full">Done</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Automation Form Modal -->
        <Teleport to="body">
            <div v-if="showAutomationFormModal && selectedStatus" class="relative z-[60]">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeAutomationForm"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    {{ selectedAutomation ? 'Edit automation' : 'Add automation' }}
                                </h3>

                                <div class="mt-6 space-y-4">
                                    <!-- Trigger -->
                                    <div>
                                        <Label>Trigger</Label>
                                        <select
                                            v-model="automationFormData.trigger"
                                            class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                                        >
                                            <option v-for="trigger in automationOptions.triggers" :key="trigger.value" :value="trigger.value">
                                                {{ trigger.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Action Type -->
                                    <div>
                                        <Label>Action type</Label>
                                        <select
                                            v-model="automationFormData.action_type"
                                            class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                                        >
                                            <option v-for="action in automationOptions.action_types" :key="action.value" :value="action.value">
                                                {{ action.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Notification Config -->
                                    <template v-if="automationFormData.action_type === 'notification'">
                                        <div>
                                            <Label>Notification template</Label>
                                            <select
                                                v-model="automationFormData.template_id"
                                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                                            >
                                                <option :value="null">Select a template...</option>
                                                <option v-for="template in notificationTemplates" :key="template.id" :value="template.id">
                                                    {{ template.name }}
                                                </option>
                                            </select>
                                            <p v-if="notificationTemplates.length === 0" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                No notification templates found. Create one in Settings > Notifications.
                                            </p>
                                        </div>
                                        <div>
                                            <Label>Recipients</Label>
                                            <div class="mt-2 space-y-2">
                                                <label v-for="recipient in automationOptions.recipients" :key="recipient.value" class="flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        :checked="automationFormData.recipients.includes(recipient.value)"
                                                        @change="toggleRecipient(recipient.value)"
                                                        class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                                    />
                                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ recipient.label }}</span>
                                                </label>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Webhook Config -->
                                    <template v-if="automationFormData.action_type === 'webhook'">
                                        <div>
                                            <Label for="webhook-url">Webhook URL</Label>
                                            <Input id="webhook-url" v-model="automationFormData.webhook_url" type="url" placeholder="https://example.com/webhook" class="mt-1" />
                                        </div>
                                        <div>
                                            <Label>HTTP Method</Label>
                                            <select
                                                v-model="automationFormData.webhook_method"
                                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                                            >
                                                <option v-for="method in automationOptions.webhook_methods" :key="method.value" :value="method.value">
                                                    {{ method.label }}
                                                </option>
                                            </select>
                                        </div>
                                        <div>
                                            <Label for="webhook-headers">Headers (JSON, optional)</Label>
                                            <textarea
                                                id="webhook-headers"
                                                v-model="automationFormData.webhook_headers"
                                                rows="3"
                                                placeholder='{"Authorization": "Bearer token"}'
                                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                                            ></textarea>
                                            <p v-if="automationFormErrors.webhook_headers" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ automationFormErrors.webhook_headers }}
                                            </p>
                                        </div>
                                    </template>

                                    <!-- Custom Action Config -->
                                    <template v-if="automationFormData.action_type === 'custom'">
                                        <div>
                                            <Label>Action</Label>
                                            <select
                                                v-model="automationFormData.custom_action"
                                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                                            >
                                                <option value="">Select an action...</option>
                                                <option v-for="action in automationOptions.custom_actions" :key="action.value" :value="action.value">
                                                    {{ action.label }}
                                                </option>
                                            </select>
                                        </div>
                                        <div>
                                            <Label for="custom-params">Parameters (JSON, optional)</Label>
                                            <textarea
                                                id="custom-params"
                                                v-model="automationFormData.custom_params"
                                                rows="3"
                                                placeholder='{"auto_send": true}'
                                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/20 dark:bg-gray-900 dark:text-white"
                                            ></textarea>
                                            <p v-if="automationFormErrors.custom_params" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ automationFormErrors.custom_params }}
                                            </p>
                                        </div>
                                    </template>

                                    <!-- Enabled toggle -->
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="automation-enabled"
                                            v-model="automationFormData.is_enabled"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                        />
                                        <Label for="automation-enabled" class="!mb-0">Enabled</Label>
                                    </div>

                                    <p v-if="automationFormErrors.trigger" class="text-sm text-red-600 dark:text-red-400">
                                        {{ automationFormErrors.trigger }}
                                    </p>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button @click="saveAutomation" :disabled="isSubmitting" class="w-full sm:ml-3 sm:w-auto">
                                    {{ isSubmitting ? 'Saving...' : (selectedAutomation ? 'Save changes' : 'Add automation') }}
                                </Button>
                                <Button variant="outline" @click="closeAutomationForm" class="mt-3 w-full sm:mt-0 sm:w-auto">Cancel</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
