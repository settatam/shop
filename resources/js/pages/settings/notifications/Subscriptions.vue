<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems, Listbox, ListboxButton, ListboxOptions, ListboxOption } from '@headlessui/vue';
import {
    BellIcon,
    EllipsisVerticalIcon,
    PencilIcon,
    TrashIcon,
    PlayIcon,
    PlusIcon,
    ChevronDownIcon,
    CheckIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface NotificationTemplate {
    id: number;
    name: string;
    channel: string;
    category: string | null;
}

interface NotificationSubscription {
    id: number;
    activity: string;
    name: string | null;
    description: string | null;
    notification_template_id: number;
    conditions: Array<{ field: string; operator: string; value: string }>;
    recipients: Array<{ type: string; value?: string }>;
    schedule_type: string;
    delay_minutes: number | null;
    delay_unit: string | null;
    is_enabled: boolean;
    template?: NotificationTemplate;
}

interface ActivityDefinition {
    name: string;
    category: string;
    description: string;
}

interface Props {
    subscriptions: NotificationSubscription[];
    templates: NotificationTemplate[];
    activities: Record<string, ActivityDefinition>;
    groupedActivities: Record<string, Record<string, ActivityDefinition>>;
    scheduleTypes: string[];
    recipientTypes: string[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Notifications', href: '/settings/notifications' },
    { title: 'Triggers', href: '/settings/notifications/subscriptions' },
];

const showFormModal = ref(false);
const showDeleteModal = ref(false);
const isEditing = ref(false);
const selectedSubscription = ref<NotificationSubscription | null>(null);
const isSaving = ref(false);
const isDeleting = ref(false);
const isTesting = ref(false);

const form = ref({
    activity: '',
    name: '',
    description: '',
    notification_template_id: null as number | null,
    recipients: [{ type: 'owner', value: '' }] as Array<{ type: string; value: string }>,
    conditions: [] as Array<{ field: string; operator: string; value: string }>,
    schedule_type: 'immediate',
    delay_minutes: 5,
    delay_unit: 'minutes',
    is_enabled: true,
});

const formErrors = ref<Record<string, string>>({});

const operators = [
    { value: '==', label: 'equals' },
    { value: '!=', label: 'not equals' },
    { value: '>', label: 'greater than' },
    { value: '>=', label: 'greater or equal' },
    { value: '<', label: 'less than' },
    { value: '<=', label: 'less or equal' },
    { value: 'contains', label: 'contains' },
    { value: 'empty', label: 'is empty' },
    { value: 'not_empty', label: 'is not empty' },
];

const selectedActivity = computed(() => {
    if (!form.value.activity) return null;
    return props.activities[form.value.activity] || null;
});

function openCreateModal() {
    isEditing.value = false;
    selectedSubscription.value = null;
    form.value = {
        activity: '',
        name: '',
        description: '',
        notification_template_id: null,
        recipients: [{ type: 'owner', value: '' }],
        conditions: [],
        schedule_type: 'immediate',
        delay_minutes: 5,
        delay_unit: 'minutes',
        is_enabled: true,
    };
    formErrors.value = {};
    showFormModal.value = true;
}

function openEditModal(subscription: NotificationSubscription) {
    isEditing.value = true;
    selectedSubscription.value = subscription;
    form.value = {
        activity: subscription.activity,
        name: subscription.name || '',
        description: subscription.description || '',
        notification_template_id: subscription.notification_template_id,
        recipients: subscription.recipients.length > 0
            ? subscription.recipients.map(r => ({ type: r.type, value: r.value || '' }))
            : [{ type: 'owner', value: '' }],
        conditions: subscription.conditions || [],
        schedule_type: subscription.schedule_type,
        delay_minutes: subscription.delay_minutes || 5,
        delay_unit: subscription.delay_unit || 'minutes',
        is_enabled: subscription.is_enabled,
    };
    formErrors.value = {};
    showFormModal.value = true;
}

function openDeleteModal(subscription: NotificationSubscription) {
    selectedSubscription.value = subscription;
    showDeleteModal.value = true;
}

function closeModals() {
    showFormModal.value = false;
    showDeleteModal.value = false;
    selectedSubscription.value = null;
    formErrors.value = {};
}

function addRecipient() {
    form.value.recipients.push({ type: 'owner', value: '' });
}

function removeRecipient(index: number) {
    if (form.value.recipients.length > 1) {
        form.value.recipients.splice(index, 1);
    }
}

function addCondition() {
    form.value.conditions.push({ field: '', operator: '==', value: '' });
}

function removeCondition(index: number) {
    form.value.conditions.splice(index, 1);
}

function submitForm() {
    if (isSaving.value) return;

    isSaving.value = true;
    formErrors.value = {};

    const url = isEditing.value && selectedSubscription.value
        ? `/api/v1/notifications/${selectedSubscription.value.id}`
        : '/api/v1/notifications';

    const method = isEditing.value ? 'put' : 'post';

    // Clean up empty conditions
    const cleanedConditions = form.value.conditions.filter(c => c.field && c.operator);

    router[method](url, {
        ...form.value,
        conditions: cleanedConditions,
        recipients: form.value.recipients.map(r => ({
            type: r.type,
            ...(r.type === 'custom' && r.value ? { value: r.value } : {}),
        })),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSaving.value = false;
        },
    });
}

function deleteSubscription() {
    if (!selectedSubscription.value || isDeleting.value) return;

    isDeleting.value = true;

    router.delete(`/api/v1/notifications/${selectedSubscription.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

function toggleEnabled(subscription: NotificationSubscription) {
    router.put(`/api/v1/notifications/${subscription.id}`, {
        is_enabled: !subscription.is_enabled,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            subscription.is_enabled = !subscription.is_enabled;
        },
    });
}

function testSubscription(subscription: NotificationSubscription) {
    if (isTesting.value) return;

    isTesting.value = true;

    router.post(`/api/v1/notifications/${subscription.id}/test`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isTesting.value = false;
        },
    });
}

function getActivityName(slug: string): string {
    return props.activities[slug]?.name || slug;
}

function getRecipientLabel(type: string): string {
    switch (type) {
        case 'owner': return 'Store Owner';
        case 'customer': return 'Customer';
        case 'staff': return 'All Staff';
        case 'custom': return 'Custom';
        default: return type;
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Notification triggers" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Triggers"
                        description="Configure when notifications are sent"
                    />
                    <Button size="sm" @click="openCreateModal">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Create Trigger
                    </Button>
                </div>

                <!-- Navigation Tabs -->
                <div class="border-b border-gray-200 dark:border-white/10">
                    <nav class="-mb-px flex space-x-8">
                        <Link href="/settings/notifications" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Overview</Link>
                        <Link href="/settings/notifications/templates" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Templates</Link>
                        <Link href="/settings/notifications/subscriptions" class="border-b-2 border-indigo-500 px-1 pb-4 text-sm font-medium text-indigo-600 dark:text-indigo-400">Triggers</Link>
                        <Link href="/settings/notifications/channels" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Channels</Link>
                        <Link href="/settings/notifications/logs" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Logs</Link>
                    </nav>
                </div>

                <!-- Subscriptions List -->
                <div v-if="subscriptions.length > 0" class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">Trigger</th>
                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Template</th>
                                <th class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white md:table-cell">Recipients</th>
                                <th class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">Schedule</th>
                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                                <th class="relative py-3.5 pl-3 pr-4 sm:pr-6"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                            <tr v-for="subscription in subscriptions" :key="subscription.id">
                                <td class="whitespace-nowrap py-4 pl-4 pr-3 sm:pl-6">
                                    <div class="flex items-center gap-2">
                                        <BellIcon class="h-5 w-5 text-gray-400" />
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-white">
                                                {{ subscription.name || getActivityName(subscription.activity) }}
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ subscription.activity }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    {{ subscription.template?.name || '-' }}
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 md:table-cell">
                                    {{ subscription.recipients.map(r => getRecipientLabel(r.type)).join(', ') }}
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 sm:table-cell">
                                    <span v-if="subscription.schedule_type === 'immediate'">Immediate</span>
                                    <span v-else>{{ subscription.delay_minutes }} {{ subscription.delay_unit }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <button
                                        @click="toggleEnabled(subscription)"
                                        :class="[
                                            'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors',
                                            subscription.is_enabled ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700',
                                        ]"
                                    >
                                        <span :class="['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition', subscription.is_enabled ? 'translate-x-5' : 'translate-x-0']" />
                                    </button>
                                </td>
                                <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                    <Menu as="div" class="relative inline-block text-left">
                                        <MenuButton class="-m-2.5 block p-2.5 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                                            <EllipsisVerticalIcon class="h-5 w-5" />
                                        </MenuButton>
                                        <transition enter-active-class="transition ease-out duration-100" enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100" leave-active-class="transition ease-in duration-75" leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                                            <MenuItems class="absolute right-0 z-10 mt-2 w-48 origin-top-right rounded-md bg-white py-2 shadow-lg ring-1 ring-gray-900/5 focus:outline-none dark:bg-gray-800 dark:ring-white/10">
                                                <MenuItem v-slot="{ active }">
                                                    <button @click="openEditModal(subscription)" :class="[active ? 'bg-gray-50 dark:bg-white/5' : '', 'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white']">
                                                        <PencilIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Edit
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button @click="testSubscription(subscription)" :class="[active ? 'bg-gray-50 dark:bg-white/5' : '', 'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white']">
                                                        <PlayIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Test
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button @click="openDeleteModal(subscription)" :class="[active ? 'bg-gray-50 dark:bg-white/5' : '', 'flex w-full items-center px-3 py-2 text-sm text-red-600 dark:text-red-400']">
                                                        <TrashIcon class="mr-3 h-5 w-5 text-red-400" />
                                                        Delete
                                                    </button>
                                                </MenuItem>
                                            </MenuItems>
                                        </transition>
                                    </Menu>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="rounded-lg border border-gray-200 bg-gray-50 py-12 text-center dark:border-white/10 dark:bg-white/5">
                    <BellIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No triggers configured</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Create triggers to send notifications when events occur.</p>
                    <div class="mt-6">
                        <Button size="sm" @click="openCreateModal">Create Trigger</Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>

        <!-- Form Modal -->
        <Teleport to="body">
            <div v-if="showFormModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div class="absolute right-4 top-4">
                                <button @click="closeModals" class="text-gray-400 hover:text-gray-500">
                                    <XMarkIcon class="h-6 w-6" />
                                </button>
                            </div>

                            <div class="mb-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ isEditing ? 'Edit Trigger' : 'Create Trigger' }}
                                </h3>
                            </div>

                            <div class="space-y-4 max-h-[60vh] overflow-y-auto">
                                <!-- Activity -->
                                <div>
                                    <Label>Activity *</Label>
                                    <select v-model="form.activity" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                        <option value="">Select activity...</option>
                                        <optgroup v-for="(activities, category) in groupedActivities" :key="category" :label="category">
                                            <option v-for="(def, slug) in activities" :key="slug" :value="slug">
                                                {{ def.name }}
                                            </option>
                                        </optgroup>
                                    </select>
                                    <p v-if="selectedActivity" class="mt-1 text-xs text-gray-500">{{ selectedActivity.description }}</p>
                                </div>

                                <!-- Template -->
                                <div>
                                    <Label>Template *</Label>
                                    <select v-model="form.notification_template_id" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                        <option :value="null">Select template...</option>
                                        <option v-for="template in templates" :key="template.id" :value="template.id">
                                            {{ template.name }} ({{ template.channel }})
                                        </option>
                                    </select>
                                </div>

                                <!-- Name -->
                                <div>
                                    <Label>Name (optional)</Label>
                                    <Input v-model="form.name" type="text" placeholder="Custom trigger name" class="mt-1" />
                                </div>

                                <!-- Recipients -->
                                <div>
                                    <Label>Recipients</Label>
                                    <div class="mt-2 space-y-2">
                                        <div v-for="(recipient, index) in form.recipients" :key="index" class="flex items-center gap-2">
                                            <select v-model="recipient.type" class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                                <option value="owner">Store Owner</option>
                                                <option value="customer">Customer</option>
                                                <option value="staff">All Staff</option>
                                                <option value="custom">Custom Email</option>
                                            </select>
                                            <Input v-if="recipient.type === 'custom'" v-model="recipient.value" type="email" placeholder="email@example.com" class="flex-1" />
                                            <button v-if="form.recipients.length > 1" @click="removeRecipient(index)" class="p-2 text-red-500 hover:text-red-700">
                                                <XMarkIcon class="h-4 w-4" />
                                            </button>
                                        </div>
                                        <Button variant="outline" size="sm" @click="addRecipient">Add Recipient</Button>
                                    </div>
                                </div>

                                <!-- Schedule -->
                                <div>
                                    <Label>Schedule</Label>
                                    <div class="mt-2 flex items-center gap-2">
                                        <select v-model="form.schedule_type" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                            <option value="immediate">Send immediately</option>
                                            <option value="delayed">Delay</option>
                                        </select>
                                        <template v-if="form.schedule_type === 'delayed'">
                                            <Input v-model.number="form.delay_minutes" type="number" min="1" class="w-20" />
                                            <select v-model="form.delay_unit" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                                <option value="minutes">minutes</option>
                                                <option value="hours">hours</option>
                                                <option value="days">days</option>
                                            </select>
                                        </template>
                                    </div>
                                </div>

                                <!-- Conditions -->
                                <div>
                                    <Label>Conditions (optional)</Label>
                                    <div class="mt-2 space-y-2">
                                        <div v-for="(condition, index) in form.conditions" :key="index" class="flex items-center gap-2">
                                            <Input v-model="condition.field" type="text" placeholder="order.total" class="flex-1" />
                                            <select v-model="condition.operator" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                                <option v-for="op in operators" :key="op.value" :value="op.value">{{ op.label }}</option>
                                            </select>
                                            <Input v-model="condition.value" type="text" placeholder="100" class="w-24" />
                                            <button @click="removeCondition(index)" class="p-2 text-red-500 hover:text-red-700">
                                                <XMarkIcon class="h-4 w-4" />
                                            </button>
                                        </div>
                                        <Button variant="outline" size="sm" @click="addCondition">Add Condition</Button>
                                    </div>
                                </div>

                                <!-- Enabled -->
                                <div class="flex items-center gap-2">
                                    <input id="is_enabled" v-model="form.is_enabled" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600" />
                                    <Label for="is_enabled" class="mb-0">Enabled</Label>
                                </div>
                            </div>

                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button @click="submitForm" :disabled="!form.activity || !form.notification_template_id || isSaving" class="sm:col-start-2">
                                    {{ isSaving ? 'Saving...' : (isEditing ? 'Save Changes' : 'Create') }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 sm:col-start-1 sm:mt-0">Cancel</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedSubscription" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                                <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                            </div>
                            <div class="mt-3 text-center sm:mt-5">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Delete trigger</h3>
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    Are you sure you want to delete this trigger? This action cannot be undone.
                                </p>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button variant="destructive" @click="deleteSubscription" :disabled="isDeleting" class="sm:col-start-2">
                                    {{ isDeleting ? 'Deleting...' : 'Delete' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 sm:col-start-1 sm:mt-0">Cancel</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
