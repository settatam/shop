<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import {
    DocumentTextIcon,
    EllipsisVerticalIcon,
    PencilIcon,
    DocumentDuplicateIcon,
    TrashIcon,
    PlusIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface NotificationTemplate {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    channel: string;
    category: string | null;
    is_system: boolean;
    is_enabled: boolean;
    subscriptions_count: number;
    created_at: string;
}

interface Props {
    templates: NotificationTemplate[];
    channelTypes: string[];
    categories: string[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Notifications',
        href: '/settings/notifications',
    },
    {
        title: 'Templates',
        href: '/settings/notifications/templates',
    },
];

const searchQuery = ref('');
const filterChannel = ref('');
const filterCategory = ref('');
const showDeleteModal = ref(false);
const selectedTemplate = ref<NotificationTemplate | null>(null);
const isDeleting = ref(false);

const filteredTemplates = computed(() => {
    let result = props.templates;

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        result = result.filter(t =>
            t.name.toLowerCase().includes(query) ||
            t.description?.toLowerCase().includes(query)
        );
    }

    if (filterChannel.value) {
        result = result.filter(t => t.channel === filterChannel.value);
    }

    if (filterCategory.value) {
        result = result.filter(t => t.category === filterCategory.value);
    }

    return result;
});

function getChannelBadgeClass(channel: string): string {
    switch (channel) {
        case 'email':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
        case 'sms':
            return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
        case 'push':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
        case 'slack':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
        case 'webhook':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
}

function toggleEnabled(template: NotificationTemplate) {
    router.put(`/settings/notifications/templates/${template.id}`, {
        is_enabled: !template.is_enabled,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            template.is_enabled = !template.is_enabled;
        },
    });
}

function duplicateTemplate(template: NotificationTemplate) {
    router.post(`/settings/notifications/templates/${template.id}/duplicate`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            router.reload();
        },
    });
}

function openDeleteModal(template: NotificationTemplate) {
    selectedTemplate.value = template;
    showDeleteModal.value = true;
}

function closeDeleteModal() {
    showDeleteModal.value = false;
    selectedTemplate.value = null;
}

