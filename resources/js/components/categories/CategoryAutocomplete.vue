<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    Combobox,
    ComboboxInput,
    ComboboxButton,
    ComboboxOptions,
    ComboboxOption,
} from '@headlessui/vue';
import {
    MagnifyingGlassIcon,
    CheckIcon,
    ChevronUpDownIcon,
    XMarkIcon,
    FolderIcon,
} from '@heroicons/vue/20/solid';

interface Category {
    id: number;
    name: string;
    full_path: string;
    parent_id: number | null;
    level?: number;
}

interface Props {
    modelValue: number | string | null;
    categories: Category[];
    placeholder?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: null,
    placeholder: 'Search categories...',
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [categoryId: number | string | null];
}>();

const query = ref('');

// Find selected category object from ID
const selectedCategory = computed(() => {
    if (!props.modelValue) return null;
    return props.categories.find(c => c.id === Number(props.modelValue)) || null;
});

// Filter categories based on search query
const filteredCategories = computed(() => {
    if (!query.value) {
        // Show all categories when no search, limited to first 20
        return props.categories.slice(0, 20);
    }

    const searchTerm = query.value.toLowerCase();
    return props.categories
        .filter(category =>
            category.name.toLowerCase().includes(searchTerm) ||
            category.full_path.toLowerCase().includes(searchTerm)
        )
        .slice(0, 20); // Limit results
});

function handleSelect(category: Category | null) {
    emit('update:modelValue', category?.id ?? null);
    query.value = '';
}

function clearSelection() {
    emit('update:modelValue', null);
    query.value = '';
}

// Get breadcrumb parts from full_path
function getPathParts(fullPath: string): string[] {
    return fullPath.split(' > ');
}
</script>

<template>
    <div class="relative">
        <!-- Selected category display -->
        <div
            v-if="selectedCategory"
            class="flex items-center justify-between rounded-md border border-gray-300 bg-white px-3 py-1.5 dark:border-gray-600 dark:bg-gray-700"
        >
            <div class="flex items-center gap-2 min-w-0">
                <FolderIcon class="size-4 shrink-0 text-gray-400" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                        {{ selectedCategory.name }}
                    </p>
                    <p
                        v-if="selectedCategory.full_path !== selectedCategory.name"
                        class="text-xs text-gray-500 dark:text-gray-400 truncate"
                    >
                        {{ selectedCategory.full_path }}
                    </p>
                </div>
            </div>
            <button
                type="button"
                class="ml-2 shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-600"
                @click="clearSelection"
            >
                <XMarkIcon class="size-4" />
            </button>
        </div>

        <!-- Search input -->
        <Combobox
            v-else
            :model-value="selectedCategory"
            as="div"
            :disabled="disabled"
            @update:model-value="handleSelect"
        >
            <div class="relative">
                <ComboboxInput
                    class="w-full rounded-md border-0 bg-white py-1.5 pl-9 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                    :placeholder="placeholder"
                    @change="query = $event.target.value"
                />
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <MagnifyingGlassIcon class="size-4 text-gray-400" aria-hidden="true" />
                </div>
                <ComboboxButton class="absolute inset-y-0 right-0 flex items-center pr-2">
                    <ChevronUpDownIcon class="size-5 text-gray-400" aria-hidden="true" />
                </ComboboxButton>
            </div>

            <ComboboxOptions
                class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm dark:bg-gray-800 dark:ring-white/10"
            >
                <!-- No results -->
                <div
                    v-if="filteredCategories.length === 0"
                    class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400"
                >
                    No categories found.
                </div>

                <!-- Results -->
                <ComboboxOption
                    v-for="category in filteredCategories"
                    :key="category.id"
                    v-slot="{ active, selected }"
                    :value="category"
                    as="template"
                >
                    <li
                        :class="[
                            'relative cursor-pointer select-none py-2 pl-3 pr-9',
                            active ? 'bg-indigo-600 text-white' : 'text-gray-900 dark:text-white',
                        ]"
                    >
                        <div class="flex items-start gap-2">
                            <FolderIcon
                                :class="[
                                    'size-4 shrink-0 mt-0.5',
                                    active ? 'text-indigo-200' : 'text-gray-400'
                                ]"
                            />
                            <div class="min-w-0 flex-1">
                                <!-- Category name highlighted -->
                                <p :class="['font-medium truncate', selected ? 'font-semibold' : '']">
                                    {{ category.name }}
                                </p>
                                <!-- Full path as breadcrumb -->
                                <div
                                    v-if="category.full_path !== category.name"
                                    :class="[
                                        'flex items-center gap-1 text-xs mt-0.5 flex-wrap',
                                        active ? 'text-indigo-200' : 'text-gray-500 dark:text-gray-400'
                                    ]"
                                >
                                    <template v-for="(part, index) in getPathParts(category.full_path)" :key="index">
                                        <span v-if="index > 0" class="mx-0.5">/</span>
                                        <span
                                            :class="{ 'font-medium': index === getPathParts(category.full_path).length - 1 }"
                                        >
                                            {{ part }}
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <span
                            v-if="selected"
                            :class="[
                                'absolute inset-y-0 right-0 flex items-center pr-4',
                                active ? 'text-white' : 'text-indigo-600',
                            ]"
                        >
                            <CheckIcon class="size-5" aria-hidden="true" />
                        </span>
                    </li>
                </ComboboxOption>
            </ComboboxOptions>
        </Combobox>
    </div>
</template>
