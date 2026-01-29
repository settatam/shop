<script setup lang="ts">
import { ref } from 'vue';
import { PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline';
import AddItemModal from './AddItemModal.vue';

interface Category {
    value: number;
    label: string;
}

interface SelectOption {
    value: string;
    label: string;
}

interface TransactionItem {
    id: string;
    title: string;
    description?: string;
    category_id?: number;
    precious_metal?: string;
    dwt?: number;
    condition?: string;
    price?: number;
    buy_price: number;
}

interface Props {
    items: TransactionItem[];
    categories: Category[];
    preciousMetals: SelectOption[];
    conditions: SelectOption[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    update: [items: TransactionItem[]];
}>();

const showModal = ref(false);
const editingItem = ref<TransactionItem | null>(null);

function openAddModal() {
    editingItem.value = null;
    showModal.value = true;
}

function openEditModal(item: TransactionItem) {
    editingItem.value = item;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingItem.value = null;
}

function handleSaveItem(item: TransactionItem) {
    const existingIndex = props.items.findIndex(i => i.id === item.id);

    if (existingIndex >= 0) {
        const newItems = [...props.items];
        newItems[existingIndex] = item;
        emit('update', newItems);
    } else {
        emit('update', [...props.items, item]);
    }
}

function removeItem(itemId: string) {
    emit('update', props.items.filter(i => i.id !== itemId));
}

function getCategoryName(categoryId?: number): string {
    if (!categoryId) return '-';
    const cat = props.categories.find(c => c.value === categoryId);
    return cat?.label || '-';
}

function getMetalName(metalValue?: string): string {
    if (!metalValue) return '-';
    const metal = props.preciousMetals.find(m => m.value === metalValue);
    return metal?.label || metalValue;
}

function getConditionName(conditionValue?: string): string {
    if (!conditionValue) return '-';
    const cond = props.conditions.find(c => c.value === conditionValue);
    return cond?.label || conditionValue;
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                    Add Items
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Add the items being purchased from the customer.
                </p>
            </div>
            <button
                type="button"
                @click="openAddModal"
                class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
                <PlusIcon class="-ml-0.5 size-5" aria-hidden="true" />
                Add Item
            </button>
        </div>

        <!-- Empty state -->
        <div
            v-if="items.length === 0"
            class="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center dark:border-gray-600"
        >
            <svg
                class="mx-auto size-12 text-gray-400"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.5"
                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                />
            </svg>
            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No items</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Get started by adding an item to this transaction.
            </p>
            <div class="mt-6">
                <button
                    type="button"
                    @click="openAddModal"
                    class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                >
                    <PlusIcon class="-ml-0.5 mr-1.5 size-5" aria-hidden="true" />
                    Add Item
                </button>
            </div>
        </div>

        <!-- Items table -->
        <div v-else class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                            Item
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                            Category
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                            Metal
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                            DWT
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">
                            Est. Value
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">
                            Buy Price
                        </th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                    <tr v-for="item in items" :key="item.id">
                        <td class="whitespace-nowrap py-4 pl-4 pr-3 sm:pl-6">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-white">{{ item.title }}</div>
                                <div v-if="item.condition" class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ getConditionName(item.condition) }}
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                            {{ getCategoryName(item.category_id) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                            {{ getMetalName(item.precious_metal) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                            {{ item.dwt ? item.dwt.toFixed(2) : '-' }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-right text-sm text-gray-500 dark:text-gray-400">
                            {{ item.price ? `$${item.price.toFixed(2)}` : '-' }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-right text-sm font-medium text-gray-900 dark:text-white">
                            ${{ item.buy_price.toFixed(2) }}
                        </td>
                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    @click="openEditModal(item)"
                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-indigo-600 dark:hover:bg-gray-700"
                                    title="Edit"
                                >
                                    <PencilIcon class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    @click="removeItem(item.id)"
                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700"
                                    title="Remove"
                                >
                                    <TrashIcon class="size-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <td colspan="5" class="py-3 pl-4 pr-3 text-right text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                            Total Buy Price
                        </td>
                        <td class="py-3 pr-3 text-right text-sm font-semibold text-gray-900 dark:text-white">
                            ${{ items.reduce((sum, i) => sum + i.buy_price, 0).toFixed(2) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Add/Edit Item Modal -->
        <AddItemModal
            :open="showModal"
            :categories="categories"
            :editing-item="editingItem"
            @close="closeModal"
            @save="handleSaveItem"
        />
    </div>
</template>
