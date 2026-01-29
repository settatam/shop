<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { ArrowLeftIcon, ArrowPathIcon, InformationCircleIcon, PlusIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import { Bars3Icon } from '@heroicons/vue/24/outline';
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
    sku_suffix: string | null;
    effective_sku_format: string | null;
    effective_sku_prefix: string | null;
    effective_sku_suffix: string | null;
    default_bucket_id: number | null;
    default_bucket_name: string | null;
    effective_default_bucket_name: string | null;
    barcode_attributes: string[];
    effective_barcode_attributes: string[];
    label_template_id: number | null;
    label_template_name: string | null;
    effective_label_template_name: string | null;
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

interface Bucket {
    id: number;
    name: string;
}

interface AttributeOption {
    key: string;
    label: string;
    description: string;
    canonical_name?: string;
}

interface AvailableAttributes {
    built_in: AttributeOption[];
    template: AttributeOption[];
}

interface Props {
    category: Category;
    templates: Template[];
    labelTemplates: LabelTemplate[];
    buckets: Bucket[];
    skuPreview: string | null;
    availableVariables: Record<string, string>;
    availableAttributes: AvailableAttributes;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'Product Types', href: '/product-types' },
    { title: props.category.name, href: `/product-types/${props.category.id}/settings` },
];

// Form for updating settings
const form = useForm({
    template_id: props.category.template_id,
    sku_format: props.category.sku_format || '',
    sku_prefix: props.category.sku_prefix || '',
    sku_suffix: props.category.sku_suffix || '',
    default_bucket_id: props.category.default_bucket_id,
    barcode_attributes: props.category.barcode_attributes || [],
    label_template_id: props.category.label_template_id,
});

// SKU preview state
const skuPreview = ref(props.skuPreview || '');
const previewLoading = ref(false);
const previewErrors = ref<string[]>([]);

// Attribute picker modal
const showAttributePicker = ref(false);

// Drag state for reordering
const draggedIndex = ref<number | null>(null);

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
    form.put(`/product-types/${props.category.id}/settings`);
}

function insertVariable(variable: string) {
    form.sku_format = (form.sku_format || '') + variable;
}

// Barcode attribute management
function addAttribute(key: string) {
    if (!form.barcode_attributes.includes(key)) {
        form.barcode_attributes.push(key);
    }
}

function removeAttribute(index: number) {
    form.barcode_attributes.splice(index, 1);
}

function getAttributeLabel(key: string): string {
    // Check built-in
    const builtIn = props.availableAttributes.built_in.find(a => a.key === key);
    if (builtIn) return builtIn.label;

    // Check template
    const templateAttr = props.availableAttributes.template.find(a => a.key === key);
    if (templateAttr) return templateAttr.label;

    // Fallback
    return key;
}

function isAttributeSelected(key: string): boolean {
    return form.barcode_attributes.includes(key);
}

// Drag and drop for reordering
function onDragStart(index: number) {
    draggedIndex.value = index;
}

function onDragOver(event: DragEvent, index: number) {
    event.preventDefault();
    if (draggedIndex.value === null || draggedIndex.value === index) return;

    const newAttributes = [...form.barcode_attributes];
    const draggedItem = newAttributes[draggedIndex.value];
    newAttributes.splice(draggedIndex.value, 1);
    newAttributes.splice(index, 0, draggedItem);

    form.barcode_attributes = newAttributes;
    draggedIndex.value = index;
}

function onDragEnd() {
    draggedIndex.value = null;
}

// Check if values are inherited from parent
const isSkuFormatInherited = computed(() => {
    return !props.category.sku_format && props.category.effective_sku_format;
});

const isSkuPrefixInherited = computed(() => {
    return !props.category.sku_prefix && props.category.effective_sku_prefix;
});

const isSkuSuffixInherited = computed(() => {
    return !props.category.sku_suffix && props.category.effective_sku_suffix;
});

