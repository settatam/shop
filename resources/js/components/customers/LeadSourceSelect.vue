<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import {
    Combobox,
    ComboboxInput,
    ComboboxButton,
    ComboboxOptions,
    ComboboxOption,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon, PlusIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

interface LeadSource {
    id: number;
    name: string;
    slug?: string;
    description?: string;
}

interface Props {
    modelValue: number | null;
    placeholder?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: null,
    placeholder: 'Select lead source...',
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [id: number | null];
}>();

const query = ref('');
const leadSources = ref<LeadSource[]>([]);
const isLoading = ref(false);
const isCreating = ref(false);
const createError = ref<string | null>(null);

// Fetch lead sources on mount
onMounted(async () => {
    await fetchLeadSources();
});

const fetchLeadSources = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/lead-sources');
        leadSources.value = response.data;
    } catch (err) {
        console.error('Failed to fetch lead sources:', err);
    } finally {
        isLoading.value = false;
    }
};

const selectedLeadSource = computed({
    get: () => leadSources.value.find((ls) => ls.id === props.modelValue) || null,
    set: (value) => {
        if (value && 'isCreateOption' in value) {
            // User wants to create a new lead source
            createNewLeadSource();
        } else {
            emit('update:modelValue', value?.id || null);
        }
    },
});

// Filter lead sources based on query
const filteredLeadSources = computed(() => {
    if (!query.value) {
        return leadSources.value;
    }
    const searchLower = query.value.toLowerCase();
    return leadSources.value.filter((ls) => ls.name.toLowerCase().includes(searchLower));
});

// Check if we should show the create option
const showCreateOption = computed(() => {
    if (!query.value.trim()) return false;
    // Show create option if no exact match exists
    const exactMatch = leadSources.value.some(
        (ls) => ls.name.toLowerCase() === query.value.trim().toLowerCase()
    );
    return !exactMatch;
});

// Create option marker
const createOption = computed(() => ({
    isCreateOption: true,
    name: `Create "${query.value.trim()}"`,
}));

const allOptions = computed(() => {
    const results = [...filteredLeadSources.value];
    if (showCreateOption.value) {
        results.push(createOption.value as any);
    }
    return results;
});

const createNewLeadSource = async () => {
    if (!query.value.trim()) return;

    isCreating.value = true;
    createError.value = null;

    try {
        const response = await axios.post('/lead-sources', {
            name: query.value.trim(),
        });

        // Add to list and select it
        const newLeadSource = response.data;
        leadSources.value.push(newLeadSource);
        emit('update:modelValue', newLeadSource.id);
        query.value = '';
    } catch (err: any) {
        createError.value = err.response?.data?.message || 'Failed to create lead source.';
    } finally {
        isCreating.value = false;
    }
};

const displayValue = (leadSource: LeadSource | null): string => {
    return leadSource?.name || '';
};
</script>

<template>
    <div class="relative">
        <Combobox v-model="selectedLeadSource" as="div" :disabled="disabled || isLoading" nullable>
            <div class="relative">
                <ComboboxInput
                    class="w-full rounded-md border-0 bg-white py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                    :placeholder="isLoading ? 'Loading...' : placeholder"
                    :display-value="displayValue"
                    @change="query = $event.target.value"
                />
                <ComboboxButton class="absolute inset-y-0 right-0 flex items-center pr-2">
                    <ChevronUpDownIcon class="size-5 text-gray-400" aria-hidden="true" />
                </ComboboxButton>
            </div>

            <ComboboxOptions
                class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm dark:bg-gray-800 dark:ring-white/10"
            >
                <!-- Loading state -->
                <div v-if="isLoading" class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
                    Loading lead sources...
                </div>

                <!-- Error state -->
                <div v-else-if="createError" class="px-4 py-2 text-sm text-red-600 dark:text-red-400">
                    {{ createError }}
                </div>

                <!-- Creating state -->
                <div v-else-if="isCreating" class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
                    Creating lead source...
                </div>

                <!-- No results -->
                <div
                    v-else-if="allOptions.length === 0"
                    class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400"
                >
                    No lead sources found. Type to create one.
                </div>

                <!-- Options -->
                <ComboboxOption
                    v-for="option in allOptions"
                    :key="'isCreateOption' in option ? 'create' : option.id"
                    v-slot="{ active, selected }"
                    :value="option"
                    as="template"
                >
                    <li
                        :class="[
                            'relative cursor-pointer select-none py-2 pl-3 pr-9',
                            active ? 'bg-indigo-600 text-white' : 'text-gray-900 dark:text-white',
                            'isCreateOption' in option ? 'border-t border-gray-200 dark:border-gray-700' : '',
                        ]"
                    >
                        <!-- Create new option -->
                        <template v-if="'isCreateOption' in option">
                            <div class="flex items-center gap-2">
                                <PlusIcon class="size-4" />
                                <span class="font-medium">{{ option.name }}</span>
                            </div>
                        </template>

                        <!-- Regular option -->
                        <template v-else>
                            <span :class="['block truncate', selected ? 'font-semibold' : '']">
                                {{ option.name }}
                            </span>
                            <span
                                v-if="selected"
                                :class="[
                                    'absolute inset-y-0 right-0 flex items-center pr-4',
                                    active ? 'text-white' : 'text-indigo-600',
                                ]"
                            >
                                <CheckIcon class="size-5" aria-hidden="true" />
                            </span>
                        </template>
                    </li>
                </ComboboxOption>
            </ComboboxOptions>
        </Combobox>
    </div>
</template>
