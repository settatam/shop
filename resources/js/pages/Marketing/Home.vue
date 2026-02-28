<script setup lang="ts">
import { login, register } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    canRegister: boolean;
}>();

const mobileMenuOpen = ref(false);

const openFaq = ref<number | null>(null);

function toggleFaq(index: number): void {
    openFaq.value = openFaq.value === index ? null : index;
}

const features = [
    {
        name: 'Inventory Management',
        description: 'Track every piece across locations with detailed attributes, images, and real-time stock levels.',
        icon: 'inventory',
    },
    {
        name: 'Multi-Channel Selling',
        description: 'Sell on Shopify, eBay, Amazon, Walmart, and Etsy — all synced from one dashboard.',
        icon: 'channels',
    },
    {
        name: 'Consignment & Memos',
        description: 'Manage vendor relationships, track memo items, due dates, and returns with ease.',
        icon: 'consignment',
    },
    {
        name: 'Repairs & Services',
        description: 'Track repair orders from intake to completion. Manage vendors and keep customers informed.',
        icon: 'repairs',
    },
    {
        name: 'AI-Powered Assistant',
        description: 'A chat widget for your storefront customers — answer questions and drive sales automatically.',
        icon: 'ai',
    },
    {
        name: 'Point of Sale',
        description: 'Barcode scanning, layaways, invoicing, and payments — all built for jewelry retail.',
        icon: 'pos',
    },
];

const faqs = [
    {
        question: 'What is Shopmata?',
        answer: 'Shopmata is an all-in-one platform designed specifically for jewelry retailers. It combines inventory management, multi-channel selling, consignment tracking, repairs, and point of sale into a single, easy-to-use system.',
    },
    {
        question: 'Which selling platforms does Shopmata support?',
        answer: 'Shopmata integrates with Shopify, eBay, Amazon, Walmart, and Etsy. You can manage all your listings and orders from one dashboard, with real-time inventory sync across all channels.',
    },
    {
        question: 'How long does it take to set up?',
        answer: 'Most jewelers are up and running within minutes. Simply create your account, connect your selling channels, and start managing your business. Our onboarding process guides you through every step.',
    },
    {
        question: 'Is there a free trial?',
        answer: 'Yes! Shopmata is free during our beta period. Sign up today and get full access to all features at no cost. As we grow, we\'ll offer affordable plans that scale with your business.',
    },
    {
        question: 'Is my data secure?',
        answer: 'Absolutely. We use industry-standard encryption and security practices to protect your data. Your inventory, customer, and financial information is stored securely and never shared with third parties.',
    },
    {
        question: 'What kind of support do you offer?',
        answer: 'We offer email support and are building out additional support channels. As a beta user, you\'ll also have direct access to our team for feedback and feature requests.',
    },
];

const steps = [
    {
        number: '1',
        title: 'Sign up',
        description: 'Create your free account in under a minute. No credit card required.',
    },
    {
        number: '2',
        title: 'Connect your channels',
        description: 'Link your Shopify, eBay, Amazon, Walmart, or Etsy stores to sync inventory automatically.',
    },
    {
        number: '3',
        title: 'Manage everything in one place',
        description: 'Track inventory, process repairs, manage consignments, and sell across channels — all from one dashboard.',
    },
];
</script>

