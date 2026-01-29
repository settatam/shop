<script setup lang="ts">
interface Stat {
    name: string;
    value: number;
    change: string | null;
    changeType: 'positive' | 'negative' | 'neutral';
    format: 'currency' | 'number' | 'percent';
}

interface Props {
    stats: Stat[];
}

defineProps<Props>();

function formatValue(value: number, format: string): string {
    if (format === 'currency') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(value);
    }
    if (format === 'percent') {
        return `${value}%`;
    }
    return new Intl.NumberFormat('en-US').format(value);
}
</script>

<template>
    <div class="border-b border-b-gray-900/10 lg:border-t lg:border-t-gray-900/5 dark:border-b-white/10 dark:lg:border-t-white/5">
        <dl class="mx-auto grid max-w-7xl grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 lg:px-2 xl:px-0">
            <div
                v-for="(stat, statIdx) in stats"
                :key="stat.name"
                :class="[
                    statIdx % 2 === 1 ? 'sm:border-l' : statIdx === 2 ? 'lg:border-l' : '',
                    'flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2 border-t border-gray-900/5 px-4 py-10 sm:px-6 lg:border-t-0 xl:px-8 dark:border-white/5',
                ]"
            >
                <dt class="text-sm/6 font-medium text-gray-500 dark:text-gray-400">
                    {{ stat.name }}
                </dt>
                <dd
                    v-if="stat.change"
                    :class="[
                        stat.changeType === 'negative' ? 'text-rose-600 dark:text-rose-400' : 'text-gray-700 dark:text-gray-300',
                        'text-xs font-medium',
                    ]"
                >
                    {{ stat.change }}
                </dd>
                <dd class="w-full flex-none text-3xl/10 font-medium tracking-tight text-gray-900 dark:text-white">
                    {{ formatValue(stat.value, stat.format) }}
                </dd>
            </div>
        </dl>
    </div>
</template>
