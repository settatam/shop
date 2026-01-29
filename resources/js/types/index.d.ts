import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';
import type { FunctionalComponent, SVGAttributes } from 'vue';

// Heroicon type
export type HeroIcon = FunctionalComponent<SVGAttributes>;

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

// Nav item child for collapsible menus
export interface NavItemChild {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
}

// Legacy NavItem for compatibility
export interface NavItem {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    children?: NavItemChild[];
}

// New navigation types for TailwindUI layout
export interface NavChild {
    name: string;
    href: string;
}

export interface NavGroup {
    name: string;
    href?: string;
    icon: HeroIcon;
    current?: boolean;
    children?: NavChild[];
}

export interface StoreRole {
    id: number;
    name: string;
    slug: string;
}

export interface Store {
    id: number;
    name: string;
    slug?: string;
    logo?: string | null;
    logo_url?: string | null;
    initial: string;
    is_owner?: boolean;
    role?: StoreRole | null;
    current?: boolean;
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    stores?: Store[];
    currentStore?: Store;
};

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
