<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { ChevronUpIcon, ChevronDownIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import { ArrowLeftIcon, ArrowPathIcon, InformationCircleIcon } from '@heroicons/vue/20/solid';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';

interface Category {
    id: number;
    name: string;
    full_path: string;
    products_count: number;
    template_id: number | null;
    template_name: string | null;
    effective_template_name: string | null;
    sku_format: string | null;
    sku_prefix: string | null;
    effective_sku_format: string | null;
    effective_sku_prefix: string | null;
    title_format: string | null;
    effective_title_format: string | null;
    label_template_id: number | null;
    label_template_name: string | null;
    effective_label_template_name: string | null;
    barcode_attributes: string[] | null;
    effective_barcode_attributes: string[];
    current_sequence: number;
}

interface Template {
    id: number;
    name: string;
}

interface LabelTemplate {
    id: number;
    name: string;
}

interface TemplateField {
    name: string;
    label: string;
}

interface Props {
    category: Category;
    templates: Template[];
    labelTemplates: LabelTemplate[];
    skuPreview: string | null;
    availableVariables: Record<string, string>;
    templateFields: TemplateField[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Categories', href: '/categories' },
    { title: props.category.name, href: `/categories/${props.category.id}/settings` },
    { title: 'Settings', href: `/categories/${props.category.id}/settings` },
];

// Form for updating settings
const form = useForm({
    template_id: props.category.template_id,
    sku_format: props.category.sku_format || '',
    sku_prefix: props.category.sku_prefix || '',
    title_format: props.category.title_format || '',
    label_template_id: props.category.label_template_id,
    barcode_attributes: props.category.barcode_attributes || [],
});

// Available built-in barcode attributes
const builtInBarcodeAttributes = [
    { name: 'price_code', label: 'Price Code' },
    { name: 'category', label: 'Category Name' },
    { name: 'price', label: 'Price (spelled out)' },
    { name: 'sku', label: 'SKU' },
];

// Combine built-in and template field attributes
const availableBarcodeAttributes = computed(() => {
    return [
        ...builtInBarcodeAttributes,
        ...props.templateFields.map(f => ({ name: f.name, label: `${f.label} (template)` })),
    ];
});

// Check if barcode attributes are inherited
const isBarcodeAttributesInherited = computed(() => {
    return (!props.category.barcode_attributes || props.category.barcode_attributes.length === 0)
        && props.category.effective_barcode_attributes.length > 0;
});

// Add a barcode attribute
function addBarcodeAttribute(attr: string) {
    if (!form.barcode_attributes.includes(attr)) {
        form.barcode_attributes = [...form.barcode_attributes, attr];
    }
}

// Remove a barcode attribute
function removeBarcodeAttribute(index: number) {
    form.barcode_attributes = form.barcode_attributes.filter((_, i) => i !== index);
}

// Move barcode attribute up
function moveBarcodeAttributeUp(index: number) {
    if (index > 0) {
        const newAttrs = [...form.barcode_attributes];
        [newAttrs[index - 1], newAttrs[index]] = [newAttrs[index], newAttrs[index - 1]];
        form.barcode_attributes = newAttrs;
    }
}

// Move barcode attribute down
function moveBarcodeAttributeDown(index: number) {
    if (index < form.barcode_attributes.length - 1) {
        const newAttrs = [...form.barcode_attributes];
        [newAttrs[index], newAttrs[index + 1]] = [newAttrs[index + 1], newAttrs[index]];
        form.barcode_attributes = newAttrs;
    }
}

// Get display label for attribute
function getAttributeLabel(name: string): string {
    const builtin = builtInBarcodeAttributes.find(a => a.name === name);
    if (builtin) return builtin.label;
    const field = props.templateFields.find(f => f.name === name);
    if (field) return field.label;
    return name;
}

// SKU preview state
const skuPreview = ref(props.skuPreview || '');
const previewLoading = ref(false);
const previewErrors = ref<string[]>([]);

// Reset sequence modal
const showResetModal = ref(false);
const resetValue = ref(0);

// Debounced preview update
let previewTimeout: ReturnType<typeof setTimeout> | null = null;

async function updatePreview() {
    if (previewTimeout) {
        clearTimeout(previewTimeout);
    }

    previewTimeout = setTimeout(async () => {
        if (!form.sku_format) {
            skuPreview.value = '';
            previewErrors.value = [];
            return;
        }

        previewLoading.value = true;
        previewErrors.value = [];

        try {
            const response = await fetch(`/categories/${props.category.id}/preview-sku`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    format: form.sku_format,
                    sku_prefix: form.sku_prefix,
                }),
            });

            const data = await response.json();

            if (data.valid) {
                skuPreview.value = data.preview;
                previewErrors.value = [];
            } else {
                skuPreview.value = '';
                previewErrors.value = data.errors;
            }
        } catch {
            previewErrors.value = ['Failed to generate preview'];
        } finally {
            previewLoading.value = false;
        }
    }, 300);
}

