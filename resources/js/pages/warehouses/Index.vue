<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    PlusIcon,
    PencilIcon,
    TrashIcon,
    StarIcon,
    CheckCircleIcon,
    XCircleIcon,
    TruckIcon,
    ArrowsRightLeftIcon,
} from '@heroicons/vue/20/solid';
import { ref } from 'vue';

interface Warehouse {
    id: number;
    name: string;
    code: string;
    description: string | null;
    full_address: string;
    city: string | null;
    state: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    is_default: boolean;
    is_active: boolean;
    accepts_transfers: boolean;
    fulfills_orders: boolean;
    priority: number;
    inventories_count: number;
    total_quantity: number;
}

interface Props {
    warehouses: Warehouse[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Warehouses', href: '/warehouses' },
];

const deleteWarehouse = ref<Warehouse | null>(null);
const showDeleteModal = ref(false);

function confirmDelete(warehouse: Warehouse) {
    deleteWarehouse.value = warehouse;
    showDeleteModal.value = true;
}

function handleDelete() {
    if (deleteWarehouse.value) {
        router.delete(`/warehouses/${deleteWarehouse.value.id}`, {
            onSuccess: () => {
                showDeleteModal.value = false;
                deleteWarehouse.value = null;
            },
        });
    }
}

function makeDefault(warehouse: Warehouse) {
    router.post(`/warehouses/${warehouse.id}/make-default`);
}
</script>

<template>
    <Head title="Warehouses" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Warehouses</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage your warehouse locations and settings
                    </p>
                </div>
                <Link
                    href="/warehouses/create"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                >
                    <PlusIcon class="-ml-0.5 size-5" />
                    Add Warehouse
                </Link>
            </div>

            <!-- Warehouses Grid -->
            <div v-if="warehouses.length > 0" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="warehouse in warehouses"
                    :key="warehouse.id"
                    class="relative rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <!-- Default badge -->
                    <div v-if="warehouse.is_default" class="absolute -top-2 -right-2">
                        <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">
                            <StarIcon class="mr-1 size-3" />
                            Default
                        </span>
                    </div>

                    <div class="p-5">
                        <!-- Header -->
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ warehouse.name }}
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Code: {{ warehouse.code }}
                                </p>
                            </div>
                            <span
                                :class="[
                                    warehouse.is_active
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                                        : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                    'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
                                ]"
                            >
                                {{ warehouse.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>

                        <!-- Description -->
                        <p v-if="warehouse.description" class="mt-2 text-sm text-gray-600 dark:text-gray-400 line-clamp-2">
                            {{ warehouse.description }}
                        </p>

                        <!-- Address -->
                        <p v-if="warehouse.full_address" class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                            {{ warehouse.full_address }}
                        </p>

                        <!-- Stats -->
                        <div class="mt-4 flex items-center gap-4 text-sm">
                            <div class="flex items-center gap-1 text-gray-500 dark:text-gray-400">
                                <span class="font-semibold text-gray-900 dark:text-white">{{ warehouse.inventories_count }}</span>
                                SKUs
                            </div>
                            <div class="flex items-center gap-1 text-gray-500 dark:text-gray-400">
                                <span class="font-semibold text-gray-900 dark:text-white">{{ warehouse.total_quantity }}</span>
                                units
                            </div>
                        </div>

                        <!-- Capabilities -->
                        <div class="mt-4 flex items-center gap-3">
                            <div
                                v-if="warehouse.fulfills_orders"
                                class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400"
                                title="Fulfills orders"
                            >
                                <TruckIcon class="size-4 text-green-500" />
                                Fulfills
                            </div>
                            <div
                                v-if="warehouse.accepts_transfers"
                                class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400"
                                title="Accepts transfers"
                            >
                                <ArrowsRightLeftIcon class="size-4 text-blue-500" />
                                Transfers
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="mt-4 flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                            <div class="flex items-center gap-2">
                                <Link
                                    :href="`/warehouses/${warehouse.id}/edit`"
                                    class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                >
                                    <PencilIcon class="size-4" />
                                    Edit
                                </Link>
                                <button
                                    v-if="!warehouse.is_default"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="makeDefault(warehouse)"
                                >
                                    <StarIcon class="size-4" />
                                    Set Default
                                </button>
                            </div>
                            <button
                                v-if="!warehouse.is_default"
                                type="button"
                                class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                @click="confirmDelete(warehouse)"
                            >
                                <TrashIcon class="size-5" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No warehouses</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Get started by creating your first warehouse.
                </p>
                <div class="mt-6">
                    <Link
                        href="/warehouses/create"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        <PlusIcon class="-ml-0.5 size-5" />
                        Add Warehouse
                    </Link>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10 dark:bg-red-900">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete Warehouse
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Are you sure you want to delete "{{ deleteWarehouse?.name }}"? This action cannot be undone.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                                <button
                                    type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:w-auto"
                                    @click="handleDelete"
                                >
                                    Delete
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showDeleteModal = false"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
