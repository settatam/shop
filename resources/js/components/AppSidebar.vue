<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
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
import { Link, usePage } from '@inertiajs/vue3';
import { ArchiveIcon, Banknotes, BarChart3, BookOpen, Building2, CreditCard, FileText, Folder, FolderTree, LayoutGrid, Package, ShoppingCart, Plug, ChevronDown, Users, Store } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { router } from '@inertiajs/vue3';

const page = usePage();

const stores = computed(() => (page.props.stores as Store[] | undefined) || []);
const currentStore = computed(() => page.props.currentStore as Store | undefined);

function switchStore(store: Store) {
    if (store.id === currentStore.value?.id) return;

    router.post(`/stores/${store.id}/switch`, {}, {
        preserveState: false,
        preserveScroll: false,
    });
}

import { computed } from 'vue';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
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
            { title: 'Lead Sources', href: '/leads' },
        ],
    },
    {
        title: 'Orders',
        href: '/orders',
        icon: ShoppingCart,
    },
    {
        title: 'Buys',
        icon: Banknotes,
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
        icon: Store,
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
            { title: 'Sales (Daily)', href: '/reports/sales/daily' },
            { title: 'Sales (Month over Month)', href: '/reports/sales/monthly' },
            { title: 'Sales (Month to Date)', href: '/reports/sales/mtd' },
            { title: 'Buys Report (Online)', href: '/reports/buys/online' },
            { title: 'Buys Report (In Store)', href: '/reports/buys/in-store' },
            { title: 'Buys Report (Trade-In)', href: '/reports/buys/trade-in' },
            { title: 'Inventory Report', href: '/reports/inventory' },
        ],
    },
];

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
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <SidebarMenuButton
                                size="lg"
                                class="w-full justify-between data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
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
                        </DropdownMenuTrigger>
                        <DropdownMenuContent class="w-56" align="start" side="bottom">
                            <DropdownMenuItem
                                v-for="store in stores"
                                :key="store.id"
                                :class="{ 'bg-accent': store.id === currentStore?.id }"
                                @click="switchStore(store)"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex size-6 shrink-0 items-center justify-center overflow-hidden rounded border bg-background"
                                    >
                                        <img
                                            v-if="store.logo_url"
                                            :src="store.logo_url"
                                            :alt="store.name"
                                            class="h-full w-full object-contain"
                                        />
                                        <span v-else class="text-xs font-medium">{{ store.initial }}</span>
                                    </div>
                                    <span class="truncate">{{ store.name }}</span>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator v-if="stores.length > 0" />
                            <DropdownMenuItem as-child>
                                <Link :href="dashboard()">
                                    <AppLogo class="mr-2 size-4" />
                                    Dashboard
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
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
</template>
