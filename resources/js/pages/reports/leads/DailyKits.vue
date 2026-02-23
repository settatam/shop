<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    TruckIcon,
    ClockIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';
import ReportTable from '@/components/widgets/ReportTable.vue';
import { computed } from 'vue';

interface Kit {
    id: number;
    transaction_number: string;
    kit_delivered_at: string;
    kit_delivered_at_raw: string;
    customer_name: string;
    customer_id: number | null;
    status: string;
    status_raw: string;
    outbound_tracking: string | null;
    outbound_carrier: string | null;
    return_tracking: string | null;
    return_carrier: string | null;
    days_since_delivered: number;
}

const props = defineProps<{
    kits: Kit[];
    daysBack: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reports', href: '#' },
    { title: 'Leads Report', href: '/reports/leads' },
    { title: 'Daily Kits', href: '/reports/leads/daily-kits' },
];

// Stats
const totalKits = computed(() => props.kits.length);
const urgentKits = computed(
    () => props.kits.filter((k) => k.days_since_delivered >= 5).length,
);
const warningKits = computed(
    () =>
        props.kits.filter(
            (k) => k.days_since_delivered >= 3 && k.days_since_delivered < 5,
        ).length,
);
const avgDays = computed(() => {
    if (props.kits.length === 0) return 0;
    const total = props.kits.reduce((sum, k) => sum + k.days_since_delivered, 0);
    return Math.round(total / props.kits.length);
});

function getStatusColor(kit: Kit): string {
    if (kit.days_since_delivered >= 5) {
        return 'text-red-600 dark:text-red-400';
    } else if (kit.days_since_delivered >= 3) {
        return 'text-yellow-600 dark:text-yellow-400';
    }
    return 'text-green-600 dark:text-green-400';
}

function getStatusBadge(kit: Kit): { label: string; class: string } {
    if (kit.days_since_delivered >= 5) {
        return {
            label: 'Overdue',
            class: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        };
    } else if (kit.days_since_delivered >= 3) {
        return {
            label: 'Warning',
            class: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        };
    }
    return {
        label: 'On Track',
        class: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    };
}

function changeDaysBack(days: number) {
    router.get('/reports/leads/daily-kits', { days }, { preserveState: true });
}

const exportUrl = computed(() => `/reports/leads/daily-kits/export?days=${props.daysBack}`);
const emailUrl = computed(() => `/reports/leads/daily-kits/email?days=${props.daysBack}`);
</script>

