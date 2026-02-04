<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import {
    EllipsisVerticalIcon,
    CreditCardIcon,
    TrashIcon,
    PencilIcon,
    SignalIcon,
    PlayIcon,
    PauseIcon,
} from '@heroicons/vue/24/outline';
import { CheckCircleIcon, XCircleIcon } from '@heroicons/vue/24/solid';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Terminal {
    id: number;
    name: string;
    gateway: string;
    gateway_label: string;
    device_id: string;
    status: string;
    status_label: string;
    warehouse_id: number | null;
    warehouse_name: string | null;
    last_seen_at: string | null;
    paired_at: string | null;
}

interface SelectOption {
    value: string | number;
    label: string;
}

interface Props {
    terminals: Terminal[];
    warehouses: SelectOption[];
    gateways: SelectOption[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Payment terminals',
        href: '/settings/terminals',
    },
];

// Modal state
const showFormModal = ref(false);
const showDeleteModal = ref(false);
const isEditing = ref(false);
const selectedTerminal = ref<Terminal | null>(null);

// Form state
const form = ref({
    name: '',
    gateway: 'dejavoo',
    device_id: '',
    warehouse_id: null as number | null,
    settings: {
        auth_key: '',
        register_id: '',
    },
});
const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);
const isDeleting = ref(false);

const selectedGateway = computed(() => {
    return props.gateways.find(g => g.value === form.value.gateway);
});

function openCreateModal() {
    isEditing.value = false;
    selectedTerminal.value = null;
    form.value = {
        name: '',
        gateway: 'dejavoo',
        device_id: '',
        warehouse_id: null,
        settings: {
            auth_key: '',
            register_id: '',
        },
    };
    formErrors.value = {};
    showFormModal.value = true;
}

function openEditModal(terminal: Terminal) {
    isEditing.value = true;
    selectedTerminal.value = terminal;
    form.value = {
        name: terminal.name,
        gateway: terminal.gateway,
        device_id: terminal.device_id,
        warehouse_id: terminal.warehouse_id,
        settings: {
            auth_key: '',
            register_id: '',
        },
    };
    formErrors.value = {};
    showFormModal.value = true;
}

function openDeleteModal(terminal: Terminal) {
    selectedTerminal.value = terminal;
    showDeleteModal.value = true;
}

function closeModals() {
    showFormModal.value = false;
    showDeleteModal.value = false;
    selectedTerminal.value = null;
    formErrors.value = {};
}