// Watch for changes to update preview
watch(() => form.sku_format, updatePreview);
watch(() => form.sku_prefix, updatePreview);

function submit() {
    form.put(`/categories/${props.category.id}/settings`);
}

function openResetModal() {
    resetValue.value = 0;
    showResetModal.value = true;
}

function confirmReset() {
    router.post(`/categories/${props.category.id}/reset-sequence`, {
        reset_to: resetValue.value,
    }, {
        onSuccess: () => {
            showResetModal.value = false;
        },
    });
}

function insertVariable(variable: string) {
    form.sku_format = (form.sku_format || '') + variable;
}

// Check if values are inherited from parent
const isSkuFormatInherited = computed(() => {
    return !props.category.sku_format && props.category.effective_sku_format;
});

const isSkuPrefixInherited = computed(() => {
    return !props.category.sku_prefix && props.category.effective_sku_prefix;
});

const isTemplateInherited = computed(() => {
    return !props.category.template_id && props.category.effective_template_name;
});

const isLabelTemplateInherited = computed(() => {
    return !props.category.label_template_id && props.category.effective_label_template_name;
});

const isTitleFormatInherited = computed(() => {
    return !props.category.title_format && props.category.effective_title_format;
});

function insertTitleVariable(variable: string) {
    form.title_format = (form.title_format || '') + variable;
}
</script>

