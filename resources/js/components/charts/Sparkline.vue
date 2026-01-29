<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue';

interface Props {
    data: number[];
    color?: string;
    height?: number;
    width?: number;
    showArea?: boolean;
    strokeWidth?: number;
}

const props = withDefaults(defineProps<Props>(), {
    color: '#6366f1',
    height: 32,
    width: 100,
    showArea: true,
    strokeWidth: 1.5,
});

const canvasRef = ref<HTMLCanvasElement | null>(null);

const trend = computed(() => {
    if (props.data.length < 2) return 0;
    const first = props.data[0];
    const last = props.data[props.data.length - 1];
    if (first === 0) return last > 0 ? 100 : 0;
    return ((last - first) / Math.abs(first)) * 100;
});

function drawSparkline() {
    const canvas = canvasRef.value;
    if (!canvas || props.data.length < 2) return;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const dpr = window.devicePixelRatio || 1;
    canvas.width = props.width * dpr;
    canvas.height = props.height * dpr;
    ctx.scale(dpr, dpr);

    const width = props.width;
    const height = props.height;
    const padding = 2;

    ctx.clearRect(0, 0, width, height);

    const data = props.data;
    const maxValue = Math.max(...data);
    const minValue = Math.min(...data);
    const range = maxValue - minValue || 1;

    const points: { x: number; y: number }[] = data.map((value, index) => ({
        x: padding + (index / (data.length - 1)) * (width - padding * 2),
        y: padding + (1 - (value - minValue) / range) * (height - padding * 2),
    }));

    // Draw area fill
    if (props.showArea) {
        ctx.beginPath();
        ctx.moveTo(points[0].x, height - padding);
        points.forEach(point => ctx.lineTo(point.x, point.y));
        ctx.lineTo(points[points.length - 1].x, height - padding);
        ctx.closePath();

        const gradient = ctx.createLinearGradient(0, 0, 0, height);
        gradient.addColorStop(0, props.color + '40');
        gradient.addColorStop(1, props.color + '00');
        ctx.fillStyle = gradient;
        ctx.fill();
    }

    // Draw line
    ctx.beginPath();
    ctx.strokeStyle = props.color;
    ctx.lineWidth = props.strokeWidth;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';

    points.forEach((point, index) => {
        if (index === 0) {
            ctx.moveTo(point.x, point.y);
        } else {
            ctx.lineTo(point.x, point.y);
        }
    });
    ctx.stroke();
}

onMounted(drawSparkline);
watch(() => props.data, drawSparkline, { deep: true });
</script>

<template>
    <canvas
        ref="canvasRef"
        :style="{ width: width + 'px', height: height + 'px' }"
        class="block"
    />
</template>
