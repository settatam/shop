<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    PlusIcon,
    TrashIcon,
    ChevronUpIcon,
    ChevronDownIcon,
    ArrowLeftIcon,
} from '@heroicons/vue/20/solid';

interface FieldOption {
    label: string;
    value: string;
}

interface Field {
    name: string;
    label: string;
    type: string;
    placeholder: string;
    help_text: string;
    default_value: string;
    is_required: boolean;
    is_searchable: boolean;
    is_filterable: boolean;
    show_in_listing: boolean;
    group_name: string;
    group_position: number;
    width_class: string;
    options: FieldOption[];
}

interface Props {
    fieldTypes: string[];
    typesWithOptions: string[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Templates', href: '/templates' },
    { title: 'Create', href: '/templates/create' },
];

const form = useForm({
    name: '',
    description: '',
    is_active: true,
    fields: [] as Field[],
});

function addField() {
    form.fields.push({
        name: '',
        label: '',
        type: 'text',
        placeholder: '',
        help_text: '',
        default_value: '',
        is_required: false,
        is_searchable: false,
        is_filterable: false,
        show_in_listing: false,
        group_name: '',
        group_position: 1,
        width_class: 'full',
        options: [],
    });
}

function removeField(index: number) {
    form.fields.splice(index, 1);
}

function moveFieldUp(index: number) {
    if (index > 0) {
        const temp = form.fields[index];
        form.fields[index] = form.fields[index - 1];
        form.fields[index - 1] = temp;
    }
}

function moveFieldDown(index: number) {
    if (index < form.fields.length - 1) {
        const temp = form.fields[index];
        form.fields[index] = form.fields[index + 1];
        form.fields[index + 1] = temp;
    }
}

function addOption(fieldIndex: number) {
    form.fields[fieldIndex].options.push({ label: '', value: '' });
}

function removeOption(fieldIndex: number, optionIndex: number) {
    form.fields[fieldIndex].options.splice(optionIndex, 1);
}

function generateFieldName(label: string): string {
    return label
        .toLowerCase()
        .replace(/[^a-z0-9\s]/g, '')
        .replace(/\s+/g, '_');
}

function onLabelChange(index: number) {
    const field = form.fields[index];
    if (!field.name) {
        field.name = generateFieldName(field.label);
    }
}

function requiresOptions(type: string): boolean {
    return props.typesWithOptions.includes(type);
}

function submit() {
    form.post('/templates');
}

const widthClasses = [
    { value: 'full', label: 'Full Width' },
    { value: 'half', label: 'Half (1/2)' },
    { value: 'third', label: 'Third (1/3)' },
    { value: 'quarter', label: 'Quarter (1/4)' },
];

const typeLabels: Record<string, string> = {
    text: 'Text',
    textarea: 'Textarea',
    number: 'Number',
    select: 'Select Dropdown',
    checkbox: 'Checkbox',
    radio: 'Radio Buttons',
    date: 'Date',
};
</script>

<template>
    <Head title="Create Template" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 lg:p-8">
            <form @submit.prevent="submit" class="space-y-8">
                <!-- Header -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Link
                            href="/templates"
                            class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                        >
                            <ArrowLeftIcon class="size-5" />
                        </Link>
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Create Template</h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Define a new product template with custom fields.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <Link
                            href="/templates"
                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Creating...' : 'Create Template' }}
                        </button>
                    </div>
                </div>

                <!-- Basic Info -->
                <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Template Information</h3>
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Template Name <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    required
                                    placeholder="e.g. Jewelry, Electronics, Handbags"
                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Description
                                </label>
                                <textarea
                                    id="description"
                                    v-model="form.description"
                                    rows="2"
                                    placeholder="Brief description of when to use this template"
                                    class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>

                            <div>
                                <label class="flex items-center gap-3">
                                    <input
                                        v-model="form.is_active"
                                        type="checkbox"
                                        class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                    />
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Active</span>
                                </label>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Inactive templates won't appear when editing products
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fields -->
                <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="px-4 py-5 sm:p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                Fields ({{ form.fields.length }})
                            </h3>
                            <button
                                type="button"
                                class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-50 px-2.5 py-1.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-900/50 dark:text-indigo-400 dark:hover:bg-indigo-900"
                                @click="addField"
                            >
                                <PlusIcon class="-ml-0.5 size-4" />
                                Add Field
                            </button>
                        </div>

