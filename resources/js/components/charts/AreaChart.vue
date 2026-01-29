<script setup lang="ts">
import { ref, onMounted, watch, onUnmounted } from 'vue';

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
}

const props = withDefaults(defineProps<Props>(), {
    height: 200,
    showLegend: true,
    formatValue: (v: number) => v.toLocaleString(),
});

const canvasRef = ref<HTMLCanvasElement | null>(null);
const tooltipRef = ref<HTMLDivElement | null>(null);
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

    // Find max value across all datasets
    const allValues = props.datasets.flatMap(d => d.data);
    const maxValue = Math.max(...allValues) || 1;
    const minValue = 0;

    // Draw grid and labels
    const isDark = document.documentElement.classList.contains('dark');
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

    // Draw x-axis labels
    ctx.textAlign = 'center';
    const labelStep = Math.ceil(props.labels.length / 10);
    props.labels.forEach((label, index) => {
        if (index % labelStep === 0 || index === props.labels.length - 1) {
            const x = padding.left + (chartWidth / (props.labels.length - 1)) * index;
            ctx.fillText(label, x, height - padding.bottom + 20);
        }
    });

    // Draw datasets
    props.datasets.forEach((dataset, datasetIndex) => {
        const color = dataset.color || colors[datasetIndex % colors.length];

        // Area fill
        const gradient = ctx.createLinearGradient(0, padding.top, 0, height - padding.bottom);
        gradient.addColorStop(0, color + '30');
        gradient.addColorStop(1, color + '00');

        ctx.beginPath();
        ctx.moveTo(padding.left, height - padding.bottom);

        dataset.data.forEach((value, index) => {
            const x = padding.left + (chartWidth / (dataset.data.length - 1)) * index;
            const y = padding.top + chartHeight - (chartHeight * (value - minValue)) / (maxValue - minValue || 1);
            ctx.lineTo(x, y);
        });

        ctx.lineTo(padding.left + chartWidth, height - padding.bottom);
        ctx.closePath();
        ctx.fillStyle = gradient;
        ctx.fill();

        // Line
        ctx.beginPath();
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';

        dataset.data.forEach((value, index) => {
            const x = padding.left + (chartWidth / (dataset.data.length - 1)) * index;
            const y = padding.top + chartHeight - (chartHeight * (value - minValue)) / (maxValue - minValue || 1);
            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });
        ctx.stroke();
    });
}

function handleMouseMove(event: MouseEvent) {
    const canvas = canvasRef.value;
    if (!canvas || !props.labels.length) return;

    const rect = canvas.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const padding = { left: 60, right: 20 };
    const chartWidth = rect.width - padding.left - padding.right;

    const index = Math.round(((x - padding.left) / chartWidth) * (props.labels.length - 1));
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
                    class="size-3 rounded-full"
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
                ref="tooltipRef"
                class="pointer-events-none fixed z-50 rounded-lg bg-gray-900 px-3 py-2 text-sm text-white shadow-lg dark:bg-gray-700"
                :style="{ left: tooltipData.x + 10 + 'px', top: tooltipData.y - 10 + 'px' }"
            >
                <div class="font-medium">{{ tooltipData.label }}</div>
                <div v-for="item in tooltipData.values" :key="item.label" class="flex items-center gap-2 mt-1">
                    <span class="size-2 rounded-full" :style="{ backgroundColor: item.color }" />
                    <span class="text-gray-300">{{ item.label }}:</span>
                    <span class="font-medium">{{ item.value }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
