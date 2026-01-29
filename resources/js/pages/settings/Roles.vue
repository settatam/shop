<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    DocumentDuplicateIcon,
    ShieldCheckIcon,
    ChevronDownIcon,
    ChevronRightIcon,
} from '@heroicons/vue/24/outline';
import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Permission {
    slug: string;
    name: string;
    description: string;
    category: string;
}

interface Role {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    permissions: string[];
    is_system: boolean;
    is_default: boolean;
    store_users_count: number;
}

interface Preset {
    name: string;
    slug: string;
    description: string;
    permissions: string[];
}

interface Props {
    roles: Role[];
    permissionsGrouped: Record<string, Permission[]>;
    categories: Record<string, string>;
    presets: Record<string, Preset>;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Roles & Permissions',
        href: '/settings/roles',
    },
];

// Modal states
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);

// Form state
const selectedRole = ref<Role | null>(null);
const formData = ref({
    name: '',
    slug: '',
    description: '',
    permissions: [] as string[],
    is_default: false,
});
const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

// Category expansion state for edit modal
const expandedCategories = ref<string[]>(Object.keys(props.categories));

function openCreateModal(presetKey?: string) {
    formErrors.value = {};
    if (presetKey && props.presets[presetKey]) {
        const preset = props.presets[presetKey];
        formData.value = {
            name: '',
            slug: '',
            description: preset.description,
            permissions: [...preset.permissions],
            is_default: false,
        };
    } else {
        formData.value = {
            name: '',
            slug: '',
            description: '',
            permissions: [],
            is_default: false,
        };
    }
    showCreateModal.value = true;
}

function openEditModal(role: Role) {
    selectedRole.value = role;
    formData.value = {
        name: role.name,
        slug: role.slug,
        description: role.description || '',
        permissions: [...role.permissions],
        is_default: role.is_default,
    };
    formErrors.value = {};
    showEditModal.value = true;
}

function openDeleteModal(role: Role) {
    selectedRole.value = role;
    showDeleteModal.value = true;
}

function closeModals() {
    showCreateModal.value = false;
    showEditModal.value = false;
    showDeleteModal.value = false;
    selectedRole.value = null;
    formErrors.value = {};
}

function generateSlug(name: string): string {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
}

function onNameChange(e: Event) {
    const name = (e.target as HTMLInputElement).value;
    formData.value.name = name;
    if (!selectedRole.value) {
        formData.value.slug = generateSlug(name);
    }
}

function togglePermission(slug: string) {
    const index = formData.value.permissions.indexOf(slug);
    if (index === -1) {
        formData.value.permissions.push(slug);
    } else {
        formData.value.permissions.splice(index, 1);
    }
}

function toggleCategory(category: string) {
    const categoryPermissions = props.permissionsGrouped[category]?.map(p => p.slug) || [];
    const allSelected = categoryPermissions.every(p => formData.value.permissions.includes(p));

    if (allSelected) {
        // Remove all permissions in this category
        formData.value.permissions = formData.value.permissions.filter(
            p => !categoryPermissions.includes(p)
        );
    } else {
        // Add all permissions in this category
        const newPermissions = new Set([...formData.value.permissions, ...categoryPermissions]);
        formData.value.permissions = Array.from(newPermissions);
    }
}

function isCategoryFullySelected(category: string): boolean {
    const categoryPermissions = props.permissionsGrouped[category]?.map(p => p.slug) || [];
    return categoryPermissions.every(p => formData.value.permissions.includes(p));
}

function isCategoryPartiallySelected(category: string): boolean {
    const categoryPermissions = props.permissionsGrouped[category]?.map(p => p.slug) || [];
    const selectedCount = categoryPermissions.filter(p => formData.value.permissions.includes(p)).length;
    return selectedCount > 0 && selectedCount < categoryPermissions.length;
}

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find(row => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] || ''
    );
}

