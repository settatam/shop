<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Form } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { ArrowLeftIcon, SparklesIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

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

interface FieldOption {
    value: string;
    label: string;
}

interface TemplateField {
    id: number;
    name: string;
    label: string;
    type: string;
    placeholder: string | null;
    help_text: string | null;
    default_value: string | null;
    is_required: boolean;
    group_name: string | null;
    group_position: number;
    width_class: string;
    options: FieldOption[];
}

interface TransactionItem {
    id: number;
    transaction_id: number;
    title: string;
    description: string | null;
    sku: string | null;
    quantity: number;
    category_id: number | null;
    category: { id: number; name: string; full_path: string } | null;
    price: number | null;
    buy_price: number | null;
    dwt: number | null;
    precious_metal: string | null;
    condition: string | null;
    attributes: Record<string, string> | null;
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
    templateFields: TemplateField[];
}

const props = defineProps<Props>();

// Template fields state
const templateFields = ref<TemplateField[]>(props.templateFields || []);
const loadingTemplate = ref(false);
const selectedCategoryId = ref(props.item.category_id);
const attributes = ref<Record<string, string>>({ ...(props.item.attributes || {}) });

// Watch for category changes to load new template fields
watch(selectedCategoryId, async (newCategoryId) => {
    if (newCategoryId) {
        loadingTemplate.value = true;
        try {
            const response = await fetch(`/api/v1/categories/${newCategoryId}/template`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });

            if (response.ok) {
                const data = await response.json();
                templateFields.value = (data.fields || []).map((f: any) => ({
                    ...f,
                    options: f.options || [],
                }));
            } else {
                templateFields.value = [];
            }
        } catch {
            templateFields.value = [];
        } finally {
            loadingTemplate.value = false;
        }
    } else {
        templateFields.value = [];
    }
});

// Group template fields
const groupedTemplateFields = computed(() => {
    const groups: Record<string, TemplateField[]> = {};
    const standalone: TemplateField[] = [];

    for (const field of templateFields.value) {
        if (field.group_name) {
            if (!groups[field.group_name]) {
                groups[field.group_name] = [];
            }
            groups[field.group_name].push(field);
            groups[field.group_name].sort((a, b) => a.group_position - b.group_position);
        } else {
            standalone.push(field);
        }
    }

    return { groups, standalone };
});

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

// AI Auto-populate fields
const autoPopulatingFields = ref(false);
const autoPopulateError = ref<string | null>(null);
const autoPopulateResult = ref<{
    identified: boolean;
    confidence: string;
    product_info: Record<string, string | null>;
    notes: string | null;
} | null>(null);

