<script setup lang="ts">
import { onMounted, computed } from 'vue';
import { useWidget, type WidgetFilter } from '@/composables/useWidget';
import DataTable from './DataTable.vue';
import ChartWidget from './ChartWidget.vue';
import CardWidget from './CardWidget.vue';
import FormWidget from './FormWidget.vue';
import TableSkeleton from './TableSkeleton.vue';

interface Props {
    type: string;
    filter?: WidgetFilter;
}

const props = withDefaults(defineProps<Props>(), {
    filter: () => ({}),
});

const { data, loading, error, loadWidget, updateFilter, setPage, setSort, setSearch } = useWidget(
    props.type,
    props.filter
);

// Map backend component names to Vue components
const componentMap: Record<string, unknown> = {
    Table: DataTable,
    Chart: ChartWidget,
    Card: CardWidget,
    FormWidget: FormWidget,
};

const currentComponent = computed(() => {
    if (!data.value) return null;
    return componentMap[data.value.component] || DataTable;
});

onMounted(() => {
    loadWidget();
});

// Expose methods for parent components
defineExpose({
    loadWidget,
    updateFilter,
    setPage,
    setSort,
    setSearch,
});
</script>

<template>
    <div>
        <!-- Loading state -->
        <TableSkeleton v-if="loading && !data" />

        <!-- Error state -->
        <div v-else-if="error" class="rounded-md bg-red-50 p-4 dark:bg-red-900/20">
            <div class="flex">
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800 dark:text-red-200">Error loading widget</h3>
                    <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                        {{ error }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Widget content -->
        <component
            v-else-if="data && currentComponent"
            :is="currentComponent"
            :data="data"
            :loading="loading"
            @page-change="setPage"
            @sort-change="(field: string, desc: boolean) => setSort(field, desc)"
            @search="setSearch"
            @filter-change="updateFilter"
        />
    </div>
</template>