const isTemplateInherited = computed(() => {
    return !props.category.template_id && props.category.effective_template_name;
});

const isLabelTemplateInherited = computed(() => {
    return !props.category.label_template_id && props.category.effective_label_template_name;
});

const isDefaultBucketInherited = computed(() => {
    return !props.category.default_bucket_id && props.category.effective_default_bucket_name;
});

const isBarcodeAttributesInherited = computed(() => {
    return props.category.barcode_attributes.length === 0 && props.category.effective_barcode_attributes.length > 0;
});
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
                    @click="router.visit('/product-types')"
                >
                    <ArrowLeftIcon class="size-4" />
                    Back to Product Types
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
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label for="sku_prefix" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Prefix
                                            </label>
                                            <input
                                                id="sku_prefix"
                                                v-model="form.sku_prefix"
                                                type="text"
                                                maxlength="50"
                                                placeholder="e.g., JEW"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 font-mono uppercase"
                                            />
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="isSkuPrefixInherited" class="text-amber-600 dark:text-amber-400">
                                                    Inheriting "{{ category.effective_sku_prefix }}"
                                                </span>
                                            </p>
                                            <p v-if="form.errors.sku_prefix" class="mt-1 text-sm text-red-600">{{ form.errors.sku_prefix }}</p>
                                        </div>

                                        <!-- SKU Suffix -->
                                        <div>
                                            <label for="sku_suffix" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Suffix
                                            </label>
                                            <input
                                                id="sku_suffix"
                                                v-model="form.sku_suffix"
                                                type="text"
                                                maxlength="50"
                                                placeholder="e.g., -A"
                                                class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 font-mono uppercase"
                                            />
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="isSkuSuffixInherited" class="text-amber-600 dark:text-amber-400">
                                                    Inheriting "{{ category.effective_sku_suffix }}"
                                                </span>
                                            </p>
                                            <p v-if="form.errors.sku_suffix" class="mt-1 text-sm text-red-600">{{ form.errors.sku_suffix }}</p>
                                        </div>
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

                                    <!-- Sequence Info -->
                                    <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                        <div>
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Current Sequence</p>
                                            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ category.current_sequence }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Next SKU will use {{ category.current_sequence + 1 }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inventory Settings -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Inventory Settings</h3>

                                <div>
                                    <label for="default_bucket_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Default Bucket
                                    </label>
                                    <select
                                        id="default_bucket_id"
                                        v-model="form.default_bucket_id"
                                        class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option :value="null">
                                            {{ isDefaultBucketInherited ? `Inherit from parent (${category.effective_default_bucket_name})` : '— No default bucket —' }}
                                        </option>
                                        <option v-for="bucket in buckets" :key="bucket.id" :value="bucket.id">
                                            {{ bucket.name }}
                                        </option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Items with this category will be automatically added to this bucket
                                    </p>
                                    <p v-if="form.errors.default_bucket_id" class="mt-1 text-sm text-red-600">{{ form.errors.default_bucket_id }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Barcode Attributes -->
                        <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <div class="px-4 py-5 sm:p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Barcode Attributes</h3>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                        @click="showAttributePicker = true"
                                    >
                                        <PlusIcon class="size-4" />
                                        Add
                                    </button>
                                </div>

                                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                                    Define the order of attributes to display on barcodes. Drag to reorder.
                                    <span v-if="isBarcodeAttributesInherited" class="text-amber-600 dark:text-amber-400">
                                        (Using inherited defaults)
                                    </span>
                                </p>

                                <!-- Selected Attributes (Sortable) -->
                                <div v-if="form.barcode_attributes.length > 0" class="space-y-2">
                                    <div
                                        v-for="(attr, index) in form.barcode_attributes"
                                        :key="attr"
                                        draggable="true"
                                        class="flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-700/50 cursor-move"
                                        @dragstart="onDragStart(index)"
                                        @dragover="onDragOver($event, index)"
                                        @dragend="onDragEnd"
                                    >
                                        <Bars3Icon class="size-5 text-gray-400" />
                                        <span class="flex-1 text-sm font-medium text-gray-900 dark:text-white">
                                            {{ getAttributeLabel(attr) }}
                                        </span>
                                        <span class="text-xs text-gray-500 font-mono">{{ attr }}</span>
                                        <button
                                            type="button"
                                            class="text-gray-400 hover:text-red-500"
                                            @click="removeAttribute(index)"
                                        >
                                            <XMarkIcon class="size-5" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Empty State -->
                                <div v-else class="rounded-lg border-2 border-dashed border-gray-300 p-6 text-center dark:border-gray-600">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        No attributes configured.
                                        <span v-if="isBarcodeAttributesInherited">
                                            Using defaults: {{ category.effective_barcode_attributes.map(a => getAttributeLabel(a)).join(', ') }}
                                        </span>
                                    </p>
                                </div>

                                <!-- Preview -->
                                <div v-if="form.barcode_attributes.length > 0" class="mt-4 rounded-lg bg-gray-100 p-3 dark:bg-gray-700">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Barcode Preview Sequence</p>
                                    <p class="font-mono text-sm text-gray-900 dark:text-white">
                                        {{ form.barcode_attributes.map(a => getAttributeLabel(a)).join(' | ') }}
                                    </p>
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

                        <!-- Submit -->
                        <div class="flex justify-end gap-3">
                            <button
                                type="button"
                                class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                                @click="router.visit('/product-types')"
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
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                                <InformationCircleIcon class="inline size-5 mr-1 text-gray-400" />
                                SKU Variables
                            </h3>

                            <div class="space-y-3 max-h-64 overflow-y-auto">
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
                </div>
            </div>
        </div>

        <!-- Attribute Picker Modal -->
        <TransitionRoot as="template" :show="showAttributePicker">
            <Dialog class="relative z-50" @close="showAttributePicker = false">
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
                            <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    Add Barcode Attribute
                                </DialogTitle>

                                <div class="space-y-6 max-h-96 overflow-y-auto">
                                    <!-- Built-in Attributes -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Built-in Fields</h4>
                                        <div class="space-y-2">
                                            <button
                                                v-for="attr in availableAttributes.built_in"
                                                :key="attr.key"
                                                type="button"
                                                class="w-full flex items-center justify-between rounded-lg border p-3 text-left transition-colors"
                                                :class="isAttributeSelected(attr.key)
                                                    ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                                                    : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/50'"
                                                @click="addAttribute(attr.key); showAttributePicker = false"
                                                :disabled="isAttributeSelected(attr.key)"
                                            >
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ attr.label }}</p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ attr.description }}</p>
                                                </div>
                                                <span v-if="isAttributeSelected(attr.key)" class="text-xs text-indigo-600 dark:text-indigo-400">Added</span>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Template Attributes -->
                                    <div v-if="availableAttributes.template.length > 0">
                                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Template Fields</h4>
                                        <div class="space-y-2">
                                            <button
                                                v-for="attr in availableAttributes.template"
                                                :key="attr.key"
                                                type="button"
                                                class="w-full flex items-center justify-between rounded-lg border p-3 text-left transition-colors"
                                                :class="isAttributeSelected(attr.key)
                                                    ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                                                    : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/50'"
                                                @click="addAttribute(attr.key); showAttributePicker = false"
                                                :disabled="isAttributeSelected(attr.key)"
                                            >
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ attr.label }}</p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ attr.description }}</p>
                                                </div>
                                                <span v-if="isAttributeSelected(attr.key)" class="text-xs text-indigo-600 dark:text-indigo-400">Added</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-6 flex justify-end">
                                    <button
                                        type="button"
                                        class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                        @click="showAttributePicker = false"
                                    >
                                        Close
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
