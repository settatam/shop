<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue';
import { ChartBarIcon } from '@heroicons/vue/24/outline';

interface Dataset {
    label: string;
    data: number[];
    borderColor: string;
    backgroundColor: string;
    fill: boolean;
}

interface ChartData {
    labels: string[];
    datasets: Dataset[];
    ordersData?: number[];
}

interface Props {
    data: ChartData;
}

const props = defineProps<Props>();
const canvasRef = ref<HTMLCanvasElement | null>(null);

const totalRevenue = computed(() => {
    if (!props.data.datasets?.[0]?.data) return 0;
    return props.data.datasets[0].data.reduce((sum, val) => sum + val, 0);
});

const totalOrders = computed(() => {
    if (!props.data.ordersData) return 0;
    return props.data.ordersData.reduce((sum, val) => sum + val, 0);
});

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}

function drawChart() {
    const canvas = canvasRef.value;
    if (!canvas || !props.data.labels?.length) return;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    // Get device pixel ratio for sharp rendering
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();

    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);

    const width = rect.width;
    const height = rect.height;
    const padding = { top: 20, right: 20, bottom: 30, left: 60 };
    const chartWidth = width - padding.left - padding.right;
    const chartHeight = height - padding.top - padding.bottom;

    // Clear canvas
    ctx.clearRect(0, 0, width, height);

    const data = props.data.datasets[0]?.data || [];
    if (data.length === 0) return;

    const maxValue = Math.max(...data) || 1;
    const minValue = 0;

    // Draw grid lines and y-axis labels
    const isDark = document.documentElement.classList.contains('dark');
    ctx.strokeStyle = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
    ctx.lineWidth = 1;
    ctx.fillStyle = isDark ? '#9ca3af' : '#6b7280';
    ctx.font = '11px system-ui';
    ctx.textAlign = 'right';

    const gridLines = 5;
    for (let i = 0; i <= gridLines; i++) {
        const y = padding.top + (chartHeight / gridLines) * i;
        const value = maxValue - (maxValue / gridLines) * i;

        // Grid line
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(width - padding.right, y);
        ctx.stroke();

        // Y-axis label
        ctx.fillText(formatCurrency(value), padding.left - 10, y + 4);
    }

    // Draw area fill
    const gradient = ctx.createLinearGradient(0, padding.top, 0, height - padding.bottom);
    gradient.addColorStop(0, 'rgba(99, 102, 241, 0.2)');
    gradient.addColorStop(1, 'rgba(99, 102, 241, 0)');

    ctx.beginPath();
    ctx.moveTo(padding.left, height - padding.bottom);

    data.forEach((value, index) => {
        const x = padding.left + (chartWidth / (data.length - 1)) * index;
        const y = padding.top + chartHeight - (chartHeight * (value - minValue)) / (maxValue - minValue || 1);
        if (index === 0) {
            ctx.lineTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });

    ctx.lineTo(padding.left + chartWidth, height - padding.bottom);
    ctx.closePath();
    ctx.fillStyle = gradient;
    ctx.fill();

    // Draw line
    ctx.beginPath();
    ctx.strokeStyle = 'rgb(99, 102, 241)';
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';

    data.forEach((value, index) => {
        const x = padding.left + (chartWidth / (data.length - 1)) * index;
        const y = padding.top + chartHeight - (chartHeight * (value - minValue)) / (maxValue - minValue || 1);
        if (index === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });
    ctx.stroke();

    // Draw data points
    data.forEach((value, index) => {
        const x = padding.left + (chartWidth / (data.length - 1)) * index;
        const y = padding.top + chartHeight - (chartHeight * (value - minValue)) / (maxValue - minValue || 1);

        ctx.beginPath();
        ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fillStyle = 'rgb(99, 102, 241)';
        ctx.fill();
        ctx.strokeStyle = isDark ? '#1f2937' : '#ffffff';
        ctx.lineWidth = 2;
        ctx.stroke();
    });
}

onMounted(() => {
    drawChart();
    window.addEventListener('resize', drawChart);
});

watch(() => props.data, drawChart, { deep: true });
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Sales Overview</h3>
                <span class="text-sm text-gray-500 dark:text-gray-400">Last 30 days</span>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Revenue</p>
                    <p class="text-2xl font-semibold text-gray-900 dark:text-white">
                        {{ formatCurrency(totalRevenue) }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Orders</p>
                    <p class="text-2xl font-semibold text-gray-900 dark:text-white">
                        {{ totalOrders.toLocaleString() }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="px-4 py-6 sm:px-6">
            <div v-if="!data.labels?.length" class="flex h-64 items-center justify-center">
                <div class="text-center">
                    <ChartBarIcon class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" />
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No sales data available</p>
                </div>
            </div>
            <div v-else class="h-64">
                <canvas ref="canvasRef" class="h-full w-full" />
            </div>
        </div>
    </div>
</template>