<template>
    <Head title="Daily Kits Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-gray-900 dark:text-white"
                    >
                        Daily Kits Report
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Kits delivered in the last {{ daysBack }} days awaiting
                        return
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <select
                        :value="daysBack"
                        @change="
                            changeDaysBack(
                                Number(($event.target as HTMLSelectElement).value),
                            )
                        "
                        class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option :value="7">Last 7 Days</option>
                        <option :value="14">Last 14 Days</option>
                        <option :value="30">Last 30 Days</option>
                    </select>
                    <Link
                        href="/reports/leads"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        Back to Leads
                    </Link>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="rounded-lg bg-blue-100 p-2 dark:bg-blue-900/30"
                        >
                            <TruckIcon class="size-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p
                                class="text-sm font-medium text-gray-500 dark:text-gray-400"
                            >
                                Total Pending
                            </p>
                            <p
                                class="text-2xl font-semibold text-gray-900 dark:text-white"
                            >
                                {{ totalKits }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="rounded-lg bg-red-100 p-2 dark:bg-red-900/30"
                        >
                            <ExclamationTriangleIcon
                                class="size-5 text-red-600 dark:text-red-400"
                            />
                        </div>
                        <div>
                            <p
                                class="text-sm font-medium text-gray-500 dark:text-gray-400"
                            >
                                Overdue (5+ days)
                            </p>
                            <p
                                class="text-2xl font-semibold text-red-600 dark:text-red-400"
                            >
                                {{ urgentKits }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="rounded-lg bg-yellow-100 p-2 dark:bg-yellow-900/30"
                        >
                            <ClockIcon
                                class="size-5 text-yellow-600 dark:text-yellow-400"
                            />
                        </div>
                        <div>
                            <p
                                class="text-sm font-medium text-gray-500 dark:text-gray-400"
                            >
                                Warning (3-4 days)
                            </p>
                            <p
                                class="text-2xl font-semibold text-yellow-600 dark:text-yellow-400"
                            >
                                {{ warningKits }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-lg bg-white p-4 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="rounded-lg bg-gray-100 p-2 dark:bg-gray-700"
                        >
                            <ClockIcon
                                class="size-5 text-gray-600 dark:text-gray-400"
                            />
                        </div>
                        <div>
                            <p
                                class="text-sm font-medium text-gray-500 dark:text-gray-400"
                            >
                                Avg. Days Pending
                            </p>
                            <p
                                class="text-2xl font-semibold text-gray-900 dark:text-white"
                            >
                                {{ avgDays }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <ReportTable title="Daily Kits Data" :export-url="exportUrl" :email-url="emailUrl">
            <div
                class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10"
            >
                <div class="overflow-x-auto">
                    <table
                        class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                    >
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Transaction ID
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Date Kit Delivered
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Customer Name
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Days Pending
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-300"
                                >
                                    Tracking
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                        >
                            <tr
                                v-for="kit in kits"
                                :key="kit.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td
                                    class="px-4 py-4 text-sm font-medium whitespace-nowrap"
                                >
                                    <Link
                                        :href="`/transactions/${kit.id}`"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        {{ kit.transaction_number }}
                                    </Link>
                                </td>
                                <td
                                    class="px-4 py-4 text-sm whitespace-nowrap text-gray-900 dark:text-white"
                                >
                                    {{ kit.kit_delivered_at }}
                                </td>
                                <td
                                    class="px-4 py-4 text-sm whitespace-nowrap"
                                >
                                    <Link
                                        v-if="kit.customer_id"
                                        :href="`/customers/${kit.customer_id}`"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        {{ kit.customer_name }}
                                    </Link>
                                    <span
                                        v-else
                                        class="text-gray-500 dark:text-gray-400"
                                    >
                                        {{ kit.customer_name }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="getStatusBadge(kit).class"
                                    >
                                        {{ getStatusBadge(kit).label }}
                                    </span>
                                    <span
                                        class="ml-2 text-gray-500 dark:text-gray-400"
                                    >
                                        {{ kit.status }}
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-4 text-sm font-medium whitespace-nowrap"
                                    :class="getStatusColor(kit)"
                                >
                                    {{ kit.days_since_delivered }}
                                    {{ kit.days_since_delivered === 1 ? 'day' : 'days' }}
                                </td>
                                <td
                                    class="px-4 py-4 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400"
                                >
                                    <div v-if="kit.outbound_tracking">
                                        <span class="text-xs text-gray-400"
                                            >Out:</span
                                        >
                                        {{ kit.outbound_tracking }}
                                    </div>
                                    <div
                                        v-if="kit.return_tracking"
                                        class="mt-1"
                                    >
                                        <span class="text-xs text-gray-400"
                                            >Return:</span
                                        >
                                        {{ kit.return_tracking }}
                                    </div>
                                    <span
                                        v-if="
                                            !kit.outbound_tracking &&
                                            !kit.return_tracking
                                        "
                                        class="text-gray-400"
                                        >-</span
                                    >
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="kits.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                >
                                    <TruckIcon
                                        class="mx-auto size-12 text-gray-300 dark:text-gray-600"
                                    />
                                    <p class="mt-2">
                                        No pending kits found in the last
                                        {{ daysBack }} days.
                                    </p>
                                    <p class="text-xs">
                                        All kits have been returned or no kits
                                        were delivered.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </ReportTable>
        </div>
    </AppLayout>
</template>
