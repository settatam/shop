<script setup lang="ts">
import { computed, ref } from 'vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import StoreSwitcherModal from '@/components/StoreSwitcherModal.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem, type Store } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ArchiveIcon, Banknote, BarChart3, BookOpen, Building2, CreditCard, FileText, Folder, FolderTree, LayoutGrid, MessageSquare, Package, ShoppingCart, Plug, ChevronDown, Users, Store as StoreIcon, TruckIcon } from 'lucide-vue-next';

const page = usePage();

const stores = computed(() => (page.props.stores as Store[] | undefined) || []);
const currentStore = computed(() => page.props.currentStore as Store | undefined);

const showStoreSwitcher = ref(false);

// Base navigation items
const baseNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Leads',
        href: '/leads',
        icon: TruckIcon,
    },
    {
        title: 'Products',
        href: '/products',
        icon: Package,
    },
    {
        title: 'Categories',
        href: '/categories',
        icon: FolderTree,
    },
    {
        title: 'Customers',
        icon: Users,
        children: [
            { title: 'All Customers', href: '/customers' },
            { title: 'Lead Sources', href: '/settings/lead-sources' },
        ],
    },
    {
        title: 'Sales',
        icon: ShoppingCart,
        children: [
            { title: 'Orders', href: '/orders' },
            { title: 'Transactions', href: '/transactions' },
        ],
    },
    {
        title: 'SMS Messages',
        href: '/messages',
        icon: MessageSquare,
    },
    {
        title: 'Buys',
        icon: Banknote,
        children: [
            { title: 'By Transaction', href: '/buys' },
            { title: 'By Item', href: '/buys/items' },
        ],
    },
    {
        title: 'Buckets',
        href: '/buckets',
        icon: ArchiveIcon,
    },
    {
        title: 'Invoices',
        href: '/invoices',
        icon: FileText,
    },
    {
        title: 'Payments',
        href: '/payments',
        icon: CreditCard,
    },
    {
        title: 'Warehouses',
        href: '/warehouses',
        icon: Building2,
    },
    {
        title: 'Vendors',
        href: '/vendors',
        icon: StoreIcon,
    },
    {
        title: 'Integrations',
        href: '/integrations',
        icon: Plug,
    },
    {
        title: 'Reports',
        icon: BarChart3,
        children: [
            { title: 'Buys (Daily)', href: '/reports/buys/daily' },
            { title: 'Buys (Month over Month)', href: '/reports/buys/monthly' },
            { title: 'Buys (Month to Date)', href: '/reports/buys' },
            { title: 'Buys (Year over Year)', href: '/reports/buys/yearly' },
            { title: 'Sales (Daily Orders)', href: '/reports/sales/daily' },
            { title: 'Sales (Daily Items)', href: '/reports/sales/daily-items' },
            { title: 'Sales (Month over Month)', href: '/reports/sales/monthly' },
            { title: 'Sales (Month to Date)', href: '/reports/sales/mtd' },
            { title: 'Leads Funnel', href: '/reports/leads' },
            { title: 'Inventory Report', href: '/reports/inventory' },
        ],
    },
];

// Main nav items - use base items directly
const mainNavItems = computed(() => baseNavItems);

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        class="w-full justify-between hover:bg-sidebar-accent"
                        @click="showStoreSwitcher = true"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-background"
                            >
                                <img
                                    v-if="currentStore?.logo_url"
                                    :src="currentStore.logo_url"
                                    :alt="currentStore.name"
                                    class="h-full w-full object-contain"
                                />
                                <span v-else class="text-sm font-semibold">
                                    {{ currentStore?.initial || 'S' }}
                                </span>
                            </div>
                            <div class="flex flex-col items-start text-left">
                                <span class="truncate text-sm font-semibold">
                                    {{ currentStore?.name || 'Select Store' }}
                                </span>
                                <span v-if="currentStore?.role" class="truncate text-xs text-muted-foreground">
                                    {{ currentStore.role.name }}
                                </span>
                            </div>
                        </div>
                        <ChevronDown class="ml-auto size-4 opacity-50" />
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />

    <!-- Store Switcher Modal -->
    <StoreSwitcherModal
        v-model:open="showStoreSwitcher"
        :stores="stores"
        :current-store="currentStore"
    />
</template>
