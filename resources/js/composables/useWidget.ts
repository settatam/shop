import { ref, watch, type Ref } from 'vue';
import axios from 'axios';

export interface WidgetFilter {
    [key: string]: unknown;
    page?: number;
    per_page?: number;
    sortBy?: string;
    sortDesc?: boolean;
    term?: string;
    status?: string;
}

export interface WidgetData {
    widget: string;
    title: string;
    component: string;
    data: {
        fields?: Array<[string, string, boolean]>;
        items?: Array<Record<string, unknown>>;
        options?: Record<string, unknown>;
        [key: string]: unknown;
    };
    pagination?: {
        total: number;
        per_page: number;
        current_page: number;
        from: number;
        to: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        show_pagination: boolean;
    };
    filter?: Record<string, unknown>;
    fields?: Array<[string, string, boolean]>;
    hasCheckBox?: boolean;
    isSearchable?: boolean;
    noData?: string;
    [key: string]: unknown;
}

export interface UseWidgetOptions {
    syncToUrl?: boolean;
    urlParamMapping?: Record<string, string>; // Maps filter keys to URL param names
}

export function useWidget(type: string, initialFilter: WidgetFilter = {}, options: UseWidgetOptions = {}) {
    const { syncToUrl = false, urlParamMapping = {} } = options;

    const data = ref<WidgetData | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const filter: Ref<WidgetFilter> = ref({ ...initialFilter });

    let cancelToken: AbortController | null = null;

    // Sync filter state to URL
    function syncFilterToUrl() {
        if (!syncToUrl) return;

        const url = new URL(window.location.href);
        const params = url.searchParams;

        // Clear existing filter params (but keep non-filter params)
        const keysToRemove: string[] = [];
        params.forEach((_, key) => {
            // Check if this is a filter-related param
            if (Object.keys(filter.value).includes(key) || Object.values(urlParamMapping).includes(key)) {
                keysToRemove.push(key);
            }
        });
        keysToRemove.forEach(key => params.delete(key));

        // Add current filter values
        Object.entries(filter.value).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '' && key !== 'page') {
                const urlKey = urlParamMapping[key] || key;
                params.set(urlKey, String(value));
            }
        });

        // Update URL without reloading
        const newUrl = params.toString() ? `${url.pathname}?${params.toString()}` : url.pathname;
        window.history.replaceState({}, '', newUrl);
    }

    async function loadWidget() {
        // Cancel previous request if still pending
        if (cancelToken) {
            cancelToken.abort();
        }
        cancelToken = new AbortController();

        loading.value = true;
        error.value = null;

        try {
            const params = new URLSearchParams();
            params.append('type', type);

            // Add all filter params
            Object.entries(filter.value).forEach(([key, value]) => {
                if (value !== undefined && value !== null && value !== '') {
                    params.append(key, String(value));
                }
            });

            const response = await axios.get<WidgetData>(`/widgets/view?${params.toString()}`, {
                signal: cancelToken.signal,
            });

            data.value = response.data;
        } catch (err) {
            if (axios.isCancel(err)) {
                return; // Request was cancelled, don't update state
            }
            error.value = err instanceof Error ? err.message : 'Failed to load widget';
            console.error('Widget load error:', err);
        } finally {
            loading.value = false;
        }
    }

    function updateFilter(newFilter: Partial<WidgetFilter>) {
        filter.value = { ...filter.value, ...newFilter };
    }

    function setPage(page: number) {
        filter.value.page = page;
    }

    function setSort(field: string, desc: boolean) {
        filter.value.sortBy = field;
        filter.value.sortDesc = desc;
    }

    function setSearch(term: string) {
        filter.value.term = term;
        filter.value.page = 1; // Reset to first page on search
    }

    function setPerPage(perPage: number) {
        filter.value.per_page = perPage;
        filter.value.page = 1; // Reset to first page when changing page size
    }

    // Watch filter changes and reload
    watch(
        filter,
        () => {
            loadWidget();
            syncFilterToUrl();
        },
        { deep: true }
    );

    return {
        data,
        loading,
        error,
        filter,
        loadWidget,
        updateFilter,
        setPage,
        setSort,
        setSearch,
        setPerPage,
        syncFilterToUrl,
    };
}
