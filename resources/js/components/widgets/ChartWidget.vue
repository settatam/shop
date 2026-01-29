<script setup lang="ts">
import { computed } from 'vue';
import type { WidgetData } from '@/composables/useWidget';

interface Props {
    data: WidgetData;
    loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    loading: false,
});

const chartConfig = computed(() => props.data.data || {});
const chartType = computed(() => chartConfig.value.chartType || 'bar');
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
            {{ data.title }}
        </h3>

        <!-- Placeholder for chart - integrate with Chart.js later -->
        <div class="mt-4 flex h-64 items-center justify-center rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600">
            <div class="text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Chart: {{ chartType }}
                </p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Integrate Chart.js for visualization
                </p>
            </div>
        </div>
    </div>
</template>
