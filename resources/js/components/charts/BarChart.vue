<script setup lang="ts">
import { ref, onMounted, watch, onUnmounted, computed } from 'vue';

interface Props {
    labels: string[];
    datasets: {
        label: string;
        data: number[];
        color?: string;
    }[];
    height?: number;
    showLegend?: boolean;
    formatValue?: (value: number) => string;
    horizontal?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    height: 200,
    showLegend: true,
    formatValue: (v: number) => v.toLocaleString(),
    horizontal: false,
});

const canvasRef = ref<HTMLCanvasElement | null>(null);
const tooltipData = ref<{ x: number; y: number; label: string; values: { label: string; value: string; color: string }[] } | null>(null);

const colors = ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'];

function drawChart() {
    const canvas = canvasRef.value;
    if (!canvas || !props.labels.length) return;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);

    const width = rect.width;
    const height = rect.height;
    const padding = { top: 20, right: 20, bottom: 40, left: 60 };
    const chartWidth = width - padding.left - padding.right;
    const chartHeight = height - padding.top - padding.bottom;

    ctx.clearRect(0, 0, width, height);

    const isDark = document.documentElement.classList.contains('dark');
    const allValues = props.datasets.flatMap(d => d.data);
    const maxValue = Math.max(...allValues) || 1;

    // Draw grid
    ctx.strokeStyle = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
    ctx.fillStyle = isDark ? '#9ca3af' : '#6b7280';
    ctx.font = '11px system-ui';
    ctx.textAlign = 'right';

    const gridLines = 5;
    for (let i = 0; i <= gridLines; i++) {
        const y = padding.top + (chartHeight / gridLines) * i;
        const value = maxValue - (maxValue / gridLines) * i;

        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(width - padding.right, y);
        ctx.stroke();

        ctx.fillText(props.formatValue(value), padding.left - 10, y + 4);
    }

    // Draw bars
    const numGroups = props.labels.length;
    const numBars = props.datasets.length;
    const groupWidth = chartWidth / numGroups;
    const barWidth = (groupWidth * 0.8) / numBars;
    const groupPadding = groupWidth * 0.1;

    props.datasets.forEach((dataset, datasetIndex) => {
        const color = dataset.color || colors[datasetIndex % colors.length];

        dataset.data.forEach((value, index) => {
            const x = padding.left + groupPadding + index * groupWidth + datasetIndex * barWidth;
            const barHeight = (value / maxValue) * chartHeight;
            const y = padding.top + chartHeight - barHeight;

            // Draw bar with rounded top
            ctx.beginPath();
            ctx.fillStyle = color;
            const radius = Math.min(4, barWidth / 2);
            ctx.roundRect(x, y, barWidth - 2, barHeight, [radius, radius, 0, 0]);
            ctx.fill();
        });
    });

    // Draw x-axis labels
    ctx.textAlign = 'center';
    ctx.fillStyle = isDark ? '#9ca3af' : '#6b7280';
    props.labels.forEach((label, index) => {
        const x = padding.left + groupPadding + index * groupWidth + (groupWidth * 0.8) / 2;
        ctx.fillText(label.length > 10 ? label.slice(0, 10) + '...' : label, x, height - padding.bottom + 20);
    });
}

function handleMouseMove(event: MouseEvent) {
    const canvas = canvasRef.value;
    if (!canvas || !props.labels.length) return;

    const rect = canvas.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const padding = { left: 60, right: 20 };
    const chartWidth = rect.width - padding.left - padding.right;
    const groupWidth = chartWidth / props.labels.length;

    const index = Math.floor((x - padding.left) / groupWidth);
    if (index >= 0 && index < props.labels.length) {
        tooltipData.value = {
            x: event.clientX,
            y: event.clientY,
            label: props.labels[index],
            values: props.datasets.map((dataset, i) => ({
                label: dataset.label,
                value: props.formatValue(dataset.data[index]),
                color: dataset.color || colors[i % colors.length],
            })),
        };
    }
}

function handleMouseLeave() {
    tooltipData.value = null;
}

onMounted(() => {
    drawChart();
    window.addEventListener('resize', drawChart);
});

onUnmounted(() => {
    window.removeEventListener('resize', drawChart);
});

watch(() => [props.labels, props.datasets], drawChart, { deep: true });
</script>

<template>
    <div class="relative">
        <!-- Legend -->
        <div v-if="showLegend && datasets.length > 1" class="mb-4 flex flex-wrap gap-4">
            <div v-for="(dataset, index) in datasets" :key="dataset.label" class="flex items-center gap-2">
                <span
                    class="size-3 rounded"
                    :style="{ backgroundColor: dataset.color || colors[index % colors.length] }"
                />
                <span class="text-sm text-gray-600 dark:text-gray-400">{{ dataset.label }}</span>
            </div>
        </div>

        <!-- Chart -->
        <div class="relative" :style="{ height: height + 'px' }">
            <canvas
                ref="canvasRef"
                class="h-full w-full"
                @mousemove="handleMouseMove"
                @mouseleave="handleMouseLeave"
            />

            <!-- Tooltip -->
            <div
                v-if="tooltipData"
                class="pointer-events-none fixed z-50 rounded-lg bg-gray-900 px-3 py-2 text-sm text-white shadow-lg dark:bg-gray-700"
                :style="{ left: tooltipData.x + 10 + 'px', top: tooltipData.y - 10 + 'px' }"
            >
                <div class="font-medium">{{ tooltipData.label }}</div>
                <div v-for="item in tooltipData.values" :key="item.label" class="flex items-center gap-2 mt-1">
                    <span class="size-2 rounded" :style="{ backgroundColor: item.color }" />
                    <span class="text-gray-300">{{ item.label }}:</span>
                    <span class="font-medium">{{ item.value }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
