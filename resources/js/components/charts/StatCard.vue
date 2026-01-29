<script setup lang="ts">
import { computed } from 'vue';
import Sparkline from './Sparkline.vue';
import { ArrowTrendingUpIcon, ArrowTrendingDownIcon } from '@heroicons/vue/20/solid';

interface Props {
    title: string;
    value: string | number;
    trend?: number;
    trendLabel?: string;
    sparklineData?: number[];
    sparklineColor?: string;
    icon?: object;
}

const props = withDefaults(defineProps<Props>(), {
    trend: 0,
    trendLabel: 'vs last period',
});

const formattedTrend = computed(() => {
    const sign = props.trend >= 0 ? '+' : '';
    return `${sign}${props.trend.toFixed(1)}%`;
});

const trendColor = computed(() => {
    if (props.trend > 0) return 'text-green-600 dark:text-green-400';
    if (props.trend < 0) return 'text-red-600 dark:text-red-400';
    return 'text-gray-500 dark:text-gray-400';
});

const sparklineColorComputed = computed(() => {
    if (props.sparklineColor) return props.sparklineColor;
    if (props.trend > 0) return '#22c55e';
    if (props.trend < 0) return '#ef4444';
    return '#6366f1';
});
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="p-5">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ title }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ value }}</p>
                    <div v-if="trend !== undefined" class="mt-1 flex items-center gap-1">
                        <component
                            :is="trend >= 0 ? ArrowTrendingUpIcon : ArrowTrendingDownIcon"
                            class="size-4"
                            :class="trendColor"
                        />
                        <span class="text-sm" :class="trendColor">{{ formattedTrend }}</span>
                        <span v-if="trendLabel" class="text-xs text-gray-500 dark:text-gray-400">{{ trendLabel }}</span>
                    </div>
                </div>
                <div v-if="sparklineData && sparklineData.length > 1" class="ml-4">
                    <Sparkline :data="sparklineData" :color="sparklineColorComputed" :width="80" :height="40" />
                </div>
                <div v-else-if="icon" class="ml-4">
                    <component :is="icon" class="size-10 text-gray-400 dark:text-gray-500" />
                </div>
            </div>
        </div>
    </div>
</template>
