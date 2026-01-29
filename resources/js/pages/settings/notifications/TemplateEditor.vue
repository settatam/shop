<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    CodeBracketIcon,
    Squares2X2Icon,
    EyeIcon,
    ArrowLeftIcon,
    CheckIcon,
    ChevronDownIcon,
} from '@heroicons/vue/24/outline';
import { Listbox, ListboxButton, ListboxOptions, ListboxOption } from '@headlessui/vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import MonacoEditor from '@/components/notifications/MonacoEditor.vue';
import VisualEmailBuilder from '@/components/notifications/VisualEmailBuilder.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface NotificationTemplate {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    channel: string;
    subject: string | null;
    content: string;
    available_variables: string[];
    category: string | null;
    is_system: boolean;
    is_enabled: boolean;
}

interface DefaultTemplate {
    slug: string;
    name: string;
    channel: string;
    category: string;
    subject: string | null;
    content: string;
    available_variables: string[];
    is_system: boolean;
}

interface SampleData {
    store: Record<string, unknown>;
    order: Record<string, unknown>;
    customer: Record<string, unknown>;
    product: Record<string, unknown>;
    transaction: Record<string, unknown>;
    memo: Record<string, unknown>;
    repair: Record<string, unknown>;
    user: Record<string, unknown>;
    warehouse: Record<string, unknown>;
    role: Record<string, unknown>;
}

interface AvailableVariables {
    [group: string]: string[];
}

interface Props {
    template: NotificationTemplate | null;
    channelTypes: string[];
    categories: string[];
    defaultTemplates: DefaultTemplate[];
    sampleData: SampleData;
    availableVariables?: AvailableVariables;
}

const props = defineProps<Props>();

const isEditing = computed(() => !!props.template);

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Notifications',
        href: '/settings/notifications',
    },
    {
        title: 'Templates',
        href: '/settings/notifications/templates',
    },
    {
        title: isEditing.value ? 'Edit Template' : 'Create Template',
        href: '#',
    },
];

// Form state
const form = ref({
    name: props.template?.name || '',
    slug: props.template?.slug || '',
    description: props.template?.description || '',
    channel: props.template?.channel || 'email',
    subject: props.template?.subject || '',
    content: props.template?.content || '',
    category: props.template?.category || 'orders',
    is_enabled: props.template?.is_enabled ?? true,
});

const formErrors = ref<Record<string, string>>({});
const isSaving = ref(false);
const editorMode = ref<'code' | 'visual'>('code');
const showPreview = ref(true);
const previewContent = ref('');
const previewSubject = ref('');
const isLoadingPreview = ref(false);
const monacoEditorRef = ref<InstanceType<typeof MonacoEditor> | null>(null);

// Available variables grouped by context - use backend data if available
const variableGroups = computed(() => {
    if (props.availableVariables && Object.keys(props.availableVariables).length > 0) {
        return props.availableVariables;
    }
    // Fallback to basic variables if prop not provided
    return {
        store: ['store.name', 'store.email', 'store.phone', 'store.address'],
        order: ['order.number', 'order.total', 'order.tracking_number', 'order.items', 'order.status'],
        customer: ['customer.name', 'customer.email', 'customer.phone', 'customer.first_name', 'customer.last_name'],
        product: ['product.title', 'product.sku', 'product.price', 'product.description'],
        user: ['user.name', 'user.email'],
    };
});

// Auto-generate slug from name
watch(() => form.value.name, (name) => {
    if (!isEditing.value && name) {
        form.value.slug = name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }
});

// Fetch preview when content changes (debounced)
let previewTimeout: ReturnType<typeof setTimeout>;
watch([() => form.value.content, () => form.value.subject], () => {
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(fetchPreview, 500);
});

onMounted(() => {
    if (form.value.content) {
        fetchPreview();
    }
});

