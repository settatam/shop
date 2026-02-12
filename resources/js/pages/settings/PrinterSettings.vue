<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import {
    EllipsisVerticalIcon,
    PrinterIcon,
    TrashIcon,
    PencilIcon,
    StarIcon,
    SignalIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface PrinterSetting {
    id: number;
    name: string;
    printer_type: string;
    ip_address: string | null;
    port: number;
    top_offset: number;
    left_offset: number;
    right_offset: number;
    text_size: number;
    barcode_height: number;
    line_height: number;
    label_width: number;
    label_height: number;
    is_default: boolean;
    network_print_enabled: boolean;
}

interface Props {
    printerSettings: PrinterSetting[];
    printerTypes: Record<string, string>;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Printer settings',
        href: '/settings/printers',
    },
];

// Modal state
const showFormModal = ref(false);
const showDeleteModal = ref(false);
const isEditing = ref(false);
const selectedSetting = ref<PrinterSetting | null>(null);

// Form state
const form = ref({
    name: '',
    printer_type: 'zebra',
    ip_address: '',
    port: 9100,
    top_offset: 30,
    left_offset: 0,
    right_offset: 0,
    text_size: 20,
    barcode_height: 50,
    line_height: 25,
    label_width: 406,
    label_height: 203,
    is_default: false,
});
const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);
const isDeleting = ref(false);

function openCreateModal() {
    isEditing.value = false;
    selectedSetting.value = null;
    form.value = {
        name: '',
        printer_type: 'zebra',
        ip_address: '',
        port: 9100,
        top_offset: 30,
        left_offset: 0,
        right_offset: 0,
        text_size: 20,
        barcode_height: 50,
        line_height: 25,
        label_width: 406,
        label_height: 203,
        is_default: props.printerSettings.length === 0,
    };
    formErrors.value = {};
    showFormModal.value = true;
}

function openEditModal(setting: PrinterSetting) {
    isEditing.value = true;
    selectedSetting.value = setting;
    form.value = {
        name: setting.name,
        printer_type: setting.printer_type,
        ip_address: setting.ip_address || '',
        port: setting.port || 9100,
        top_offset: setting.top_offset,
        left_offset: setting.left_offset,
        right_offset: setting.right_offset,
        text_size: setting.text_size,
        barcode_height: setting.barcode_height,
        line_height: setting.line_height,
        label_width: setting.label_width,
        label_height: setting.label_height,
        is_default: setting.is_default,
    };
    formErrors.value = {};
    showFormModal.value = true;
}

function openDeleteModal(setting: PrinterSetting) {
    selectedSetting.value = setting;
    showDeleteModal.value = true;
}

function closeModals() {
    showFormModal.value = false;
    showDeleteModal.value = false;
    selectedSetting.value = null;
    formErrors.value = {};
}

function submitForm() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    const url = isEditing.value && selectedSetting.value
        ? `/settings/printers/${selectedSetting.value.id}`
        : '/settings/printers';
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

