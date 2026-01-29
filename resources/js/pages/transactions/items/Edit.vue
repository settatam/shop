<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeftIcon } from '@heroicons/vue/20/solid';

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level: number;
    template_id: number | null;
}

interface ItemImage {
    id: number;
    url: string;
    thumbnail_url: string | null;
    alt_text: string | null;
    is_primary: boolean;
}

interface TransactionItem {
    id: number;
    transaction_id: number;
    title: string;
    description: string | null;
    sku: string | null;
    category_id: number | null;
    category: { id: number; name: string; full_path: string } | null;
    price: number | null;
    buy_price: number | null;
    dwt: number | null;
    precious_metal: string | null;
    condition: string | null;
    images: ItemImage[];
    created_at: string;
    updated_at: string;
}

interface Transaction {
    id: number;
    transaction_number: string;
}

interface MetalOption {
    value: string;
    label: string;
}

interface Props {
    transaction: Transaction;
    item: TransactionItem;
    categories: Category[];
    preciousMetals: MetalOption[];
    conditions: MetalOption[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Transactions', href: '/transactions' },
    { title: props.transaction.transaction_number, href: `/transactions/${props.transaction.id}` },
    { title: props.item.title || 'Item', href: `/transactions/${props.transaction.id}/items/${props.item.id}` },
    { title: 'Edit', href: `/transactions/${props.transaction.id}/items/${props.item.id}/edit` },
];

const imageFiles = ref<File[]>([]);
const uploadingImages = ref(false);

const handleImageUpload = (event: Event) => {
    const input = event.target as HTMLInputElement;
    if (input.files) {
        imageFiles.value = Array.from(input.files);
    }
};

const uploadImages = () => {
    if (imageFiles.value.length === 0) return;

    uploadingImages.value = true;
    const formData = new FormData();
    imageFiles.value.forEach((file) => {
        formData.append('images[]', file);
    });

    router.post(`/transactions/${props.transaction.id}/items/${props.item.id}/images`, formData, {
        forceFormData: true,
        onFinish: () => {
            uploadingImages.value = false;
            imageFiles.value = [];
        },
    });
};

const deleteImage = (imageId: number) => {
    if (!confirm('Delete this image?')) return;
    router.delete(`/transactions/${props.transaction.id}/items/${props.item.id}/images/${imageId}`);
};
</script>

<template>
    <Head :title="`Edit: ${item.title || 'Item'}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center gap-4 mb-6">
                <Link
                    :href="`/transactions/${transaction.id}/items/${item.id}`"
                    class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                >
                    <ArrowLeftIcon class="size-5" />
                </Link>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Edit Item</h1>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main form -->
                <div class="lg:col-span-2 space-y-6">
                    <Form
                        :action="`/transactions/${transaction.id}/items/${item.id}`"
                        method="put"
                        #default="{ errors, processing }"
                    >
                        <!-- Basic Info -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 mb-6">
                            <div class="px-4 py-5 sm:p-6 space-y-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Basic Information</h3>

                                <div>
                                    <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Title</label>
                                    <input
                                        id="title"
                                        type="text"
                                        name="title"
                                        :value="item.title"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    />
                                    <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>
                                </div>

                                <div>
                                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                    <textarea
                                        id="description"
                                        name="description"
                                        rows="4"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    >{{ item.description }}</textarea>
                                    <p v-if="errors.description" class="mt-1 text-sm text-red-600">{{ errors.description }}</p>
                                </div>

                                <div>
                                    <label for="category_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                    <select
                                        id="category_id"
                                        name="category_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    >
                                        <option value="">No Category</option>
                                        <option
                                            v-for="cat in categories"
                                            :key="cat.id"
                                            :value="cat.id"
                                            :selected="cat.id === item.category_id"
                                        >
                                            {{ '\u00A0'.repeat(cat.level * 2) }}{{ cat.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Metal & Condition -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 mb-6">
                            <div class="px-4 py-5 sm:p-6 space-y-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Metal & Condition</h3>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <label for="precious_metal" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Precious Metal</label>
                                        <select
                                            id="precious_metal"
                                            name="precious_metal"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option value="">None</option>
                                            <option
                                                v-for="metal in preciousMetals"
                                                :key="metal.value"
                                                :value="metal.value"
                                                :selected="metal.value === item.precious_metal"
                                            >
                                                {{ metal.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="dwt" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Weight (DWT)</label>
                                        <input
                                            id="dwt"
                                            type="number"
                                            name="dwt"
                                            step="0.0001"
                                            :value="item.dwt"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        />
                                    </div>

                                    <div>
                                        <label for="condition" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Condition</label>
                                        <select
                                            id="condition"
                                            name="condition"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        >
                                            <option value="">Not specified</option>
                                            <option
                                                v-for="cond in conditions"
                                                :key="cond.value"
                                                :value="cond.value"
                                                :selected="cond.value === item.condition"
                                            >
                                                {{ cond.label }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 mb-6">
                            <div class="px-4 py-5 sm:p-6 space-y-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Pricing</h3>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="price" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Estimated Value</label>
                                        <div class="relative mt-1">
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                <span class="text-gray-500 sm:text-sm">$</span>
                                            </div>
                                            <input
                                                id="price"
                                                type="number"
                                                name="price"
                                                step="0.01"
                                                :value="item.price"
                                                class="block w-full rounded-md border-gray-300 pl-7 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <label for="buy_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Buy Price</label>
                                        <div class="relative mt-1">
                                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                <span class="text-gray-500 sm:text-sm">$</span>
                                            </div>
                                            <input
                                                id="buy_price"
                                                type="number"
                                                name="buy_price"
                                                step="0.01"
                                                :value="item.buy_price"
                                                class="block w-full rounded-md border-gray-300 pl-7 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3">
                            <Link
                                :href="`/transactions/${transaction.id}/items/${item.id}`"
                                class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                :disabled="processing"
                                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                            >
                                {{ processing ? 'Saving...' : 'Save Changes' }}
                            </button>
                        </div>
                    </Form>
                </div>

                <!-- Sidebar: Images -->
                <div class="space-y-6">
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Images</h3>

                            <!-- Existing images -->
                            <div v-if="item.images.length > 0" class="mb-4 grid grid-cols-3 gap-2">
                                <div
                                    v-for="image in item.images"
                                    :key="image.id"
                                    class="group relative overflow-hidden rounded-lg"
                                >
                                    <img
                                        :src="image.thumbnail_url || image.url"
                                        :alt="image.alt_text || ''"
                                        class="h-20 w-full object-cover"
                                    />
                                    <button
                                        type="button"
                                        class="absolute inset-0 flex items-center justify-center bg-black/50 opacity-0 transition group-hover:opacity-100"
                                        @click="deleteImage(image.id)"
                                    >
                                        <span class="text-xs font-medium text-white">Delete</span>
                                    </button>
                                    <span
                                        v-if="image.is_primary"
                                        class="absolute bottom-0 left-0 right-0 bg-indigo-600 text-center text-[10px] text-white"
                                    >
                                        Primary
                                    </span>
                                </div>
                            </div>

                            <!-- Upload -->
                            <div>
                                <input
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100 dark:text-gray-400 dark:file:bg-indigo-900/30 dark:file:text-indigo-400"
                                    @change="handleImageUpload"
                                />
                                <button
                                    v-if="imageFiles.length > 0"
                                    type="button"
                                    :disabled="uploadingImages"
                                    class="mt-2 w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                    @click="uploadImages"
                                >
                                    {{ uploadingImages ? 'Uploading...' : `Upload ${imageFiles.length} image(s)` }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
