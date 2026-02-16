<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    BuildingOffice2Icon,
    MapPinIcon,
    PhoneIcon,
    EnvelopeIcon,
    StarIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
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

interface Warehouse {
    id: number;
    name: string;
    code: string;
    description: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    contact_name: string | null;
    is_default: boolean;
    is_active: boolean;
    accepts_transfers: boolean;
    fulfills_orders: boolean;
    full_address: string;
    inventories_count: number;
}

interface Props {
    warehouses: Warehouse[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Warehouses',
        href: '/settings/warehouses',
    },
];

// Modal states
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);

// Form state
const selectedWarehouse = ref<Warehouse | null>(null);
const formData = ref({
    name: '',
    code: '',
    description: '',
    address_line1: '',
    address_line2: '',
    city: '',
    state: '',
    postal_code: '',
    country: 'United States',
    phone: '',
    email: '',
    contact_name: '',
    is_active: true,
    accepts_transfers: true,
    fulfills_orders: true,
});
const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

function resetForm() {
    formData.value = {
        name: '',
        code: '',
        description: '',
        address_line1: '',
        address_line2: '',
        city: '',
        state: '',
        postal_code: '',
        country: 'United States',
        phone: '',
        email: '',
        contact_name: '',
        is_active: true,
        accepts_transfers: true,
        fulfills_orders: true,
    };
}

function openCreateModal() {
    formErrors.value = {};
    resetForm();
    showCreateModal.value = true;
}

function openEditModal(warehouse: Warehouse) {
    selectedWarehouse.value = warehouse;
    formData.value = {
        name: warehouse.name,
        code: warehouse.code || '',
        description: warehouse.description || '',
        address_line1: warehouse.address_line1 || '',
        address_line2: warehouse.address_line2 || '',
        city: warehouse.city || '',
        state: warehouse.state || '',
        postal_code: warehouse.postal_code || '',
        country: warehouse.country || 'United States',
        phone: warehouse.phone || '',
        email: warehouse.email || '',
        contact_name: warehouse.contact_name || '',
        is_active: warehouse.is_active,
        accepts_transfers: warehouse.accepts_transfers,
        fulfills_orders: warehouse.fulfills_orders,
    };
    formErrors.value = {};
    showEditModal.value = true;
}

function openDeleteModal(warehouse: Warehouse) {
    selectedWarehouse.value = warehouse;
    showDeleteModal.value = true;
}

function closeModals() {
    showCreateModal.value = false;
    showEditModal.value = false;
    showDeleteModal.value = false;
    selectedWarehouse.value = null;
    formErrors.value = {};
}

