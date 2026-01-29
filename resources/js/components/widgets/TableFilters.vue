<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { MagnifyingGlassIcon, XMarkIcon, FunnelIcon } from '@heroicons/vue/20/solid';
import { useDebounceFn } from '@vueuse/core';
import PopoverFilter, { type FilterOption, type FilterType } from './PopoverFilter.vue';

export interface FilterDefinition {
    key: string;
    label: string;
    type?: FilterType;
    options?: FilterOption[];
    searchable?: boolean;
    showCounts?: boolean;
    clearable?: boolean;
}

export type FilterValues = Record<string, string | number | (string | number)[] | null>;

interface Props {
    filters: FilterDefinition[];
    modelValue: FilterValues;
    searchable?: boolean;
    searchPlaceholder?: string;
    searchValue?: string;
    searchDebounce?: number;
    showClearAll?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    searchable: false,
    searchPlaceholder: 'Search...',
    searchValue: '',
    searchDebounce: 300,
    showClearAll: true,
});

const emit = defineEmits<{
    'update:modelValue': [value: FilterValues];
    'update:searchValue': [value: string];
    'search': [value: string];
    'change': [key: string, value: string | number | (string | number)[] | null];
}>();

// Local search state
const localSearch = ref(props.searchValue);

// Sync local search with prop
watch(() => props.searchValue, (newValue) => {
    localSearch.value = newValue;
});

// Debounced search
const debouncedSearch = useDebounceFn(() => {
    emit('update:searchValue', localSearch.value);
    emit('search', localSearch.value);
}, props.searchDebounce);

// Handle search input
function handleSearch() {
    debouncedSearch();
}

// Clear search
function clearSearch() {
    localSearch.value = '';
    emit('update:searchValue', '');
    emit('search', '');
}

// Update individual filter
function updateFilter(key: string, value: string | number | (string | number)[] | null) {
    const newValues = { ...props.modelValue, [key]: value };
    emit('update:modelValue', newValues);
    emit('change', key, value);
}

// Check if any filters are active
const hasActiveFilters = computed(() => {
    return Object.entries(props.modelValue).some(([, value]) => {
        if (Array.isArray(value)) return value.length > 0;
        return value !== null && value !== '';
    });
});

// Check if search or filters are active
const hasAnyActive = computed(() => {
    return localSearch.value !== '' || hasActiveFilters.value;
});

// Clear all filters
function clearAllFilters() {
    const clearedValues: FilterValues = {};
    for (const filter of props.filters) {
        clearedValues[filter.key] = filter.type === 'checkbox' ? [] : null;
    }
    emit('update:modelValue', clearedValues);

    if (props.searchable && localSearch.value) {
        localSearch.value = '';
        emit('update:searchValue', '');
        emit('search', '');
    }
}

// Get current value for a filter
function getFilterValue(key: string) {
    return props.modelValue[key];
}

// Count active filters
const activeFilterCount = computed(() => {
    let count = 0;
    for (const [key, value] of Object.entries(props.modelValue)) {
        if (Array.isArray(value)) {
            if (value.length > 0) count++;
        } else if (value !== null && value !== '') {
            count++;
        }
    }
    return count;
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <!-- Search input -->
        <div v-if="searchable" class="relative flex-1 max-w-md min-w-[200px]">
            <MagnifyingGlassIcon
                class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400"
            />
            <input
                v-model="localSearch"
                type="text"
                :placeholder="searchPlaceholder"
                class="block w-full rounded-md border-0 py-1.5 pl-10 pr-8 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                @input="handleSearch"
                @keyup.enter="emit('search', localSearch)"
            />
            <button
                v-if="localSearch"
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-600"
                @click="clearSearch"
            >
                <XMarkIcon class="size-4" />
            </button>
        </div>

        <!-- Filter indicator (mobile) -->
        <div v-if="activeFilterCount > 0" class="sm:hidden flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400">
            <FunnelIcon class="size-4" />
            <span>{{ activeFilterCount }} filter{{ activeFilterCount > 1 ? 's' : '' }}</span>
        </div>

        <!-- Popover filters -->
        <div class="flex flex-wrap items-center gap-2">
            <PopoverFilter
                v-for="filter in filters"
                :key="filter.key"
                :label="filter.label"
                :options="filter.options || []"
                :type="filter.type || 'radio'"
                :searchable="filter.searchable"
                :show-counts="filter.showCounts"
                :clearable="filter.clearable !== false"
                :model-value="getFilterValue(filter.key)"
                @update:model-value="(v) => updateFilter(filter.key, v)"
            />
        </div>

        <!-- Clear all button -->
        <button
            v-if="showClearAll && hasAnyActive"
            type="button"
            class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            @click="clearAllFilters"
        >
            Clear all
        </button>

        <!-- Slot for extra content (like export button) -->
        <slot name="actions" />
    </div>
</template>
