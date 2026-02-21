<script setup lang="ts">
/**
 * Shopmata Public Edition - Dashboard
 *
 * Standard inventory management dashboard for e-commerce and retail.
 * Focused on products, orders, customers, and inventory.
 * Includes multichannel marketplace integration.
 * Does not include buys, repairs, or memos features.
 */
import AppLayout from '@/layouts/AppLayout.vue';
import StatsCard from '@/components/dashboard/StatsCard.vue';
import SalesChart from '@/components/dashboard/SalesChart.vue';
import RecentOrders from '@/components/dashboard/RecentOrders.vue';
import OrdersByStatus from '@/components/dashboard/OrdersByStatus.vue';
import LowStockAlert from '@/components/dashboard/LowStockAlert.vue';
import ActivityFeed from '@/components/dashboard/ActivityFeed.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ShoppingCart, Package, Users, BarChart3, Store, CheckCircle, Clock } from 'lucide-vue-next';
import { computed } from 'vue';

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

interface Marketplace {
    id: number;
    platform: string;
    platform_label: string;
    name: string;
    status: string;
    last_sync_at: string | null;
    last_sync_ago: string | null;
}

interface SalesChannel {
    id: number;
    name: string;
    code: string;
    color: string | null;
    is_local: boolean;
    orders_count: number;
    revenue: number;
    avg_order_value: number;
}

