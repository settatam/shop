<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { ChevronDownIcon, XMarkIcon, CheckIcon } from '@heroicons/vue/20/solid';
import {
    Popover,
    PopoverTrigger,
    PopoverContent,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface FilterOption {
    value: string | number;
    label: string;
    count?: number;
}

export type FilterType = 'checkbox' | 'radio' | 'date-range';

interface Props {
    label: string;
    options?: FilterOption[];
    modelValue: string | number | (string | number)[] | null;
    type?: FilterType;
    placeholder?: string;
    searchable?: boolean;
    showCounts?: boolean;
    clearable?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    options: () => [],
    type: 'radio',
    placeholder: 'Select...',
    searchable: false,
    showCounts: false,
    clearable: true,
});

const emit = defineEmits<{
    'update:modelValue': [value: string | number | (string | number)[] | null];
}>();

const isOpen = ref(false);
const searchQuery = ref('');

// Filtered options based on search
const filteredOptions = computed(() => {
    if (!searchQuery.value) return props.options;
    const query = searchQuery.value.toLowerCase();
    return props.options.filter(option =>
        option.label.toLowerCase().includes(query)
    );
});

// Display value for the trigger button
const displayValue = computed(() => {
    if (props.type === 'checkbox') {
        const values = Array.isArray(props.modelValue) ? props.modelValue : [];
        if (values.length === 0) return null;
        if (values.length === 1) {
            const option = props.options.find(o => o.value === values[0]);
            return option?.label || String(values[0]);
        }
        return `${values.length} selected`;
    }

    if (props.modelValue === null || props.modelValue === '') return null;
    const option = props.options.find(o => o.value === props.modelValue);
    return option?.label || String(props.modelValue);
});

// Check if filter has active selection
const hasSelection = computed(() => {
    if (props.type === 'checkbox') {
        return Array.isArray(props.modelValue) && props.modelValue.length > 0;
    }
    return props.modelValue !== null && props.modelValue !== '';
});

// Handle checkbox selection
function toggleCheckbox(value: string | number) {
    const currentValues = Array.isArray(props.modelValue) ? [...props.modelValue] : [];
    const index = currentValues.indexOf(value);

    if (index === -1) {
        currentValues.push(value);
    } else {
        currentValues.splice(index, 1);
    }

    emit('update:modelValue', currentValues);
}

// Handle radio selection
function selectRadio(value: string | number) {
    emit('update:modelValue', value);
    isOpen.value = false;
}

// Clear filter
function clearFilter(event: Event) {
    event.stopPropagation();
    if (props.type === 'checkbox') {
        emit('update:modelValue', []);
    } else {
        emit('update:modelValue', null);
    }
}

// Check if option is selected
function isSelected(value: string | number): boolean {
    if (props.type === 'checkbox') {
        return Array.isArray(props.modelValue) && props.modelValue.includes(value);
    }
    return props.modelValue === value;
}

// Reset search when popover closes
watch(isOpen, (open) => {
    if (!open) {
        searchQuery.value = '';
    }
});
</script>

<template>
    <Popover v-model:open="isOpen">
        <PopoverTrigger as-child>
            <button
                type="button"
                :class="cn(
                    'inline-flex items-center gap-x-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                    'ring-1 ring-inset focus:outline-none focus:ring-2 focus:ring-indigo-600',
                    hasSelection
                        ? 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-900/50 dark:text-indigo-300 dark:ring-indigo-700'
                        : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700'
                )"
            >
                <span>{{ label }}</span>
                <template v-if="displayValue">
                    <span class="text-indigo-600 dark:text-indigo-400">: {{ displayValue }}</span>
                </template>

                <!-- Clear button -->
                <button
                    v-if="clearable && hasSelection"
                    type="button"
                    class="ml-1 -mr-1 rounded-full p-0.5 text-indigo-500 hover:bg-indigo-100 hover:text-indigo-700 dark:text-indigo-400 dark:hover:bg-indigo-800 dark:hover:text-indigo-300"
                    @click="clearFilter"
                >
                    <XMarkIcon class="size-3.5" />
                </button>
                <ChevronDownIcon v-else class="-mr-1 size-4 text-gray-400" />
            </button>
        </PopoverTrigger>

        <PopoverContent align="start" :side-offset="4" class="w-56 p-0">
            <!-- Search input -->
            <div v-if="searchable" class="p-2 border-b border-gray-200 dark:border-gray-700">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search..."
                    class="w-full rounded-md border-0 bg-gray-50 py-1.5 px-2 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                />
            </div>

            <!-- Options list -->
            <div class="max-h-60 overflow-y-auto p-1">
                <template v-if="filteredOptions.length === 0">
                    <div class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                        No options found
                    </div>
                </template>

                <!-- Checkbox type -->
                <template v-else-if="type === 'checkbox'">
                    <label
                        v-for="option in filteredOptions"
                        :key="option.value"
                        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                    >
                        <input
                            type="checkbox"
                            :checked="isSelected(option.value)"
                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                            @change="toggleCheckbox(option.value)"
                        />
                        <span class="flex-1 text-gray-700 dark:text-gray-200">{{ option.label }}</span>
                        <span v-if="showCounts && option.count !== undefined" class="text-xs text-gray-400 dark:text-gray-500">
                            {{ option.count }}
                        </span>
                    </label>
                </template>

                <!-- Radio type -->
                <template v-else-if="type === 'radio'">
                    <button
                        v-for="option in filteredOptions"
                        :key="option.value"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                        @click="selectRadio(option.value)"
                    >
                        <CheckIcon
                            :class="cn(
                                'size-4',
                                isSelected(option.value) ? 'text-indigo-600 dark:text-indigo-400' : 'invisible'
                            )"
                        />
                        <span class="flex-1 text-left text-gray-700 dark:text-gray-200">{{ option.label }}</span>
                        <span v-if="showCounts && option.count !== undefined" class="text-xs text-gray-400 dark:text-gray-500">
                            {{ option.count }}
                        </span>
                    </button>
                </template>
            </div>

            <!-- Clear all button for checkbox -->
            <div v-if="type === 'checkbox' && hasSelection" class="border-t border-gray-200 p-2 dark:border-gray-700">
                <button
                    type="button"
                    class="w-full rounded-md px-2 py-1 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
                    @click="emit('update:modelValue', [])"
                >
                    Clear all
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
