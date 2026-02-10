<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronUpDownIcon, CheckIcon } from '@heroicons/vue/20/solid';
import { BuildingStorefrontIcon } from '@heroicons/vue/24/outline';
import type { Store } from '@/types';
import {
    Listbox,
    ListboxButton,
    ListboxOptions,
    ListboxOption,
} from '@headlessui/vue';

const page = usePage();

const stores = computed(() => (page.props.stores as Store[] | undefined) || []);
const currentStore = computed(() => page.props.currentStore as Store | undefined);

const selectedStore = computed({
    get: () => stores.value.find(s => s.id === currentStore.value?.id) || stores.value[0],
    set: (store: Store) => {
        if (store.id !== currentStore.value?.id) {
            switchStore(store);
        }
    },
});

function switchStore(store: Store) {
    router.post(`/stores/${store.id}/switch`, {}, {
        preserveState: false,
        preserveScroll: false,
    });
}
</script>

<template>
    <div class="px-2 pb-4">
        <Listbox v-model="selectedStore" v-if="stores.length > 1">
            <div class="relative">
                <ListboxButton
                    class="relative w-full cursor-pointer rounded-lg bg-white py-3 pl-3 pr-10 text-left shadow-md ring-1 ring-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-800 dark:ring-white/10"
                >
                    <span class="flex items-center gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400"
                        >
                            <img
                                v-if="selectedStore?.logo_url"
                                :src="selectedStore.logo_url"
                                :alt="selectedStore?.name"
                                class="h-full w-full object-contain"
                            />
                            <BuildingStorefrontIcon v-else class="size-6" />
                        </span>
                        <span class="block truncate">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-white">
                                {{ selectedStore?.name }}
                            </span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                Current store
                            </span>
                        </span>
                    </span>
                    <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                        <ChevronUpDownIcon class="size-5 text-gray-400" aria-hidden="true" />
                    </span>
                </ListboxButton>

                <transition
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <ListboxOptions
                        class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none dark:bg-gray-800 dark:ring-white/10 sm:text-sm"
                    >
                        <ListboxOption
                            v-for="store in stores"
                            :key="store.id"
                            :value="store"
                            v-slot="{ active, selected }"
                            as="template"
                        >
                            <li
                                :class="[
                                    active ? 'bg-indigo-50 dark:bg-indigo-500/10' : '',
                                    'relative cursor-pointer select-none py-3 pl-3 pr-10',
                                ]"
                            >
                                <span class="flex items-center gap-3">
                                    <span
                                        :class="[
                                            selected
                                                ? 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-400'
                                                : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
                                            'flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg',
                                        ]"
                                    >
                                        <img
                                            v-if="store.logo_url"
                                            :src="store.logo_url"
                                            :alt="store.name"
                                            class="h-full w-full object-contain"
                                        />
                                        <span v-else class="text-sm font-medium">{{ store.initial }}</span>
                                    </span>
                                    <span
                                        :class="[
                                            selected ? 'font-semibold' : 'font-normal',
                                            'block truncate text-gray-900 dark:text-white',
                                        ]"
                                    >
                                        {{ store.name }}
                                    </span>
                                </span>
                                <span
                                    v-if="selected"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-indigo-600 dark:text-indigo-400"
                                >
                                    <CheckIcon class="size-5" aria-hidden="true" />
                                </span>
                            </li>
                        </ListboxOption>
                    </ListboxOptions>
                </transition>
            </div>
        </Listbox>

        <!-- Single store display (non-interactive) -->
        <div
            v-else-if="currentStore"
            class="flex items-center gap-3 rounded-lg bg-white p-3 shadow-md ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-white/10"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400"
            >
                <img
                    v-if="currentStore.logo_url"
                    :src="currentStore.logo_url"
                    :alt="currentStore.name"
                    class="h-full w-full object-contain"
                />
                <BuildingStorefrontIcon v-else class="size-6" />
            </span>
            <span class="block truncate">
                <span class="block text-sm font-semibold text-gray-900 dark:text-white">
                    {{ currentStore.name }}
                </span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">
                    Current store
                </span>
            </span>
        </div>
    </div>
</template>
