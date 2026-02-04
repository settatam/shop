<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Bars3Icon, BellIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';
import { ChevronDownIcon } from '@heroicons/vue/20/solid';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import type { BreadcrumbItemType, User, Store } from '@/types';
import { useInitials } from '@/composables/useInitials';
import { BuildingStorefrontIcon } from '@heroicons/vue/24/outline';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const emit = defineEmits<{
    openSidebar: [];
    openSearch: [];
}>();

const page = usePage();
const user = page.props.auth?.user as User | undefined;
const currentStore = page.props.currentStore as Store | undefined;
const { getInitials } = useInitials();

const userNavigation = [
    { name: 'Your profile', href: '/settings/profile' },
    { name: 'Settings', href: '/settings' },
];
</script>

<template>
    <div class="sticky top-0 z-40 lg:mx-auto lg:max-w-7xl lg:px-8">
        <div class="flex h-16 items-center gap-x-4 border-b border-gray-200 bg-white px-4 shadow-xs sm:gap-x-6 sm:px-6 lg:px-0 lg:shadow-none dark:border-white/10 dark:bg-gray-900">
            <!-- Mobile menu button -->
            <button
                type="button"
                class="-m-2.5 p-2.5 text-gray-700 hover:text-gray-900 lg:hidden dark:text-gray-400 dark:hover:text-white"
                @click="emit('openSidebar')"
            >
                <span class="sr-only">Open sidebar</span>
                <Bars3Icon class="size-6" aria-hidden="true" />
            </button>

            <!-- Separator -->
            <div class="h-6 w-px bg-gray-200 lg:hidden dark:bg-gray-700" aria-hidden="true" />

            <!-- Current Store Name -->
            <div v-if="currentStore" class="flex items-center gap-x-2">
                <BuildingStorefrontIcon class="size-5 text-gray-400" />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ currentStore.name }}
                </span>
            </div>

            <!-- Separator -->
            <div v-if="currentStore" class="hidden sm:block h-6 w-px bg-gray-200 dark:bg-gray-700" aria-hidden="true" />

            <div class="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
                <!-- Search -->
                <button
                    type="button"
                    class="grid flex-1 grid-cols-1 text-left"
                    @click="emit('openSearch')"
                >
                    <span
                        class="col-start-1 row-start-1 block size-full border-0 bg-white pl-8 text-base text-gray-400 sm:text-sm/6 dark:bg-gray-900 dark:text-gray-500 flex items-center"
                    >
                        Search...
                        <kbd class="ml-auto mr-4 hidden sm:inline-flex items-center rounded border border-gray-200 dark:border-gray-600 px-1.5 font-sans text-xs text-gray-400">
                            ⌘K
                        </kbd>
                    </span>
                    <MagnifyingGlassIcon
                        class="pointer-events-none col-start-1 row-start-1 size-5 self-center text-gray-400"
                        aria-hidden="true"
                    />
                </button>

                <div class="flex items-center gap-x-4 lg:gap-x-6">
                    <!-- Breadcrumbs (desktop) -->
                    <div class="hidden lg:block">
                        <Breadcrumbs v-if="breadcrumbs.length > 0" :breadcrumbs="breadcrumbs" />
                    </div>

                    <!-- Notifications -->
                    <button
                        type="button"
                        class="-m-2.5 p-2.5 text-gray-400 hover:text-gray-500 dark:hover:text-white"
                    >
                        <span class="sr-only">View notifications</span>
                        <BellIcon class="size-6" aria-hidden="true" />
                    </button>

                    <!-- Separator -->
                    <div class="hidden lg:block lg:h-6 lg:w-px lg:bg-gray-200 dark:lg:bg-white/10" aria-hidden="true" />

                    <!-- Profile dropdown -->
                    <Menu as="div" class="relative">
                        <MenuButton class="relative flex items-center">
                            <span class="absolute -inset-1.5" />
                            <span class="sr-only">Open user menu</span>
                            <span
                                v-if="user?.avatar"
                                class="size-8 rounded-full bg-gray-50 outline outline-offset-1 outline-black/5 dark:bg-gray-800 dark:outline-white/10"
                            >
                                <img :src="user.avatar" :alt="user.name" class="size-8 rounded-full" />
                            </span>
                            <span
                                v-else
                                class="flex size-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-medium text-white"
                            >
                                {{ user ? getInitials(user.name) : '?' }}
                            </span>
                            <span class="hidden lg:flex lg:items-center">
                                <span class="ml-4 text-sm/6 font-semibold text-gray-900 dark:text-white" aria-hidden="true">
                                    {{ user?.name || 'Guest' }}
                                </span>
                                <ChevronDownIcon class="ml-2 size-5 text-gray-400" aria-hidden="true" />
                            </span>
                        </MenuButton>
                        <transition
                            enter-active-class="transition ease-out duration-100"
                            enter-from-class="transform opacity-0 scale-95"
                            enter-to-class="transform scale-100 opacity-100"
                            leave-active-class="transition ease-in duration-75"
                            leave-from-class="transform scale-100 opacity-100"
                            leave-to-class="transform opacity-0 scale-95"
                        >
                            <MenuItems
                                class="absolute right-0 z-10 mt-2.5 w-32 origin-top-right rounded-md bg-white py-2 shadow-lg ring-1 ring-gray-900/5 focus:outline-none dark:bg-gray-800 dark:ring-white/10"
                            >
                                <MenuItem v-for="item in userNavigation" :key="item.name" v-slot="{ active }">
                                    <Link
                                        :href="item.href"
                                        :class="[
                                            active ? 'bg-gray-50 dark:bg-gray-700' : '',
                                            'block px-3 py-1 text-sm/6 text-gray-900 dark:text-white',
                                        ]"
                                    >
                                        {{ item.name }}
                                    </Link>
                                </MenuItem>
                                <MenuItem v-slot="{ active }">
                                    <Link
                                        href="/logout"
                                        method="post"
                                        as="button"
                                        :class="[
                                            active ? 'bg-gray-50 dark:bg-gray-700' : '',
                                            'block w-full px-3 py-1 text-left text-sm/6 text-gray-900 dark:text-white',
                                        ]"
                                    >
                                        Sign out
                                    </Link>
                                </MenuItem>
                            </MenuItems>
                        </transition>
                    </Menu>
                </div>
            </div>
        </div>
    </div>
</template>
