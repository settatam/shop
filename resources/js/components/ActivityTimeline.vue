<script setup lang="ts">
import {
    BanknotesIcon,
    ChatBubbleLeftEllipsisIcon,
    CheckCircleIcon,
    ClockIcon,
    DocumentIcon,
    InboxIcon,
    PencilIcon,
    PlusIcon,
    TrashIcon,
    TruckIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline';
import { computed } from 'vue';

interface User {
    name: string;
}

interface Changes {
    [key: string]: {
        old: string;
        new: string;
    };
}

interface ActivityItem {
    id: number;
    activity: string;
    description: string;
    user: User | null;
    changes: Changes | null;
    time: string;
    created_at: string;
    icon: string;
    color: string;
}

interface ActivityDay {
    date: string;
    dateTime: string;
    items: ActivityItem[];
}

interface Props {
    activities?: ActivityDay[];
    title?: string;
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Activity Log',
});

const isLoading = computed(() => props.activities === undefined);
const hasActivities = computed(
    () => props.activities && props.activities.length > 0,
);

const iconComponents: Record<string, typeof PlusIcon> = {
    plus: PlusIcon,
    pencil: PencilIcon,
    trash: TrashIcon,
    'x-circle': XCircleIcon,
    banknotes: BanknotesIcon,
    'check-circle': CheckCircleIcon,
    truck: TruckIcon,
    inbox: InboxIcon,
    document: DocumentIcon,
    'chat-bubble': ChatBubbleLeftEllipsisIcon,
};

const colorClasses: Record<string, { bg: string; icon: string }> = {
    green: {
        bg: 'bg-green-100 dark:bg-green-900/50',
        icon: 'text-green-600 dark:text-green-400',
    },
    blue: {
        bg: 'bg-blue-100 dark:bg-blue-900/50',
        icon: 'text-blue-600 dark:text-blue-400',
    },
    red: {
        bg: 'bg-red-100 dark:bg-red-900/50',
        icon: 'text-red-600 dark:text-red-400',
    },
    yellow: {
        bg: 'bg-yellow-100 dark:bg-yellow-900/50',
        icon: 'text-yellow-600 dark:text-yellow-400',
    },
    gray: {
        bg: 'bg-gray-100 dark:bg-gray-700',
        icon: 'text-gray-600 dark:text-gray-400',
    },
};

function getIconComponent(icon: string) {
    return iconComponents[icon] || DocumentIcon;
}

function getColorClasses(color: string) {
    return colorClasses[color] || colorClasses.gray;
}
</script>

<template>
    <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">
            {{ title }}
        </h2>

        <!-- Loading skeleton -->
        <div v-if="isLoading" class="space-y-4">
            <div class="animate-pulse">
                <div
                    class="mb-2 h-4 w-16 rounded bg-gray-200 dark:bg-gray-700"
                ></div>
                <div class="space-y-3">
                    <div v-for="i in 3" :key="i" class="flex items-start gap-3">
                        <div
                            class="size-8 shrink-0 rounded-full bg-gray-200 dark:bg-gray-700"
                        ></div>
                        <div class="flex-1 space-y-2">
                            <div
                                class="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700"
                            ></div>
                            <div
                                class="h-3 w-1/4 rounded bg-gray-200 dark:bg-gray-700"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="animate-pulse">
                <div
                    class="mb-2 h-4 w-20 rounded bg-gray-200 dark:bg-gray-700"
                ></div>
                <div class="space-y-3">
                    <div v-for="i in 2" :key="i" class="flex items-start gap-3">
                        <div
                            class="size-8 shrink-0 rounded-full bg-gray-200 dark:bg-gray-700"
                        ></div>
                        <div class="flex-1 space-y-2">
                            <div
                                class="h-4 w-2/3 rounded bg-gray-200 dark:bg-gray-700"
                            ></div>
                            <div
                                class="h-3 w-1/3 rounded bg-gray-200 dark:bg-gray-700"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-else-if="!hasActivities" class="py-8 text-center">
            <ClockIcon
                class="mx-auto size-12 text-gray-300 dark:text-gray-600"
            />
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                No activity recorded yet.
            </p>
        </div>

        <!-- Activity timeline -->
        <div v-else class="space-y-6">
            <div v-for="day in activities" :key="day.dateTime" class="relative">
                <!-- Date header -->
                <h3
                    class="mb-3 text-sm font-medium text-gray-500 dark:text-gray-400"
                >
                    {{ day.date }}
                </h3>

                <!-- Timeline -->
                <div class="relative">
                    <!-- Vertical line -->
                    <div
                        class="absolute top-0 bottom-0 left-4 w-px bg-gray-200 dark:bg-gray-700"
                    ></div>

                    <!-- Activity items -->
                    <div class="space-y-4">
                        <div
                            v-for="item in day.items"
                            :key="item.id"
                            class="relative flex items-start gap-3 pl-0"
                        >
                            <!-- Icon -->
                            <div
                                :class="[
                                    'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full',
                                    getColorClasses(item.color).bg,
                                ]"
                            >
                                <component
                                    :is="getIconComponent(item.icon)"
                                    :class="[
                                        'size-4',
                                        getColorClasses(item.color).icon,
                                    ]"
                                />
                            </div>

                            <!-- Content -->
                            <div class="min-w-0 flex-1 pb-2">
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <p
                                        class="text-sm text-gray-900 dark:text-white"
                                    >
                                        <span
                                            v-if="item.user"
                                            class="font-medium"
                                            >{{ item.user.name }}</span
                                        >
                                        <span
                                            v-else
                                            class="font-medium text-gray-500"
                                            >System</span
                                        >
                                        <span
                                            class="ml-1 text-gray-600 dark:text-gray-300"
                                            >{{ item.description }}</span
                                        >
                                    </p>
                                    <span
                                        class="shrink-0 text-xs text-gray-400 dark:text-gray-500"
                                    >
                                        {{ item.time }}
                                    </span>
                                </div>

                                <!-- Changes -->
                                <div
                                    v-if="item.changes"
                                    class="mt-2 rounded-md bg-gray-50 p-2 dark:bg-gray-700/50"
                                >
                                    <div
                                        v-for="(change, field) in item.changes"
                                        :key="field"
                                        class="text-xs"
                                    >
                                        <span
                                            class="font-medium text-gray-500 dark:text-gray-400"
                                            >{{ field }}:</span
                                        >
                                        <span
                                            class="ml-1 text-red-600 line-through dark:text-red-400"
                                            >{{ change.old }}</span
                                        >
                                        <span class="mx-1 text-gray-400"
                                            >&rarr;</span
                                        >
                                        <span
                                            class="text-green-600 dark:text-green-400"
                                            >{{ change.new }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
