<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    ChevronUpIcon,
    ChevronDownIcon,
    MagnifyingGlassIcon,
    ArrowDownTrayIcon,
    PhotoIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';
import type { WidgetData } from '@/composables/useWidget';
import Pagination from './Pagination.vue';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import ProductQuickView from '@/components/products/ProductQuickView.vue';

// Lightbox state
const lightboxOpen = ref(false);
const lightboxImageUrl = ref<string | null>(null);
const lightboxImageAlt = ref<string>('');

function openLightbox(url: string, alt: string = '') {
    lightboxImageUrl.value = url;
    lightboxImageAlt.value = alt;
    lightboxOpen.value = true;
}

function closeLightbox() {
    lightboxOpen.value = false;
    lightboxImageUrl.value = null;
    lightboxImageAlt.value = '';
}

// Types for typed cells
interface TypedCell {
    type?: 'text' | 'link' | 'image' | 'badge' | 'status-badge' | 'tags' | 'currency' | 'review_action';
    data: unknown;
    href?: string;
    alt?: string;
    class?: string;
    variant?: 'default' | 'success' | 'warning' | 'danger' | 'secondary' | 'info' | 'primary';
    currency?: string;
    color?: string;
    tags?: TagData[];
    reviewed?: boolean;
    transaction_id?: number;
    item_id?: number;
}

interface TagData {
    id: number;
    name: string;
    color?: string;
}

interface BulkAction {
    key: string;
    label: string;
    icon?: string;
    variant?: 'default' | 'success' | 'warning' | 'danger' | 'info' | 'primary' | 'secondary';
    confirm?: string | null;
    type?: 'post' | 'modal';
    handler?: 'post' | 'navigate' | 'download';
    url?: string;
    config?: Record<string, unknown>;
}

interface ColumnTotal {
    key: string;
    format?: 'currency' | 'number' | 'integer';
    currency?: string;
    label?: string;
}

interface Props {
    data: WidgetData;
    loading?: boolean;
    bulkActionUrl?: string;
    enableQuickView?: boolean;
    quickViewField?: string;
    exportable?: boolean;
    exportFilename?: string | (() => string);
    showTotals?: boolean;
    totalColumns?: (string | ColumnTotal)[];
    perPageOptions?: number[];
    showPerPageSelector?: boolean;
    initialSearchTerm?: string;
}

const props = withDefaults(defineProps<Props>(), {
    loading: false,
    bulkActionUrl: '',
    enableQuickView: false,
    quickViewField: 'title',
    exportable: true,
    exportFilename: 'export',
    showTotals: false,
    totalColumns: () => [],
    perPageOptions: () => [15, 25, 50, 100],
    showPerPageSelector: true,
    initialSearchTerm: '',
});

const emit = defineEmits<{
    pageChange: [page: number];
    perPageChange: [perPage: number];
    sortChange: [field: string, desc: boolean];
    search: [term: string];
    filterChange: [filter: Record<string, unknown>];
    bulkAction: [action: string, ids: (number | string)[], config: Record<string, unknown>];
    bulkActionModal: [action: string, ids: (number | string)[]];
    reviewItem: [transactionId: number, itemId: number];
}>();

// Review action state
const reviewingItemId = ref<number | null>(null);

function handleReviewClick(transactionId: number, itemId: number) {
    if (confirm('Are you sure you want to mark this item as reviewed?')) {
        reviewingItemId.value = itemId;
        emit('reviewItem', transactionId, itemId);
    }
}

function clearReviewingState() {
    reviewingItemId.value = null;
}

const searchTerm = ref(props.initialSearchTerm);
const selectedItems = ref<Set<number | string>>(new Set());
const sortField = ref<string | null>(null);
const sortDesc = ref(false);

// Computed properties
const fields = computed(() => props.data.fields || []);
const items = computed(() => props.data.data?.items || []);
const options = computed(() => props.data.data?.options || {});
const pagination = computed(() => props.data.pagination);
const hasCheckBox = computed(() => props.data.hasCheckBox || options.value.hasCheckBox);
const isSearchable = computed(() => props.data.isSearchable);
const noDataMessage = computed(() => props.data.noData || 'No data available.');
const htmlFields = computed(() => options.value.htmlFormattedFields || []);
const actions = computed(() => (props.data.actions || []) as BulkAction[]);

