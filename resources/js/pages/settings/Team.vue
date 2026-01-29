<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import {
    EllipsisVerticalIcon,
    PlusIcon,
    UserPlusIcon,
    ShieldCheckIcon,
    TrashIcon,
    ArrowsRightLeftIcon,
    CheckCircleIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Role {
    id: number;
    name: string;
    slug: string;
    is_system?: boolean;
}

interface TeamMember {
    id: number;
    user_id: number | null;
    first_name: string;
    last_name: string;
    email: string;
    name: string;
    role: Role | null;
    is_owner: boolean;
    status: string;
    created_at: string;
}

interface Props {
    members: TeamMember[];
    roles: Role[];
    isOwner: boolean;
    currentUserId: number;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Team settings',
        href: '/settings/team',
    },
];

// Modals state
const showInviteModal = ref(false);
const showEditRoleModal = ref(false);
const showRemoveModal = ref(false);
const showTransferModal = ref(false);
const showAcceptModal = ref(false);

// Form state
const inviteForm = ref({
    email: '',
    first_name: '',
    last_name: '',
    role_id: props.roles.find(r => r.slug === 'staff')?.id || props.roles[0]?.id,
});
const inviteErrors = ref<Record<string, string>>({});
const isInviting = ref(false);

const selectedMember = ref<TeamMember | null>(null);
const selectedRoleId = ref<number | null>(null);
const isUpdating = ref(false);
const isRemoving = ref(false);
const isTransferring = ref(false);

// Accept invitation form state
const acceptForm = ref({
    password: '',
    password_confirmation: '',
});
const acceptErrors = ref<Record<string, string>>({});
const isAccepting = ref(false);

// Filter out owner role from selectable roles (can't assign owner role)
const assignableRoles = computed(() =>
    props.roles.filter(r => r.slug !== 'owner')
);

function openEditRoleModal(member: TeamMember) {
    selectedMember.value = member;
    selectedRoleId.value = member.role?.id || null;
    showEditRoleModal.value = true;
}

function openRemoveModal(member: TeamMember) {
    selectedMember.value = member;
    showRemoveModal.value = true;
}

function openTransferModal(member: TeamMember) {
    selectedMember.value = member;
    showTransferModal.value = true;
}

function openAcceptModal(member: TeamMember) {
    selectedMember.value = member;
    showAcceptModal.value = true;
}

function closeModals() {
    showInviteModal.value = false;
    showEditRoleModal.value = false;
    showRemoveModal.value = false;
    showTransferModal.value = false;
    showAcceptModal.value = false;
    selectedMember.value = null;
    selectedRoleId.value = null;
    inviteForm.value = {
        email: '',
        first_name: '',
        last_name: '',
        role_id: props.roles.find(r => r.slug === 'staff')?.id || props.roles[0]?.id,
    };
    inviteErrors.value = {};
    acceptForm.value = {
        password: '',
        password_confirmation: '',
    };
    acceptErrors.value = {};
}

function inviteMember() {
    if (isInviting.value) return;

    isInviting.value = true;
    inviteErrors.value = {};

    fetch('/api/v1/team', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find(row => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] || ''
            ),
        },
        credentials: 'include',
        body: JSON.stringify(inviteForm.value),
    })
        .then(async (response) => {
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 422) {
                    inviteErrors.value = data.errors || { email: data.message };
                } else {
                    inviteErrors.value = { email: data.message || 'Failed to invite member' };
                }
                return;
            }
            closeModals();
            router.reload({ only: ['members'] });
        })
        .catch(() => {
            inviteErrors.value = { email: 'An error occurred. Please try again.' };
        })
        .finally(() => {
            isInviting.value = false;
        });
}

function updateRole() {
    if (!selectedMember.value || !selectedRoleId.value || isUpdating.value) return;

    isUpdating.value = true;

    fetch(`/api/v1/team/${selectedMember.value.id}`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find(row => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] || ''
            ),
        },
        credentials: 'include',
        body: JSON.stringify({ role_id: selectedRoleId.value }),
    })
        .then(async (response) => {
            if (!response.ok) {
                const data = await response.json();
                alert(data.message || 'Failed to update role');
                return;
            }
            closeModals();
            router.reload({ only: ['members'] });
        })
        .catch(() => {
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            isUpdating.value = false;
        });
}

function removeMember() {
    if (!selectedMember.value || isRemoving.value) return;

    isRemoving.value = true;

    fetch(`/api/v1/team/${selectedMember.value.id}`, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find(row => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] || ''
            ),
        },
        credentials: 'include',
    })
        .then(async (response) => {
            if (!response.ok) {
                const data = await response.json();
                alert(data.message || 'Failed to remove member');
                return;
            }
            closeModals();
            router.reload({ only: ['members'] });
        })
        .catch(() => {
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            isRemoving.value = false;
        });
}

