import { ref, computed } from 'vue';
import axios from 'axios';
import { useDebounceFn } from '@vueuse/core';

export interface SearchResult {
    id: number;
    title: string;
    subtitle: string | null;
    url: string;
}

export interface SearchResults {
    products: SearchResult[];
    orders: SearchResult[];
    customers: SearchResult[];
    repairs: SearchResult[];
    memos: SearchResult[];
    transactions: SearchResult[];
    categories: SearchResult[];
    templates: SearchResult[];
}

export interface SearchResponse {
    results: SearchResults;
    total: number;
}

const typeLabels: Record<keyof SearchResults, string> = {
    products: 'Products',
    orders: 'Orders',
    customers: 'Customers',
    repairs: 'Repairs',
    memos: 'Memos',
    transactions: 'Transactions',
    categories: 'Categories',
    templates: 'Templates',
};

const typeIcons: Record<keyof SearchResults, string> = {
    products: 'cube',
    orders: 'shopping-cart',
    customers: 'users',
    repairs: 'wrench',
    memos: 'document-text',
    transactions: 'currency-dollar',
    categories: 'folder',
    templates: 'template',
};

export function useSearch() {
    const query = ref('');
    const results = ref<SearchResults | null>(null);
    const isLoading = ref(false);
    const error = ref<string | null>(null);
    const isOpen = ref(false);

    const hasResults = computed(() => {
        if (!results.value) return false;
        return Object.values(results.value).some(items => items.length > 0);
    });

    const groupedResults = computed(() => {
        if (!results.value) return [];

        return Object.entries(results.value)
            .filter(([, items]) => items.length > 0)
            .map(([type, items]) => ({
                type: type as keyof SearchResults,
                label: typeLabels[type as keyof SearchResults],
                icon: typeIcons[type as keyof SearchResults],
                items,
            }));
    });

    const search = useDebounceFn(async (searchQuery: string) => {
        if (!searchQuery || searchQuery.length < 1) {
            results.value = null;
            return;
        }

        isLoading.value = true;
        error.value = null;

        try {
            const response = await axios.get<SearchResponse>('/api/v1/search', {
                params: { q: searchQuery, limit: 5 },
            });
            results.value = response.data.results;
        } catch (err) {
            error.value = 'Failed to search. Please try again.';
            results.value = null;
        } finally {
            isLoading.value = false;
        }
    }, 300);

    const open = () => {
        isOpen.value = true;
    };

    const close = () => {
        isOpen.value = false;
        query.value = '';
        results.value = null;
        error.value = null;
    };

    const onQueryChange = (value: string) => {
        query.value = value;
        search(value);
    };

    return {
        query,
        results,
        isLoading,
        error,
        isOpen,
        hasResults,
        groupedResults,
        search,
        open,
        close,
        onQueryChange,
    };
}