// Get item ID (handles both simple and typed cell formats)
function getItemId(item: Record<string, unknown>): number | string {
    const idField = item.id;
    if (typeof idField === 'object' && idField !== null && 'data' in idField) {
        return (idField as TypedCell).data as number | string;
    }
    return idField as number | string;
}

// Get cell value (handles both simple and typed cell formats)
function getCellValue(item: Record<string, unknown>, key: string): TypedCell {
    const cell = item[key];
    if (typeof cell === 'object' && cell !== null && 'data' in cell) {
        return cell as TypedCell;
    }
    return { data: cell };
}

// Check if cell is a typed cell
function isTypedCell(cell: TypedCell): boolean {
    return !!cell.type;
}

// Format currency
function formatCurrency(value: number, currency = 'USD'): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
    }).format(value);
}

// Get badge classes based on variant
function getBadgeClasses(variant?: string): string {
    const baseClasses = 'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium';
    switch (variant) {
        case 'success':
            return `${baseClasses} bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20`;
        case 'warning':
            return `${baseClasses} bg-yellow-50 text-yellow-800 ring-1 ring-inset ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20`;
        case 'danger':
            return `${baseClasses} bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20`;
        case 'secondary':
            return `${baseClasses} bg-gray-50 text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20`;
        case 'info':
            return `${baseClasses} bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-700/10 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20`;
        case 'primary':
            return `${baseClasses} bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-700/10 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30`;
        default:
            return `${baseClasses} bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-700/10 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30`;
    }
}

// Get classes for status badge with custom color
function getStatusBadgeStyle(color?: string): Record<string, string> {
    if (!color) {
        return {};
    }
    return {
        backgroundColor: `${color}15`,
        color: color,
        border: `1px solid ${color}30`,
    };
}

// Get button variant classes
function getButtonVariantClasses(variant?: string): string {
    switch (variant) {
        case 'danger':
            return 'bg-red-600 text-white hover:bg-red-500 focus-visible:outline-red-600';
        case 'success':
            return 'bg-green-600 text-white hover:bg-green-500 focus-visible:outline-green-600';
        case 'warning':
            return 'bg-yellow-500 text-white hover:bg-yellow-400 focus-visible:outline-yellow-500';
        case 'info':
            return 'bg-blue-600 text-white hover:bg-blue-500 focus-visible:outline-blue-600';
        case 'primary':
            return 'bg-indigo-600 text-white hover:bg-indigo-500 focus-visible:outline-indigo-600';
        case 'secondary':
            return 'bg-gray-600 text-white hover:bg-gray-500 focus-visible:outline-gray-600';
        default:
            return 'bg-indigo-600 text-white hover:bg-indigo-500 focus-visible:outline-indigo-600';
    }
}

// Selection
const allSelected = computed(() => {
    if (items.value.length === 0) return false;
    return items.value.every((item) => selectedItems.value.has(getItemId(item)));
});

function toggleAll() {
    if (allSelected.value) {
        selectedItems.value.clear();
    } else {
        items.value.forEach((item) => {
            selectedItems.value.add(getItemId(item));
        });
    }
}

function toggleItem(id: number | string) {
    if (selectedItems.value.has(id)) {
        selectedItems.value.delete(id);
    } else {
        selectedItems.value.add(id);
    }
}

// Sorting
function handleSort(field: string, sortable: boolean) {
    if (!sortable) return;

    if (sortField.value === field) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortField.value = field;
        sortDesc.value = false;
    }

    emit('sortChange', field, sortDesc.value);
}

// Search
function handleSearch() {
    emit('search', searchTerm.value);
}