                        <div v-if="form.fields.length === 0" class="text-center py-8 border-2 border-dashed border-gray-300 rounded-lg dark:border-gray-600">
                            <p class="text-sm text-gray-500 dark:text-gray-400">No fields yet. Click "Add Field" to get started.</p>
                        </div>

                        <div v-else class="space-y-4">
                            <div
                                v-for="(field, index) in form.fields"
                                :key="index"
                                class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                            >
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Field {{ index + 1 }}
                                        <span v-if="field.label" class="text-gray-500">- {{ field.label }}</span>
                                    </span>
                                    <div class="flex items-center gap-1">
                                        <button
                                            type="button"
                                            class="rounded p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:text-gray-300 dark:hover:bg-gray-700 disabled:opacity-50"
                                            :disabled="index === 0"
                                            @click="moveFieldUp(index)"
                                        >
                                            <ChevronUpIcon class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:text-gray-300 dark:hover:bg-gray-700 disabled:opacity-50"
                                            :disabled="index === form.fields.length - 1"
                                            @click="moveFieldDown(index)"
                                        >
                                            <ChevronDownIcon class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded p-1 text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:text-red-400 dark:hover:bg-red-900/20"
                                            @click="removeField(index)"
                                        >
                                            <TrashIcon class="size-4" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Basic Field Info -->
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4 mb-4">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                            Label <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            v-model="field.label"
                                            type="text"
                                            required
                                            placeholder="Display label"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            @blur="onLabelChange(index)"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                            Field Name <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            v-model="field.name"
                                            type="text"
                                            required
                                            placeholder="field_name"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm font-mono dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                            Type <span class="text-red-500">*</span>
                                        </label>
                                        <select
                                            v-model="field.type"
                                            required
                                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option v-for="type in fieldTypes" :key="type" :value="type">
                                                {{ typeLabels[type] || type }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Width</label>
                                        <select
                                            v-model="field.width_class"
                                            class="mt-1 block w-full rounded-md border-0 bg-white py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        >
                                            <option v-for="w in widthClasses" :key="w.value" :value="w.value">
                                                {{ w.label }}
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Additional Info -->
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-4">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Placeholder</label>
                                        <input
                                            v-model="field.placeholder"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Default Value</label>
                                        <input
                                            v-model="field.default_value"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Help Text</label>
                                        <input
                                            v-model="field.help_text"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <!-- Grouping -->
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-4">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                            Group Name
                                            <span class="font-normal">(for field sets like "weight + unit")</span>
                                        </label>
                                        <input
                                            v-model="field.group_name"
                                            type="text"
                                            placeholder="e.g. weight, dimensions"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                            Group Position
                                            <span class="font-normal">(1 = main field, 2+ = suffix)</span>
                                        </label>
                                        <input
                                            v-model.number="field.group_position"
                                            type="number"
                                            min="1"
                                            class="mt-1 block w-full rounded-md border-0 bg-white px-3 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                    </div>
                                </div>

                                <!-- Options -->
                                <div class="flex flex-wrap gap-4 mb-4">
                                    <label class="flex items-center gap-2">
                                        <input
                                            v-model="field.is_required"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Required</span>
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input
                                            v-model="field.is_searchable"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Searchable</span>
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input
                                            v-model="field.is_filterable"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Filterable</span>
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input
                                            v-model="field.show_in_listing"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Show in Listing</span>
                                    </label>
                                </div>

                                <!-- Options for select/checkbox/radio -->
                                <div v-if="requiresOptions(field.type)" class="border-t border-gray-200 pt-4 dark:border-gray-700">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Options</label>
                                        <button
                                            type="button"
                                            class="text-xs text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                            @click="addOption(index)"
                                        >
                                            + Add Option
                                        </button>
                                    </div>
                                    <div v-if="field.options.length === 0" class="text-xs text-gray-500 dark:text-gray-400">
                                        No options defined. Add at least one option for this field type.
                                    </div>
                                    <div v-else class="space-y-2">
                                        <div
                                            v-for="(option, optIndex) in field.options"
                                            :key="optIndex"
                                            class="flex items-center gap-2"
                                        >
                                            <input
                                                v-model="option.label"
                                                type="text"
                                                placeholder="Label"
                                                class="flex-1 rounded-md border-0 bg-white px-3 py-1 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <input
                                                v-model="option.value"
                                                type="text"
                                                placeholder="Value"
                                                class="flex-1 rounded-md border-0 bg-white px-3 py-1 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm font-mono dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                            <button
                                                type="button"
                                                class="rounded p-1 text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
                                                @click="removeOption(index, optIndex)"
                                            >
                                                <TrashIcon class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