async function fetchPreview() {
    if (!form.value.content) {
        previewContent.value = '';
        previewSubject.value = '';
        return;
    }

    isLoadingPreview.value = true;

    try {
        // Use the API preview endpoint if editing, otherwise render locally
        if (isEditing.value && props.template) {
            const response = await fetch(`/api/v1/notification-templates/${props.template.id}/preview`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ data: props.sampleData }),
            });

            if (response.ok) {
                const data = await response.json();
                previewContent.value = data.content;
                previewSubject.value = data.subject;
            }
        } else {
            // Simple local preview (replace variables with sample data)
            let content = form.value.content;
            let subject = form.value.subject;

            // Replace common variables with sample data
            const replacements: Record<string, string> = {
                '{{ store.name }}': props.sampleData.store.name as string,
                '{{ store.email }}': props.sampleData.store.email as string,
                '{{ order.number }}': (props.sampleData.order as any).number,
                '{{ order.total }}': String((props.sampleData.order as any).total),
                '{{ customer.name }}': (props.sampleData.customer as any).name,
                '{{ customer.email }}': (props.sampleData.customer as any).email,
                '{{ product.title }}': (props.sampleData.product as any).title,
                '{{ product.sku }}': (props.sampleData.product as any).sku,
                '{{ product.price }}': String((props.sampleData.product as any).price),
            };

            for (const [key, value] of Object.entries(replacements)) {
                content = content.replace(new RegExp(key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), value);
                subject = subject.replace(new RegExp(key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), value);
            }

            previewContent.value = content;
            previewSubject.value = subject;
        }
    } catch (error) {
        console.error('Failed to fetch preview:', error);
    } finally {
        isLoadingPreview.value = false;
    }
}

function insertVariable(variable: string) {
    const text = `{{ ${variable} }}`;
    if (editorMode.value === 'code' && monacoEditorRef.value) {
        monacoEditorRef.value.insertAtCursor(text);
    } else {
        // For visual mode, append to content
        form.value.content += text;
    }
}

function loadDefaultTemplate(template: DefaultTemplate) {
    form.value.name = template.name;
    form.value.slug = template.slug;
    form.value.channel = template.channel;
    form.value.category = template.category;
    form.value.subject = template.subject || '';
    form.value.content = template.content;
}

function handleVisualBuilderUpdate(content: string) {
    form.value.content = content;
}

function saveTemplate() {
    if (isSaving.value) return;

    isSaving.value = true;
    formErrors.value = {};

    const url = isEditing.value
        ? `/api/v1/notification-templates/${props.template!.id}`
        : '/api/v1/notification-templates';

    const method = isEditing.value ? 'put' : 'post';

    router[method](url, form.value, {
        preserveScroll: true,
        onSuccess: () => {
            router.visit('/settings/notifications/templates');
        },
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSaving.value = false;
        },
    });
}