function submitForm() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    const url = isEditing.value && selectedTerminal.value
        ? `/settings/terminals/${selectedTerminal.value.id}`
        : '/settings/terminals';
    const method = isEditing.value ? 'put' : 'post';

    router[method](url, form.value, {
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

function deleteTerminal() {
    if (!selectedTerminal.value || isDeleting.value) return;

    isDeleting.value = true;

    router.delete(`/settings/terminals/${selectedTerminal.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

function testConnection(terminal: Terminal) {
    router.post(`/settings/terminals/${terminal.id}/test`, {}, {
        preserveScroll: true,
    });
}

function activateTerminal(terminal: Terminal) {
    router.post(`/settings/terminals/${terminal.id}/activate`, {}, {
        preserveScroll: true,
    });
}

function deactivateTerminal(terminal: Terminal) {
    router.post(`/settings/terminals/${terminal.id}/deactivate`, {}, {
        preserveScroll: true,
    });
}

function getStatusColor(status: string): string {
    switch (status) {
        case 'active':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
        case 'inactive':
            return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
        case 'pending':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300';
        case 'disconnected':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Payment terminals" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Payment terminals"
                        description="Configure payment terminals for in-person transactions"
                    />
                    <Button @click="openCreateModal" size="sm">
                        <CreditCardIcon class="mr-2 h-4 w-4" />
                        Add terminal
                    </Button>
                </div>

                <!-- Terminals list -->
                <div v-if="terminals.length > 0" class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                    Terminal
                                </th>
                                <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white lg:table-cell">
                                    Gateway
                                </th>
                                <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                    Location
                                </th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                    Status
                                </th>
                                <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                            <tr v-for="terminal in terminals" :key="terminal.id">
                                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-500/10">
                                            <CreditCardIcon class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-white">{{ terminal.name }}</div>
                                            <div class="text-gray-500 dark:text-gray-400 font-mono text-xs">{{ terminal.device_id }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 lg:table-cell">
                                    {{ terminal.gateway_label }}
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 sm:table-cell">
                                    {{ terminal.warehouse_name || 'All locations' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
                                            getStatusColor(terminal.status),
                                        ]"
                                    >
                                        <CheckCircleIcon v-if="terminal.status === 'active'" class="h-3 w-3" />
                                        <XCircleIcon v-else-if="terminal.status === 'disconnected'" class="h-3 w-3" />
                                        {{ terminal.status_label }}
                                    </span>
                                    <div v-if="terminal.last_seen_at" class="mt-1 text-xs text-gray-400">
                                        Last seen {{ terminal.last_seen_at }}
                                    </div>
                                </td>
                                <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                    <Menu as="div" class="relative inline-block text-left">
                                        <MenuButton class="-m-2.5 block p-2.5 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                                            <span class="sr-only">Open options</span>
                                            <EllipsisVerticalIcon class="h-5 w-5" />
                                        </MenuButton>
                                        <transition
                                            enter-active-class="transition ease-out duration-100"
                                            enter-from-class="transform opacity-0 scale-95"
                                            enter-to-class="transform opacity-100 scale-100"
                                            leave-active-class="transition ease-in duration-75"
                                            leave-from-class="transform opacity-100 scale-100"
                                            leave-to-class="transform opacity-0 scale-95"
                                        >
                                            <MenuItems class="absolute right-0 z-10 mt-2 w-48 origin-top-right rounded-md bg-white py-2 shadow-lg ring-1 ring-gray-900/5 focus:outline-none dark:bg-gray-800 dark:ring-white/10">
                                                <MenuItem v-slot="{ active }">
                                                    <button
                                                        @click="openEditModal(terminal)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <PencilIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Edit
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button
                                                        @click="testConnection(terminal)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <SignalIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Test connection
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-if="terminal.status !== 'active'" v-slot="{ active }">
                                                    <button
                                                        @click="activateTerminal(terminal)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <PlayIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Activate
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-if="terminal.status === 'active'" v-slot="{ active }">
                                                    <button
                                                        @click="deactivateTerminal(terminal)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <PauseIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Deactivate
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button
                                                        @click="openDeleteModal(terminal)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-red-600 dark:text-red-400',
                                                        ]"
                                                    >
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

                <div v-else class="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center dark:border-gray-600">
                    <CreditCardIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No payment terminals</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Get started by adding a payment terminal.
                    </p>
                    <div class="mt-6">
                        <Button @click="openCreateModal">
                            <CreditCardIcon class="mr-2 h-4 w-4" />
                            Add terminal
                        </Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>

        <!-- Create/Edit Modal -->
        <Teleport to="body">
            <div v-if="showFormModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-500/10">
                                    <CreditCardIcon class="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        {{ isEditing ? 'Edit payment terminal' : 'Add payment terminal' }}
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Configure your payment terminal settings.
                                    </p>
                                </div>

                                <div class="mt-6 space-y-4">
                                    <!-- Name -->
                                    <div>
                                        <Label for="name">Name</Label>
                                        <Input
                                            id="name"
                                            v-model="form.name"
                                            type="text"
                                            placeholder="e.g., Front Counter Terminal"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.name }}
                                        </p>
                                    </div>

                                    <!-- Gateway -->
                                    <div v-if="!isEditing">
                                        <Label for="gateway">Gateway</Label>
                                        <select
                                            id="gateway"
                                            v-model="form.gateway"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                        >
                                            <option v-for="gateway in gateways" :key="gateway.value" :value="gateway.value">
                                                {{ gateway.label }}
                                            </option>
                                        </select>
                                        <p v-if="formErrors.gateway" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.gateway }}
                                        </p>
                                    </div>

                                    <!-- Device ID -->
                                    <div>
                                        <Label for="device_id">Device ID / Terminal ID</Label>
                                        <Input
                                            id="device_id"
                                            v-model="form.device_id"
                                            type="text"
                                            placeholder="Enter the terminal's device ID"
                                            class="mt-1 font-mono"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Find this on your terminal or in your gateway dashboard.
                                        </p>
                                        <p v-if="formErrors.device_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.device_id }}
                                        </p>
                                    </div>

                                    <!-- Dejavoo-specific settings -->
                                    <template v-if="form.gateway === 'dejavoo'">
                                        <div>
                                            <Label for="auth_key">Authentication Key</Label>
                                            <Input
                                                id="auth_key"
                                                v-model="form.settings.auth_key"
                                                type="password"
                                                placeholder="Enter Dejavoo auth key"
                                                class="mt-1 font-mono"
                                            />
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                Your Dejavoo authentication key for API access.
                                            </p>
                                        </div>

                                        <div>
                                            <Label for="register_id">Register ID (optional)</Label>
                                            <Input
                                                id="register_id"
                                                v-model="form.settings.register_id"
                                                type="text"
                                                placeholder="e.g., REG001"
                                                class="mt-1"
                                            />
                                        </div>
                                    </template>

                                    <!-- Warehouse/Location -->
                                    <div>
                                        <Label for="warehouse_id">Location (optional)</Label>
                                        <select
                                            id="warehouse_id"
                                            v-model="form.warehouse_id"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                        >
                                            <option :value="null">All locations</option>
                                            <option v-for="warehouse in warehouses" :key="warehouse.value" :value="warehouse.value">
                                                {{ warehouse.label }}
                                            </option>
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Assign this terminal to a specific location, or leave as "All locations".
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    @click="submitForm"
                                    :disabled="!form.name || !form.device_id || isSubmitting"
                                    class="sm:col-start-2"
                                >
                                    {{ isSubmitting ? 'Saving...' : (isEditing ? 'Save changes' : 'Add terminal') }}
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

        <!-- Delete Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedTerminal" class="relative z-50">
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
                                        Delete payment terminal
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete <span class="font-medium">{{ selectedTerminal.name }}</span>? This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    @click="deleteTerminal"
                                    :disabled="isDeleting"
                                    class="sm:col-start-2"
                                >
                                    {{ isDeleting ? 'Deleting...' : 'Delete' }}
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