interface Props {
    stats: Stat[];
    salesChart: ChartData;
    recentOrders: Order[];
    ordersByStatus: Record<string, number>;
    lowStockProducts: LowStockProduct[];
    recentActivity: ActivityDay[];
    todaySummary: TodaySummaryData;
    marketplaces: Marketplace[];
    salesByChannel: SalesChannel[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

// Platform icons/colors mapping
const platformConfig: Record<string, { icon: string; bgColor: string; textColor: string }> = {
    shopify: { icon: '🛍️', bgColor: 'bg-green-100 dark:bg-green-900/30', textColor: 'text-green-700 dark:text-green-400' },
    ebay: { icon: '🏷️', bgColor: 'bg-red-100 dark:bg-red-900/30', textColor: 'text-red-700 dark:text-red-400' },
    amazon: { icon: '📦', bgColor: 'bg-orange-100 dark:bg-orange-900/30', textColor: 'text-orange-700 dark:text-orange-400' },
    etsy: { icon: '🎨', bgColor: 'bg-amber-100 dark:bg-amber-900/30', textColor: 'text-amber-700 dark:text-amber-400' },
    walmart: { icon: '🏪', bgColor: 'bg-blue-100 dark:bg-blue-900/30', textColor: 'text-blue-700 dark:text-blue-400' },
    woocommerce: { icon: '🛒', bgColor: 'bg-purple-100 dark:bg-purple-900/30', textColor: 'text-purple-700 dark:text-purple-400' },
};

const getPlatformConfig = (platform: string) => {
    return platformConfig[platform] || { icon: '🌐', bgColor: 'bg-gray-100 dark:bg-gray-800', textColor: 'text-gray-700 dark:text-gray-400' };
};

// Calculate total revenue across all channels
const totalChannelRevenue = computed(() => {
    return props.salesByChannel.reduce((sum, channel) => sum + channel.revenue, 0);
});

// Calculate revenue percentage for each channel
const getRevenuePercentage = (revenue: number) => {
    if (totalChannelRevenue.value === 0) return 0;
    return Math.round((revenue / totalChannelRevenue.value) * 100);
};

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 p-4 lg:p-8">
            <!-- Quick Actions -->
            <div class="flex flex-wrap gap-3">
                <Link
                    href="/orders/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-500"
                >
                    <ShoppingCart class="size-5" />
                    New Order
                </Link>
                <Link
                    href="/products/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-500"
                >
                    <Package class="size-5" />
                    Add Product
                </Link>
                <Link
                    href="/customers/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-purple-500"
                >
                    <Users class="size-5" />
                    Add Customer
                </Link>
                <Link
                    href="/reports"
                    class="inline-flex items-center gap-2 rounded-lg bg-gray-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-500"
                >
                    <BarChart3 class="size-5" />
                    View Reports
                </Link>
            </div>

            <!-- Stats Cards -->
            <StatsCard :stats="stats" />

            <!-- Connected Marketplaces -->
            <div v-if="marketplaces.length > 0" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                        <Store class="mr-2 inline-block size-5" />
                        Connected Marketplaces
                    </h3>
                    <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400">
                        {{ marketplaces.length }} Active
                    </span>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="marketplace in marketplaces"
                        :key="marketplace.id"
                        class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50"
                    >
                        <div
                            :class="[
                                'flex size-10 items-center justify-center rounded-lg text-lg',
                                getPlatformConfig(marketplace.platform).bgColor
                            ]"
                        >
                            {{ getPlatformConfig(marketplace.platform).icon }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-gray-900 dark:text-white">
                                {{ marketplace.platform_label }}
                            </div>
                            <div class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                <CheckCircle class="size-3 text-green-500" />
                                <span v-if="marketplace.last_sync_ago">Synced {{ marketplace.last_sync_ago }}</span>
                                <span v-else>Connected</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales by Channel -->
            <div v-if="salesByChannel.length > 0" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">
                    Sales by Channel (Last 30 Days)
                </h3>
                <div class="space-y-4">
                    <div
                        v-for="channel in salesByChannel"
                        :key="channel.id"
                        class="flex items-center gap-4"
                    >
                        <div class="flex w-32 items-center gap-2">
                            <div
                                class="size-3 rounded-full"
                                :style="{ backgroundColor: channel.color || '#6b7280' }"
                            ></div>
                            <span class="truncate text-sm font-medium text-gray-900 dark:text-white">
                                {{ channel.name }}
                            </span>
                        </div>
                        <div class="flex-1">
                            <div class="h-4 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div
                                    class="h-full rounded-full transition-all duration-500"
                                    :style="{
                                        width: `${getRevenuePercentage(channel.revenue)}%`,
                                        backgroundColor: channel.color || '#6b7280'
                                    }"
                                ></div>
                            </div>
                        </div>
                        <div class="flex w-48 items-center justify-end gap-4 text-sm">
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ channel.orders_count }} orders
                            </span>
                            <span class="font-semibold text-gray-900 dark:text-white">
                                {{ formatCurrency(channel.revenue) }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Revenue</span>
                    <span class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ formatCurrency(totalChannelRevenue) }}
                    </span>
                </div>
            </div>

            <!-- Today's Summary -->
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">
                    Today's Summary - {{ todaySummary.dateFormatted }}
                </h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20">
                        <div class="text-sm font-medium text-blue-600 dark:text-blue-400">Orders</div>
                        <div class="mt-1 text-2xl font-bold text-blue-900 dark:text-blue-100">
                            {{ todaySummary.sales.count }}
                        </div>
                        <div class="mt-1 text-sm text-blue-600 dark:text-blue-400">
                            ${{ Number(todaySummary.sales.total || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }} revenue
                        </div>
                    </div>
                    <div class="rounded-lg bg-emerald-50 p-4 dark:bg-emerald-900/20">
                        <div class="text-sm font-medium text-emerald-600 dark:text-emerald-400">Low Stock Items</div>
                        <div class="mt-1 text-2xl font-bold text-emerald-900 dark:text-emerald-100">
                            {{ lowStockProducts.length }}
                        </div>
                        <div class="mt-1 text-sm text-emerald-600 dark:text-emerald-400">
                            Items need attention
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales Chart (Full Width) -->
            <SalesChart :data="salesChart" />

            <!-- Orders and Inventory Section -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <!-- Orders Section -->
                <div class="space-y-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Orders</h2>
                    <RecentOrders :orders="recentOrders" />
                    <OrdersByStatus :orders-by-status="ordersByStatus" />
                </div>

                <!-- Inventory Section -->
                <div class="space-y-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Inventory Alerts</h2>
                    <LowStockAlert :products="lowStockProducts" />
                </div>
            </div>

            <!-- Activity Feed -->
            <ActivityFeed :activities="recentActivity" />
        </div>
    </AppLayout>
</template>