function goBack() {
    router.visit('/settings/notifications/templates');
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="isEditing ? 'Edit Template' : 'Create Template'" />

        <div class="flex h-[calc(100vh-4rem)] flex-col">
            <!-- Header -->
            <div class="flex shrink-0 items-center justify-between border-b border-gray-200 bg-white px-6 py-3 dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center gap-4">
                    <Button variant="ghost" size="sm" @click="goBack">
                        <ArrowLeftIcon class="h-4 w-4" />
                    </Button>
                    <div>
                        <h1 class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ isEditing ? 'Edit Template' : 'Create Template' }}
                        </h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Design your notification template with Twig syntax</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" @click="goBack">
                        Cancel
                    </Button>
                    <Button size="sm" :disabled="!form.name || !form.content || isSaving" @click="saveTemplate">
                        {{ isSaving ? 'Saving...' : 'Save Template' }}
                    </Button>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex min-h-0 flex-1">
                <!-- Left Sidebar: Properties -->
                <div class="w-72 shrink-0 overflow-y-auto border-r border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-gray-900/50">
                    <div class="space-y-4">
                        <!-- Load from Default -->
                        <div v-if="!isEditing && defaultTemplates.length > 0">
                            <Label class="text-xs">Start from template</Label>
                            <Listbox as="div" class="mt-1">
                                <div class="relative">
                                    <ListboxButton class="relative w-full cursor-pointer rounded-md border border-gray-300 bg-white py-2 pl-3 pr-10 text-left text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                        <span class="block truncate text-gray-500">Select a template...</span>
                                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                            <ChevronDownIcon class="h-5 w-5 text-gray-400" />
                                        </span>
                                    </ListboxButton>
                                    <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-sm shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none dark:bg-gray-800">
                                        <ListboxOption
                                            v-for="t in defaultTemplates"
                                            :key="t.slug"
                                            :value="t"
                                            v-slot="{ active }"
                                            @click="loadDefaultTemplate(t)"
                                        >
                                            <div :class="[active ? 'bg-indigo-600 text-white' : 'text-gray-900 dark:text-white', 'relative cursor-pointer select-none py-2 pl-3 pr-9']">
                                                <span class="block truncate">{{ t.name }}</span>
                                                <span class="block truncate text-xs" :class="active ? 'text-indigo-200' : 'text-gray-500'">
                                                    {{ t.channel }} - {{ t.category }}
                                                </span>
                                            </div>
                                        </ListboxOption>
                                    </ListboxOptions>
                                </div>
                            </Listbox>
                        </div>

                        <div>
                            <Label for="name" class="text-xs">Name *</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                type="text"
                                placeholder="Order Confirmation"
                                class="mt-1"
                            />
                            <p v-if="formErrors.name" class="mt-1 text-xs text-red-600">{{ formErrors.name }}</p>
                        </div>

                        <div>
                            <Label for="slug" class="text-xs">Slug</Label>
                            <Input
                                id="slug"
                                v-model="form.slug"
                                type="text"
                                placeholder="order-confirmation"
                                class="mt-1"
                                :disabled="isEditing"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <Label for="channel" class="text-xs">Channel *</Label>
                                <select
                                    id="channel"
                                    v-model="form.channel"
                                    class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/10 dark:bg-gray-900 dark:text-white"
                                >
                                    <option v-for="channel in channelTypes" :key="channel" :value="channel">
                                        {{ channel }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <Label for="category" class="text-xs">Category</Label>
                                <select
                                    id="category"
                                    v-model="form.category"
                                    class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/10 dark:bg-gray-900 dark:text-white"
                                >
                                    <option v-for="cat in categories" :key="cat" :value="cat">
                                        {{ cat }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div v-if="form.channel === 'email'">
                            <Label for="subject" class="text-xs">Subject</Label>
                            <Input
                                id="subject"
                                v-model="form.subject"
                                type="text"
                                placeholder="Order #{{ order.number }} Confirmed"
                                class="mt-1"
                            />
                        </div>

                        <div>
                            <Label for="description" class="text-xs">Description</Label>
                            <textarea
                                id="description"
                                v-model="form.description"
                                rows="2"
                                class="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/10 dark:bg-gray-900 dark:text-white"
                                placeholder="Optional description"
                            ></textarea>
                        </div>

                        <div class="flex items-center gap-2">
                            <input
                                id="is_enabled"
                                v-model="form.is_enabled"
                                type="checkbox"
                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                            />
                            <Label for="is_enabled" class="mb-0 text-xs">Enabled</Label>
                        </div>

                        <!-- Variables Panel -->
                        <div class="border-t border-gray-200 pt-4 dark:border-white/10">
                            <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Variables</h3>
                            <div class="space-y-2 max-h-48 overflow-y-auto">
                                <div v-for="(variables, group) in variableGroups" :key="group">
                                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ group }}</p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <button
                                            v-for="variable in variables"
                                            :key="variable"
                                            type="button"
                                            class="inline-flex items-center rounded bg-white px-1.5 py-0.5 text-xs text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700"
                                            @click="insertVariable(variable)"
                                        >
                                            {{ variable }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Editor Area -->
                <div class="flex min-w-0 flex-1 flex-col">
                    <!-- Editor Toolbar -->
                    <div class="flex shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 py-2 dark:border-white/10 dark:bg-gray-900">
                        <div class="flex items-center rounded-lg border border-gray-200 p-0.5 dark:border-white/10">
                            <button
                                type="button"
                                :class="[
                                    'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm font-medium transition-colors',
                                    editorMode === 'code'
                                        ? 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white'
                                        : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
                                ]"
                                @click="editorMode = 'code'"
                            >
                                <CodeBracketIcon class="h-4 w-4" />
                                Code
                            </button>
                            <button
                                v-if="form.channel === 'email'"
                                type="button"
                                :class="[
                                    'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm font-medium transition-colors',
                                    editorMode === 'visual'
                                        ? 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white'
                                        : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
                                ]"
                                @click="editorMode = 'visual'"
                            >
                                <Squares2X2Icon class="h-4 w-4" />
                                Visual
                            </button>
                        </div>
                        <button
                            type="button"
                            :class="[
                                'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm font-medium transition-colors',
                                showPreview
                                    ? 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white'
                                    : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
                            ]"
                            @click="showPreview = !showPreview"
                        >
                            <EyeIcon class="h-4 w-4" />
                            Preview
                        </button>
                    </div>

                    <!-- Editor + Preview -->
                    <div class="flex min-h-0 flex-1">
                        <!-- Code/Visual Editor -->
                        <div :class="['flex min-h-0 flex-col', showPreview ? 'w-1/2' : 'w-full']">
                            <div class="border-b border-gray-200 bg-gray-50 px-4 py-1.5 dark:border-white/10 dark:bg-white/5">
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">
                                    {{ editorMode === 'code' ? 'Content (Twig/HTML)' : 'Visual Builder' }}
                                </span>
                            </div>
                            <div class="min-h-0 flex-1">
                                <MonacoEditor
                                    v-show="editorMode === 'code'"
                                    ref="monacoEditorRef"
                                    v-model="form.content"
                                    :language="form.channel === 'email' ? 'twig' : 'text'"
                                    height="100%"
                                    class="h-full"
                                />
                                <VisualEmailBuilder
                                    v-if="editorMode === 'visual' && form.channel === 'email'"
                                    :content="form.content"
                                    class="h-full overflow-auto"
                                    @update:content="handleVisualBuilderUpdate"
                                />
                            </div>
                        </div>

                        <!-- Preview Panel -->
                        <div v-if="showPreview" class="flex w-1/2 min-h-0 flex-col border-l border-gray-200 dark:border-white/10">
                            <div class="border-b border-gray-200 bg-gray-50 px-4 py-1.5 dark:border-white/10 dark:bg-white/5">
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Preview</span>
                            </div>
                            <div class="min-h-0 flex-1 overflow-auto bg-white p-4 dark:bg-gray-900">
                                <div v-if="isLoadingPreview" class="flex items-center justify-center py-8">
                                    <div class="h-6 w-6 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent"></div>
                                </div>
                                <div v-else-if="form.channel === 'email'" class="h-full flex flex-col">
                                    <div v-if="previewSubject" class="mb-3 shrink-0 rounded-md bg-gray-100 p-2 dark:bg-gray-800">
                                        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Subject</p>
                                        <p class="text-sm text-gray-900 dark:text-white">{{ previewSubject }}</p>
                                    </div>
                                    <div class="min-h-0 flex-1 overflow-hidden rounded-md border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                                        <iframe
                                            :srcdoc="previewContent"
                                            class="h-full w-full"
                                            sandbox="allow-same-origin"
                                        ></iframe>
                                    </div>
                                </div>
                                <div v-else class="rounded-md bg-gray-100 p-4 dark:bg-gray-800">
                                    <p class="whitespace-pre-wrap text-sm text-gray-900 dark:text-white">{{ previewContent }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