async function createRole() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    try {
        const response = await fetch('/api/v1/roles', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'include',
            body: JSON.stringify(formData.value),
        });

        const data = await response.json();

        if (!response.ok) {
            if (response.status === 422) {
                formErrors.value = data.errors || { name: data.message };
            } else {
                formErrors.value = { name: data.message || 'Failed to create role' };
            }
            return;
        }

        closeModals();
        router.reload({ only: ['roles'] });
    } catch {
        formErrors.value = { name: 'An error occurred. Please try again.' };
    } finally {
        isSubmitting.value = false;
    }
}

async function updateRole() {
    if (!selectedRole.value || isSubmitting.value) return;

    isSubmitting.value = true;
    formErrors.value = {};

    try {
        const response = await fetch(`/api/v1/roles/${selectedRole.value.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'include',
            body: JSON.stringify({
                name: formData.value.name,
                description: formData.value.description,
                permissions: formData.value.permissions,
                is_default: formData.value.is_default,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            if (response.status === 422) {
                formErrors.value = data.errors || { name: data.message };
            } else {
                formErrors.value = { name: data.message || 'Failed to update role' };
            }
            return;
        }

        closeModals();
        router.reload({ only: ['roles'] });
    } catch {
        formErrors.value = { name: 'An error occurred. Please try again.' };
    } finally {
        isSubmitting.value = false;
    }
}

async function deleteRole() {
    if (!selectedRole.value || isSubmitting.value) return;

    isSubmitting.value = true;

    try {
        const response = await fetch(`/api/v1/roles/${selectedRole.value.id}`, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'include',
        });

        if (!response.ok) {
            const data = await response.json();
            alert(data.message || 'Failed to delete role');
            return;
        }

        closeModals();
        router.reload({ only: ['roles'] });
    } catch {
        alert('An error occurred. Please try again.');
    } finally {
        isSubmitting.value = false;
    }
}

async function duplicateRole(role: Role) {
    try {
        const response = await fetch(`/api/v1/roles/${role.id}/duplicate`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'include',
        });

        if (!response.ok) {
            const data = await response.json();
            alert(data.message || 'Failed to duplicate role');
            return;
        }

        router.reload({ only: ['roles'] });
    } catch {
        alert('An error occurred. Please try again.');
    }
}

const hasWildcard = computed(() => formData.value.permissions.includes('*'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Roles & Permissions" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Roles & Permissions"
                        description="Manage roles and what activities each role can perform"
                    />
                    <Button @click="openCreateModal()" size="sm">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Create role
                    </Button>
                </div>

                <!-- Roles list -->
                <div class="space-y-3">
                    <div
                        v-for="role in roles"
                        :key="role.id"
                        class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ role.name }}
                                    </h3>
                                    <span
                                        v-if="role.is_system"
                                        class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 ring-1 ring-indigo-700/10 ring-inset dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20"
                                    >
                                        System
                                    </span>
                                    <span
                                        v-if="role.is_default"
                                        class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-700/10 ring-inset dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20"
                                    >
                                        Default
                                    </span>
                                </div>
                                <p v-if="role.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ role.description }}
                                </p>
                                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                    {{ role.store_users_count }} {{ role.store_users_count === 1 ? 'member' : 'members' }}
                                    &bull;
                                    {{ role.permissions.includes('*') ? 'All permissions' : `${role.permissions.length} permissions` }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="openEditModal(role)"
                                    :title="role.is_system && role.slug === 'owner' ? 'Owner role cannot be modified' : 'Edit role'"
                                    :disabled="role.slug === 'owner'"
                                >
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="duplicateRole(role)"
                                    title="Duplicate role"
                                >
                                    <DocumentDuplicateIcon class="h-4 w-4" />
                                </Button>
                                <Button
                                    v-if="!role.is_system"
                                    variant="ghost"
                                    size="sm"
                                    @click="openDeleteModal(role)"
                                    :disabled="role.store_users_count > 0"
                                    :title="role.store_users_count > 0 ? 'Cannot delete role with members' : 'Delete role'"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="roles.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No roles configured yet.
                </p>
            </div>
        </SettingsLayout>

        <!-- Create Role Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-500/10">
                                        <ShieldCheckIcon class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <div>
                                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                            Create new role
                                        </h3>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Define permissions for this role
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-6 space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label for="create-name">Role name</Label>
                                            <Input
                                                id="create-name"
                                                :value="formData.name"
                                                @input="onNameChange"
                                                type="text"
                                                placeholder="e.g., Sales Associate"
                                                class="mt-1"
                                            />
                                            <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ formErrors.name }}
                                            </p>
                                        </div>
                                        <div>
                                            <Label for="create-slug">Slug</Label>
                                            <Input
                                                id="create-slug"
                                                v-model="formData.slug"
                                                type="text"
                                                placeholder="e.g., sales-associate"
                                                class="mt-1"
                                            />
                                            <p v-if="formErrors.slug" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ formErrors.slug }}
                                            </p>
                                        </div>
                                    </div>

                                    <div>
                                        <Label for="create-description">Description</Label>
                                        <Input
                                            id="create-description"
                                            v-model="formData.description"
                                            type="text"
                                            placeholder="What this role is for..."
                                            class="mt-1"
                                        />
                                    </div>

                                    <!-- Quick presets -->
                                    <div>
                                        <Label>Start from preset</Label>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                v-for="(preset, key) in presets"
                                                :key="key"
                                                v-show="key !== 'owner'"
                                                type="button"
                                                @click="formData.permissions = [...preset.permissions]; formData.description = preset.description"
                                                class="inline-flex items-center rounded-md bg-gray-100 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20"
                                            >
                                                {{ preset.name }}
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Permissions -->
                                    <div>
                                        <Label>Permissions</Label>
                                        <div class="mt-2 max-h-64 overflow-y-auto rounded-md border border-gray-200 dark:border-white/10">
                                            <Disclosure v-for="(categoryName, categoryKey) in categories" :key="categoryKey" v-slot="{ open }" :default-open="true">
                                                <DisclosureButton class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                                                    <div class="flex items-center gap-3">
                                                        <input
                                                            type="checkbox"
                                                            :checked="isCategoryFullySelected(categoryKey)"
                                                            :indeterminate="isCategoryPartiallySelected(categoryKey)"
                                                            @click.stop="toggleCategory(categoryKey)"
                                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                                        />
                                                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ categoryName }}</span>
                                                    </div>
                                                    <ChevronDownIcon v-if="open" class="h-4 w-4 text-gray-400" />
                                                    <ChevronRightIcon v-else class="h-4 w-4 text-gray-400" />
                                                </DisclosureButton>
                                                <DisclosurePanel class="border-t border-gray-100 bg-gray-50/50 px-4 py-2 dark:border-white/5 dark:bg-white/[0.02]">
                                                    <div class="space-y-2">
                                                        <label
                                                            v-for="permission in permissionsGrouped[categoryKey]"
                                                            :key="permission.slug"
                                                            class="flex items-start gap-3 py-1"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                :checked="formData.permissions.includes(permission.slug)"
                                                                @change="togglePermission(permission.slug)"
                                                                class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                                            />
                                                            <div>
                                                                <span class="text-sm text-gray-900 dark:text-white">{{ permission.name }}</span>
                                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ permission.description }}</p>
                                                            </div>
                                                        </label>
                                                    </div>
                                                </DisclosurePanel>
                                            </Disclosure>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="create-default"
                                            v-model="formData.is_default"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                        />
                                        <Label for="create-default" class="!mb-0">Set as default role for new team members</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button
                                    @click="createRole"
                                    :disabled="!formData.name || !formData.slug || isSubmitting"
                                    class="w-full sm:ml-3 sm:w-auto"
                                >
                                    {{ isSubmitting ? 'Creating...' : 'Create role' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 w-full sm:mt-0 sm:w-auto">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Edit Role Modal -->
        <Teleport to="body">
            <div v-if="showEditModal && selectedRole" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                            <div class="px-4 pb-4 pt-5 sm:p-6">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-500/10">
                                        <ShieldCheckIcon class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <div>
                                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                            Edit role: {{ selectedRole.name }}
                                        </h3>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Update permissions for this role
                                        </p>
                                    </div>
                                </div>

                                <div v-if="hasWildcard" class="mt-4 rounded-md bg-yellow-50 p-4 dark:bg-yellow-500/10">
                                    <p class="text-sm text-yellow-700 dark:text-yellow-400">
                                        This role has full access (all permissions). Remove the wildcard permission to customize.
                                    </p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="formData.permissions = formData.permissions.filter(p => p !== '*')"
                                        class="mt-2"
                                    >
                                        Remove wildcard
                                    </Button>
                                </div>

                                <div v-else class="mt-6 space-y-4">
                                    <div>
                                        <Label for="edit-name">Role name</Label>
                                        <Input
                                            id="edit-name"
                                            v-model="formData.name"
                                            type="text"
                                            :disabled="selectedRole.is_system"
                                            class="mt-1"
                                        />
                                        <p v-if="formErrors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ formErrors.name }}
                                        </p>
                                    </div>

                                    <div>
                                        <Label for="edit-description">Description</Label>
                                        <Input
                                            id="edit-description"
                                            v-model="formData.description"
                                            type="text"
                                            class="mt-1"
                                        />
                                    </div>

                                    <!-- Permissions -->
                                    <div>
                                        <Label>Permissions ({{ formData.permissions.length }} selected)</Label>
                                        <div class="mt-2 max-h-64 overflow-y-auto rounded-md border border-gray-200 dark:border-white/10">
                                            <Disclosure v-for="(categoryName, categoryKey) in categories" :key="categoryKey" v-slot="{ open }" :default-open="true">
                                                <DisclosureButton class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                                                    <div class="flex items-center gap-3">
                                                        <input
                                                            type="checkbox"
                                                            :checked="isCategoryFullySelected(categoryKey)"
                                                            :indeterminate="isCategoryPartiallySelected(categoryKey)"
                                                            @click.stop="toggleCategory(categoryKey)"
                                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                                        />
                                                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ categoryName }}</span>
                                                    </div>
                                                    <ChevronDownIcon v-if="open" class="h-4 w-4 text-gray-400" />
                                                    <ChevronRightIcon v-else class="h-4 w-4 text-gray-400" />
                                                </DisclosureButton>
                                                <DisclosurePanel class="border-t border-gray-100 bg-gray-50/50 px-4 py-2 dark:border-white/5 dark:bg-white/[0.02]">
                                                    <div class="space-y-2">
                                                        <label
                                                            v-for="permission in permissionsGrouped[categoryKey]"
                                                            :key="permission.slug"
                                                            class="flex items-start gap-3 py-1"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                :checked="formData.permissions.includes(permission.slug)"
                                                                @change="togglePermission(permission.slug)"
                                                                class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                                            />
                                                            <div>
                                                                <span class="text-sm text-gray-900 dark:text-white">{{ permission.name }}</span>
                                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ permission.description }}</p>
                                                            </div>
                                                        </label>
                                                    </div>
                                                </DisclosurePanel>
                                            </Disclosure>
                                        </div>
                                    </div>

                                    <div v-if="!selectedRole.is_system" class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="edit-default"
                                            v-model="formData.is_default"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                        />
                                        <Label for="edit-default" class="!mb-0">Set as default role for new team members</Label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 dark:bg-white/5 sm:flex sm:flex-row-reverse sm:px-6">
                                <Button
                                    @click="updateRole"
                                    :disabled="!formData.name || isSubmitting"
                                    class="w-full sm:ml-3 sm:w-auto"
                                >
                                    {{ isSubmitting ? 'Saving...' : 'Save changes' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 w-full sm:mt-0 sm:w-auto">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Role Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedRole" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                                    <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Delete role
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to delete the <span class="font-medium">{{ selectedRole.name }}</span> role? This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    @click="deleteRole"
                                    :disabled="isSubmitting"
                                    class="sm:col-start-2"
                                >
                                    {{ isSubmitting ? 'Deleting...' : 'Delete' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 sm:col-start-1 sm:mt-0">
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
