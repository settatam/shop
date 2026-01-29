<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import {
    PlusIcon,
    PencilIcon,
    TrashIcon,
    ArrowLeftIcon,
    CurrencyDollarIcon,
    CheckCircleIcon,
    ShoppingCartIcon,
    MagnifyingGlassIcon,
    UserIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';

interface BucketItem {
    id: number;
    title: string;
    description: string | null;
    value: number;
    sold_at: string | null;
    is_sold: boolean;
    transaction_item_id: number | null;
    created_at: string;
}

interface Bucket {
    id: number;
    name: string;
    description: string | null;
    total_value: number;
    items: BucketItem[];
    created_at: string;
}

interface StoreUser {
    id: number;
    name: string;
}

interface Customer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string | null;
    phone: string | null;
}

interface ActivityItem {
    id: number;
    activity: string;
    description: string;
    user: { name: string } | null;
    changes: Record<string, { old: string; new: string }> | null;
    time: string;
    created_at: string;
    icon: string;
    color: string;
}

interface ActivityDay {
    date: string;
    dateTime: string;
    items: ActivityItem[];
}

interface Props {
    bucket: Bucket;
    storeUsers: StoreUser[];
    currentStoreUserId: number | null;
    activityLogs: ActivityDay[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Buckets', href: '/buckets' },
    { title: props.bucket.name, href: `/buckets/${props.bucket.id}` },
];

const showEditModal = ref(false);
const showAddItemModal = ref(false);
const showDeleteModal = ref(false);
const showSaleModal = ref(false);
const deleteItem = ref<BucketItem | null>(null);

const editForm = useForm({
    name: props.bucket.name,
    description: props.bucket.description || '',
});

const addItemForm = useForm({
    title: '',
    description: '',
    value: '',
});

// Sale modal state
const selectedItemIds = ref<number[]>([]);
const itemPrices = ref<Record<number, number>>({});
const selectedCustomer = ref<Customer | null>(null);
const customerSearch = ref('');
const customerResults = ref<Customer[]>([]);
const searchingCustomers = ref(false);
const saleForm = useForm({
    store_user_id: props.currentStoreUserId,
    customer_id: null as number | null,
    item_ids: [] as number[],
    prices: {} as Record<number, number>,
    tax_rate: 0,
});

function submitEditForm() {
    editForm.put(`/buckets/${props.bucket.id}`, {
        onSuccess: () => {
            showEditModal.value = false;
        },
    });
}

function submitAddItemForm() {
    addItemForm.post(`/buckets/${props.bucket.id}/items`, {
        onSuccess: () => {
            showAddItemModal.value = false;
            addItemForm.reset();
        },
    });
}

function confirmDeleteItem(item: BucketItem) {
    deleteItem.value = item;
    showDeleteModal.value = true;
}

function handleDeleteItem() {
    if (deleteItem.value) {
        router.delete(`/bucket-items/${deleteItem.value.id}`, {
            onSuccess: () => {
                showDeleteModal.value = false;
                deleteItem.value = null;
            },
        });
    }
}

function handleDeleteBucket() {
    router.delete(`/buckets/${props.bucket.id}`);
}

function formatCurrency(amount: number) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function formatDate(date: string) {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

const activeItems = computed(() => props.bucket.items.filter(item => !item.is_sold));
const soldItems = computed(() => props.bucket.items.filter(item => item.is_sold));

// Sale modal functions
function openSaleModal() {
    // Reset state
    selectedItemIds.value = [];
    itemPrices.value = {};
    selectedCustomer.value = null;
    customerSearch.value = '';
    customerResults.value = [];

    // Initialize prices with item values
    activeItems.value.forEach(item => {
        itemPrices.value[item.id] = item.value;
    });

    showSaleModal.value = true;
}

function toggleItemSelection(itemId: number) {
    const index = selectedItemIds.value.indexOf(itemId);
    if (index === -1) {
        selectedItemIds.value.push(itemId);
    } else {
        selectedItemIds.value.splice(index, 1);
    }
}

function selectAllItems() {
    selectedItemIds.value = activeItems.value.map(item => item.id);
}

function clearSelection() {
    selectedItemIds.value = [];
}

const selectedItemsTotal = computed(() => {
    return selectedItemIds.value.reduce((sum, id) => {
        return sum + (itemPrices.value[id] || 0);
    }, 0);
});

let customerSearchTimeout: ReturnType<typeof setTimeout> | null = null;

async function searchCustomers() {
    if (customerSearchTimeout) {
        clearTimeout(customerSearchTimeout);
    }

    if (!customerSearch.value || customerSearch.value.length < 2) {
        customerResults.value = [];
        return;
    }

    customerSearchTimeout = setTimeout(async () => {
        searchingCustomers.value = true;
        try {
            const response = await fetch(`/buckets/search-customers?query=${encodeURIComponent(customerSearch.value)}`);
            const data = await response.json();
            customerResults.value = data.customers || [];
        } catch (error) {
            console.error('Error searching customers:', error);
            customerResults.value = [];
        } finally {
            searchingCustomers.value = false;
        }
    }, 300);
}

function selectCustomer(customer: Customer) {
    selectedCustomer.value = customer;
    customerSearch.value = '';
    customerResults.value = [];
}

function clearCustomer() {
    selectedCustomer.value = null;
}

watch(customerSearch, searchCustomers);

function submitSaleForm() {
    if (selectedItemIds.value.length === 0) {
        return;
    }

    saleForm.item_ids = selectedItemIds.value;
    saleForm.prices = itemPrices.value;
    saleForm.customer_id = selectedCustomer.value?.id || null;

    saleForm.post(`/buckets/${props.bucket.id}/create-sale`, {
        onSuccess: () => {
            showSaleModal.value = false;
        },
    });
}
</script>

<template>
    <Head :title="bucket.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div class="flex items-start gap-4">
                    <Link
                        href="/buckets"
                        class="mt-1 rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                            {{ bucket.name }}
                        </h1>
                        <p v-if="bucket.description" class="text-sm text-gray-500 dark:text-gray-400">
                            {{ bucket.description }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="activeItems.length > 0"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500"
                        @click="openSaleModal"
                    >
                        <ShoppingCartIcon class="-ml-0.5 size-4" />
                        Create Sale
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="showAddItemModal = true"
                    >
                        <PlusIcon class="-ml-0.5 size-4" />
                        Add Item
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                        @click="showEditModal = true"
                    >
                        <PencilIcon class="-ml-0.5 size-4" />
                        Edit
                    </button>
                    <button
                        v-if="activeItems.length === 0"
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                        @click="handleDeleteBucket"
                    >
                        <TrashIcon class="-ml-0.5 size-4" />
                        Delete
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Items Table -->
                <div class="lg:col-span-2">
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:px-6 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Items</h3>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                {{ activeItems.length }} active, {{ soldItems.length }} sold
                            </span>
                        </div>

                        <div v-if="bucket.items.length > 0" class="border-t border-gray-200 dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Item
                                        </th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Value
                                        </th>
                                        <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Status
                                        </th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                    <tr v-for="item in bucket.items" :key="item.id">
                                        <td class="whitespace-nowrap px-4 py-4">
                                            <div>
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                    {{ item.title }}
                                                </p>
                                                <p v-if="item.description" class="text-sm text-gray-500 dark:text-gray-400 truncate max-w-xs">
                                                    {{ item.description }}
                                                </p>
                                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                                    Added {{ formatDate(item.created_at) }}
                                                </p>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4 text-right">
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                                {{ formatCurrency(item.value) }}
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4 text-center">
                                            <span
                                                v-if="item.is_sold"
                                                class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-900 dark:text-green-300"
                                            >
                                                <CheckCircleIcon class="size-3.5" />
                                                Sold
                                            </span>
                                            <span
                                                v-else
                                                class="inline-flex rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-300"
                                            >
                                                Active
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4 text-right">
                                            <button
                                                v-if="!item.is_sold"
                                                type="button"
                                                class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                                @click="confirmDeleteItem(item)"
                                            >
                                                <TrashIcon class="size-5" />
                                            </button>
                                            <span v-else class="text-xs text-gray-400 dark:text-gray-500">
                                                {{ formatDate(item.sold_at!) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Empty State -->
                        <div v-else class="border-t border-gray-200 dark:border-gray-700 py-12 text-center">
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                No items in this bucket yet.
                            </p>
                            <button
                                type="button"
                                class="mt-4 inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                @click="showAddItemModal = true"
                            >
                                <PlusIcon class="-ml-0.5 size-5" />
                                Add First Item
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="space-y-6">
                    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10 p-6">
                        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-2">
                            <CurrencyDollarIcon class="size-5" />
                            Total Value
                        </div>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400">
                            {{ formatCurrency(bucket.total_value) }}
                        </p>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            Based on {{ activeItems.length }} active item{{ activeItems.length !== 1 ? 's' : '' }}
                        </p>
                    </div>

                    <!-- Activity Timeline -->
                    <ActivityTimeline :activities="activityLogs" />
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <Teleport to="body">
            <div v-if="showEditModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showEditModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <form @submit.prevent="submitEditForm">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    Edit Bucket
                                </h3>

                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name *</label>
                                        <input
                                            v-model="editForm.name"
                                            type="text"
                                            required
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                        <textarea
                                            v-model="editForm.description"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <div class="mt-5 sm:mt-6 flex flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        :disabled="editForm.processing"
                                        class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                    >
                                        {{ editForm.processing ? 'Saving...' : 'Save Changes' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showEditModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Add Item Modal -->
        <Teleport to="body">
            <div v-if="showAddItemModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showAddItemModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                            <form @submit.prevent="submitAddItemForm">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    Add Item to Bucket
                                </h3>

                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Title *</label>
                                        <input
                                            v-model="addItemForm.title"
                                            type="text"
                                            required
                                            placeholder="e.g., Broken Watch, Scrap Ring"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="addItemForm.errors.title" class="mt-1 text-sm text-red-600">{{ addItemForm.errors.title }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Value *</label>
                                        <div class="relative mt-1">
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                <span class="text-gray-500 sm:text-sm">$</span>
                                            </div>
                                            <input
                                                v-model="addItemForm.value"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                required
                                                placeholder="0.00"
                                                class="block w-full rounded-md border-0 py-1.5 pl-7 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <p v-if="addItemForm.errors.value" class="mt-1 text-sm text-red-600">{{ addItemForm.errors.value }}</p>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                        <textarea
                                            v-model="addItemForm.description"
                                            rows="2"
                                            placeholder="Optional notes about this item"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <div class="mt-5 sm:mt-6 flex flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        :disabled="addItemForm.processing"
                                        class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                    >
                                        {{ addItemForm.processing ? 'Adding...' : 'Add Item' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showAddItemModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Item Confirmation Modal -->
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
                                        Remove Item
                                    </h3>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Are you sure you want to remove "{{ deleteItem?.title }}"? This action cannot be undone.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-3">
                                <button
                                    type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:w-auto"
                                    @click="handleDeleteItem"
                                >
                                    Remove
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

        <!-- Create Sale Modal -->
        <Teleport to="body">
            <div v-if="showSaleModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" @click="showSaleModal = false" />
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6 dark:bg-gray-800">
                            <form @submit.prevent="submitSaleForm">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    Create Sale from Bucket
                                </h3>

                                <div class="space-y-6">
                                    <!-- Employee Selection -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Employee *</label>
                                        <select
                                            v-model="saleForm.store_user_id"
                                            required
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option v-for="user in storeUsers" :key="user.id" :value="user.id">
                                                {{ user.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Customer Search -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Customer (Optional)</label>
                                        <div v-if="selectedCustomer" class="mt-1 flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-700">
                                            <UserIcon class="size-5 text-gray-400" />
                                            <span class="flex-1 text-sm text-gray-900 dark:text-white">
                                                {{ selectedCustomer.full_name }}
                                                <span v-if="selectedCustomer.email" class="text-gray-500 dark:text-gray-400"> - {{ selectedCustomer.email }}</span>
                                            </span>
                                            <button type="button" @click="clearCustomer" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                                <XMarkIcon class="size-5" />
                                            </button>
                                        </div>
                                        <div v-else class="relative mt-1">
                                            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                                            <input
                                                v-model="customerSearch"
                                                type="text"
                                                placeholder="Search customers by name, email, or phone..."
                                                class="block w-full rounded-md border-0 py-1.5 pl-10 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <!-- Customer Search Results -->
                                            <div v-if="customerResults.length > 0" class="absolute z-10 mt-1 max-h-48 w-full overflow-auto rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 dark:bg-gray-700">
                                                <button
                                                    v-for="customer in customerResults"
                                                    :key="customer.id"
                                                    type="button"
                                                    @click="selectCustomer(customer)"
                                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-600"
                                                >
                                                    <UserIcon class="size-4 text-gray-400" />
                                                    <div>
                                                        <p class="font-medium text-gray-900 dark:text-white">{{ customer.full_name }}</p>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                                            {{ customer.email || customer.phone || 'No contact info' }}
                                                        </p>
                                                    </div>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Item Selection -->
                                    <div>
                                        <div class="flex items-center justify-between mb-2">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Select Items to Sell *
                                            </label>
                                            <div class="flex gap-2">
                                                <button type="button" @click="selectAllItems" class="text-xs text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                    Select All
                                                </button>
                                                <span class="text-gray-300 dark:text-gray-600">|</span>
                                                <button type="button" @click="clearSelection" class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
                                                    Clear
                                                </button>
                                            </div>
                                        </div>
                                        <div class="max-h-64 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-600">
                                            <div
                                                v-for="item in activeItems"
                                                :key="item.id"
                                                class="flex items-center gap-3 border-b border-gray-200 px-3 py-3 last:border-b-0 dark:border-gray-600"
                                                :class="{ 'bg-indigo-50 dark:bg-indigo-900/20': selectedItemIds.includes(item.id) }"
                                            >
                                                <input
                                                    type="checkbox"
                                                    :checked="selectedItemIds.includes(item.id)"
                                                    @change="toggleItemSelection(item.id)"
                                                    class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ item.title }}</p>
                                                    <p v-if="item.description" class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ item.description }}</p>
                                                </div>
                                                <div class="w-24">
                                                    <div class="relative">
                                                        <span class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-sm">$</span>
                                                        <input
                                                            v-model.number="itemPrices[item.id]"
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            class="block w-full rounded-md border-0 py-1 pl-6 pr-2 text-right text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <p v-if="saleForm.errors.item_ids" class="mt-1 text-sm text-red-600">{{ saleForm.errors.item_ids }}</p>
                                    </div>

                                    <!-- Summary -->
                                    <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-700">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ selectedItemIds.length }} item{{ selectedItemIds.length !== 1 ? 's' : '' }} selected
                                            </span>
                                            <span class="text-lg font-semibold text-gray-900 dark:text-white">
                                                {{ formatCurrency(selectedItemsTotal) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-5 sm:mt-6 flex flex-row-reverse gap-3">
                                    <button
                                        type="submit"
                                        :disabled="saleForm.processing || selectedItemIds.length === 0"
                                        class="inline-flex justify-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 disabled:opacity-50"
                                    >
                                        {{ saleForm.processing ? 'Creating...' : 'Create Sale' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showSaleModal = false"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
