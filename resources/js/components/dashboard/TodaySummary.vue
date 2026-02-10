<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ShoppingCartIcon,
    BanknotesIcon,
    WrenchScrewdriverIcon,
    DocumentTextIcon,
    ArrowRightIcon,
} from '@heroicons/vue/24/outline';

interface SummaryItem {
    count: number;
    total?: number;
}

interface TodaySummary {
    date: string;
    dateFormatted: string;
    sales: SummaryItem;
    buys: SummaryItem;
    repairs: SummaryItem;
    memos: SummaryItem;
}

interface Props {
    summary: TodaySummary;
}

defineProps<Props>();

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Today's Activity</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ summary.dateFormatted }}</p>
        </div>

        <div class="grid grid-cols-2 gap-px bg-gray-200 dark:bg-gray-700 sm:grid-cols-4">
            <!-- Sales -->
            <Link
                :href="`/orders?from_date=${summary.date}&to_date=${summary.date}`"
                class="group bg-white px-4 py-6 hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/50"
            >
                <div class="flex items-center gap-x-2">
                    <ShoppingCartIcon class="h-5 w-5 text-indigo-500" />
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Sales</span>
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                    {{ summary.sales.count }}
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ formatCurrency(summary.sales.total || 0) }}
                </p>
                <div class="mt-3 flex items-center text-sm font-medium text-indigo-600 group-hover:text-indigo-500 dark:text-indigo-400">
                    View today's sales
                    <ArrowRightIcon class="ml-1 h-4 w-4" />
                </div>
            </Link>

            <!-- Buys -->
            <Link
                :href="`/transactions?date_from=${summary.date}&date_to=${summary.date}`"
                class="group bg-white px-4 py-6 hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/50"
            >
                <div class="flex items-center gap-x-2">
                    <BanknotesIcon class="h-5 w-5 text-green-500" />
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Buys</span>
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                    {{ summary.buys.count }}
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ formatCurrency(summary.buys.total || 0) }}
                </p>
                <div class="mt-3 flex items-center text-sm font-medium text-indigo-600 group-hover:text-indigo-500 dark:text-indigo-400">
                    View today's buys
                    <ArrowRightIcon class="ml-1 h-4 w-4" />
                </div>
            </Link>

            <!-- Repairs -->
            <Link
                :href="`/repairs?date_from=${summary.date}&date_to=${summary.date}`"
                class="group bg-white px-4 py-6 hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/50"
            >
                <div class="flex items-center gap-x-2">
                    <WrenchScrewdriverIcon class="h-5 w-5 text-orange-500" />
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Repairs</span>
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                    {{ summary.repairs.count }}
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    jobs created
                </p>
                <div class="mt-3 flex items-center text-sm font-medium text-indigo-600 group-hover:text-indigo-500 dark:text-indigo-400">
                    View today's repairs
                    <ArrowRightIcon class="ml-1 h-4 w-4" />
                </div>
            </Link>

            <!-- Memos -->
            <Link
                :href="`/memos?date_from=${summary.date}&date_to=${summary.date}`"
                class="group bg-white px-4 py-6 hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/50"
            >
                <div class="flex items-center gap-x-2">
                    <DocumentTextIcon class="h-5 w-5 text-purple-500" />
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Memos</span>
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                    {{ summary.memos.count }}
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    consignments
                </p>
                <div class="mt-3 flex items-center text-sm font-medium text-indigo-600 group-hover:text-indigo-500 dark:text-indigo-400">
                    View today's memos
                    <ArrowRightIcon class="ml-1 h-4 w-4" />
                </div>
            </Link>
        </div>
    </div>
</template>
