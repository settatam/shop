<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    BuildingStorefrontIcon,
    GlobeAltIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Warehouse {
    id: number;
    name: string;
}

interface Marketplace {
    id: number;
    name: string;
    platform: string;
    platform_label: string;
}

interface SalesChannel {
    id: number;
    name: string;
    code: string;
    type: string;
    type_label: string;
    is_local: boolean;
    is_active: boolean;
    is_default: boolean;
    color: string | null;
    sort_order: number;
    warehouse: Warehouse | null;
    store_marketplace: {
        id: number;
        platform: string;
        status: string;
    } | null;
}

interface ChannelType {
    value: string;
    label: string;
    is_local: boolean;
}

interface Props {
    channels: SalesChannel[];
    warehouses: Warehouse[];
    marketplaces: Marketplace[];
    channelTypes: ChannelType[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Sales Channels',
        href: '/settings/channels',
    },
];

// Modal states
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);

// Form state
const selectedChannel = ref<SalesChannel | null>(null);
const formData = ref({
    name: '',
    type: 'local',
    warehouse_id: null as number | null,
    store_marketplace_id: null as number | null,
    color: '',
    is_default: false,
    is_active: true,
});
const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

const isLocalType = computed(() => formData.value.type === 'local');

const colors = [
    { name: 'Blue', value: '#3b82f6' },
    { name: 'Green', value: '#22c55e' },
    { name: 'Yellow', value: '#eab308' },
    { name: 'Red', value: '#ef4444' },
    { name: 'Purple', value: '#a855f7' },
    { name: 'Pink', value: '#ec4899' },
    { name: 'Orange', value: '#f97316' },
    { name: 'Teal', value: '#14b8a6' },
];

function openCreateModal() {
    formErrors.value = {};
    formData.value = {
        name: '',
        type: 'local',
        warehouse_id: null,
        store_marketplace_id: null,
        color: '',
        is_default: false,
        is_active: true,
    };
    showCreateModal.value = true;
}

function openEditModal(channel: SalesChannel) {
    selectedChannel.value = channel;
    formData.value = {
        name: channel.name,
        type: channel.type,
        warehouse_id: channel.warehouse?.id ?? null,
        store_marketplace_id: channel.store_marketplace?.id ?? null,
        color: channel.color || '',
        is_default: channel.is_default,
        is_active: channel.is_active,
    };
    formErrors.value = {};
    showEditModal.value = true;
}

function openDeleteModal(channel: SalesChannel) {
    selectedChannel.value = channel;
    showDeleteModal.value = true;
}

function closeModals() {
    showCreateModal.value = false;
    showEditModal.value = false;
    showDeleteModal.value = false;
    selectedChannel.value = null;
    formErrors.value = {};
}

