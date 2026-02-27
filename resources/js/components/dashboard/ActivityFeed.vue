<script setup lang="ts">
import { useDate } from '@/composables/useDate';
import {
    CheckCircleIcon,
    CubeIcon,
    ShoppingCartIcon,
    UserIcon,
    DocumentTextIcon,
    PencilSquareIcon,
    TrashIcon,
    PlusCircleIcon,
} from '@heroicons/vue/20/solid';
import { computed, type Component } from 'vue';

interface ActivityUser {
    name: string;
    avatar: string | null;
}

interface ActivitySubject {
    type: string;
    id: number;
}

interface ActivityItem {
    id: number;
    description: string;
    activity: string;
    user: ActivityUser | null;
    subject: ActivitySubject | null;
    time: string;
    created_at: string;
}

interface ActivityDay {
    date: string;
    dateTime: string;
    items: ActivityItem[];
}

interface Props {
    activities: ActivityDay[];
}

const props = defineProps<Props>();
const { formatTime } = useDate();

// Map activity types to icons
const activityIcons: Record<string, Component> = {
    'products.create': PlusCircleIcon,
    'products.update': PencilSquareIcon,
    'products.delete': TrashIcon,
    'orders.create': ShoppingCartIcon,
    'orders.update': ShoppingCartIcon,
    'orders.complete': CheckCircleIcon,
    'customers.create': UserIcon,
    'categories.create': CubeIcon,
    'categories.update': CubeIcon,
};

function getActivityIcon(activity: string): Component {
    return activityIcons[activity] || DocumentTextIcon;
}

function getActivityColor(activity: string): string {
    if (activity.includes('create')) return 'text-green-500';
    if (activity.includes('delete')) return 'text-red-500';
    if (activity.includes('update')) return 'text-blue-500';
    return 'text-gray-400 dark:text-gray-500';
}

const hasActivities = computed(() => props.activities.length > 0);
</script>

<template>
    <div>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 class="mx-auto max-w-2xl text-base font-semibold text-gray-900 lg:mx-0 lg:max-w-none dark:text-white">
                Recent activity
            </h2>
        </div>

        <div class="mt-6 overflow-hidden border-t border-gray-100 dark:border-white/5">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl lg:mx-0 lg:max-w-none">
                    <!-- Empty state -->
                    <div v-if="!hasActivities" class="py-12 text-center">
                        <DocumentTextIcon class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" />
                        <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No activity yet</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Activity will appear here as you use the app.
                        </p>
                    </div>

                    <!-- Activity list -->
                    <div v-else class="flow-root">
                        <ul role="list" class="-mb-8">
                            <template v-for="day in activities" :key="day.dateTime">
                                <!-- Date header -->
                                <li class="relative pb-4 pt-6">
                                    <div class="relative flex items-center space-x-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                                <time :datetime="day.dateTime">{{ day.date }}</time>
                                            </div>
                                        </div>
                                    </div>
                                </li>

                                <!-- Activity items -->
                                <li v-for="(item, itemIdx) in day.items" :key="item.id" class="relative pb-8">
                                    <!-- Connector line -->
                                    <span
                                        v-if="itemIdx !== day.items.length - 1"
                                        class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-700"
                                        aria-hidden="true"
                                    />

                                    <div class="relative flex space-x-3">
                                        <!-- Icon -->
                                        <div>
                                            <span
                                                :class="[
                                                    'flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 ring-8 ring-white dark:bg-gray-800 dark:ring-gray-900',
                                                ]"
                                            >
                                                <component
                                                    :is="getActivityIcon(item.activity)"
                                                    :class="['h-5 w-5', getActivityColor(item.activity)]"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </div>

                                        <!-- Content -->
                                        <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                            <div>
                                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                                    {{ item.description }}
                                                    <span v-if="item.user" class="font-medium text-gray-900 dark:text-white">
                                                        by {{ item.user.name }}
                                                    </span>
                                                </p>
                                            </div>
                                            <div class="whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                                                <time :datetime="item.created_at">{{ item.time }}</time>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
