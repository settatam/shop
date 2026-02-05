<script setup lang="ts">
import { watch, ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
    Combobox,
    ComboboxInput,
    ComboboxOptions,
    ComboboxOption,
} from '@headlessui/vue';
import { MagnifyingGlassIcon } from '@heroicons/vue/20/solid';
import {
    CubeIcon,
    ShoppingCartIcon,
    UsersIcon,
    WrenchScrewdriverIcon,
    DocumentTextIcon,
    CurrencyDollarIcon,
    FolderIcon,
    RectangleStackIcon,
    TagIcon,
} from '@heroicons/vue/24/outline';
import { useSearch, type SearchResult, type SearchResults } from '@/composables/useSearch';

const {
    query,
    isLoading,
    isOpen,
    hasResults,
    groupedResults,
    close,
    onQueryChange,
} = useSearch();

const selectedItem = ref<SearchResult | null>(null);

const iconMap: Record<keyof SearchResults, any> = {
    products: CubeIcon,
    orders: ShoppingCartIcon,
    customers: UsersIcon,
    repairs: WrenchScrewdriverIcon,
    memos: DocumentTextIcon,
    transactions: CurrencyDollarIcon,
    transaction_items: TagIcon,
    categories: FolderIcon,
    templates: RectangleStackIcon,
};

const open = () => {
    isOpen.value = true;
};

const handleKeyDown = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
        event.preventDefault();
        open();
    }
};

onMounted(() => {
    document.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeyDown);
});

const navigateTo = (item: SearchResult) => {
    close();
    router.visit(item.url);
};

watch(selectedItem, (item) => {
    if (item) {
        navigateTo(item);
        selectedItem.value = null;
    }
});

defineExpose({ open });
</script>

<template>
    <TransitionRoot :show="isOpen" as="template" appear @after-leave="query = ''">
        <Dialog class="relative z-50" @close="close">
            <TransitionChild
                as="template"
                enter="ease-out duration-300"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-200"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-gray-500/25 dark:bg-gray-900/80 transition-opacity" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto p-4 sm:p-6 md:p-20">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-300"
                    enter-from="opacity-0 scale-95"
                    enter-to="opacity-100 scale-100"
                    leave="ease-in duration-200"
                    leave-from="opacity-100 scale-100"
                    leave-to="opacity-0 scale-95"
                >
                    <DialogPanel
                        class="mx-auto max-w-xl transform divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden rounded-xl bg-white dark:bg-gray-800 shadow-2xl ring-1 ring-black/5 transition-all"
                    >
                        <Combobox v-model="selectedItem" @update:model-value="navigateTo">
                            <div class="relative">
                                <MagnifyingGlassIcon
                                    class="pointer-events-none absolute left-4 top-3.5 size-5 text-gray-400"
                                    aria-hidden="true"
                                />
                                <ComboboxInput
                                    class="h-12 w-full border-0 bg-transparent pl-11 pr-4 text-gray-900 dark:text-white placeholder:text-gray-400 focus:ring-0 sm:text-sm"
                                    placeholder="Search products, orders, customers..."
                                    :value="query"
                                    @input="onQueryChange($event.target.value)"
                                />
                                <div
                                    v-if="isLoading"
                                    class="absolute right-4 top-3.5"
                                >
                                    <svg
                                        class="animate-spin size-5 text-gray-400"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <circle
                                            class="opacity-25"
                                            cx="12"
                                            cy="12"
                                            r="10"
                                            stroke="currentColor"
                                            stroke-width="4"
                                        />
                                        <path
                                            class="opacity-75"
                                            fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                        />
                                    </svg>
                                </div>
                                <kbd
                                    v-else
                                    class="absolute right-4 top-3 hidden sm:inline-flex items-center rounded border border-gray-200 dark:border-gray-600 px-1.5 font-sans text-xs text-gray-400"
                                >
                                    ESC
                                </kbd>
                            </div>

                            <ComboboxOptions
                                v-if="query && hasResults"
                                static
                                class="max-h-80 scroll-py-2 divide-y divide-gray-100 dark:divide-gray-700 overflow-y-auto"
                            >
                                <li
                                    v-for="group in groupedResults"
                                    :key="group.type"
                                    class="p-2"
                                >
                                    <h2 class="mb-2 mt-1 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        {{ group.label }}
                                    </h2>
                                    <ul class="text-sm text-gray-700 dark:text-gray-300">
                                        <ComboboxOption
                                            v-for="item in group.items"
                                            :key="`${group.type}-${item.id}`"
                                            :value="item"
                                            as="template"
                                            v-slot="{ active }"
                                        >
                                            <li
                                                :class="[
                                                    'flex cursor-pointer select-none items-center rounded-md px-3 py-2',
                                                    active ? 'bg-primary text-primary-foreground' : '',
                                                ]"
                                            >
                                                <component
                                                    :is="iconMap[group.type]"
                                                    :class="[
                                                        'size-5 flex-none',
                                                        active ? 'text-primary-foreground' : 'text-gray-400',
                                                    ]"
                                                    aria-hidden="true"
                                                />
                                                <div class="ml-3 flex-auto truncate">
                                                    <span class="font-medium">{{ item.title }}</span>
                                                    <span
                                                        v-if="item.subtitle"
                                                        :class="[
                                                            'ml-2 text-sm',
                                                            active ? 'text-primary-foreground/70' : 'text-gray-400',
                                                        ]"
                                                    >
                                                        {{ item.subtitle }}
                                                    </span>
                                                </div>
                                            </li>
                                        </ComboboxOption>
                                    </ul>
                                </li>
                            </ComboboxOptions>

                            <div
                                v-if="query && !isLoading && !hasResults"
                                class="px-6 py-14 text-center sm:px-14"
                            >
                                <MagnifyingGlassIcon
                                    class="mx-auto size-6 text-gray-400"
                                    aria-hidden="true"
                                />
                                <p class="mt-4 text-sm text-gray-900 dark:text-white">
                                    No results found for "<strong>{{ query }}</strong>"
                                </p>
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    Try searching for something else.
                                </p>
                            </div>

                            <div
                                v-if="!query"
                                class="px-6 py-14 text-center sm:px-14"
                            >
                                <MagnifyingGlassIcon
                                    class="mx-auto size-6 text-gray-400"
                                    aria-hidden="true"
                                />
                                <p class="mt-4 text-sm text-gray-900 dark:text-white">
                                    Search for products, orders, customers, and more
                                </p>
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    Start typing to search across your store.
                                </p>
                            </div>
                        </Combobox>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
