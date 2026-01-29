<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/vue';
import {
    HomeIcon,
    UsersIcon,
    CubeIcon,
    ShoppingCartIcon,
    ArchiveBoxIcon,
    WrenchScrewdriverIcon,
    DocumentTextIcon,
    ChartBarIcon,
    Cog6ToothIcon,
    PuzzlePieceIcon,
    CurrencyDollarIcon,
    CreditCardIcon,
    BeakerIcon,
    TagIcon,
    BellIcon,
    BanknotesIcon,
    PrinterIcon,
    ShoppingBagIcon,
} from '@heroicons/vue/24/outline';
import { ChevronRightIcon } from '@heroicons/vue/20/solid';
import type { NavGroup } from '@/types';
import AppLogo from '@/components/AppLogo.vue';
import StoreSwitcher from '@/components/layout/StoreSwitcher.vue';

const page = usePage();

const navigation: NavGroup[] = [
    { name: 'Dashboard', href: '/dashboard', icon: HomeIcon },
    {
        name: 'Customers',
        icon: UsersIcon,
        children: [
            { name: 'All Customers', href: '/customers' },
            { name: 'Leads', href: '/leads' },
        ],
    },
    {
        name: 'Products',
        icon: CubeIcon,
        children: [
            { name: 'All Products', href: '/products' },
            { name: 'Categories', href: '/categories' },
            { name: 'Templates', href: '/templates' },
        ],
    },
    {
        name: 'Orders',
        icon: ShoppingCartIcon,
        children: [
            { name: 'All Orders', href: '/orders' },
            { name: 'Shipments', href: '/shipments' },
            { name: 'Returns', href: '/returns' },
        ],
    },
    { name: 'Inventory', href: '/inventory', icon: ArchiveBoxIcon },
    { name: 'Repairs', href: '/repairs', icon: WrenchScrewdriverIcon },
    { name: 'Memos', href: '/memos', icon: DocumentTextIcon },
    { name: 'Transactions', href: '/transactions', icon: BanknotesIcon },
    {
        name: 'Buys',
        icon: ShoppingBagIcon,
        children: [
            { name: 'By Transaction', href: '/buys' },
            { name: 'All Items', href: '/buys/items' },
        ],
    },
    { name: 'Invoices', href: '/invoices', icon: CurrencyDollarIcon },
    { name: 'Payments', href: '/payments', icon: CreditCardIcon },
    { name: 'Labels', href: '/labels', icon: PrinterIcon },
    { name: 'Buckets', href: '/buckets', icon: BeakerIcon },
    {
        name: 'Reports',
        icon: ChartBarIcon,
        children: [
            { name: 'Sales (Daily)', href: '/reports/sales/daily' },
            { name: 'Sales (Month over Month)', href: '/reports/sales/monthly' },
            { name: 'Sales (Month to Date)', href: '/reports/sales/mtd' },
            { name: 'Buys Report (Online)', href: '/reports/buys/online' },
            { name: 'Buys Report (In Store)', href: '/reports/buys/in-store' },
            { name: 'Buys Report (Trade-In)', href: '/reports/buys/trade-in' },
            { name: 'Inventory Report', href: '/reports/inventory' },
        ],
    },
    { name: 'Tags', href: '/tags', icon: TagIcon },
    { name: 'Notifications', href: '/notifications', icon: BellIcon },
    { name: 'Integrations', href: '/integrations', icon: PuzzlePieceIcon },
];

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

                <!-- Store switcher -->
                <li>
                    <StoreSwitcher />
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
