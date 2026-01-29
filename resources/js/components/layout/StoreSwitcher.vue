<script setup lang="ts">
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { PlusIcon } from '@heroicons/vue/24/outline';
import type { Store } from '@/types';
import CreateStoreWizard from '@/components/store/CreateStoreWizard.vue';

const page = usePage();

// Get stores from page props
const stores = (page.props.stores as Store[] | undefined) || [];
const currentStore = (page.props.currentStore as Store | undefined);

// Modal state for creating new store
const showCreateWizard = ref(false);

function switchStore(store: Store) {
    if (store.current || store.id === currentStore?.id) return;

    router.post(`/stores/${store.id}/switch`, {}, {
        preserveState: false,
        preserveScroll: false,
    });
}
</script>

<template>
    <div>
        <div class="text-xs/6 font-semibold text-gray-400">Your stores</div>
        <ul role="list" class="-mx-2 mt-2 space-y-1">
            <li v-for="store in stores" :key="store.id">
                <button
                    type="button"
                    @click="switchStore(store)"
                    :class="[
                        store.current || store.id === currentStore?.id
                            ? 'bg-gray-50 text-indigo-600 dark:bg-white/5 dark:text-white'
                            : 'text-gray-700 hover:bg-gray-50 hover:text-indigo-600 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white',
                        'group flex w-full gap-x-3 rounded-md p-2 text-sm/6 font-semibold text-left',
                    ]"
                >
                    <span
                        :class="[
                            store.current || store.id === currentStore?.id
                                ? 'border-indigo-600 text-indigo-600 dark:border-white/20 dark:text-white'
                                : 'border-gray-200 text-gray-400 group-hover:border-indigo-600 group-hover:text-indigo-600 dark:border-white/10 dark:group-hover:border-white/20 dark:group-hover:text-white',
                            'flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-white text-[0.625rem] font-medium dark:bg-white/5',
                        ]"
                    >
                        <img
                            v-if="store.logo_url"
                            :src="store.logo_url"
                            :alt="store.name"
                            class="h-full w-full object-contain"
                        />
                        <template v-else>{{ store.initial }}</template>
                    </span>
                    <span class="truncate">{{ store.name }}</span>
                </button>
            </li>

            <!-- Create new store button -->
            <li>
                <button
                    type="button"
                    @click="showCreateWizard = true"
                    class="group flex w-full gap-x-3 rounded-md p-2 text-sm/6 font-semibold text-left text-gray-700 hover:bg-gray-50 hover:text-indigo-600 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                >
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-lg border border-dashed border-gray-300 bg-white text-gray-400 group-hover:border-indigo-600 group-hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:group-hover:border-white/20 dark:group-hover:text-white">
                        <PlusIcon class="size-4" />
                    </span>
                    <span class="truncate">Create store</span>
                </button>
            </li>
        </ul>

        <!-- Create store wizard -->
        <CreateStoreWizard v-if="showCreateWizard" @close="showCreateWizard = false" />
    </div>
</template>
