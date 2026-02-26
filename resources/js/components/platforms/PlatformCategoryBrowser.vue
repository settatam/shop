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
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface PlatformCategory {
    id: number;
    name: string;
    ebay_category_id?: string;
    etsy_id?: string;
    level: number;
    parent_id: number | null;
    children_count: number;
    has_children: boolean;
}

interface BreadcrumbItem {
    id: number | null;
    name: string;
}

interface Props {
    platform: string;
    selectedCategoryId?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    selectedCategoryId: null,
});

const emit = defineEmits<{
    (e: 'select', category: PlatformCategory): void;
}>();

const searchQuery = ref('');
const categories = ref<PlatformCategory[]>([]);
const loading = ref(false);
const breadcrumbs = ref<BreadcrumbItem[]>([{ id: null, name: 'All Categories' }]);
const currentParentId = ref<number | null>(null);

const apiEndpoints: Record<string, string> = {
    ebay: '/taxonomy/ebay/categories',
    etsy: '/taxonomy/etsy/categories',
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
        <!-- Search -->
        <div class="relative mb-3">
            <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                v-model="searchQuery"
                type="text"
                placeholder="Search categories..."
                class="pl-9"
            />
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
                    'bg-primary/5 border-l-2 border-l-primary': selectedCategoryId === String(cat.ebay_category_id || cat.etsy_id || cat.id),
                }"
                @click="cat.has_children ? navigateToCategory(cat) : selectCategory(cat)"
            >
                <component
                    :is="cat.has_children ? FolderIcon : FolderOpenIcon"
                    class="h-4 w-4 shrink-0"
                    :class="cat.has_children ? 'text-amber-500' : 'text-muted-foreground'"
                />
                <span class="flex-1 truncate">{{ cat.name }}</span>
                <Badge v-if="!cat.has_children" variant="outline" class="text-[10px] shrink-0">
                    leaf
                </Badge>
                <ChevronRightIcon v-if="cat.has_children" class="h-4 w-4 shrink-0 text-muted-foreground" />
            </button>
        </div>
    </div>
</template>
