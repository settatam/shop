<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftIcon,
    PencilIcon,
    DocumentDuplicateIcon,
    TrashIcon,
    CheckCircleIcon,
    XCircleIcon,
} from '@heroicons/vue/20/solid';
import { SparklesIcon } from '@heroicons/vue/24/outline';

interface FieldOption {
    id: number;
    label: string;
    value: string;
}

interface PlatformMapping {
    id: number;
    platform_id: number;
    platform_name: string;
    platform_slug: string;
    platform_field_name: string;
    is_required: boolean;
    is_recommended: boolean;
}

interface Field {
    id: number;
    name: string;
    canonical_name: string | null;
    label: string;
    type: string;
    placeholder: string | null;
    help_text: string | null;
    default_value: string | null;
    is_required: boolean;
    is_searchable: boolean;
    is_filterable: boolean;
    show_in_listing: boolean;
    group_name: string | null;
    group_position: number;
    width_class: string;
    sort_order: number;
    ai_generated: boolean;
    options: FieldOption[];
    platform_mappings: PlatformMapping[];
}

interface Category {
    id: number;
    name: string;
    full_path: string;
}

interface Template {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    ai_generated: boolean;
    generation_prompt: string | null;
    created_at: string;
    updated_at: string;
    fields: Field[];
    categories: Category[];
}

interface Props {
    template: Template;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Templates', href: '/templates' },
    { title: props.template.name, href: `/templates/${props.template.id}` },
];

function deleteTemplate() {
    if (confirm(`Are you sure you want to delete "${props.template.name}"? This will remove the template from all assigned categories.`)) {
        router.delete(`/templates/${props.template.id}`);
    }
}