function deleteSetting() {
    if (!selectedSetting.value || isDeleting.value) return;

    isDeleting.value = true;

    router.delete(`/settings/printers/${selectedSetting.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

function makeDefault(setting: PrinterSetting) {
    router.post(`/settings/printers/${setting.id}/make-default`, {}, {
        preserveScroll: true,
    });
}

function getPrinterTypeLabel(type: string): string {
    return props.printerTypes[type] || type;
}

const testingPrinterId = ref<number | null>(null);

async function testNetworkPrint(setting: PrinterSetting) {
    if (!setting.network_print_enabled) {
        alert('Network printing is not configured for this printer. Please add an IP address first.');
        return;
    }

    testingPrinterId.value = setting.id;

    try {
        const response = await fetch(`/settings/printers/${setting.id}/test-print`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
        });

        const data = await response.json();

        if (response.ok) {
            alert(data.message || 'Test label sent to printer!');
        } else {
            alert(data.error || 'Failed to send test print');
        }
    } catch (error) {
        alert('Failed to connect to printer. Please check the IP address and ensure the printer is online.');
    } finally {
        testingPrinterId.value = null;
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Printer settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Printer settings"
                        description="Configure label printers for barcode printing"
                    />
                    <Button @click="openCreateModal" size="sm">
                        <PrinterIcon class="mr-2 h-4 w-4" />
                        Add printer
                    </Button>
                </div>

                <!-- Printer settings list -->
                <div v-if="printerSettings.length > 0" class="rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                    Name
                                </th>
                                <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white lg:table-cell">
                                    Type
                                </th>
                                <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                    Label Size
                                </th>
                                <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white md:table-cell">
                                    Barcode Height
                                </th>
                                <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white lg:table-cell">
                                    Network
                                </th>
                                <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                            <tr v-for="setting in printerSettings" :key="setting.id">
                                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                    <div class="flex items-center gap-2">
                                        <StarIconSolid
                                            v-if="setting.is_default"
                                            class="h-4 w-4 text-yellow-500"
                                            title="Default printer"
                                        />
                                        <span class="font-medium text-gray-900 dark:text-white">{{ setting.name }}</span>
                                    </div>
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 lg:table-cell">
                                    {{ getPrinterTypeLabel(setting.printer_type) }}
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 sm:table-cell">
                                    {{ setting.label_width }} x {{ setting.label_height }} dots
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 md:table-cell">
                                    {{ setting.barcode_height }} dots
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm lg:table-cell">
                                    <span
                                        v-if="setting.network_print_enabled"
                                        class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20"
                                    >
                                        {{ setting.ip_address }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-gray-400 dark:text-gray-500"
                                    >
                                        Not configured
                                    </span>
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
                                                        @click="openEditModal(setting)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <PencilIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Edit
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-if="!setting.is_default" v-slot="{ active }">
                                                    <button
                                                        @click="makeDefault(setting)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <StarIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Make default
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-if="setting.network_print_enabled" v-slot="{ active }">
                                                    <button
                                                        @click="testNetworkPrint(setting)"
                                                        :disabled="testingPrinterId === setting.id"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <SignalIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        {{ testingPrinterId === setting.id ? 'Sending...' : 'Test network print' }}
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button
                                                        @click="openDeleteModal(setting)"
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

                <p v-else class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No printer settings yet. Add a printer to get started.
                </p>
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
                                    <PrinterIcon class="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        {{ isEditing ? 'Edit printer setting' : 'Add printer setting' }}
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Configure label dimensions and offsets for your printer.
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
                                            placeholder="e.g., Main Label Printer"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.name }}
                                        </p>
                                    </div>

                                    <!-- Printer Type -->
                                    <div>
                                        <Label for="printer_type">Printer Type</Label>
                                        <select
                                            id="printer_type"
                                            v-model="form.printer_type"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                        >
                                            <option v-for="(label, value) in printerTypes" :key="value" :value="value">
                                                {{ label }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Network Printing (for iPad/mobile support) -->
                                    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">
                                            Network Printing (iPad/Mobile)
                                        </h4>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                                            Enter the printer's IP address to enable printing from mobile devices.
                                        </p>
                                        <div class="grid grid-cols-3 gap-4">
                                            <div class="col-span-2">
                                                <Label for="ip_address">IP Address</Label>
                                                <Input
                                                    id="ip_address"
                                                    v-model="form.ip_address"
                                                    type="text"
                                                    placeholder="e.g., 192.168.1.100"
                                                    class="mt-1"
                                                />
                                                <p v-if="formErrors.ip_address" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                    {{ formErrors.ip_address }}
                                                </p>
                                            </div>
                                            <div>
                                                <Label for="port">Port</Label>
                                                <Input
                                                    id="port"
                                                    v-model.number="form.port"
                                                    type="number"
                                                    min="1"
                                                    max="65535"
                                                    class="mt-1"
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Label Dimensions -->
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label for="label_width">Label Width (dots)</Label>
                                            <Input
                                                id="label_width"
                                                v-model.number="form.label_width"
                                                type="number"
                                                min="100"
                                                max="1000"
                                                class="mt-1"
                                            />
                                            <p class="mt-1 text-xs text-gray-500">203 dots = 1 inch</p>
                                        </div>
                                        <div>
                                            <Label for="label_height">Label Height (dots)</Label>
                                            <Input
                                                id="label_height"
                                                v-model.number="form.label_height"
                                                type="number"
                                                min="50"
                                                max="1000"
                                                class="mt-1"
                                            />
                                        </div>
                                    </div>

                                    <!-- Offsets -->
                                    <div class="grid grid-cols-3 gap-4">
                                        <div>
                                            <Label for="top_offset">Top Offset</Label>
                                            <Input
                                                id="top_offset"
                                                v-model.number="form.top_offset"
                                                type="number"
                                                min="0"
                                                max="500"
                                                class="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <Label for="left_offset">Left Offset</Label>
                                            <Input
                                                id="left_offset"
                                                v-model.number="form.left_offset"
                                                type="number"
                                                min="0"
                                                max="500"
                                                class="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <Label for="right_offset">Right Offset</Label>
                                            <Input
                                                id="right_offset"
                                                v-model.number="form.right_offset"
                                                type="number"
                                                min="0"
                                                max="500"
                                                class="mt-1"
                                            />
                                        </div>
                                    </div>

                                    <!-- Text Settings -->
                                    <div class="grid grid-cols-3 gap-4">
                                        <div>
                                            <Label for="text_size">Text Size</Label>
                                            <Input
                                                id="text_size"
                                                v-model.number="form.text_size"
                                                type="number"
                                                min="10"
                                                max="100"
                                                class="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <Label for="barcode_height">Barcode Height</Label>
                                            <Input
                                                id="barcode_height"
                                                v-model.number="form.barcode_height"
                                                type="number"
                                                min="20"
                                                max="200"
                                                class="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <Label for="line_height">Line Height</Label>
                                            <Input
                                                id="line_height"
                                                v-model.number="form.line_height"
                                                type="number"
                                                min="10"
                                                max="100"
                                                class="mt-1"
                                            />
                                        </div>
                                    </div>

                                    <!-- Default checkbox -->
                                    <div class="flex items-center gap-2">
                                        <input
                                            id="is_default"
                                            v-model="form.is_default"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                        />
                                        <Label for="is_default" class="mb-0">Set as default printer</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    @click="submitForm"
                                    :disabled="!form.name || isSubmitting"
                                    class="sm:col-start-2"
                                >
                                    {{ isSubmitting ? 'Saving...' : (isEditing ? 'Save changes' : 'Add printer') }}
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
            <div v-if="showDeleteModal && selectedSetting" class="relative z-50">
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
                                        Delete printer setting
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete <span class="font-medium">{{ selectedSetting.name }}</span>? This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    @click="deleteSetting"
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