function transferOwnership() {
    if (!selectedMember.value || isTransferring.value) return;

    isTransferring.value = true;

    fetch(`/api/v1/team/${selectedMember.value.id}/transfer-ownership`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find(row => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] || ''
            ),
        },
        credentials: 'include',
    })
        .then(async (response) => {
            if (!response.ok) {
                const data = await response.json();
                alert(data.message || 'Failed to transfer ownership');
                return;
            }
            closeModals();
            router.reload();
        })
        .catch(() => {
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            isTransferring.value = false;
        });
}

function acceptInvitation() {
    if (!selectedMember.value || isAccepting.value) return;

    isAccepting.value = true;
    acceptErrors.value = {};

    fetch(`/api/v1/team/${selectedMember.value.id}/accept-invitation`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find(row => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] || ''
            ),
        },
        credentials: 'include',
        body: JSON.stringify(acceptForm.value),
    })
        .then(async (response) => {
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 422) {
                    acceptErrors.value = data.errors || { password: data.message };
                } else {
                    acceptErrors.value = { password: data.message || 'Failed to accept invitation' };
                }
                return;
            }
            closeModals();
            router.reload({ only: ['members'] });
        })
        .catch(() => {
            acceptErrors.value = { password: 'An error occurred. Please try again.' };
        })
        .finally(() => {
            isAccepting.value = false;
        });
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function getStatusBadgeClass(status: string): string {
    switch (status) {
        case 'active':
            return 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20';
        case 'invite sent':
            return 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20';
        default:
            return 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20';
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Team settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Team members"
                        description="Manage who has access to this store"
                    />
                    <Button @click="showInviteModal = true" size="sm">
                        <UserPlusIcon class="mr-2 h-4 w-4" />
                        Invite member
                    </Button>
                </div>

                <!-- Team members list -->
                <ul role="list" class="divide-y divide-gray-100 dark:divide-white/10">
                    <li
                        v-for="member in members"
                        :key="member.id"
                        class="flex items-center justify-between gap-x-6 py-5"
                    >
                        <div class="flex min-w-0 gap-x-4">
                            <div class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">
                                {{ getInitials(member.name) }}
                            </div>
                            <div class="min-w-0 flex-auto">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ member.name }}
                                    <span
                                        v-if="member.is_owner"
                                        class="ml-2 inline-flex items-center rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 ring-1 ring-indigo-700/10 ring-inset dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20"
                                    >
                                        Owner
                                    </span>
                                </p>
                                <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ member.email }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-x-4">
                            <div class="hidden sm:flex sm:flex-col sm:items-end">
                                <p class="text-sm text-gray-900 dark:text-white">
                                    {{ member.role?.name || 'No role' }}
                                </p>
                                <span
                                    :class="[
                                        getStatusBadgeClass(member.status),
                                        'mt-1 inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                                    ]"
                                >
                                    {{ member.status }}
                                </span>
                            </div>

                            <!-- Actions menu -->
                            <Menu
                                v-if="!member.is_owner && member.user_id !== currentUserId"
                                as="div"
                                class="relative flex-none"
                            >
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
                                        <MenuItem v-if="member.status === 'invite sent' && !member.user_id" v-slot="{ active }">
                                            <button
                                                @click="openAcceptModal(member)"
                                                :class="[
                                                    active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                    'flex w-full items-center px-3 py-2 text-sm text-green-600 dark:text-green-400',
                                                ]"
                                            >
                                                <CheckCircleIcon class="mr-3 h-5 w-5 text-green-500" />
                                                Accept invitation
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button
                                                @click="openEditRoleModal(member)"
                                                :class="[
                                                    active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                    'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                ]"
                                            >
                                                <ShieldCheckIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                Change role
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-if="isOwner" v-slot="{ active }">
                                            <button
                                                @click="openTransferModal(member)"
                                                :class="[
                                                    active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                    'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white',
                                                ]"
                                            >
                                                <ArrowsRightLeftIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                Transfer ownership
                                            </button>
                                        </MenuItem>
                                        <MenuItem v-slot="{ active }">
                                            <button
                                                @click="openRemoveModal(member)"
                                                :class="[
                                                    active ? 'bg-gray-50 dark:bg-white/5' : '',
                                                    'flex w-full items-center px-3 py-2 text-sm text-red-600 dark:text-red-400',
                                                ]"
                                            >
                                                <TrashIcon class="mr-3 h-5 w-5 text-red-400" />
                                                Remove
                                            </button>
                                        </MenuItem>
                                    </MenuItems>
                                </transition>
                            </Menu>
                        </div>
                    </li>
                </ul>

                <p v-if="members.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No team members yet. Invite someone to get started.
                </p>
            </div>
        </SettingsLayout>

        <!-- Invite Modal -->
        <Teleport to="body">
            <div v-if="showInviteModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-500/10">
                                    <UserPlusIcon class="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Invite team member
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Send an invitation to join your store. They'll receive an email with instructions.
                                    </p>
                                </div>

                                <div class="mt-6 space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label for="first_name">First name</Label>
                                            <Input
                                                id="first_name"
                                                v-model="inviteForm.first_name"
                                                type="text"
                                                placeholder="John"
                                                class="mt-1"
                                            />
                                            <p v-if="inviteErrors.first_name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {{ inviteErrors.first_name }}
                                            </p>
                                        </div>
                                        <div>
                                            <Label for="last_name">Last name</Label>
                                            <Input
                                                id="last_name"
                                                v-model="inviteForm.last_name"
                                                type="text"
                                                placeholder="Doe"
                                                class="mt-1"
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <Label for="email">Email address</Label>
                                        <Input
                                            id="email"
                                            v-model="inviteForm.email"
                                            type="email"
                                            placeholder="john@example.com"
                                            class="mt-1"
                                        />
                                        <p v-if="inviteErrors.email" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ inviteErrors.email }}
                                        </p>
                                    </div>

                                    <div>
                                        <Label for="role">Role</Label>
                                        <select
                                            id="role"
                                            v-model="inviteForm.role_id"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                        >
                                            <option v-for="role in assignableRoles" :key="role.id" :value="role.id">
                                                {{ role.name }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    @click="inviteMember"
                                    :disabled="!inviteForm.email || !inviteForm.first_name || isInviting"
                                    class="sm:col-start-2"
                                >
                                    {{ isInviting ? 'Sending...' : 'Send invitation' }}
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

        <!-- Edit Role Modal -->
        <Teleport to="body">
            <div v-if="showEditRoleModal && selectedMember" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div>
                                <div class="text-center">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Change role
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Update the role for {{ selectedMember.name }}
                                    </p>
                                </div>
                                <div class="mt-4">
                                    <select
                                        v-model="selectedRoleId"
                                        class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                    >
                                        <option v-for="role in assignableRoles" :key="role.id" :value="role.id">
                                            {{ role.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    @click="updateRole"
                                    :disabled="!selectedRoleId || isUpdating"
                                    class="sm:col-start-2"
                                >
                                    {{ isUpdating ? 'Updating...' : 'Update role' }}
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

        <!-- Remove Member Modal -->
        <Teleport to="body">
            <div v-if="showRemoveModal && selectedMember" class="relative z-50">
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
                                        Remove team member
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to remove <span class="font-medium">{{ selectedMember.name }}</span> from the store? They will lose access immediately.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    variant="destructive"
                                    @click="removeMember"
                                    :disabled="isRemoving"
                                    class="sm:col-start-2"
                                >
                                    {{ isRemoving ? 'Removing...' : 'Remove' }}
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

        <!-- Transfer Ownership Modal -->
        <Teleport to="body">
            <div v-if="showTransferModal && selectedMember" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-500/10">
                                    <ArrowsRightLeftIcon class="h-6 w-6 text-yellow-600 dark:text-yellow-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Transfer ownership
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Are you sure you want to transfer ownership to <span class="font-medium">{{ selectedMember.name }}</span>? You will lose owner privileges.
                                    </p>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    @click="transferOwnership"
                                    :disabled="isTransferring"
                                    class="sm:col-start-2"
                                >
                                    {{ isTransferring ? 'Transferring...' : 'Transfer' }}
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

        <!-- Accept Invitation Modal -->
        <Teleport to="body">
            <div v-if="showAcceptModal && selectedMember" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="closeModals"></div>

                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-500/10">
                                    <CheckCircleIcon class="h-6 w-6 text-green-600 dark:text-green-400" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        Accept invitation
                                    </h3>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        Manually accept the invitation for <span class="font-medium">{{ selectedMember.name }}</span> and set their password.
                                    </p>
                                </div>

                                <div class="mt-6 space-y-4">
                                    <div>
                                        <Label for="accept_password">Password</Label>
                                        <Input
                                            id="accept_password"
                                            v-model="acceptForm.password"
                                            type="password"
                                            placeholder="Enter password"
                                            class="mt-1"
                                        />
                                        <p v-if="acceptErrors.password" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ acceptErrors.password }}
                                        </p>
                                    </div>

                                    <div>
                                        <Label for="accept_password_confirmation">Confirm password</Label>
                                        <Input
                                            id="accept_password_confirmation"
                                            v-model="acceptForm.password_confirmation"
                                            type="password"
                                            placeholder="Confirm password"
                                            class="mt-1"
                                        />
                                        <p v-if="acceptErrors.password_confirmation" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ acceptErrors.password_confirmation }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button
                                    @click="acceptInvitation"
                                    :disabled="!acceptForm.password || !acceptForm.password_confirmation || isAccepting"
                                    class="sm:col-start-2"
                                >
                                    {{ isAccepting ? 'Accepting...' : 'Accept & create account' }}
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