// Bulk actions
function handleBulkAction(action: BulkAction) {
    const ids = Array.from(selectedItems.value);
    if (ids.length === 0) return;

    // Check if action should open a modal instead of posting
    if (action.type === 'modal') {
        emit('bulkActionModal', action.key, ids);
        return;
    }

    if (action.confirm && !confirm(action.confirm)) {
        return;
    }

    const handler = action.handler || 'post';

    if (handler === 'navigate' && action.url) {
        const params = new URLSearchParams();
        ids.forEach(id => params.append('transactions[]', String(id)));
        const separator = action.url.includes('?') ? '&' : '?';
        window.location.href = `${action.url}${separator}${params.toString()}`;
        return;
    }

    if (handler === 'download' && action.url) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        fetch(action.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'text/csv',
            },
            body: JSON.stringify({ ids }),
        })
            .then(response => {
                if (!response.ok) throw new Error('Export failed');
                const disposition = response.headers.get('Content-Disposition');
                const filenameMatch = disposition?.match(/filename="?(.+?)"?$/);
                const filename = filenameMatch?.[1] || 'export.csv';
                return response.blob().then(blob => ({ blob, filename }));
            })
            .then(({ blob, filename }) => {
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
                selectedItems.value.clear();
            })
            .catch(() => {
                alert('Export failed. Please try again.');
            });
        return;
    }

    if (props.bulkActionUrl) {
        router.post(props.bulkActionUrl, {
            action: action.key,
            ids,
            config: action.config || {},
        }, {
            preserveScroll: true,
            onSuccess: () => {
                selectedItems.value.clear();
            },
        });
    } else {
        emit('bulkAction', action.key, ids, action.config || {});
    }
}

// Clear selection (exposed for parent components)
function clearSelection() {
    selectedItems.value.clear();
}

defineExpose({
    clearSelection,
    clearReviewingState,
});

// Check if field should render HTML
function isHtmlField(fieldKey: string): boolean {
    return (htmlFields.value as string[]).includes(fieldKey);
}

// Get field width
function getFieldWidth(fieldKey: string): string | undefined {
    const widths = options.value.fieldWidths as Record<string, string> | undefined;
    return widths?.[fieldKey];
}

// Get field min-width from options
function getFieldMinWidth(fieldKey: string): string | undefined {
    const minWidths = options.value.fieldMinWidths as Record<string, string> | undefined;
    return minWidths?.[fieldKey];
}

// Get combined style for field
function getFieldStyle(fieldKey: string): Record<string, string> {
    const style: Record<string, string> = {};
    const width = getFieldWidth(fieldKey);
    const minWidth = getFieldMinWidth(fieldKey);
    if (width) style.width = width;
    if (minWidth) style.minWidth = minWidth;
    return style;
}

// ===== EXPORT FUNCTIONALITY =====
const isExporting = ref(false);

function getColumnConfig(key: string): ColumnTotal | null {
    for (const col of props.totalColumns) {
        if (typeof col === 'string') {
            if (col === key) return { key };
        } else if (col.key === key) {
            return col;
        }
    }
    return null;
}

function shouldShowTotal(key: string): boolean {
    return getColumnConfig(key) !== null;
}

// Extract numeric value from a cell (handles typed cells and formatted strings)
function extractNumericValue(item: Record<string, unknown>, key: string): number {
    const cell = getCellValue(item, key);
    let value = cell.data;

    // Handle currency type cells
    if (cell.type === 'currency' && typeof value === 'number') {
        return value;
    }

    // Handle string values (strip currency symbols, commas)
    if (typeof value === 'string') {
        const cleaned = value.replace(/[^0-9.-]/g, '');
        return parseFloat(cleaned) || 0;
    }

    // Handle direct numbers
    if (typeof value === 'number') {
        return value;
    }

    return 0;
}

// Calculate column totals
const columnTotals = computed(() => {
    const totals: Record<string, number> = {};

    for (const col of props.totalColumns) {
        const key = typeof col === 'string' ? col : col.key;
        totals[key] = items.value.reduce((sum, item) => {
            return sum + extractNumericValue(item, key);
        }, 0);
    }

    return totals;
});

// Format total value based on column config
function formatTotal(key: string, value: number): string {
    const config = getColumnConfig(key);
    if (!config) return String(value);

    const format = config.format || 'currency';
    const currency = config.currency || 'USD';

    switch (format) {
        case 'currency':
            return formatCurrency(value, currency);
        case 'integer':
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
        case 'number':
        default:
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
    }
}