<template>
    <Head title="Shopmata — The All-in-One Platform for Jewelry Businesses" />

    <div class="min-h-screen bg-white dark:bg-gray-950" style="scroll-behavior: smooth">
        <!-- Navigation -->
        <nav class="sticky top-0 z-50 border-b border-gray-100 bg-white/90 backdrop-blur-sm dark:border-gray-800 dark:bg-gray-950/90">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <div class="flex items-center gap-2">
                        <img
                            src="https://fashionerize.nyc3.cdn.digitaloceanspaces.com/shopmata-logo.png"
                            alt="Shopmata"
                            class="h-8 w-auto"
                        />
                        <span class="text-lg font-bold text-gray-900 dark:text-white">Shopmata</span>
                    </div>

                    <!-- Desktop nav -->
                    <div class="hidden items-center gap-8 md:flex">
                        <a href="#features" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Features</a>
                        <a href="#about" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">About</a>
                        <a href="#faq" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">FAQ</a>
                        <a href="#pricing" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Pricing</a>
                    </div>

                    <div class="hidden items-center gap-4 md:flex">
                        <Link
                            :href="login()"
                            class="text-sm font-semibold text-gray-700 hover:text-indigo-600 dark:text-gray-300 dark:hover:text-white"
                        >
                            Sign in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Start free trial
                        </Link>
                    </div>

                    <!-- Mobile hamburger -->
                    <button
                        class="md:hidden"
                        @click="mobileMenuOpen = !mobileMenuOpen"
                        aria-label="Toggle menu"
                    >
                        <svg v-if="!mobileMenuOpen" class="h-6 w-6 text-gray-700 dark:text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg v-else class="h-6 w-6 text-gray-700 dark:text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Mobile menu -->
                <div v-if="mobileMenuOpen" class="border-t border-gray-100 pb-4 md:hidden dark:border-gray-800">
                    <div class="flex flex-col gap-3 pt-4">
                        <a href="#features" class="text-sm font-medium text-gray-600 dark:text-gray-400" @click="mobileMenuOpen = false">Features</a>
                        <a href="#about" class="text-sm font-medium text-gray-600 dark:text-gray-400" @click="mobileMenuOpen = false">About</a>
                        <a href="#faq" class="text-sm font-medium text-gray-600 dark:text-gray-400" @click="mobileMenuOpen = false">FAQ</a>
                        <a href="#pricing" class="text-sm font-medium text-gray-600 dark:text-gray-400" @click="mobileMenuOpen = false">Pricing</a>
                        <div class="flex items-center gap-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <Link :href="login()" class="text-sm font-semibold text-gray-700 dark:text-gray-300">Sign in</Link>
                            <Link
                                v-if="canRegister"
                                :href="register()"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Start free trial
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Hero Section -->
        <div class="relative isolate overflow-hidden">
            <div class="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80" aria-hidden="true">
                <div class="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-indigo-200 to-purple-300 opacity-30 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem] dark:from-indigo-900 dark:to-purple-900 dark:opacity-20" />
            </div>
            <div class="mx-auto max-w-7xl px-6 pb-24 pt-16 sm:pb-32 lg:flex lg:px-8 lg:py-40">
                <div class="mx-auto max-w-2xl shrink-0 lg:mx-0 lg:max-w-xl lg:pt-8">
                    <div class="flex items-center gap-3">
                        <img
                            src="https://fashionerize.nyc3.cdn.digitaloceanspaces.com/shopmata-logo.png"
                            alt="Shopmata"
                            class="h-12 w-auto"
                        />
                    </div>
                    <h1 class="mt-10 text-4xl font-bold tracking-tight text-gray-900 sm:text-6xl dark:text-white">
                        The all-in-one platform for jewelry businesses
                    </h1>
                    <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-400">
                        Streamline your jewelry business with powerful inventory tracking, multi-channel selling, consignment management, repairs, and point of sale — all in one place.
                    </p>
                    <div class="mt-10 flex items-center gap-x-6">
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="rounded-md bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Start free trial
                        </Link>
                        <a href="#features" class="text-sm font-semibold leading-6 text-gray-900 dark:text-white">
                            Learn more <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
                <div class="mx-auto mt-16 flex max-w-2xl sm:mt-24 lg:ml-10 lg:mr-0 lg:mt-0 lg:max-w-none lg:flex-none xl:ml-32">
                    <div class="max-w-3xl flex-none sm:max-w-5xl lg:max-w-none">
                        <div class="rounded-xl bg-gray-900/5 p-2 ring-1 ring-inset ring-gray-900/10 lg:-m-4 lg:rounded-2xl lg:p-4 dark:bg-white/5 dark:ring-white/10">
                            <div class="flex h-[400px] w-[600px] items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600">
                                <svg class="h-32 w-32 text-white/80" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Features Section -->
        <div id="features" class="bg-gray-50 py-24 sm:py-32 dark:bg-gray-900">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl lg:text-center">
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">Everything you need</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                        All-in-one jewelry management
                    </p>
                    <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-400">
                        From inventory tracking to multi-channel selling, we've got the tools to help your jewelry business thrive.
                    </p>
                </div>
                <div class="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-none">
                    <dl class="grid max-w-xl grid-cols-1 gap-x-8 gap-y-16 lg:max-w-none lg:grid-cols-3">
                        <div v-for="feature in features" :key="feature.name" class="flex flex-col">
                            <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900 dark:text-white">
                                <!-- Inventory -->
                                <svg v-if="feature.icon === 'inventory'" class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 2a.75.75 0 01.75.75v.258a33.186 33.186 0 016.668.83.75.75 0 01-.336 1.461 31.28 31.28 0 00-1.103-.232l1.702 7.545a.75.75 0 01-.387.832A4.981 4.981 0 0115 14c-.825 0-1.606-.2-2.294-.556a.75.75 0 01-.387-.832l1.77-7.849a31.743 31.743 0 00-3.339-.254v11.505a20.01 20.01 0 013.78.501.75.75 0 11-.339 1.462A18.558 18.558 0 0010 17.5c-1.442 0-2.845.165-4.191.477a.75.75 0 01-.338-1.462 20.01 20.01 0 013.779-.501V4.509c-1.129.026-2.243.112-3.34.254l1.771 7.85a.75.75 0 01-.387.83A4.981 4.981 0 015 14a4.981 4.981 0 01-2.294-.556.75.75 0 01-.387-.832L4.02 5.067a31.28 31.28 0 00-1.103.232.75.75 0 01-.336-1.462 33.186 33.186 0 016.668-.829V2.75A.75.75 0 0110 2zM5 12.088l-1.25 5.544a3.483 3.483 0 002.5 0L5 12.088zm10 0l-1.25 5.544a3.483 3.483 0 002.5 0L15 12.088z" clip-rule="evenodd" />
                                </svg>
                                <!-- Channels -->
                                <svg v-else-if="feature.icon === 'channels'" class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M4.25 2A2.25 2.25 0 002 4.25v2.5A2.25 2.25 0 004.25 9h2.5A2.25 2.25 0 009 6.75v-2.5A2.25 2.25 0 006.75 2h-2.5zm0 9A2.25 2.25 0 002 13.25v2.5A2.25 2.25 0 004.25 18h2.5A2.25 2.25 0 009 15.75v-2.5A2.25 2.25 0 006.75 11h-2.5zm9-9A2.25 2.25 0 0011 4.25v2.5A2.25 2.25 0 0013.25 9h2.5A2.25 2.25 0 0018 6.75v-2.5A2.25 2.25 0 0015.75 2h-2.5zm0 9A2.25 2.25 0 0011 13.25v2.5A2.25 2.25 0 0013.25 18h2.5A2.25 2.25 0 0018 15.75v-2.5A2.25 2.25 0 0015.75 11h-2.5z" />
                                </svg>
                                <!-- Consignment -->
                                <svg v-else-if="feature.icon === 'consignment'" class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M1 4.25a3.733 3.733 0 012.25-.75h13.5c.844 0 1.623.279 2.25.75A2.25 2.25 0 0016.75 2H3.25A2.25 2.25 0 001 4.25zM1 7.25a3.733 3.733 0 012.25-.75h13.5c.844 0 1.623.279 2.25.75A2.25 2.25 0 0016.75 5H3.25A2.25 2.25 0 001 7.25zM7 8a1 1 0 000 2h6a1 1 0 100-2H7zM3.25 8A2.25 2.25 0 001 10.25v5.5A2.25 2.25 0 003.25 18h13.5A2.25 2.25 0 0019 15.75v-5.5A2.25 2.25 0 0016.75 8H3.25z" />
                                </svg>
                                <!-- Repairs -->
                                <svg v-else-if="feature.icon === 'repairs'" class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M13.2 2.24a.75.75 0 00.04 1.06l2.1 1.95H6.75a.75.75 0 000 1.5h8.59l-2.1 1.95a.75.75 0 101.02 1.1l3.5-3.25a.75.75 0 000-1.1l-3.5-3.25a.75.75 0 00-1.06.04zm-6.4 8a.75.75 0 00-1.06-.04l-3.5 3.25a.75.75 0 000 1.1l3.5 3.25a.75.75 0 101.02-1.1l-2.1-1.95h8.59a.75.75 0 000-1.5H4.66l2.1-1.95a.75.75 0 00.04-1.06z" clip-rule="evenodd" />
                                </svg>
                                <!-- AI -->
                                <svg v-else-if="feature.icon === 'ai'" class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 2c-2.236 0-4.43.18-6.57.524C1.993 2.755 1 4.014 1 5.426v5.148c0 1.413.993 2.67 2.43 2.902 1.168.188 2.352.327 3.55.414.28.02.521.18.642.413l1.713 3.293a.75.75 0 001.33 0l1.713-3.293a.783.783 0 01.642-.413 41.102 41.102 0 003.55-.414c1.437-.231 2.43-1.49 2.43-2.902V5.426c0-1.413-.993-2.67-2.43-2.902A41.289 41.289 0 0010 2zM6.75 6a.75.75 0 000 1.5h6.5a.75.75 0 000-1.5h-6.5zm0 2.5a.75.75 0 000 1.5h3.5a.75.75 0 000-1.5h-3.5z" clip-rule="evenodd" />
                                </svg>
                                <!-- POS -->
                                <svg v-else class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M1 4a1 1 0 011-1h16a1 1 0 011 1v8a1 1 0 01-1 1H2a1 1 0 01-1-1V4zm12 4a3 3 0 11-6 0 3 3 0 016 0zM4 9a1 1 0 100-2 1 1 0 000 2zm12-1a1 1 0 11-2 0 1 1 0 012 0zM2 15a1 1 0 011-1h14a1 1 0 110 2H3a1 1 0 01-1-1z" />
                                </svg>
                                {{ feature.name }}
                            </dt>
                            <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600 dark:text-gray-400">
                                <p class="flex-auto">{{ feature.description }}</p>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <!-- About Section -->
        <div id="about" class="bg-white py-24 sm:py-32 dark:bg-gray-950">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl lg:text-center">
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">About Shopmata</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                        Built for jewelers, by people who understand jewelry retail
                    </p>
                    <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-400">
                        We know the challenges of running a jewelry business — from managing consignment relationships to keeping inventory in sync across multiple storefronts and online channels. Shopmata was built to solve these problems so you can focus on what matters: your customers and your craft.
                    </p>
                </div>
                <div class="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-8 sm:grid-cols-3 lg:max-w-none">
                    <div class="rounded-2xl border border-gray-200 p-8 text-center dark:border-gray-800">
                        <p class="text-4xl font-bold text-indigo-600 dark:text-indigo-400">Multi-Location</p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Manage inventory across all your stores and warehouses</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 p-8 text-center dark:border-gray-800">
                        <p class="text-4xl font-bold text-indigo-600 dark:text-indigo-400">Multi-Channel</p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Sell on 5+ platforms with automatic inventory sync</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 p-8 text-center dark:border-gray-800">
                        <p class="text-4xl font-bold text-indigo-600 dark:text-indigo-400">Real-Time Sync</p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Changes propagate instantly across all your channels</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- How It Works Section -->
        <div class="bg-gray-50 py-24 sm:py-32 dark:bg-gray-900">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl lg:text-center">
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">Simple setup</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                        Up and running in minutes
                    </p>
                </div>
                <div class="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-8 lg:max-w-none lg:grid-cols-3">
                    <div v-for="step in steps" :key="step.number" class="relative rounded-2xl border border-gray-200 bg-white p-8 dark:border-gray-800 dark:bg-gray-950">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600 text-lg font-bold text-white">
                            {{ step.number }}
                        </div>
                        <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">{{ step.title }}</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ step.description }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing Section -->
        <div id="pricing" class="bg-white py-24 sm:py-32 dark:bg-gray-950">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl lg:text-center">
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">Pricing</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                        Start free — upgrade as you grow
                    </p>
                </div>
                <div class="mx-auto mt-16 max-w-lg">
                    <div class="rounded-3xl border border-gray-200 p-8 ring-1 ring-indigo-600/10 dark:border-gray-800 dark:ring-indigo-400/10">
                        <h3 class="text-lg font-semibold leading-8 text-gray-900 dark:text-white">Free during beta</h3>
                        <p class="mt-4 text-sm leading-6 text-gray-600 dark:text-gray-400">
                            Get full access to every feature while we're in beta. No credit card required.
                        </p>
                        <p class="mt-6 flex items-baseline gap-x-1">
                            <span class="text-4xl font-bold tracking-tight text-gray-900 dark:text-white">$0</span>
                            <span class="text-sm font-semibold leading-6 text-gray-600 dark:text-gray-400">/month</span>
                        </p>
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="mt-6 block w-full rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Start free trial
                        </Link>
                        <ul class="mt-8 space-y-3 text-sm leading-6 text-gray-600 dark:text-gray-400">
                            <li class="flex gap-x-3">
                                <svg class="h-6 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                Unlimited inventory items
                            </li>
                            <li class="flex gap-x-3">
                                <svg class="h-6 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                Multi-channel selling (Shopify, eBay, Amazon, Walmart, Etsy)
                            </li>
                            <li class="flex gap-x-3">
                                <svg class="h-6 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                Consignment & memo tracking
                            </li>
                            <li class="flex gap-x-3">
                                <svg class="h-6 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                Repairs & services management
                            </li>
                            <li class="flex gap-x-3">
                                <svg class="h-6 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                Point of sale
                            </li>
                            <li class="flex gap-x-3">
                                <svg class="h-6 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                AI-powered storefront assistant
                            </li>
                            <li class="flex gap-x-3">
                                <svg class="h-6 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                                Multi-location support
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQ Section -->
        <div id="faq" class="bg-gray-50 py-24 sm:py-32 dark:bg-gray-900">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl lg:text-center">
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">FAQ</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                        Frequently asked questions
                    </p>
                </div>
                <div class="mx-auto mt-16 max-w-2xl">
                    <dl class="space-y-4">
                        <div
                            v-for="(faq, index) in faqs"
                            :key="index"
                            class="rounded-lg border border-gray-200 dark:border-gray-800"
                        >
                            <button
                                class="flex w-full items-center justify-between px-6 py-4 text-left"
                                @click="toggleFaq(index)"
                            >
                                <dt class="text-base font-semibold leading-7 text-gray-900 dark:text-white">
                                    {{ faq.question }}
                                </dt>
                                <svg
                                    class="h-5 w-5 flex-none text-gray-400 transition-transform duration-200"
                                    :class="{ 'rotate-180': openFaq === index }"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                >
                                    <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 011.06 0L10 11.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 9.28a.75.75 0 010-1.06z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <dd
                                v-if="openFaq === index"
                                class="border-t border-gray-200 px-6 py-4 text-base leading-7 text-gray-600 dark:border-gray-800 dark:text-gray-400"
                            >
                                {{ faq.answer }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Testimonials / Social Proof Placeholder -->
        <div class="bg-white py-24 sm:py-32 dark:bg-gray-950">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">Trusted by jewelers</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                        Jewelry retailers love Shopmata
                    </p>
                    <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-400">
                        Join a growing community of jewelry professionals who are streamlining their operations with Shopmata.
                    </p>
                </div>
            </div>
        </div>

        <!-- Final CTA Section -->
        <div class="bg-indigo-600 dark:bg-indigo-700">
            <div class="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Ready to streamline your jewelry business?
                    </h2>
                    <p class="mx-auto mt-6 max-w-xl text-lg leading-8 text-indigo-100">
                        Start managing your inventory, sales, and operations from a single platform — for free.
                    </p>
                    <div class="mt-10 flex items-center justify-center gap-x-6">
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="rounded-md bg-white px-5 py-3 text-sm font-semibold text-indigo-600 shadow-sm hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                        >
                            Start free trial
                        </Link>
                        <Link
                            :href="login()"
                            class="text-sm font-semibold leading-6 text-white"
                        >
                            Sign in <span aria-hidden="true">&rarr;</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="border-t border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-950">
            <div class="mx-auto max-w-7xl px-6 py-12 lg:px-8">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="flex items-center gap-2">
                        <img
                            src="https://fashionerize.nyc3.cdn.digitaloceanspaces.com/shopmata-logo.png"
                            alt="Shopmata"
                            class="h-6 w-auto"
                        />
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Shopmata</span>
                    </div>
                    <div class="mt-6 flex flex-wrap gap-6 md:mt-0">
                        <a href="#features" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Features</a>
                        <a href="#about" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">About</a>
                        <a href="#faq" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">FAQ</a>
                        <a href="#pricing" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Pricing</a>
                        <Link href="/terms" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Terms of Service</Link>
                        <Link href="/privacy" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Privacy Policy</Link>
                        <Link :href="login()" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Sign in</Link>
                        <Link v-if="canRegister" :href="register()" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Register</Link>
                    </div>
                </div>
                <div class="mt-8 border-t border-gray-100 pt-8 dark:border-gray-800">
                    <p class="text-center text-sm leading-5 text-gray-500 dark:text-gray-400">
                        &copy; {{ new Date().getFullYear() }} Shopmata. All rights reserved.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
