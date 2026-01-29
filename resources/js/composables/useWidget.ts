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

export function useWidget(type: string, initialFilter: WidgetFilter = {}) {
    const data = ref<WidgetData | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const filter: Ref<WidgetFilter> = ref({ ...initialFilter });

    let cancelToken: AbortController | null = null;

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

    // Watch filter changes and reload
    watch(
        filter,
        () => {
            loadWidget();
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
    };
}
