<script setup lang="ts">
import { dashboard, login, register } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="REB12345 Inventory Management for Jewelers" />

    <div class="min-h-screen bg-white dark:bg-gray-900">
        <!-- Navigation -->
        <nav class="border-b border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <div class="flex items-center">
<!--                        <AppLogo class="h-8 w-auto" />-->
                    </div>
                    <div class="flex items-center gap-4">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="dashboard()"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            Dashboard
                        </Link>
                        <template v-else>
                            <Link
                                :href="login()"
                                class="text-sm font-semibold text-gray-700 hover:text-indigo-600 dark:text-gray-300 dark:hover:text-white"
                            >
                                Sign in
                            </Link>
<!--                            <Link-->
<!--                                v-if="canRegister"-->
<!--                                :href="register()"-->
<!--                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"-->
<!--                            >-->
<!--                                Start free trial-->
<!--                            </Link>-->
                        </template>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Hero Section -->
        <div class="relative isolate overflow-hidden">
            <div class="mx-auto max-w-7xl px-6 pb-24 pt-10 sm:pb-32 lg:flex lg:px-8 lg:py-40">
                <div class="mx-auto max-w-2xl shrink-0 lg:mx-0 lg:max-w-xl lg:pt-8">
                    <h1 class="mt-10 text-4xl font-bold tracking-tight text-gray-900 sm:text-6xl dark:text-white">
                        Jewelry inventory management made simple
                    </h1>
                    <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-400">
                        Streamline your jewelry business with powerful inventory tracking, consignment management, repairs, and point of sale—all in one platform.
                    </p>
