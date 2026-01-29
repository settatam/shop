<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import {
    EllipsisVerticalIcon,
    PlusIcon,
    PencilIcon,
    TrashIcon,
    DocumentDuplicateIcon,
    TagIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid';
import AppLayout from '@/layouts/AppLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';

interface LabelTemplate {
    id: number;
    name: string;
    type: string;
    type_label: string;
    canvas_width: number;
    canvas_height: number;
    is_default: boolean;
    elements_count: number;
    updated_at: string;
}

interface Props {
    templates: LabelTemplate[];
    types: Record<string, string>;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Labels', href: '/labels' },
];

const showDeleteModal = ref(false);
const selectedTemplate = ref<LabelTemplate | null>(null);
const isDeleting = ref(false);

function openDeleteModal(template: LabelTemplate) {
    selectedTemplate.value = template;
    showDeleteModal.value = true;
}

function closeModal() {
    showDeleteModal.value = false;
    selectedTemplate.value = null;
}

function deleteTemplate() {
    if (!selectedTemplate.value || isDeleting.value) return;

    isDeleting.value = true;

    router.delete(`/labels/${selectedTemplate.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

function duplicateTemplate(template: LabelTemplate) {
    router.post(`/labels/${template.id}/duplicate`, {}, {
        preserveScroll: true,
    });
}

function getLabelSizeDisplay(template: LabelTemplate): string {
    const widthInches = Math.round(template.canvas_width / 203 * 100) / 100;
    const heightInches = Math.round(template.canvas_height / 203 * 100) / 100;
    return `${widthInches}" x ${heightInches}"`;
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Label templates" />

        <div class="flex flex-col space-y-6">
            <div class="flex items-center justify-between">
                <HeadingSmall
                    title="Label templates"
                    description="Create and manage custom label templates for printing"
                />
                <Button as="a" href="/labels/create" size="sm">
                    <PlusIcon class="mr-2 h-4 w-4" />
                    Create template
                </Button>
            </div>

            <!-- Templates grid -->
            <div v-if="templates.length > 0" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div
                    v-for="template in templates"
                    :key="template.id"
                    class="group relative rounded-lg border border-gray-200 bg-white p-4 transition-shadow hover:shadow-md dark:border-white/10 dark:bg-gray-900"
                >
                    <!-- Default badge -->
                    <div v-if="template.is_default" class="absolute -top-2 -right-2">
                        <span class="inline-flex items-center gap-1 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-500/10 dark:text-yellow-400">
                            <StarIconSolid class="h-3 w-3" />
                            Default
                        </span>
                    </div>

                    <!-- Template preview placeholder -->
                    <div class="mb-4 flex aspect-[2/1] items-center justify-center rounded border border-gray-100 bg-gray-50 dark:border-white/5 dark:bg-gray-800">
                        <TagIcon class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                    </div>

                    <!-- Template info -->
                    <div class="space-y-1">
                        <h3 class="font-medium text-gray-900 dark:text-white">{{ template.name }}</h3>
                        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-white/10">{{ template.type_label }}</span>
                            <span>{{ getLabelSizeDisplay(template) }}</span>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            {{ template.elements_count }} element{{ template.elements_count === 1 ? '' : 's' }} · Updated {{ template.updated_at }}
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="mt-4 flex items-center justify-between">
                        <Link
                            :href="`/labels/${template.id}/edit`"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                        >
                            Edit template
                        </Link>

                        <Menu as="div" class="relative">
                            <MenuButton class="-m-2 p-2 text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
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
                                            :href="`/labels/${template.id}/edit`"
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
                                    <MenuItem v-slot="{ active }">
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
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="flex flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 py-12 dark:border-white/10">
                <TagIcon class="h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No label templates</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Get started by creating a new label template.</p>
                <Button as="a" href="/labels/create" size="sm" class="mt-4">
                    <PlusIcon class="mr-2 h-4 w-4" />
                    Create template
                </Button>
            </div>
        </div>

        <!-- Delete Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedTemplate" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModal"></div>

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
                                <Button variant="outline" @click="closeModal" class="mt-3 sm:col-start-1 sm:mt-0">
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
