<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import StatsCard from '@/components/dashboard/StatsCard.vue';
import SalesChart from '@/components/dashboard/SalesChart.vue';
import RecentOrders from '@/components/dashboard/RecentOrders.vue';
import OrdersByStatus from '@/components/dashboard/OrdersByStatus.vue';
import LowStockAlert from '@/components/dashboard/LowStockAlert.vue';
import ActivityFeed from '@/components/dashboard/ActivityFeed.vue';
import RecentBuys from '@/components/dashboard/RecentBuys.vue';
import BuysByStatus from '@/components/dashboard/BuysByStatus.vue';
import RecentRepairs from '@/components/dashboard/RecentRepairs.vue';
import RepairsByStatus from '@/components/dashboard/RepairsByStatus.vue';
import RecentMemos from '@/components/dashboard/RecentMemos.vue';
import MemosByStatus from '@/components/dashboard/MemosByStatus.vue';
import TodaySummary from '@/components/dashboard/TodaySummary.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { DollarSign, ShoppingCart, Wrench, FileText } from 'lucide-vue-next';

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

interface Vendor {
    name: string;
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

interface Repair {
    id: number;
    repair_number: string;
    customer: Customer | null;
    vendor: Vendor | null;
    total: number;
    status: string;
    is_appraisal: boolean;
    created_at: string;
}

interface Memo {
    id: number;
    memo_number: string;
    vendor: Vendor | null;
    total: number;
    tenure: number | null;
    status: string;
    created_at: string;
}

interface LowStockProduct {
    id: number;
    title: string;
    handle: string;
    quantity: number;
    image: string | null;
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
    lowStockProducts: LowStockProduct[];
    recentActivity: ActivityDay[];
    recentBuys: Buy[];
    buysByStatus: Record<string, number>;
    recentRepairs: Repair[];
    repairsByStatus: Record<string, number>;
    recentMemos: Memo[];
    memosByStatus: Record<string, number>;
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
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 p-4 lg:p-8">
            <!-- Quick Actions -->
            <div class="flex flex-wrap gap-3">
                <Link
                    href="/transactions/buy"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition-colors"
                >
                    <DollarSign class="size-5" />
                    New Buy
                </Link>
                <Link
                    href="/repairs/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 transition-colors"
                >
                    <Wrench class="size-5" />
                    New Repair
                </Link>
                <Link
                    href="/memos/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-purple-500 transition-colors"
                >
                    <FileText class="size-5" />
                    New Memo
                </Link>
                <Link
                    href="/orders/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 transition-colors"
                >
                    <ShoppingCart class="size-5" />
                    New Sale
                </Link>
            </div>

            <!-- Stats Cards -->
            <StatsCard :stats="stats" />

            <!-- Today's Summary -->
            <TodaySummary :summary="todaySummary" />

            <!-- Sales Chart (Full Width) -->
            <SalesChart :data="salesChart" />

            <!-- Orders Section -->
            <div>
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Orders</h2>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <RecentOrders :orders="recentOrders" />
                    <OrdersByStatus :orders-by-status="ordersByStatus" />
                </div>
            </div>

            <!-- Buys Section -->
            <div>
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Buys</h2>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <RecentBuys :buys="recentBuys" />
                    <BuysByStatus :buys-by-status="buysByStatus" />
                </div>
            </div>

            <!-- Repairs Section -->
            <div>
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Repairs</h2>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <RecentRepairs :repairs="recentRepairs" />
                    <RepairsByStatus :repairs-by-status="repairsByStatus" />
                </div>
            </div>

            <!-- Memos Section -->
            <div>
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Memos</h2>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <RecentMemos :memos="recentMemos" />
                    <MemosByStatus :memos-by-status="memosByStatus" />
                </div>
            </div>

            <!-- Inventory Section -->
            <div>
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Inventory</h2>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <LowStockAlert :products="lowStockProducts" />
                </div>
            </div>

            <!-- Activity Feed -->
            <ActivityFeed :activities="recentActivity" />
        </div>
    </AppLayout>
</template>
