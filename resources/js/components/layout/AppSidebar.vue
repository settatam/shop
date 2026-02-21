<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/vue';
import {
    HomeIcon,
    UsersIcon,
    CubeIcon,
    ShoppingCartIcon,
    WrenchScrewdriverIcon,
    DocumentTextIcon,
    ChartBarIcon,
    Cog6ToothIcon,
    PuzzlePieceIcon,
    CurrencyDollarIcon,
    CreditCardIcon,
    BeakerIcon,
    TagIcon,
    PrinterIcon,
    ShoppingBagIcon,
    BuildingStorefrontIcon,
} from '@heroicons/vue/24/outline';
import { ChevronRightIcon } from '@heroicons/vue/20/solid';
import type { NavGroup, NavChild } from '@/types';
import AppLogo from '@/components/AppLogo.vue';
import ProminentStoreSwitcher from '@/components/layout/ProminentStoreSwitcher.vue';
import { useFeatures } from '@/composables/useFeatures';

const page = usePage();
const { hasFeature } = useFeatures();

// Full navigation with feature flags
const allNavigation: NavGroup[] = [
    { name: 'Dashboard', href: '/dashboard', icon: HomeIcon, feature: 'dashboard' },
    {
        name: 'Customers',
        icon: UsersIcon,
        feature: 'customers',
        children: [
            { name: 'All Customers', href: '/customers' },
            { name: 'Leads', href: '/leads', feature: 'leads' },
        ],
    },
    {
        name: 'Products',
        icon: CubeIcon,
        feature: 'products',
        children: [
            { name: 'All Products', href: '/products' },
            { name: 'GIA Entry', href: '/gia', feature: 'gia' },
            { name: 'Categories', href: '/categories', feature: 'categories' },
            { name: 'Product Types', href: '/product-types', feature: 'product_types' },
            { name: 'Templates', href: '/templates', feature: 'templates' },
        ],
    },
    {
        name: 'Sales',
        icon: ShoppingCartIcon,
        feature: 'orders',
        children: [
            { name: 'All Orders', href: '/orders' },
            { name: 'Layaways', href: '/layaways', feature: 'layaways' },
            { name: 'Shipments', href: '/shipments', feature: 'shipments' },
            { name: 'Returns', href: '/returns', feature: 'returns' },
        ],
    },
    {
        name: 'Purchases',
        icon: ShoppingBagIcon,
        feature: 'transactions',
        children: [
            { name: 'All Transactions', href: '/transactions', feature: 'all_transactions' },
            { name: 'Buys', href: '/buys', feature: 'buys' },
            { name: 'Purchased Items', href: '/buys/items', feature: 'buys' },
        ],
    },
    { name: 'Vendors', href: '/vendors', icon: BuildingStorefrontIcon, feature: 'vendors' },
    { name: 'Repairs', href: '/repairs', icon: WrenchScrewdriverIcon, feature: 'repairs' },
    { name: 'Memos', href: '/memos', icon: DocumentTextIcon, feature: 'memos' },
    { name: 'Invoices', href: '/invoices', icon: CurrencyDollarIcon, feature: 'invoices' },
    { name: 'Payments', href: '/payments', icon: CreditCardIcon, feature: 'payments' },
    { name: 'Labels', href: '/labels', icon: PrinterIcon, feature: 'labels' },
    { name: 'Buckets', href: '/buckets', icon: BeakerIcon, feature: 'buckets' },
    {
        name: 'Reports',
        icon: ChartBarIcon,
        feature: 'reports',
        children: [
            { name: 'Buys (Daily)', href: '/reports/buys/daily' },
            { name: 'Buys (Month over Month)', href: '/reports/buys/monthly' },
            { name: 'Buys (Month to Date)', href: '/reports/buys' },
            { name: 'Buys (Year over Year)', href: '/reports/buys/yearly' },
            { name: 'Sales (Daily Orders)', href: '/reports/sales/daily' },
            { name: 'Sales (Daily Items)', href: '/reports/sales/daily-items' },
            { name: 'Sales (Month over Month)', href: '/reports/sales/monthly' },
            { name: 'Sales (Month to Date)', href: '/reports/sales/mtd' },
            { name: 'Transactions (Daily)', href: '/reports/transactions/daily', feature: 'transactions_reports' },
            { name: 'Transactions (Weekly)', href: '/reports/transactions/weekly', feature: 'transactions_reports' },
            { name: 'Transactions (Monthly)', href: '/reports/transactions/monthly', feature: 'transactions_reports' },
            { name: 'Transactions (Yearly)', href: '/reports/transactions/yearly', feature: 'transactions_reports' },
            { name: 'Leads Funnel', href: '/reports/leads' },
            { name: 'Inventory Report', href: '/reports/inventory' },
        ],
    },
    { name: 'Tags', href: '/tags', icon: TagIcon, feature: 'tags' },
    { name: 'Integrations', href: '/integrations', icon: PuzzlePieceIcon, feature: 'integrations' },
];

