<script setup lang="ts">
/**
 * Client X Edition - Custom Dashboard
 *
 * This dashboard is specifically designed for Client X's workflow:
 * - Prominent focus on bulk operations
 * - Custom metrics they requested
 * - Simplified navigation without features they don't use
 */
import AppLayout from '@/layouts/AppLayout.vue';
import StatsCard from '@/components/dashboard/StatsCard.vue';
import SalesChart from '@/components/dashboard/SalesChart.vue';
import RecentOrders from '@/components/dashboard/RecentOrders.vue';
import OrdersByStatus from '@/components/dashboard/OrdersByStatus.vue';
import ActivityFeed from '@/components/dashboard/ActivityFeed.vue';
import RecentBuys from '@/components/dashboard/RecentBuys.vue';
import BuysByStatus from '@/components/dashboard/BuysByStatus.vue';
import TodaySummary from '@/components/dashboard/TodaySummary.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { DollarSign, ShoppingCart, Package, Upload } from 'lucide-vue-next';

interface Stat {
    name: string;
    value: string;
    change: string;
    changeType: 'positive' | 'negative' | 'neutral';
}

interface ChartDataset {
    label: string;
    data: number[];
    borderColor: string;
    backgroundColor: string;
    fill: boolean;
}

interface ChartData {
    labels: string[];
    datasets: ChartDataset[];
    ordersData: number[];
}

interface Customer {
    name: string;
    email: string;
}

interface Order {
    id: number;
    invoice_number: string | null;
    customer: Customer | null;
    total: number;
    status: string;
    created_at: string;
}

interface Buy {
    id: number;
    transaction_number: string;
    customer: Customer | null;
    final_offer: number | null;
    preliminary_offer: number | null;
    status: string;
    type: string;
    created_at: string;
}

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

interface SummaryItem {
    count: number;
    total?: number;
}

interface TodaySummaryData {
    date: string;
    dateFormatted: string;
    sales: SummaryItem;
    buys: SummaryItem;
    repairs: SummaryItem;
    memos: SummaryItem;
}

interface Props {
    stats: Stat[];
    salesChart: ChartData;
    recentOrders: Order[];
    ordersByStatus: Record<string, number>;
    recentActivity: ActivityDay[];
    recentBuys: Buy[];
    buysByStatus: Record<string, number>;
    todaySummary: TodaySummaryData;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];
</script>

<template>
    <Head title="Dashboard - Client X" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 p-4 lg:p-8">
            <!-- Client X Custom Header -->
            <div class="rounded-lg bg-gradient-to-r from-indigo-600 to-purple-600 p-6 text-white shadow-lg">
                <h1 class="text-2xl font-bold">Welcome to Your Dashboard</h1>
                <p class="mt-1 text-indigo-100">Client X Custom Edition</p>
            </div>

            <!-- Quick Actions - Client X Specific -->
            <div class="flex flex-wrap gap-3">
                <Link
                    href="/transactions/buy"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition-colors"
                >
                    <DollarSign class="size-5" />
                    New Buy
                </Link>
                <Link
                    href="/orders/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 transition-colors"
                >
                    <ShoppingCart class="size-5" />
                    New Sale
                </Link>
                <Link
                    href="/products/import"
                    class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-purple-500 transition-colors"
                >
                    <Upload class="size-5" />
                    Bulk Import
                </Link>
                <Link
                    href="/products"
                    class="inline-flex items-center gap-2 rounded-lg bg-gray-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-gray-500 transition-colors"
                >
                    <Package class="size-5" />
                    Manage Products
                </Link>
            </div>

            <!-- Stats Cards -->
            <StatsCard :stats="stats" />

            <!-- Today's Summary -->
            <TodaySummary :summary="todaySummary" />

            <!-- Sales Chart (Full Width) -->
            <SalesChart :data="salesChart" />

            <!-- Two Column Layout: Orders and Buys Side by Side -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <!-- Orders Section -->
                <div class="space-y-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Orders</h2>
                    <RecentOrders :orders="recentOrders" />
                    <OrdersByStatus :orders-by-status="ordersByStatus" />
                </div>

                <!-- Buys Section -->
                <div class="space-y-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Buys</h2>
                    <RecentBuys :buys="recentBuys" />
                    <BuysByStatus :buys-by-status="buysByStatus" />
                </div>
            </div>

            <!-- Activity Feed -->
            <ActivityFeed :activities="recentActivity" />
        </div>
    </AppLayout>
</template>
