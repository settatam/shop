<script setup lang="ts">
import { computed } from 'vue';

interface StatusHistory {
    id: number;
    from_status: string | null;
    to_status: string;
    notes: string | null;
    created_at: string;
}

const props = defineProps<{
    histories: StatusHistory[];
    statuses: Record<string, string>;
    currentStatus: string;
}>();

const timelineItems = computed(() => {
    return props.histories.map((h, index) => ({
        ...h,
        label: props.statuses[h.to_status] ?? h.to_status,
        isCurrent: index === props.histories.length - 1,
        date: new Date(h.created_at).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }),
    }));
});
</script>

<template>
    <div class="flow-root">
        <ul class="-mb-8">
            <li v-for="(item, index) in timelineItems" :key="item.id" class="relative pb-8">
                <!-- Connector line -->
                <span
                    v-if="index !== timelineItems.length - 1"
                    class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-700"
                />

                <div class="relative flex gap-3">
                    <!-- Dot -->
                    <div>
                        <span
                            :class="[
                                'flex h-8 w-8 items-center justify-center rounded-full ring-8 ring-white dark:ring-gray-800',
                                item.isCurrent
                                    ? 'bg-indigo-600'
                                    : 'bg-gray-400 dark:bg-gray-600',
                            ]"
                        >
                            <svg
                                v-if="item.isCurrent"
                                class="h-4 w-4 text-white"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                            >
                                <circle cx="10" cy="10" r="5" />
                            </svg>
                            <svg
                                v-else
                                class="h-4 w-4 text-white"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                    </div>

                    <!-- Content -->
                    <div class="flex min-w-0 flex-1 justify-between gap-4 pt-1.5">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ item.label }}
                            </p>
                            <p v-if="item.notes" class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                {{ item.notes }}
                            </p>
                        </div>
                        <div class="whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                            {{ item.date }}
                        </div>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