function duplicateTemplate() {
    router.post(`/templates/${props.template.id}/duplicate`);
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const typeLabels: Record<string, string> = {
    text: 'Text',
    textarea: 'Textarea',
    number: 'Number',
    select: 'Select Dropdown',
    checkbox: 'Checkbox',
    radio: 'Radio Buttons',
    date: 'Date',
};

const widthLabels: Record<string, string> = {
    full: 'Full',
    half: '1/2',
    third: '1/3',
    quarter: '1/4',
};

function getPlatformColor(platform: string): string {
    const colors: Record<string, string> = {
        ebay: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        amazon: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        etsy: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
        shopify: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        google_shopping: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        woocommerce: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
        facebook: 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400',
        poshmark: 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-400',
    };
    return colors[platform] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
}
</script>

<template>
    <Head :title="template.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 lg:p-8">
            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-4">
                    <Link
                        href="/templates"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ template.name }}</h1>
                            <span
                                v-if="template.ai_generated"
                                class="inline-flex items-center gap-x-1.5 rounded-full bg-purple-50 px-2 py-1 text-xs font-medium text-purple-700 ring-1 ring-inset ring-purple-600/20 dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/20"
                            >
                                <SparklesIcon class="size-3.5" />
                                AI Generated
                            </span>
                            <span
                                v-if="template.is_active"
                                class="inline-flex items-center gap-x-1.5 rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20"
                            >
                                <CheckCircleIcon class="size-3.5" />
                                Active
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-x-1.5 rounded-full bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20"
                            >
                                <XCircleIcon class="size-3.5" />
                                Inactive
                            </span>
                        </div>
                        <p v-if="template.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ template.description }}
                        </p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Link
                        :href="`/templates/${template.id}/edit`"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        <PencilIcon class="-ml-0.5 size-4" />
                        Edit
                    </Link>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-700"
                        @click="duplicateTemplate"
                    >
                        <DocumentDuplicateIcon class="-ml-0.5 size-4" />
                        Duplicate
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-red-50 dark:bg-gray-800 dark:ring-gray-600 dark:hover:bg-red-900/20"
                        @click="deleteTemplate"
                    >
                        <TrashIcon class="-ml-0.5 size-4" />
                        Delete
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Fields -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                                Fields ({{ template.fields.length }})
                            </h3>

                            <div v-if="template.fields.length === 0" class="text-center py-8">
                                <p class="text-sm text-gray-500 dark:text-gray-400">No fields defined for this template.</p>
                                <Link
                                    :href="`/templates/${template.id}/edit`"
                                    class="mt-2 inline-block text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    Add fields
                                </Link>
                            </div>

                            <div v-else class="divide-y divide-gray-200 dark:divide-gray-700">
                                <div
                                    v-for="field in template.fields"
                                    :key="field.id"
                                    class="py-4 first:pt-0 last:pb-0"
                                >
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-gray-900 dark:text-white">{{ field.label }}</span>
                                                <span v-if="field.is_required" class="text-xs text-red-500">Required</span>
                                            </div>
                                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                                                <span class="font-mono text-xs bg-gray-100 px-1.5 py-0.5 rounded dark:bg-gray-700">{{ field.name }}</span>
                                                <span>{{ typeLabels[field.type] || field.type }}</span>
                                                <span>{{ widthLabels[field.width_class] || field.width_class }}</span>
                                                <span v-if="field.group_name" class="text-indigo-600 dark:text-indigo-400">
                                                    Group: {{ field.group_name }} (#{{ field.group_position }})
                                                </span>
                                            </div>
                                            <p v-if="field.help_text" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                                {{ field.help_text }}
                                            </p>
                                        </div>
                                        <div class="flex gap-2 ml-4">
                                            <span
                                                v-if="field.is_searchable"
                                                class="inline-flex items-center rounded bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
                                            >
                                                Searchable
                                            </span>
                                            <span
                                                v-if="field.is_filterable"
                                                class="inline-flex items-center rounded bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/30 dark:text-purple-400"
                                            >
                                                Filterable
                                            </span>
                                            <span
                                                v-if="field.show_in_listing"
                                                class="inline-flex items-center rounded bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700 dark:bg-orange-900/30 dark:text-orange-400"
                                            >
                                                In Listing
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Options for select/checkbox/radio -->
                                    <div v-if="field.options.length > 0" class="mt-2 ml-4">
                                        <span class="text-xs text-gray-400 dark:text-gray-500">Options:</span>
                                        <div class="mt-1 flex flex-wrap gap-1.5">
                                            <span
                                                v-for="option in field.options"
                                                :key="option.id"
                                                class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-300"
                                            >
                                                {{ option.label }}
                                                <span class="ml-1 text-gray-400">({{ option.value }})</span>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Platform Mappings -->
                                    <div v-if="field.platform_mappings && field.platform_mappings.length > 0" class="mt-3 ml-4">
                                        <span class="text-xs text-gray-400 dark:text-gray-500">Platform Mappings:</span>
                                        <div class="mt-1 flex flex-wrap gap-1.5">
                                            <span
                                                v-for="mapping in field.platform_mappings"
                                                :key="mapping.id"
                                                class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium"
                                                :class="getPlatformColor(mapping.platform_slug)"
                                            >
                                                {{ mapping.platform_name }}
                                                <span class="opacity-75">→ {{ mapping.platform_field_name }}</span>
                                                <span v-if="mapping.is_required" class="text-[10px] opacity-60">(req)</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Details -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Details</h3>
                            <dl class="space-y-3 text-sm">
                                <div v-if="template.ai_generated">
                                    <dt class="text-gray-500 dark:text-gray-400">Generated From</dt>
                                    <dd class="mt-1 text-gray-900 dark:text-white italic">"{{ template.generation_prompt }}"</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Created</dt>
                                    <dd class="mt-1 text-gray-900 dark:text-white">{{ formatDate(template.created_at) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Last Updated</dt>
                                    <dd class="mt-1 text-gray-900 dark:text-white">{{ formatDate(template.updated_at) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Fields</dt>
                                    <dd class="mt-1 text-gray-900 dark:text-white">{{ template.fields.length }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Assigned Categories -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                                Assigned Categories ({{ template.categories.length }})
                            </h3>

                            <div v-if="template.categories.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                                <p>This template is not assigned to any categories.</p>
                                <Link
                                    :href="`/templates/${template.id}/edit`"
                                    class="mt-2 inline-block text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    Assign categories
                                </Link>
                            </div>

                            <ul v-else class="space-y-2">
                                <li
                                    v-for="category in template.categories"
                                    :key="category.id"
                                    class="text-sm"
                                >
                                    <span class="text-gray-900 dark:text-white">{{ category.name }}</span>
                                    <span v-if="category.full_path !== category.name" class="text-gray-400 dark:text-gray-500 text-xs block">
                                        {{ category.full_path }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