// Export to CSV
function exportToCsv() {
    if (items.value.length === 0) return;

    isExporting.value = true;

    try {
        // Get headers from fields
        const headers = fields.value.map(([, label]) => label);

        // Get rows
        const rows = items.value.map(item => {
            return fields.value.map(([key]) => {
                const cell = getCellValue(item, key);
                let value = cell.data;

                // Handle currency type
                if (cell.type === 'currency' && typeof value === 'number') {
                    return value.toFixed(2);
                }

                // Strip HTML tags for badge/html content
                if (typeof value === 'string') {
                    value = value.replace(/<[^>]*>/g, '').trim();
                }

                // Handle null/undefined
                if (value === null || value === undefined) {
                    return '';
                }

                return String(value);
            });
        });

        // Add totals row if enabled
        if (props.showTotals && props.totalColumns.length > 0) {
            const totalsRow = fields.value.map(([key], index) => {
                if (index === 0) return 'TOTALS';
                if (shouldShowTotal(key)) {
                    return columnTotals.value[key]?.toFixed(2) || '';
                }
                return '';
            });
            rows.push(totalsRow);
        }

        // Build CSV content
        const csvContent = [
            headers.map(h => `"${String(h).replace(/"/g, '""')}"`).join(','),
            ...rows.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')),
        ].join('\n');

        // Download
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        const timestamp = new Date().toISOString().split('T')[0];

        // Support filename as string or function
        const baseFilename = typeof props.exportFilename === 'function'
            ? props.exportFilename()
            : props.exportFilename;

        // Add timestamp if not already included, and .csv extension
        const filename = baseFilename.includes(timestamp)
            ? (baseFilename.endsWith('.csv') ? baseFilename : `${baseFilename}.csv`)
            : `${baseFilename}-${timestamp}.csv`;

        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } finally {
        isExporting.value = false;
    }
}
</script>