const autoPopulateFields = async () => {
    if (!selectedCategoryId.value) {
        autoPopulateError.value = 'Please select a category first.';
        return;
    }

    autoPopulatingFields.value = true;
    autoPopulateError.value = null;
    autoPopulateResult.value = null;

    try {
        const response = await axios.post(
            `/transactions/${props.transaction.id}/items/${props.item.id}/auto-populate-fields`
        );

        const data = response.data;

        if (data.error) {
            autoPopulateError.value = data.error;
            return;
        }

        // Apply the suggested field values
        if (data.fields) {
            for (const [fieldId, value] of Object.entries(data.fields)) {
                attributes.value[fieldId] = value as string;
            }
        }

        autoPopulateResult.value = {
            identified: data.identified,
            confidence: data.confidence,
            product_info: data.product_info || {},
            notes: data.notes,
        };
    } catch (error: any) {
        autoPopulateError.value = error.response?.data?.error || 'Failed to auto-populate fields.';
    } finally {
        autoPopulatingFields.value = false;
    }
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
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                    <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>
                                </div>

                                <div>
                                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                    <textarea
                                        id="description"
                                        name="description"
                                        rows="4"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >{{ item.description }}</textarea>
                                    <p v-if="errors.description" class="mt-1 text-sm text-red-600">{{ errors.description }}</p>
                                </div>

                                <div>
                                    <label for="category_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                    <select
                                        id="category_id"
                                        name="category_id"
                                        v-model="selectedCategoryId"
                                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option :value="null">No Category</option>
                                        <option
                                            v-for="cat in categories"
                                            :key="cat.id"
                                            :value="cat.id"
                                        >
                                            {{ '\u00A0'.repeat(cat.level * 2) }}{{ cat.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Template Fields -->
                        <div v-if="loadingTemplate" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 mb-6">
                            <div class="px-4 py-5 sm:p-6 flex items-center justify-center gap-2 text-gray-500 dark:text-gray-400">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                <span>Loading template fields...</span>
                            </div>
                        </div>

                        <div v-else-if="templateFields.length > 0" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 mb-6">
                            <div class="px-4 py-5 sm:p-6 space-y-4">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Template Fields</h3>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-x-1.5 rounded-md bg-gradient-to-r from-purple-600 to-indigo-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:from-purple-500 hover:to-indigo-500 disabled:opacity-50"
                                        :disabled="autoPopulatingFields"
                                        @click="autoPopulateFields"
                                    >
                                        <SparklesIcon class="-ml-0.5 size-4" :class="{ 'animate-pulse': autoPopulatingFields }" />
                                        {{ autoPopulatingFields ? 'Identifying...' : 'Auto-fill with AI' }}
                                    </button>
                                </div>

                                <!-- AI Result Alert -->
                                <div v-if="autoPopulateResult" class="rounded-md p-3" :class="autoPopulateResult.identified ? 'bg-green-50 dark:bg-green-900/20' : 'bg-yellow-50 dark:bg-yellow-900/20'">
                                    <div class="flex">
                                        <div class="shrink-0">
                                            <SparklesIcon v-if="autoPopulateResult.identified" class="size-5 text-green-400" />
                                            <svg v-else class="size-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <h3 class="text-sm font-medium" :class="autoPopulateResult.identified ? 'text-green-800 dark:text-green-200' : 'text-yellow-800 dark:text-yellow-200'">
                                                {{ autoPopulateResult.identified ? 'Product Identified' : 'Could not identify product' }}
                                                <span v-if="autoPopulateResult.confidence" class="text-xs opacity-75">({{ autoPopulateResult.confidence }} confidence)</span>
                                            </h3>
                                            <div v-if="autoPopulateResult.product_info && Object.keys(autoPopulateResult.product_info).length > 0" class="mt-2 text-sm" :class="autoPopulateResult.identified ? 'text-green-700 dark:text-green-300' : 'text-yellow-700 dark:text-yellow-300'">
                                                <p v-if="autoPopulateResult.product_info.brand"><strong>Brand:</strong> {{ autoPopulateResult.product_info.brand }}</p>
                                                <p v-if="autoPopulateResult.product_info.model"><strong>Model:</strong> {{ autoPopulateResult.product_info.model }}</p>
                                                <p v-if="autoPopulateResult.product_info.reference_number"><strong>Ref:</strong> {{ autoPopulateResult.product_info.reference_number }}</p>
                                            </div>
                                            <p v-if="autoPopulateResult.notes" class="mt-1 text-xs" :class="autoPopulateResult.identified ? 'text-green-600 dark:text-green-400' : 'text-yellow-600 dark:text-yellow-400'">
                                                {{ autoPopulateResult.notes }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Error Alert -->
                                <div v-if="autoPopulateError" class="rounded-md bg-red-50 p-3 dark:bg-red-900/20">
                                    <div class="flex">
                                        <div class="shrink-0">
                                            <svg class="size-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-red-700 dark:text-red-300">{{ autoPopulateError }}</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Grouped Fields -->
                                <div v-for="(fields, groupName) in groupedTemplateFields.groups" :key="groupName" class="space-y-2">
                                    <div class="flex gap-2">
                                        <div
                                            v-for="field in fields"
                                            :key="field.id"
                                            :class="[
                                                field.width_class === 'full' ? 'flex-1' : '',
                                                field.width_class === 'half' ? 'w-1/2' : '',
                                                field.width_class === 'third' ? 'w-1/3' : '',
                                                field.width_class === 'quarter' ? 'w-1/4' : '',
                                                field.group_position > 1 ? 'w-auto shrink-0' : 'flex-1',
                                            ]"
                                        >
                                            <label :for="`attr_${field.id}`" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                {{ field.label }}
                                                <span v-if="field.is_required" class="text-red-500">*</span>
                                            </label>

                                            <input
                                                v-if="field.type === 'text'"
                                                :id="`attr_${field.id}`"
                                                :name="`attributes[${field.id}]`"
                                                v-model="attributes[field.id]"
                                                type="text"
                                                :placeholder="field.placeholder || ''"
                                                class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <input
                                                v-else-if="field.type === 'number'"
                                                :id="`attr_${field.id}`"
                                                :name="`attributes[${field.id}]`"
                                                v-model="attributes[field.id]"
                                                type="number"
                                                step="any"
                                                :placeholder="field.placeholder || ''"
                                                class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <select
                                                v-else-if="field.type === 'select'"
                                                :id="`attr_${field.id}`"
                                                :name="`attributes[${field.id}]`"
                                                v-model="attributes[field.id]"
                                                class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option value="">{{ field.placeholder || 'Select...' }}</option>
                                                <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                                                    {{ opt.label }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Standalone Fields -->
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div
                                        v-for="field in groupedTemplateFields.standalone"
                                        :key="field.id"
                                        :class="[field.width_class === 'full' ? 'sm:col-span-2' : '']"
                                    >
                                        <label :for="`attr_${field.id}`" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ field.label }}
                                            <span v-if="field.is_required" class="text-red-500">*</span>
                                        </label>

                                        <input
                                            v-if="field.type === 'text'"
                                            :id="`attr_${field.id}`"
                                            :name="`attributes[${field.id}]`"
                                            v-model="attributes[field.id]"
                                            type="text"
                                            :placeholder="field.placeholder || ''"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <input
                                            v-else-if="field.type === 'number'"
                                            :id="`attr_${field.id}`"
                                            :name="`attributes[${field.id}]`"
                                            v-model="attributes[field.id]"
                                            type="number"
                                            step="any"
                                            :placeholder="field.placeholder || ''"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <textarea
                                            v-else-if="field.type === 'textarea'"
                                            :id="`attr_${field.id}`"
                                            :name="`attributes[${field.id}]`"
                                            v-model="attributes[field.id]"
                                            :placeholder="field.placeholder || ''"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <select
                                            v-else-if="field.type === 'select'"
                                            :id="`attr_${field.id}`"
                                            :name="`attributes[${field.id}]`"
                                            v-model="attributes[field.id]"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option value="">{{ field.placeholder || 'Select...' }}</option>
                                            <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                                                {{ opt.label }}
                                            </option>
                                        </select>

                                        <p v-if="field.help_text" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ field.help_text }}
                                        </p>
                                    </div>
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
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
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
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div>
                                        <label for="condition" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Condition</label>
                                        <select
                                            id="condition"
                                            name="condition"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
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

                        <!-- Quantity & Pricing -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 mb-6">
                            <div class="px-4 py-5 sm:p-6 space-y-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Quantity & Pricing</h3>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <label for="quantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Quantity</label>
                                        <input
                                            id="quantity"
                                            type="number"
                                            name="quantity"
                                            min="1"
                                            :value="item.quantity"
                                            class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="errors.quantity" class="mt-1 text-sm text-red-600">{{ errors.quantity }}</p>
                                    </div>

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
                                                class="block w-full rounded-md border-0 py-2 pl-7 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
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
                                                class="block w-full rounded-md border-0 py-2 pl-7 pr-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
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
