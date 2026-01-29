<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { XMarkIcon, PlusIcon } from '@heroicons/vue/20/solid';
import axios from 'axios';

interface Tag {
    id: number;
    name: string;
    slug: string;
    color: string;
}

interface Props {
    modelValue: Tag[];
    placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Search or create tags...',
});

const emit = defineEmits<{
    'update:modelValue': [tags: Tag[]];
}>();

const searchQuery = ref('');
const suggestions = ref<Tag[]>([]);
const isLoading = ref(false);
const showDropdown = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);
const containerRef = ref<HTMLDivElement | null>(null);

// Debounce timer
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const selectedTags = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const filteredSuggestions = computed(() => {
    const selectedIds = selectedTags.value.map(t => t.id);
    return suggestions.value.filter(t => !selectedIds.includes(t.id));
});

const canCreateTag = computed(() => {
    if (!searchQuery.value.trim()) return false;
    const query = searchQuery.value.trim().toLowerCase();
    // Check if tag already exists in suggestions or selected
    const existsInSuggestions = suggestions.value.some(t => t.name.toLowerCase() === query);
    const existsInSelected = selectedTags.value.some(t => t.name.toLowerCase() === query);
    return !existsInSuggestions && !existsInSelected;
});

async function searchTags() {
    if (!searchQuery.value.trim()) {
        // Still fetch tags to show dropdown
        try {
            const response = await axios.get('/tags/search', {
                params: { q: '' },
            });
            suggestions.value = response.data;
        } catch (error) {
            console.error('Error fetching tags:', error);
        }
        return;
    }

    isLoading.value = true;
    try {
        const response = await axios.get('/tags/search', {
            params: { q: searchQuery.value },
        });
        suggestions.value = response.data;
    } catch (error) {
        console.error('Error searching tags:', error);
    } finally {
        isLoading.value = false;
    }
}

function debouncedSearch() {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(() => {
        searchTags();
    }, 200);
}

watch(searchQuery, () => {
    debouncedSearch();
});

function selectTag(tag: Tag) {
    if (!selectedTags.value.some(t => t.id === tag.id)) {
        selectedTags.value = [...selectedTags.value, tag];
    }
    searchQuery.value = '';
    showDropdown.value = false;
}

function removeTag(tag: Tag) {
    selectedTags.value = selectedTags.value.filter(t => t.id !== tag.id);
}

async function createTag() {
    if (!canCreateTag.value) return;

    isLoading.value = true;
    try {
        const response = await axios.post('/tags', {
            name: searchQuery.value.trim(),
        }, {
            headers: {
                'Accept': 'application/json',
            },
        });
        const newTag = response.data;
        selectTag(newTag);
    } catch (error) {
        console.error('Error creating tag:', error);
    } finally {
        isLoading.value = false;
    }
}

function handleFocus() {
    showDropdown.value = true;
    if (suggestions.value.length === 0) {
        searchTags();
    }
}

function handleClickOutside(event: MouseEvent) {
    if (containerRef.value && !containerRef.value.contains(event.target as Node)) {
        showDropdown.value = false;
    }
}

function handleKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter') {
        event.preventDefault();
        if (canCreateTag.value) {
            createTag();
        } else if (filteredSuggestions.value.length > 0) {
            selectTag(filteredSuggestions.value[0]);
        }
    } else if (event.key === 'Escape') {
        showDropdown.value = false;
    } else if (event.key === 'Backspace' && !searchQuery.value && selectedTags.value.length > 0) {
        removeTag(selectedTags.value[selectedTags.value.length - 1]);
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div ref="containerRef" class="relative">
        <!-- Selected tags and input -->
        <div
            class="flex flex-wrap gap-1.5 rounded-md border-0 px-2 py-1.5 shadow-sm ring-1 ring-inset ring-gray-300 focus-within:ring-2 focus-within:ring-inset focus-within:ring-indigo-600 dark:ring-gray-600 dark:focus-within:ring-indigo-500"
            @click="inputRef?.focus()"
        >
            <!-- Selected tags -->
            <span
                v-for="tag in selectedTags"
                :key="tag.id"
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                :style="{ backgroundColor: tag.color + '20', color: tag.color }"
            >
                <span class="size-1.5 rounded-full" :style="{ backgroundColor: tag.color }"></span>
                {{ tag.name }}
                <button
                    type="button"
                    class="ml-0.5 rounded-full hover:bg-black/10 dark:hover:bg-white/10"
                    @click.stop="removeTag(tag)"
                >
                    <XMarkIcon class="size-3.5" />
                </button>
            </span>

            <!-- Input -->
            <input
                ref="inputRef"
                v-model="searchQuery"
                type="text"
                :placeholder="selectedTags.length === 0 ? placeholder : ''"
                class="flex-1 min-w-[120px] border-0 bg-transparent p-0 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-0 dark:text-white"
                @focus="handleFocus"
                @keydown="handleKeydown"
            />
        </div>

        <!-- Dropdown -->
        <div
            v-if="showDropdown"
            class="absolute z-10 mt-1 w-full rounded-md bg-white shadow-lg ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
        >
            <ul class="max-h-60 overflow-auto py-1">
                <!-- Loading state -->
                <li v-if="isLoading" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                    Searching...
                </li>

                <!-- Suggestions -->
                <li
                    v-for="tag in filteredSuggestions"
                    :key="tag.id"
                    class="cursor-pointer px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                    @click="selectTag(tag)"
                >
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                        :style="{ backgroundColor: tag.color + '20', color: tag.color }"
                    >
                        <span class="size-1.5 rounded-full" :style="{ backgroundColor: tag.color }"></span>
                        {{ tag.name }}
                    </span>
                </li>

                <!-- Create new tag option -->
                <li
                    v-if="canCreateTag"
                    class="cursor-pointer px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700 border-t border-gray-200 dark:border-gray-700"
                    @click="createTag"
                >
                    <span class="flex items-center gap-2 text-indigo-600 dark:text-indigo-400">
                        <PlusIcon class="size-4" />
                        Create "{{ searchQuery.trim() }}"
                    </span>
                </li>

                <!-- Empty state -->
                <li
                    v-if="!isLoading && filteredSuggestions.length === 0 && !canCreateTag"
                    class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400"
                >
                    {{ searchQuery ? 'No tags found' : 'No tags available' }}
                </li>
            </ul>
        </div>
    </div>
</template>