<template>
    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
        <!-- Header with title, search, and bulk actions -->
        <div class="border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ data.title }}
                    </h3>

                    <!-- Bulk actions -->
                    <div v-if="hasCheckBox && selectedItems.size > 0 && actions.length > 0" class="flex items-center gap-2">
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ selectedItems.size }} selected
                        </span>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="action in actions"
                                :key="action.key"
                                type="button"
                                :class="[
                                    'inline-flex items-center rounded-md px-2.5 py-1.5 text-sm font-semibold shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2',
                                    getButtonVariantClasses(action.variant),
                                ]"
                                @click="handleBulkAction(action)"
                            >
                                {{ action.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Search input -->
                    <div v-if="isSearchable" class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <MagnifyingGlassIcon class="size-5 text-gray-400" aria-hidden="true" />
                        </div>
                        <input
                            v-model="searchTerm"
                            type="search"
                            class="block w-full rounded-md border-0 bg-white py-1.5 pl-10 pr-3 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                            placeholder="Search..."
                            @keyup.enter="handleSearch"
                        />
                    </div>

                    <!-- Export button -->
                    <button
                        v-if="exportable && items.length > 0"
                        type="button"
                        :disabled="isExporting"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                        @click="exportToCsv"
                    >
                        <ArrowDownTrayIcon class="-ml-0.5 size-4" />
                        {{ isExporting ? 'Exporting...' : 'Export' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Loading overlay -->
        <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center bg-white/50 dark:bg-gray-900/50">
            <div class="size-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>

        <!-- Table -->
        <div class="relative overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <!-- Checkbox column -->
                        <th v-if="hasCheckBox" scope="col" class="relative px-7 sm:w-12 sm:px-6">
                            <input
                                type="checkbox"
                                :checked="allSelected"
                                :indeterminate="selectedItems.size > 0 && !allSelected"
                                class="absolute left-4 top-1/2 -mt-2 size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                @change="toggleAll"
                            />
                        </th>

                        <!-- Data columns -->
                        <th
                            v-for="[key, label, sortable] in fields"
                            :key="key"
                            scope="col"
                            :style="getFieldStyle(key)"
                            :class="[
                                'px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white',
                                sortable ? 'cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-800' : '',
                            ]"
                            @click="handleSort(key, sortable)"
                        >
                            <div class="group inline-flex items-center gap-1">
                                {{ label }}
                                <span v-if="sortable" class="ml-1 flex-none">
                                    <ChevronUpIcon
                                        v-if="sortField === key && !sortDesc"
                                        class="size-4 text-indigo-600"
                                    />
                                    <ChevronDownIcon
                                        v-else-if="sortField === key && sortDesc"
                                        class="size-4 text-indigo-600"
                                    />
                                    <ChevronUpIcon
                                        v-else
                                        class="size-4 text-gray-400 opacity-0 group-hover:opacity-100"
                                    />
                                </span>
                            </div>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                    <!-- Empty state -->
                    <tr v-if="items.length === 0">
                        <td
                            :colspan="fields.length + (hasCheckBox ? 1 : 0)"
                            class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                        >
                            {{ noDataMessage }}
                        </td>
                    </tr>

                    <!-- Data rows -->
                    <tr
                        v-for="item in items"
                        :key="getItemId(item)"
                        :class="[
                            selectedItems.has(getItemId(item)) ? 'bg-gray-50 dark:bg-gray-700/50' : '',
                            'hover:bg-gray-50 dark:hover:bg-gray-700/30',
                        ]"
                    >
                        <!-- Checkbox -->
                        <td v-if="hasCheckBox" class="relative px-7 sm:w-12 sm:px-6">
                            <input
                                type="checkbox"
                                :checked="selectedItems.has(getItemId(item))"
                                class="absolute left-4 top-1/2 -mt-2 size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                @change="toggleItem(getItemId(item))"
                            />
                        </td>

                        <!-- Data cells -->
                        <td
                            v-for="[key] in fields"
                            :key="key"
                            :class="[
                                'px-3 py-4 text-sm text-gray-500 dark:text-gray-300',
                                getCellValue(item, key).tags?.length ? '' : 'whitespace-nowrap',
                            ]"
                        >
                            <!-- Get the cell value -->
                            <template v-if="getCellValue(item, key).type === 'image'">
                                <div
                                    :class="[
                                        getCellValue(item, key).class || 'h-10 w-10 rounded-md bg-gray-100 dark:bg-gray-700 overflow-hidden',
                                        getCellValue(item, key).data ? 'cursor-pointer hover:ring-2 hover:ring-indigo-500 transition-all' : ''
                                    ]"
                                    @click="getCellValue(item, key).data && openLightbox(getCellValue(item, key).data as string, getCellValue(item, key).alt || '')"
                                >
                                    <img
                                        v-if="getCellValue(item, key).data"
                                        :src="getCellValue(item, key).data as string"
                                        :alt="getCellValue(item, key).alt || ''"
                                        class="h-full w-full object-cover"
                                    />
                                    <!-- Placeholder silhouette when no image -->
                                    <div
                                        v-else
                                        class="h-full w-full flex items-center justify-center"
                                    >
                                        <PhotoIcon class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                                    </div>
                                </div>
                            </template>

                            <template v-else-if="getCellValue(item, key).type === 'link'">
                                <div>
                                    <!-- With Quick View HoverCard -->
                                    <HoverCard v-if="enableQuickView && key === quickViewField" :open-delay="300" :close-delay="100">
                                        <HoverCardTrigger as-child>
                                            <Link
                                                :href="getCellValue(item, key).href || '#'"
                                                :class="[
                                                    'text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300',
                                                    getCellValue(item, key).class,
                                                ]"
                                            >
                                                {{ getCellValue(item, key).data }}
                                            </Link>
                                        </HoverCardTrigger>
                                        <HoverCardContent side="right" :side-offset="8">
                                            <ProductQuickView :product-id="getItemId(item) as number" />
                                        </HoverCardContent>
                                    </HoverCard>
                                    <!-- Without Quick View -->
                                    <Link
                                        v-else
                                        :href="getCellValue(item, key).href || '#'"
                                        :class="[
                                            'text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300',
                                            getCellValue(item, key).class,
                                        ]"
                                    >
                                        {{ getCellValue(item, key).data }}
                                    </Link>
                                    <!-- Tags underneath the link -->
                                    <div v-if="getCellValue(item, key).tags && getCellValue(item, key).tags!.length > 0" class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-for="tag in getCellValue(item, key).tags"
                                            :key="tag.id"
                                            class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                            :style="tag.color ? { backgroundColor: `${tag.color}20`, color: tag.color } : {}"
                                        >
                                            <span class="size-1 rounded-full" :style="tag.color ? { backgroundColor: tag.color } : {}"></span>
                                            {{ tag.name }}
                                        </span>
                                    </div>
                                </div>
                            </template>

                            <template v-else-if="getCellValue(item, key).type === 'badge'">
                                <span :class="getBadgeClasses(getCellValue(item, key).variant)">
                                    {{ getCellValue(item, key).data }}
                                </span>
                            </template>

                            <template v-else-if="getCellValue(item, key).type === 'currency'">
                                <span :class="getCellValue(item, key).class">
                                    {{ formatCurrency(getCellValue(item, key).data as number, getCellValue(item, key).currency) }}
                                </span>
                            </template>

                            <template v-else-if="getCellValue(item, key).type === 'status-badge'">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium"
                                    :style="getStatusBadgeStyle(getCellValue(item, key).color)"
                                >
                                    {{ getCellValue(item, key).data }}
                                </span>
                            </template>

                            <template v-else-if="getCellValue(item, key).type === 'tags'">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="tag in (getCellValue(item, key).data as TagData[])"
                                        :key="tag.id"
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                        :style="tag.color ? { backgroundColor: `${tag.color}20`, color: tag.color } : {}"
                                    >
                                        {{ tag.name }}
                                    </span>
                                    <span v-if="(getCellValue(item, key).data as TagData[])?.length === 0" class="text-gray-400">
                                        —
                                    </span>
                                </div>
                            </template>

                            <template v-else-if="getCellValue(item, key).type === 'review_action'">
                                <!-- Already reviewed - show date -->
                                <span
                                    v-if="getCellValue(item, key).reviewed"
                                    class="text-xs text-green-600 dark:text-green-400"
                                    :title="getCellValue(item, key).data as string"
                                >
                                    Reviewed {{ getCellValue(item, key).data }}
                                </span>
                                <!-- Not reviewed - show button -->
                                <button
                                    v-else
                                    type="button"
                                    :disabled="reviewingItemId === getCellValue(item, key).item_id"
                                    class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10 hover:bg-indigo-100 disabled:opacity-50 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30 dark:hover:bg-indigo-400/20"
                                    @click="handleReviewClick(getCellValue(item, key).transaction_id as number, getCellValue(item, key).item_id as number)"
                                >
                                    {{ reviewingItemId === getCellValue(item, key).item_id ? 'Reviewing...' : 'Review' }}
                                </button>
                            </template>

                            <!-- HTML content -->
                            <template v-else-if="isHtmlField(key)">
                                <span v-html="getCellValue(item, key).data" />
                            </template>

                            <!-- Regular content -->
                            <template v-else>
                                <span :class="getCellValue(item, key).class">{{ getCellValue(item, key).data }}</span>
                            </template>
                        </td>
                    </tr>
                </tbody>

                <!-- Totals footer -->
                <tfoot v-if="showTotals && totalColumns.length > 0 && items.length > 0" class="bg-gray-50 dark:bg-gray-900/50 border-t-2 border-gray-300 dark:border-gray-600">
                    <tr>
                        <!-- Empty checkbox column -->
                        <td v-if="hasCheckBox" class="px-7 sm:w-12 sm:px-6" />

                        <!-- Totals cells -->
                        <td
                            v-for="([key, label], index) in fields"
                            :key="`total-${key}`"
                            class="whitespace-nowrap px-3 py-3.5 text-sm font-semibold text-gray-900 dark:text-white"
                        >
                            <template v-if="index === 0">
                                Totals
                            </template>
                            <template v-else-if="shouldShowTotal(key)">
                                {{ formatTotal(key, columnTotals[key] || 0) }}
                            </template>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Pagination -->
        <Pagination
            v-if="pagination && pagination.show_pagination && pagination.total > 0"
            :pagination="pagination"
            :per-page-options="perPageOptions"
            :show-per-page-selector="showPerPageSelector"
            @page-change="(page) => emit('pageChange', page)"
            @per-page-change="(perPage) => emit('perPageChange', perPage)"
        />
    </div>

    <!-- Lightbox Modal -->
    <Teleport to="body">
        <div
            v-if="lightboxOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80"
            @click.self="closeLightbox"
            @keydown.escape="closeLightbox"
        >
            <!-- Close button -->
            <button
                type="button"
                class="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20 transition-colors"
                @click="closeLightbox"
            >
                <XMarkIcon class="h-6 w-6" />
                <span class="sr-only">Close</span>
            </button>

            <!-- Image -->
            <img
                v-if="lightboxImageUrl"
                :src="lightboxImageUrl"
                :alt="lightboxImageAlt"
                class="max-h-[90vh] max-w-[90vw] object-contain rounded-lg shadow-2xl"
            />
        </div>
    </Teleport>
</template>