function createWarehouse() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.post('/settings/warehouses', formData.value, {
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

function updateWarehouse() {
    if (!selectedWarehouse.value || isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    router.put(`/settings/warehouses/${selectedWarehouse.value.id}`, formData.value, {
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

function deleteWarehouse() {
    if (!selectedWarehouse.value || isSubmitting.value) return;

    isSubmitting.value = true;

    router.delete(`/settings/warehouses/${selectedWarehouse.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: (errors) => {
            // Show error message
            if (errors.error) {
                alert(errors.error);
            }
            closeModals();
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function makeDefault(warehouse: Warehouse) {
    router.post(`/settings/warehouses/${warehouse.id}/make-default`, {}, {
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Warehouses" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Warehouses"
                    description="Manage your warehouse locations and their addresses."
                />

                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ warehouses.length }} warehouse{{ warehouses.length === 1 ? '' : 's' }}
                    </p>
                    <Button @click="openCreateModal">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Add Warehouse
                    </Button>
                </div>

                <!-- Warehouses List -->
                <div v-if="warehouses.length > 0" class="space-y-4">
                    <div
                        v-for="warehouse in warehouses"
                        :key="warehouse.id"
                        class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex items-start gap-4">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700">
                                    <BuildingOffice2Icon class="h-5 w-5 text-gray-600 dark:text-gray-400" />
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ warehouse.name }}
                                        </h3>
                                        <Badge v-if="warehouse.is_default" variant="secondary" class="flex items-center gap-1">
                                            <StarIconSolid class="h-3 w-3" />
                                            Default
                                        </Badge>
                                        <Badge v-if="!warehouse.is_active" variant="outline" class="text-gray-500">
                                            Inactive
                                        </Badge>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        Code: {{ warehouse.code }}
                                    </p>
                                    <div v-if="warehouse.full_address" class="mt-2 flex items-start gap-1 text-sm text-gray-600 dark:text-gray-300">
                                        <MapPinIcon class="h-4 w-4 flex-shrink-0 mt-0.5" />
                                        <span>{{ warehouse.full_address }}</span>
                                    </div>
                                    <div v-else class="mt-2 text-sm text-gray-400 italic">
                                        No address configured
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
                                        <span v-if="warehouse.phone" class="flex items-center gap-1">
                                            <PhoneIcon class="h-3.5 w-3.5" />
                                            {{ warehouse.phone }}
                                        </span>
                                        <span v-if="warehouse.email" class="flex items-center gap-1">
                                            <EnvelopeIcon class="h-3.5 w-3.5" />
                                            {{ warehouse.email }}
                                        </span>
                                        <span>{{ warehouse.inventories_count }} items in stock</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button
                                    v-if="!warehouse.is_default"
                                    variant="ghost"
                                    size="sm"
                                    @click="makeDefault(warehouse)"
                                    title="Make Default"
                                >
                                    <StarIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openEditModal(warehouse)"
                                >
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    v-if="!warehouse.is_default"
                                    variant="ghost"
                                    size="sm"
                                    @click="openDeleteModal(warehouse)"
                                >
                                    <TrashIcon class="h-4 w-4 text-red-500" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center dark:border-gray-700">
                    <BuildingOffice2Icon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No warehouses</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Get started by adding your first warehouse location.
                    </p>
                    <div class="mt-6">
                        <Button @click="openCreateModal">
                            <PlusIcon class="mr-2 h-4 w-4" />
                            Add Warehouse
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Create/Edit Modal -->
            <Dialog :open="showCreateModal || showEditModal" @update:open="closeModals">
                <DialogContent class="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {{ showEditModal ? 'Edit Warehouse' : 'Add Warehouse' }}
                        </DialogTitle>
                        <DialogDescription>
                            {{ showEditModal ? 'Update the warehouse details and address.' : 'Add a new warehouse location with its address.' }}
                        </DialogDescription>
                    </DialogHeader>

                    <form @submit.prevent="showEditModal ? updateWarehouse() : createWarehouse()">
                        <div class="space-y-6 py-4">
                            <!-- Basic Info -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Basic Information</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <Label for="name">Name *</Label>
                                        <Input
                                            id="name"
                                            v-model="formData.name"
                                            placeholder="Main Warehouse"
                                            :class="{ 'border-red-500': formErrors.name }"
                                        />
                                        <p v-if="formErrors.name" class="text-xs text-red-500">{{ formErrors.name }}</p>
                                    </div>
                                    <div class="space-y-2">
                                        <Label for="code">Code</Label>
                                        <Input
                                            id="code"
                                            v-model="formData.code"
                                            placeholder="MAIN_WH"
                                        />
                                        <p class="text-xs text-gray-500">Auto-generated if left empty</p>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <Label for="description">Description</Label>
                                    <Textarea
                                        id="description"
                                        v-model="formData.description"
                                        placeholder="Primary warehouse for inventory storage..."
                                        rows="2"
                                    />
                                </div>
                            </div>

                            <!-- Address -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Address</h4>
                                <div class="space-y-4">
                                    <div class="space-y-2">
                                        <Label for="address_line1">Address Line 1</Label>
                                        <Input
                                            id="address_line1"
                                            v-model="formData.address_line1"
                                            placeholder="123 Main Street"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <Label for="address_line2">Address Line 2</Label>
                                        <Input
                                            id="address_line2"
                                            v-model="formData.address_line2"
                                            placeholder="Suite 100"
                                        />
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="space-y-2">
                                            <Label for="city">City</Label>
                                            <Input
                                                id="city"
                                                v-model="formData.city"
                                                placeholder="Philadelphia"
                                            />
                                        </div>
                                        <div class="space-y-2">
                                            <Label for="state">State / Province</Label>
                                            <Input
                                                id="state"
                                                v-model="formData.state"
                                                placeholder="PA"
                                            />
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="space-y-2">
                                            <Label for="postal_code">Postal Code</Label>
                                            <Input
                                                id="postal_code"
                                                v-model="formData.postal_code"
                                                placeholder="19103"
                                            />
                                        </div>
                                        <div class="space-y-2">
                                            <Label for="country">Country</Label>
                                            <Input
                                                id="country"
                                                v-model="formData.country"
                                                placeholder="United States"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Info -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Contact Information</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <Label for="contact_name">Contact Name</Label>
                                        <Input
                                            id="contact_name"
                                            v-model="formData.contact_name"
                                            placeholder="John Smith"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <Label for="phone">Phone</Label>
                                        <Input
                                            id="phone"
                                            v-model="formData.phone"
                                            placeholder="(215) 555-0100"
                                        />
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <Label for="email">Email</Label>
                                    <Input
                                        id="email"
                                        v-model="formData.email"
                                        type="email"
                                        placeholder="warehouse@example.com"
                                        :class="{ 'border-red-500': formErrors.email }"
                                    />
                                    <p v-if="formErrors.email" class="text-xs text-red-500">{{ formErrors.email }}</p>
                                </div>
                            </div>

                            <!-- Settings -->
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Settings</h4>
                                <div class="space-y-3">
                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="is_active"
                                            :checked="formData.is_active"
                                            @update:checked="formData.is_active = $event"
                                        />
                                        <Label for="is_active" class="font-normal">Active</Label>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="accepts_transfers"
                                            :checked="formData.accepts_transfers"
                                            @update:checked="formData.accepts_transfers = $event"
                                        />
                                        <Label for="accepts_transfers" class="font-normal">Accepts inventory transfers</Label>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            id="fulfills_orders"
                                            :checked="formData.fulfills_orders"
                                            @update:checked="formData.fulfills_orders = $event"
                                        />
                                        <Label for="fulfills_orders" class="font-normal">Fulfills orders (retail location)</Label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" @click="closeModals">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="isSubmitting">
                                {{ isSubmitting ? 'Saving...' : (showEditModal ? 'Update Warehouse' : 'Add Warehouse') }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Delete Confirmation Modal -->
            <Dialog :open="showDeleteModal" @update:open="closeModals">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Warehouse</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete "{{ selectedWarehouse?.name }}"? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button variant="outline" @click="closeModals">
                            Cancel
                        </Button>
                        <Button variant="destructive" @click="deleteWarehouse" :disabled="isSubmitting">
                            {{ isSubmitting ? 'Deleting...' : 'Delete Warehouse' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SettingsLayout>
    </AppLayout>
</template>