<template>
    <Head :title="`${category.name} Settings`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 lg:p-8">
            <!-- Header -->
            <div class="mb-8">
                <button
                    type="button"
                    class="mb-4 inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    @click="router.visit('/categories')"
                >
                    <ArrowLeftIcon class="size-4" />
                    Back to Categories
                </button>

                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ category.name }} Settings</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ category.full_path }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
                <!-- Main Form -->
                <div class="lg:col-span-2">
                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- SKU Configuration -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">SKU Configuration</h3>

                                <div class="space-y-4">
                                    <!-- SKU Prefix -->
                                    <div>
                                        <label for="sku_prefix" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Category Code / Prefix
                                        </label>
                                        <input
                                            id="sku_prefix"
                                            v-model="form.sku_prefix"
                                            type="text"
                                            maxlength="50"
                                            placeholder="e.g., JEW, RNG, BRC"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 font-mono uppercase"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Used with the <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">{category_code}</code> variable
                                            <span v-if="isSkuPrefixInherited" class="text-amber-600 dark:text-amber-400 ml-1">
                                                (inheriting "{{ category.effective_sku_prefix }}" from parent)
                                            </span>
                                        </p>
                                        <p v-if="form.errors.sku_prefix" class="mt-1 text-sm text-red-600">{{ form.errors.sku_prefix }}</p>
                                    </div>

                                    <!-- SKU Format -->
                                    <div>
                                        <label for="sku_format" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            SKU Format Pattern
                                        </label>
                                        <input
                                            id="sku_format"
                                            v-model="form.sku_format"
                                            type="text"
                                            maxlength="255"
                                            placeholder="e.g., {category_code}-{sequence:5}"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 font-mono"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Define how SKUs are generated for products in this category
                                            <span v-if="isSkuFormatInherited" class="text-amber-600 dark:text-amber-400 ml-1">
                                                (inheriting from parent)
                                            </span>
                                        </p>
                                        <p v-if="form.errors.sku_format" class="mt-1 text-sm text-red-600">{{ form.errors.sku_format }}</p>
                                        <p v-for="error in previewErrors" :key="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
                                    </div>

                                    <!-- Preview -->
                                    <div v-if="form.sku_format || category.effective_sku_format" class="rounded-lg bg-gray-50 p-4 dark:bg-gray-700/50">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Preview</span>
                                            <ArrowPathIcon v-if="previewLoading" class="size-4 animate-spin text-gray-400" />
                                        </div>
                                        <p class="mt-1 font-mono text-lg text-indigo-600 dark:text-indigo-400">
                                            {{ skuPreview || category.effective_sku_format || '—' }}
                                        </p>
                                    </div>

                                    <!-- Variable Reference -->
                                    <div>
                                        <button
                                            type="button"
                                            class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                            @click="$refs.variableRef?.scrollIntoView({ behavior: 'smooth' })"
                                        >
                                            View available variables
                                        </button>
                                    </div>

                                    <!-- Sequence Info -->
                                    <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                        <div>
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Current Sequence</p>
                                            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ category.current_sequence }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Next SKU will use {{ category.current_sequence + 1 }}</p>
                                        </div>
                                        <button
                                            type="button"
                                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                            @click="openResetModal"
                                        >
                                            Reset
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Title Configuration -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Title Configuration</h3>

                                <div class="space-y-4">
                                    <!-- Title Format -->
                                    <div>
                                        <label for="title_format" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Title Format Pattern
                                        </label>
                                        <input
                                            id="title_format"
                                            v-model="form.title_format"
                                            type="text"
                                            maxlength="255"
                                            placeholder="e.g., {brand} {metal_type} {category_name}"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 font-mono"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Define how product titles are auto-generated for this category. Uses the same variables as SKU format.
                                            <span v-if="isTitleFormatInherited" class="text-amber-600 dark:text-amber-400 ml-1">
                                                (inheriting from parent)
                                            </span>
                                        </p>
                                        <p v-if="form.errors.title_format" class="mt-1 text-sm text-red-600">{{ form.errors.title_format }}</p>
                                    </div>

                                    <!-- Quick Variable Insert -->
                                    <div>
                                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Quick Insert Variables</p>
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                v-for="(desc, variable) in availableVariables"
                                                :key="'title-' + variable"
                                                type="button"
                                                class="rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 hover:bg-indigo-100 hover:text-indigo-700 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-indigo-900 dark:hover:text-indigo-300"
                                                @click="insertTitleVariable(variable)"
                                                :title="desc"
                                            >
                                                {{ variable }}
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Preview -->
                                    <div v-if="form.title_format || category.effective_title_format" class="rounded-lg bg-gray-50 p-4 dark:bg-gray-700/50">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Example Output</span>
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                            Title will be generated using template attributes and the format pattern.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Templates -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Templates</h3>

                                <div class="space-y-4">
                                    <!-- Product Template -->
                                    <div>
                                        <label for="template_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Product Template
                                        </label>
                                        <select
                                            id="template_id"
                                            v-model="form.template_id"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">
                                                {{ isTemplateInherited ? `Inherit from parent (${category.effective_template_name})` : '— No template —' }}
                                            </option>
                                            <option v-for="template in templates" :key="template.id" :value="template.id">
                                                {{ template.name }}
                                            </option>
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Defines the custom fields for products in this category
                                        </p>
                                    </div>

                                    <!-- Label Template -->
                                    <div>
                                        <label for="label_template_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Label / Barcode Template
                                        </label>
                                        <select
                                            id="label_template_id"
                                            v-model="form.label_template_id"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option :value="null">
                                                {{ isLabelTemplateInherited ? `Inherit from parent (${category.effective_label_template_name})` : '— Use store default —' }}
                                            </option>
                                            <option v-for="template in labelTemplates" :key="template.id" :value="template.id">
                                                {{ template.name }}
                                            </option>
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Used when printing labels for products in this category
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Barcode Label Configuration -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Barcode Label Content</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                    Configure which fields appear on printed barcode labels. The barcode/SKU is always shown, followed by the fields you select below.
                                    <span v-if="isBarcodeAttributesInherited" class="text-amber-600 dark:text-amber-400">
                                        (inheriting from parent)
                                    </span>
                                </p>

                                <div class="space-y-4">
                                    <!-- Current attributes list -->
                                    <div v-if="form.barcode_attributes.length > 0" class="space-y-2">
                                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Label Fields (in order)</p>
                                        <div
                                            v-for="(attr, index) in form.barcode_attributes"
                                            :key="index"
                                            class="flex items-center gap-2 rounded-lg border border-gray-200 p-2 dark:border-gray-700"
                                        >
                                            <span class="text-sm text-gray-500 dark:text-gray-400 w-6 text-center">{{ index + 1 }}.</span>
                                            <span class="flex-1 text-sm text-gray-900 dark:text-white">{{ getAttributeLabel(attr) }}</span>
                                            <div class="flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 disabled:opacity-30"
                                                    :disabled="index === 0"
                                                    @click="moveBarcodeAttributeUp(index)"
                                                    title="Move up"
                                                >
                                                    <ChevronUpIcon class="size-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 disabled:opacity-30"
                                                    :disabled="index === form.barcode_attributes.length - 1"
                                                    @click="moveBarcodeAttributeDown(index)"
                                                    title="Move down"
                                                >
                                                    <ChevronDownIcon class="size-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="p-1 text-red-400 hover:text-red-600"
                                                    @click="removeBarcodeAttribute(index)"
                                                    title="Remove"
                                                >
                                                    <XMarkIcon class="size-4" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Preview of effective attributes when inheriting -->
                                    <div v-else-if="isBarcodeAttributesInherited" class="rounded-lg bg-amber-50 p-3 dark:bg-amber-900/20">
                                        <p class="text-sm font-medium text-amber-800 dark:text-amber-200 mb-2">Inherited from parent:</p>
                                        <ol class="list-decimal list-inside text-sm text-amber-700 dark:text-amber-300 space-y-1">
                                            <li v-for="attr in category.effective_barcode_attributes" :key="attr">
                                                {{ getAttributeLabel(attr) }}
                                            </li>
                                        </ol>
                                    </div>

                                    <!-- Add attribute dropdown -->
                                    <div>
                                        <label for="add_barcode_attr" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Add Field
                                        </label>
                                        <div class="flex gap-2">
                                            <select
                                                id="add_barcode_attr"
                                                class="flex-1 rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                @change="(e) => { addBarcodeAttribute((e.target as HTMLSelectElement).value); (e.target as HTMLSelectElement).value = ''; }"
                                            >
                                                <option value="">Select a field to add...</option>
                                                <optgroup label="Built-in Fields">
                                                    <option
                                                        v-for="attr in builtInBarcodeAttributes"
                                                        :key="attr.name"
                                                        :value="attr.name"
                                                        :disabled="form.barcode_attributes.includes(attr.name)"
                                                    >
                                                        {{ attr.label }}
                                                    </option>
                                                </optgroup>
                                                <optgroup v-if="templateFields.length > 0" label="Template Fields">
                                                    <option
                                                        v-for="field in templateFields"
                                                        :key="field.name"
                                                        :value="field.name"
                                                        :disabled="form.barcode_attributes.includes(field.name)"
                                                    >
                                                        {{ field.label }}
                                                    </option>
                                                </optgroup>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Clear to inherit -->
                                    <div v-if="form.barcode_attributes.length > 0" class="pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <button
                                            type="button"
                                            class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                            @click="form.barcode_attributes = []"
                                        >
                                            Clear and inherit from parent
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="flex justify-end gap-3">
                            <button
                                type="button"
                                class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                                @click="router.visit('/categories')"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                            >
                                {{ form.processing ? 'Saving...' : 'Save Settings' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Category Info -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Category Info</h3>

                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Name</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">{{ category.name }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Path</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">{{ category.full_path }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Products</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">{{ category.products_count }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Variable Reference -->
                    <div ref="variableRef" class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                                <InformationCircleIcon class="inline size-5 mr-1 text-gray-400" />
                                Available Variables
                            </h3>

                            <div class="space-y-3">
                                <div
                                    v-for="(description, variable) in availableVariables"
                                    :key="variable"
                                    class="flex items-start gap-2"
                                >
                                    <button
                                        type="button"
                                        class="shrink-0 rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 hover:bg-indigo-100 hover:text-indigo-700 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-indigo-900 dark:hover:text-indigo-300"
                                        @click="insertVariable(variable)"
                                        :title="'Click to insert ' + variable"
                                    >
                                        {{ variable }}
                                    </button>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ description }}</span>
                                </div>
                            </div>

                            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                                Click a variable to insert it into the format pattern.
                            </p>
                        </div>
                    </div>

                    <!-- Examples -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Examples</h3>

                            <div class="space-y-3 text-sm">
                                <div>
                                    <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">{category_code}-{sequence:5}</code>
                                    <p class="text-gray-500 dark:text-gray-400">JEW-00042</p>
                                </div>
                                <div>
                                    <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">{category_name:3}-{product_id}</code>
                                    <p class="text-gray-500 dark:text-gray-400">RNG-123</p>
                                </div>
                                <div>
                                    <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">{year:2}{month}-{sequence:4}</code>
                                    <p class="text-gray-500 dark:text-gray-400">2601-0042</p>
                                </div>
                                <div>
                                    <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">SKU-{random:6}</code>
                                    <p class="text-gray-500 dark:text-gray-400">SKU-X7K2M9</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reset Sequence Modal -->
        <TransitionRoot as="template" :show="showResetModal">
            <Dialog class="relative z-50" @close="showResetModal = false">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-300"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-in duration-200"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
                </TransitionChild>

                <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <TransitionChild
                            as="template"
                            enter="ease-out duration-300"
                            enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                            enter-to="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-200"
                            leave-from="opacity-100 translate-y-0 sm:scale-100"
                            leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        >
                            <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6 dark:bg-gray-800">
                                <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    Reset SKU Sequence
                                </DialogTitle>

                                <div class="space-y-4">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Reset the SKU sequence counter to a specific value. The next generated SKU will use this value + 1.
                                    </p>

                                    <div>
                                        <label for="reset_value" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Reset to
                                        </label>
                                        <input
                                            id="reset_value"
                                            v-model.number="resetValue"
                                            type="number"
                                            min="0"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>

                                    <div class="rounded-lg bg-amber-50 p-3 dark:bg-amber-900/20">
                                        <p class="text-sm text-amber-800 dark:text-amber-200">
                                            This action cannot be undone. Duplicate SKUs may be generated if you reset to a value that was previously used.
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-6 flex justify-end gap-3">
                                    <button
                                        type="button"
                                        class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showResetModal = false"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                                        @click="confirmReset"
                                    >
                                        Reset Sequence
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>
    </AppLayout>
</template>