// Filter navigation based on store features
const navigation = computed<NavGroup[]>(() => {
    return allNavigation
        .filter(item => !item.feature || hasFeature(item.feature))
        .map(item => {
            if (!item.children) return item;

            // Filter children based on features
            const filteredChildren = item.children.filter(
                (child: NavChild) => !child.feature || hasFeature(child.feature)
            );

            // If all children are filtered out, don't show the parent
            if (filteredChildren.length === 0) return null;

            return { ...item, children: filteredChildren };
        })
        .filter((item): item is NavGroup => item !== null);
});

const currentPath = computed(() => page.url);

function isActive(item: NavGroup): boolean {
    if (item.href) {
        return currentPath.value.startsWith(item.href);
    }
    if (item.children) {
        return item.children.some(child => currentPath.value.startsWith(child.href));
    }
    return false;
}

function isChildActive(href: string): boolean {
    return currentPath.value.startsWith(href);
}
</script>

<template>
    <div class="flex grow flex-col gap-y-5 overflow-y-auto border-r border-gray-200 bg-white px-6 pb-4 dark:border-white/10 dark:bg-gray-900">
        <div class="flex h-16 shrink-0 items-center">
            <Link href="/dashboard">
                <AppLogo class="h-8 w-auto" />
            </Link>
        </div>

        <!-- Store switcher/indicator - always at top, prominent for multi-store users -->
        <ProminentStoreSwitcher />

        <nav class="flex flex-1 flex-col">
            <ul role="list" class="flex flex-1 flex-col gap-y-7">
                <li>
                    <ul role="list" class="-mx-2 space-y-1">
                        <li v-for="item in navigation" :key="item.name">
                            <!-- Simple nav item (no children) -->
                            <Link
                                v-if="!item.children"
                                :href="item.href!"
                                :class="[
                                    isActive(item)
                                        ? 'bg-gray-50 text-indigo-600 dark:bg-white/5 dark:text-white'
                                        : 'text-gray-700 hover:bg-gray-50 hover:text-indigo-600 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white',
                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold',
                                ]"
                            >
                                <component
                                    :is="item.icon"
                                    :class="[
                                        isActive(item)
                                            ? 'text-indigo-600 dark:text-white'
                                            : 'text-gray-400 group-hover:text-indigo-600 dark:group-hover:text-white',
                                        'size-6 shrink-0',
                                    ]"
                                    aria-hidden="true"
                                />
                                {{ item.name }}
                            </Link>

                            <!-- Expandable nav item (has children) -->
                            <Disclosure v-else v-slot="{ open }" :default-open="isActive(item)">
                                <DisclosureButton
                                    :class="[
                                        isActive(item)
                                            ? 'bg-gray-50 text-indigo-600 dark:bg-white/5 dark:text-white'
                                            : 'text-gray-700 hover:bg-gray-50 hover:text-indigo-600 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white',
                                        'group flex w-full items-center gap-x-3 rounded-md p-2 text-left text-sm/6 font-semibold',
                                    ]"
                                >
                                    <component
                                        :is="item.icon"
                                        :class="[
                                            isActive(item)
                                                ? 'text-indigo-600 dark:text-white'
                                                : 'text-gray-400 group-hover:text-indigo-600 dark:group-hover:text-white',
                                            'size-6 shrink-0',
                                        ]"
                                        aria-hidden="true"
                                    />
                                    {{ item.name }}
                                    <ChevronRightIcon
                                        :class="[
                                            open ? 'rotate-90 text-gray-500' : 'text-gray-400',
                                            'ml-auto size-5 shrink-0 transition-transform duration-150',
                                        ]"
                                        aria-hidden="true"
                                    />
                                </DisclosureButton>
                                <DisclosurePanel as="ul" class="mt-1 px-2">
                                    <li v-for="child in item.children" :key="child.name">
                                        <Link
                                            :href="child.href"
                                            :class="[
                                                isChildActive(child.href)
                                                    ? 'bg-gray-50 text-indigo-600 dark:bg-white/5 dark:text-white'
                                                    : 'text-gray-700 hover:bg-gray-50 hover:text-indigo-600 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white',
                                                'block rounded-md py-2 pl-9 pr-2 text-sm/6',
                                            ]"
                                        >
                                            {{ child.name }}
                                        </Link>
                                    </li>
                                </DisclosurePanel>
                            </Disclosure>
                        </li>
                    </ul>
                </li>

                <!-- Settings at bottom -->
                <li class="mt-auto">
                    <Link
                        href="/settings"
                        class="group -mx-2 flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold text-gray-700 hover:bg-gray-50 hover:text-indigo-600 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                    >
                        <Cog6ToothIcon
                            class="size-6 shrink-0 text-gray-400 group-hover:text-indigo-600 dark:group-hover:text-white"
                            aria-hidden="true"
                        />
                        Settings
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
