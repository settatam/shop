<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
    ChevronRightIcon,
    MagnifyingGlassIcon,
    FolderIcon,
    FolderOpenIcon,
    ArrowPathIcon,
    SparklesIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface PlatformCategory {
    id: number;
    name: string;
    ebay_category_id?: string;
    etsy_id?: string;
    walmart_category_id?: string;
    amazon_category_id?: string;
    level: number;
    parent_id: number | null;
    children_count: number;
    has_children: boolean;
    path?: string | null;
}

interface BreadcrumbItem {
    id: number | null;
    name: string;
}

interface AiSuggestion {
    ebay_category_id: number;
    name: string;
    path: string;
    confidence: number;
    reasoning: string;
}

interface Props {
    platform: string;
    selectedCategoryId?: string | null;
    categoryName?: string | null;
    templateName?: string | null;
    categoryPath?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    selectedCategoryId: null,
    categoryName: null,
    templateName: null,
    categoryPath: null,
});

const emit = defineEmits<{
    (e: 'select', category: PlatformCategory): void;
}>();

const searchQuery = ref('');
const categories = ref<PlatformCategory[]>([]);
const loading = ref(false);
const breadcrumbs = ref<BreadcrumbItem[]>([{ id: null, name: 'All Categories' }]);
const currentParentId = ref<number | null>(null);

const suggestions = ref<AiSuggestion[]>([]);
const suggestingAi = ref(false);
const suggestError = ref('');

const canSuggest = computed(() => props.platform === 'ebay' && !!props.categoryName);

const apiEndpoints: Record<string, string> = {
    ebay: '/api/taxonomy/ebay/categories',
    etsy: '/api/taxonomy/etsy/categories',
    walmart: '/api/taxonomy/walmart/categories',
    amazon: '/api/taxonomy/amazon/categories',
};

const endpoint = computed(() => apiEndpoints[props.platform] ?? '');

async function fetchCategories(parentId: number | null = null, query: string = '') {
    if (!endpoint.value) return;

    loading.value = true;
    try {
        const params: Record<string, string | number> = {};
        if (query) {
            params.query = query;
        } else if (parentId !== null) {
            params.parent_id = parentId;
        }

        const response = await axios.get(endpoint.value, { params });
        categories.value = response.data ?? [];
    } catch (error) {
        console.error('Failed to fetch categories:', error);
        categories.value = [];
    } finally {
        loading.value = false;
    }
}

function navigateToCategory(category: PlatformCategory) {
    if (category.has_children) {
        currentParentId.value = category.id;
        breadcrumbs.value.push({ id: category.id, name: category.name });
        searchQuery.value = '';
        fetchCategories(category.id);
    }
}

function navigateToBreadcrumb(index: number) {
    const item = breadcrumbs.value[index];
    breadcrumbs.value = breadcrumbs.value.slice(0, index + 1);
    currentParentId.value = item.id;
    searchQuery.value = '';
    fetchCategories(item.id);
}

function selectCategory(category: PlatformCategory) {
    emit('select', category);
}

async function fetchAiSuggestions() {
    if (!props.categoryName) return;

    suggestingAi.value = true;
    suggestError.value = '';
    suggestions.value = [];

    try {
        const response = await axios.post('/api/taxonomy/ebay/suggest', {
            category_name: props.categoryName,
            template_name: props.templateName,
            category_path: props.categoryPath,
        });
        suggestions.value = response.data ?? [];
    } catch (error) {
        console.error('Failed to get AI suggestions:', error);
        suggestError.value = 'Failed to get suggestions. Please try again.';
    } finally {
        suggestingAi.value = false;
    }
}

async function selectSuggestion(suggestion: AiSuggestion) {
    // Fetch the actual category by ebay_category_id to get full data
    try {
        const response = await axios.get(endpoint.value, {
            params: { query: suggestion.name },
        });
        const match = (response.data ?? []).find(
            (c: PlatformCategory) => String(c.ebay_category_id) === String(suggestion.ebay_category_id),
        );
        if (match) {
            selectCategory(match);
        }
    } catch (error) {
        console.error('Failed to fetch suggested category:', error);
    }
}

function getConfidenceColor(confidence: number): string {
    if (confidence >= 80) return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
    if (confidence >= 50) return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
    return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

watch(searchQuery, (query) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        if (query.length >= 2) {
            fetchCategories(null, query);
        } else if (query.length === 0) {
            fetchCategories(currentParentId.value);
        }
    }, 300);
});