function createChannel() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.post('/settings/channels', formData.value, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function updateChannel() {
    if (!selectedChannel.value || isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.put(`/settings/channels/${selectedChannel.value.id}`, formData.value, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function deleteChannel() {
    if (!selectedChannel.value || isSubmitting.value) return;

    isSubmitting.value = true;

    router.delete(`/settings/channels/${selectedChannel.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: () => {
            alert('Cannot delete channel that has orders. Deactivate it instead.');
            closeModals();
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function getChannelIcon(channel: SalesChannel) {
    return channel.is_local ? BuildingStorefrontIcon : GlobeAltIcon;
}

function getChannelDescription(channel: SalesChannel): string {
    if (channel.is_local && channel.warehouse) {
        return `Warehouse: ${channel.warehouse.name}`;
    }
    if (channel.store_marketplace) {
        return `Platform: ${channel.type_label}`;
    }
    return channel.type_label;
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Sales Channels" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Sales Channels"
                        description="Manage where your sales come from - local stores, online marketplaces, and more"
                    />
                    <Button @click="openCreateModal()" size="sm">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Add Channel
                    </Button>
                </div>

                <!-- Channels list -->
                <div class="space-y-3">
                    <div
                        v-for="channel in channels"
                        :key="channel.id"
                        class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                                    :style="channel.color ? { backgroundColor: channel.color + '20' } : {}"
                                    :class="!channel.color ? (channel.is_local ? 'bg-blue-100 dark:bg-blue-500/20' : 'bg-purple-100 dark:bg-purple-500/20') : ''"
                                >
                                    <component
                                        :is="getChannelIcon(channel)"
                                        class="h-5 w-5"
                                        :style="channel.color ? { color: channel.color } : {}"
                                        :class="!channel.color ? (channel.is_local ? 'text-blue-600 dark:text-blue-400' : 'text-purple-600 dark:text-purple-400') : ''"
                                    />
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                                            {{ channel.name }}
                                        </h3>
                                        <Badge v-if="channel.is_default" variant="default" class="text-xs">
                                            Default
                                        </Badge>
                                        <Badge
                                            v-if="!channel.is_active"
                                            variant="secondary"
                                            class="text-xs"
                                        >
                                            Inactive
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        {{ getChannelDescription(channel) }}
                                    </p>
                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                        Code: {{ channel.code }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openEditModal(channel)"
                                    title="Edit channel"
                                >
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openDeleteModal(channel)"
                                    title="Delete channel"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="channels.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No sales channels configured yet. Add a channel to track where your sales come from.
                </p>
            </div>
        </SettingsLayout>

        <!-- Create Channel Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Add Sales Channel
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Create a new sales channel to track orders from different sources.
                                </p>

                                <div class="mt-6 space-y-4">
                                    <div>
                                        <Label for="create-name">Channel Name</Label>
                                        <Input
                                            id="create-name"
                                            v-model="formData.name"
                                            type="text"
                                            placeholder="e.g., Main Store, eBay Store 1"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.name }}
                                        </p>
                                    </div>

                                    <div>
                                        <Label for="create-type">Channel Type</Label>
                                        <select
                                            id="create-type"
                                            v-model="formData.type"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option
                                                v-for="type in channelTypes"
                                                :key="type.value"
                                                :value="type.value"
                                            >
                                                {{ type.label }}
                                            </option>
                                        </select>
                                        <p v-if="formErrors.type" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.type }}
                                        </p>
                                    </div>

                                    <div v-if="isLocalType && warehouses.length > 0">
                                        <Label for="create-warehouse">Warehouse (optional)</Label>
                                        <select
                                            id="create-warehouse"
                                            v-model="formData.warehouse_id"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option :value="null">No warehouse</option>
                                            <option
                                                v-for="warehouse in warehouses"
                                                :key="warehouse.id"
                                                :value="warehouse.id"
                                            >
                                                {{ warehouse.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <Label>Color (optional)</Label>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                v-for="color in colors"
                                                :key="color.value"
                                                type="button"
                                                class="h-8 w-8 rounded-full border-2 transition-all"
                                                :class="formData.color === color.value ? 'border-gray-900 dark:border-white scale-110' : 'border-transparent hover:scale-105'"
                                                :style="{ backgroundColor: color.value }"
                                                :title="color.name"
                                                @click="formData.color = formData.color === color.value ? '' : color.value"
                                            />
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="create-default"
                                            :checked="formData.is_default"
                                            @update:checked="formData.is_default = $event as boolean"
                                        />
                                        <Label for="create-default" class="!mb-0">Set as default channel</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button
                                    @click="createChannel"
                                    :disabled="!formData.name || isSubmitting"
                                    class="w-full sm:ml-3 sm:w-auto"
                                >
                                    {{ isSubmitting ? 'Creating...' : 'Create' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 w-full sm:mt-0 sm:w-auto">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Edit Channel Modal -->
        <Teleport to="body">
            <div v-if="showEditModal && selectedChannel" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                    Edit Sales Channel
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Update the channel details.
                                </p>

                                <div class="mt-6 space-y-4">
                                    <div>
                                        <Label for="edit-name">Channel Name</Label>
                                        <Input
                                            id="edit-name"
                                            v-model="formData.name"
                                            type="text"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.name }}
                                        </p>
                                    </div>

                                    <div v-if="selectedChannel.is_local && warehouses.length > 0">
                                        <Label for="edit-warehouse">Warehouse (optional)</Label>
                                        <select
                                            id="edit-warehouse"
                                            v-model="formData.warehouse_id"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option :value="null">No warehouse</option>
                                            <option
                                                v-for="warehouse in warehouses"
                                                :key="warehouse.id"
                                                :value="warehouse.id"
                                            >
                                                {{ warehouse.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <Label>Color (optional)</Label>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                v-for="color in colors"
                                                :key="color.value"
                                                type="button"
                                                class="h-8 w-8 rounded-full border-2 transition-all"
                                                :class="formData.color === color.value ? 'border-gray-900 dark:border-white scale-110' : 'border-transparent hover:scale-105'"
                                                :style="{ backgroundColor: color.value }"
                                                :title="color.name"
                                                @click="formData.color = formData.color === color.value ? '' : color.value"
                                            />
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="edit-active"
                                            :checked="formData.is_active"
                                            @update:checked="formData.is_active = $event as boolean"
                                        />
                                        <Label for="edit-active" class="!mb-0">Active</Label>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="edit-default"
                                            :checked="formData.is_default"
                                            @update:checked="formData.is_default = $event as boolean"
                                        />
                                        <Label for="edit-default" class="!mb-0">Set as default channel</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button
                                    @click="updateChannel"
                                    :disabled="!formData.name || isSubmitting"
                                    class="w-full sm:ml-3 sm:w-auto"
                                >
                                    {{ isSubmitting ? 'Saving...' : 'Save changes' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 w-full sm:mt-0 sm:w-auto">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Channel Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedChannel" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete sales channel
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete <span class="font-medium">{{ selectedChannel.name }}</span>? This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    @click="deleteChannel"
                                    :disabled="isSubmitting"
                                    class="sm:col-start-2"
                                >
                                    {{ isSubmitting ? 'Deleting...' : 'Delete' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 sm:col-start-1 sm:mt-0">
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