<!--                    <div class="mt-10 flex items-center gap-x-6">-->
<!--                        <Link-->
<!--                            v-if="!$page.props.auth.user && canRegister"-->
<!--                            :href="register()"-->
<!--                            class="rounded-md bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"-->
<!--                        >-->
<!--                            Get started for free-->
<!--                        </Link>-->
<!--                        <Link-->
<!--                            v-else-if="$page.props.auth.user"-->
<!--                            :href="dashboard()"-->
<!--                            class="rounded-md bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"-->
<!--                        >-->
<!--                            Go to Dashboard-->
<!--                        </Link>-->
<!--                        <a href="#features" class="text-sm font-semibold leading-6 text-gray-900 dark:text-white">-->
<!--                            Learn more <span aria-hidden="true">→</span>-->
<!--                        </a>-->
<!--                    </div>-->
                </div>
                <div class="mx-auto mt-16 flex max-w-2xl sm:mt-24 lg:ml-10 lg:mr-0 lg:mt-0 lg:max-w-none lg:flex-none xl:ml-32">
                    <div class="max-w-3xl flex-none sm:max-w-5xl lg:max-w-none">
                        <div class="rounded-xl bg-gray-900/5 p-2 ring-1 ring-inset ring-gray-900/10 lg:-m-4 lg:rounded-2xl lg:p-4 dark:bg-white/5 dark:ring-white/10">
                            <div class="h-[400px] w-[600px] rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center">
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
        <div id="features" class="bg-gray-50 py-24 sm:py-32 dark:bg-gray-800">
            <div class="mx-auto max-w-7xl px-6 lg:px-8">
                <div class="mx-auto max-w-2xl lg:text-center">
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">Everything you need</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                        All-in-one jewelry management
                    </p>
                    <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-400">
                        From inventory tracking to consignment memos, we've got the tools to help your jewelry business thrive.
                    </p>
                </div>
                <div class="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-none">
                    <dl class="grid max-w-xl grid-cols-1 gap-x-8 gap-y-16 lg:max-w-none lg:grid-cols-3">
                        <div class="flex flex-col">
                            <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900 dark:text-white">
                                <svg class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 2a.75.75 0 01.75.75v.258a33.186 33.186 0 016.668.83.75.75 0 01-.336 1.461 31.28 31.28 0 00-1.103-.232l1.702 7.545a.75.75 0 01-.387.832A4.981 4.981 0 0115 14c-.825 0-1.606-.2-2.294-.556a.75.75 0 01-.387-.832l1.77-7.849a31.743 31.743 0 00-3.339-.254v11.505a20.01 20.01 0 013.78.501.75.75 0 11-.339 1.462A18.558 18.558 0 0010 17.5c-1.442 0-2.845.165-4.191.477a.75.75 0 01-.338-1.462 20.01 20.01 0 013.779-.501V4.509c-1.129.026-2.243.112-3.34.254l1.771 7.85a.75.75 0 01-.387.83A4.981 4.981 0 015 14a4.981 4.981 0 01-2.294-.556.75.75 0 01-.387-.832L4.02 5.067a31.28 31.28 0 00-1.103.232.75.75 0 01-.336-1.462 33.186 33.186 0 016.668-.829V2.75A.75.75 0 0110 2zM5 12.088l-1.25 5.544a3.483 3.483 0 002.5 0L5 12.088zm10 0l-1.25 5.544a3.483 3.483 0 002.5 0L15 12.088z" clip-rule="evenodd" />
                                </svg>
                                Inventory Management
                            </dt>
                            <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600 dark:text-gray-400">
                                <p class="flex-auto">Track every piece in your inventory with detailed attributes, images, and real-time stock levels across multiple locations.</p>
                            </dd>
                        </div>
                        <div class="flex flex-col">
                            <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900 dark:text-white">
                                <svg class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M1 4.25a3.733 3.733 0 012.25-.75h13.5c.844 0 1.623.279 2.25.75A2.25 2.25 0 0016.75 2H3.25A2.25 2.25 0 001 4.25zM1 7.25a3.733 3.733 0 012.25-.75h13.5c.844 0 1.623.279 2.25.75A2.25 2.25 0 0016.75 5H3.25A2.25 2.25 0 001 7.25zM7 8a1 1 0 000 2h6a1 1 0 100-2H7zM3.25 8A2.25 2.25 0 001 10.25v5.5A2.25 2.25 0 003.25 18h13.5A2.25 2.25 0 0019 15.75v-5.5A2.25 2.25 0 0016.75 8H3.25z" />
                                </svg>
                                Consignment Memos
                            </dt>
                            <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600 dark:text-gray-400">
                                <p class="flex-auto">Manage consignment relationships with vendors seamlessly. Track memo items, due dates, and returns with ease.</p>
                            </dd>
                        </div>
                        <div class="flex flex-col">
                            <dt class="flex items-center gap-x-3 text-base font-semibold leading-7 text-gray-900 dark:text-white">
                                <svg class="h-5 w-5 flex-none text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M13.2 2.24a.75.75 0 00.04 1.06l2.1 1.95H6.75a.75.75 0 000 1.5h8.59l-2.1 1.95a.75.75 0 101.02 1.1l3.5-3.25a.75.75 0 000-1.1l-3.5-3.25a.75.75 0 00-1.06.04zm-6.4 8a.75.75 0 00-1.06-.04l-3.5 3.25a.75.75 0 000 1.1l3.5 3.25a.75.75 0 101.02-1.1l-2.1-1.95h8.59a.75.75 0 000-1.5H4.66l2.1-1.95a.75.75 0 00.04-1.06z" clip-rule="evenodd" />
                                </svg>
                                Repairs & Services
                            </dt>
                            <dd class="mt-4 flex flex-auto flex-col text-base leading-7 text-gray-600 dark:text-gray-400">
                                <p class="flex-auto">Track repair orders from intake to completion. Manage vendor relationships and keep customers informed.</p>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <!-- CTA Section -->
        <div class="bg-white dark:bg-gray-900">
            <div class="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
<!--                    <h2 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">-->
<!--                        Ready to get started?-->
<!--                    </h2>-->
                    <p class="mx-auto mt-6 max-w-xl text-lg leading-8 text-gray-600 dark:text-gray-400">
                        Join jewelry retailers who trust REB12345 to manage their business.
                    </p>
<!--                    <div class="mt-10 flex items-center justify-center gap-x-6">-->
<!--                        <Link-->
<!--                            v-if="!$page.props.auth.user && canRegister"-->
<!--                            :href="register()"-->
<!--                            class="rounded-md bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"-->
<!--                        >-->
<!--                            Start your free trial-->
<!--                        </Link>-->
<!--                        <Link-->
<!--                            :href="login()"-->
<!--                            class="text-sm font-semibold leading-6 text-gray-900 dark:text-white"-->
<!--                        >-->
<!--                            Sign in <span aria-hidden="true">→</span>-->
<!--                        </Link>-->
<!--                    </div>-->
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="border-t border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="mx-auto max-w-7xl px-6 py-12 lg:px-8">
                <p class="text-center text-sm leading-5 text-gray-500 dark:text-gray-400">
                    &copy; {{ new Date().getFullYear() }} Shopmata. All rights reserved.
                </p>
            </div>
        </footer>
    </div>
</template>