// Fetch root categories on mount
fetchCategories();
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- Search + AI Suggest -->
        <div class="flex items-center gap-2 mb-3">
            <div class="relative flex-1">
                <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search categories..."
                    class="pl-9"
                />
            </div>
            <Button
                v-if="canSuggest"
                variant="outline"
                size="sm"
                :disabled="suggestingAi"
                @click="fetchAiSuggestions"
            >
                <SparklesIcon v-if="!suggestingAi" class="h-4 w-4 mr-1" />
                <ArrowPathIcon v-else class="h-4 w-4 mr-1 animate-spin" />
                {{ suggestingAi ? 'Suggesting...' : 'AI Suggest' }}
            </Button>
        </div>

        <!-- AI Suggestions -->
        <div v-if="suggestingAi" class="mb-3 space-y-2">
            <div v-for="i in 3" :key="i" class="animate-pulse rounded-md border dark:border-gray-700 p-3">
                <div class="h-4 bg-muted rounded w-2/3 mb-2"></div>
                <div class="h-3 bg-muted rounded w-full"></div>
            </div>
        </div>

        <div v-else-if="suggestions.length > 0" class="mb-3 space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground mb-1">AI Suggestions</p>
            <button
                v-for="suggestion in suggestions"
                :key="suggestion.ebay_category_id"
                type="button"
                class="w-full text-left rounded-md border dark:border-gray-700 p-2.5 hover:bg-muted/50 transition-colors"
                @click="selectSuggestion(suggestion)"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium truncate">{{ suggestion.name }}</p>
                        <p class="text-xs text-muted-foreground truncate">{{ suggestion.path }}</p>
                    </div>
                    <span
                        class="shrink-0 inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium"
                        :class="getConfidenceColor(suggestion.confidence)"
                        :title="suggestion.reasoning"
                    >
                        {{ suggestion.confidence }}%
                    </span>
                </div>
            </button>
        </div>

        <div v-else-if="suggestError" class="mb-3 p-3 rounded-md bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-400">
            {{ suggestError }}
        </div>

        <!-- Breadcrumbs -->
        <div v-if="breadcrumbs.length > 1 && !searchQuery" class="flex flex-wrap items-center gap-1 mb-3 text-xs">
            <button
                v-for="(crumb, index) in breadcrumbs"
                :key="index"
                type="button"
                class="text-primary hover:underline"
                @click="navigateToBreadcrumb(index)"
            >
                {{ crumb.name }}
            </button>
            <ChevronRightIcon
                v-if="index < breadcrumbs.length - 1"
                v-for="(_, index) in breadcrumbs.slice(0, -1)"
                :key="'sep-' + index"
                class="h-3 w-3 text-muted-foreground"
            />
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-8">
            <ArrowPathIcon class="h-5 w-5 animate-spin text-muted-foreground" />
        </div>

        <!-- Category list -->
        <div v-else class="flex-1 overflow-y-auto border rounded-md divide-y dark:border-gray-700 dark:divide-gray-700 max-h-80">
            <div v-if="categories.length === 0" class="p-4 text-center text-sm text-muted-foreground">
                No categories found.
            </div>

            <button
                v-for="cat in categories"
                :key="cat.id"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-muted/50 transition-colors"
                :class="{
                    'bg-primary/5 border-l-2 border-l-primary': selectedCategoryId === String(cat.ebay_category_id || cat.etsy_id || cat.walmart_category_id || cat.amazon_category_id || cat.id),
                }"
                @click="cat.has_children ? navigateToCategory(cat) : selectCategory(cat)"
            >
                <component
                    :is="cat.has_children ? FolderIcon : FolderOpenIcon"
                    class="h-4 w-4 shrink-0"
                    :class="cat.has_children ? 'text-amber-500' : 'text-muted-foreground'"
                />
                <div class="flex-1 min-w-0">
                    <span class="truncate block">{{ cat.name }}</span>
                    <span v-if="cat.path && searchQuery" class="text-xs text-muted-foreground truncate block">
                        {{ cat.path }}
                    </span>
                </div>
                <ChevronRightIcon v-if="cat.has_children" class="h-4 w-4 shrink-0 text-muted-foreground" />
            </button>
        </div>
    </div>
</template>