function deleteTemplate() {
    if (!selectedTemplate.value || isDeleting.value) return;

    isDeleting.value = true;

    router.delete(`/settings/notifications/templates/${selectedTemplate.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeDeleteModal();
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

function createDefaults() {
    router.post('/settings/notifications/templates/create-defaults', {}, {
        preserveScroll: true,
        onSuccess: () => {
            router.reload();
        },
    });
}

import { computed } from 'vue';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Notification templates" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Templates"
                        description="Create and manage notification templates"
                    />
                    <div class="flex gap-2">
                        <Button v-if="templates.length === 0" variant="outline" size="sm" @click="createDefaults">
                            Create Defaults
                        </Button>
                        <Button as-child size="sm">
                            <Link href="/settings/notifications/templates/create">
                                <PlusIcon class="mr-2 h-4 w-4" />
                                Create Template
                            </Link>
                        </Button>
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <div class="border-b border-gray-200 dark:border-white/10">
                    <nav class="-mb-px flex space-x-8">
                        <Link
                            href="/settings/notifications"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Overview
                        </Link>
                        <Link
                            href="/settings/notifications/templates"
                            class="border-b-2 border-indigo-500 px-1 pb-4 text-sm font-medium text-indigo-600 dark:text-indigo-400"
                        >
                            Templates
                        </Link>
                        <Link
                            href="/settings/notifications/subscriptions"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Triggers
                        </Link>
                        <Link
                            href="/settings/notifications/channels"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Channels
                        </Link>
                        <Link
                            href="/settings/notifications/logs"
                            class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Logs
                        </Link>
                    </nav>
                </div>

                <!-- Filters -->
                <div class="flex flex-wrap items-center gap-4">
                    <div class="relative flex-1 min-w-[200px] max-w-xs">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <Input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search templates..."
                            class="pl-9"
                        />
                    </div>
                    <select
                        v-model="filterChannel"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white"
                    >
                        <option value="">All channels</option>
                        <option v-for="channel in channelTypes" :key="channel" :value="channel">
                            {{ channel }}
                        </option>
                    </select>
                    <select
                        v-model="filterCategory"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white"
                    >
                        <option value="">All categories</option>
                        <option v-for="category in categories" :key="category" :value="category">
                            {{ category }}
                        </option>
                    </select>
                </div>

                <!-- Templates List -->
                <div v-if="filteredTemplates.length > 0" class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">
                                    Name
                                </th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                    Channel
                                </th>
                                <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white md:table-cell">
                                    Category
                                </th>
                                <th scope="col" class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">
                                    Triggers
                                </th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                                    Status
                                </th>
                                <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                            <tr v-for="template in filteredTemplates" :key="template.id">
                                <td class="whitespace-nowrap py-4 pl-4 pr-3 sm:pl-6">
                                    <div class="flex items-center gap-2">
                                        <DocumentTextIcon class="h-5 w-5 text-gray-400" />
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-white">{{ template.name }}</div>
                                            <div v-if="template.description" class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ template.description }}
                                            </div>
                                        </div>
                                        <span v-if="template.is_system" class="ml-2 inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                            System
                                        </span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <span :class="['inline-flex items-center rounded-full px-2 py-1 text-xs font-medium', getChannelBadgeClass(template.channel)]">
                                        {{ template.channel }}
                                    </span>
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 md:table-cell">
                                    {{ template.category || '-' }}
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 sm:table-cell">
                                    {{ template.subscriptions_count }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <button
                                        @click="toggleEnabled(template)"
                                        :class="[
                                            'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2',
                                            template.is_enabled ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700',
                                        ]"
                                    >
                                        <span
                                            :class="[
                                                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                                template.is_enabled ? 'translate-x-5' : 'translate-x-0',
                                            ]"
                                        />
                                    </button>
                                </td>
                                <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                    <Menu as="div" class="relative inline-block text-left">
                                        <MenuButton class="-m-2.5 block p-2.5 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                                            <span class="sr-only">Open options</span>
                                            <EllipsisVerticalIcon class="h-5 w-5" />
                                        </MenuButton>
                                        <transition
                                            enter-active-class="transition ease-out duration-100"
                                            enter-from-class="transform opacity-0 scale-95"
                                            enter-to-class="transform opacity-100 scale-100"
                                            leave-active-class="transition ease-in duration-75"
                                            leave-from-class="transform opacity-100 scale-100"
                                            leave-to-class="transform opacity-0 scale-95"
                                        >
                                            <MenuItems class="absolute right-0 z-10 mt-2 w-48 origin-top-right rounded-md bg-white py-2 shadow-lg ring-1 ring-gray-900/5 focus:outline-none dark:bg-gray-800 dark:ring-white/10">
                                                <MenuItem v-slot="{ active }">
                                                    <Link
                                                        :href="`/settings/notifications/templates/${template.id}/edit`"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <PencilIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Edit
                                                    </Link>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button
                                                        @click="duplicateTemplate(template)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                        ]"
                                                    >
                                                        <DocumentDuplicateIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Duplicate
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-if="!template.is_system" v-slot="{ active }">
                                                    <button
                                                        @click="openDeleteModal(template)"
                                                        :class="[
                                                            active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                            'flex w-full items-center px-3 py-2 text-sm text-red-600 dark:text-red-400',
                                                        ]"
                                                    >
                                                        <TrashIcon class="mr-3 h-5 w-5 text-red-400" />
                                                        Delete
                                                    </button>
                                                </MenuItem>
                                            </MenuItems>
                                        </transition>
                                    </Menu>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="rounded-lg border border-gray-200 bg-gray-50 py-12 text-center dark:border-white/10 dark:bg-white/5">
                    <DocumentTextIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No templates found</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Get started by creating a new notification template.
                    </p>
                    <div class="mt-6 flex justify-center gap-2">
                        <Button variant="outline" size="sm" @click="createDefaults">
                            Create Defaults
                        </Button>
                        <Button as-child size="sm">
                            <Link href="/settings/notifications/templates/create">
                                Create Template
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>

        <!-- Delete Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedTemplate" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeDeleteModal"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete template
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete <span class="font-medium">{{ selectedTemplate.name }}</span>? This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    @click="deleteTemplate"
                                    :disabled="isDeleting"
                                    class="sm:col-start-2"
                                >
                                    {{ isDeleting ? 'Deleting...' : 'Delete' }}
                                </Button>
                                <Button variant="outline" @click="closeDeleteModal" class="mt-3 sm:col-start-1 sm:mt-0">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
