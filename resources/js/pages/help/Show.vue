<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface HelpArticle {
    id: number;
    category: string;
    title: string;
    slug: string;
    content: string;
    excerpt: string | null;
}

interface ArticleLink {
    title: string;
    slug: string;
}

interface Props {
    article: HelpArticle;
    allArticles: Record<string, HelpArticle[]>;
    previous: ArticleLink | null;
    next: ArticleLink | null;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Help Center', href: '/help' },
    { title: props.article.title, href: `/help/${props.article.slug}` },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="`${article.title} - Help`" />

        <div class="mx-auto max-w-6xl px-4 py-8">
            <div class="flex gap-8">
                <!-- Sidebar -->
                <aside class="hidden w-64 shrink-0 lg:block">
                    <Link href="/help" class="mb-4 flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <ArrowLeftIcon class="h-4 w-4" />
                        Back to Help Center
                    </Link>

                    <nav class="space-y-6">
                        <div v-for="(articles, category) in allArticles" :key="category">
                            <h3 class="mb-2 text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                {{ category }}
                            </h3>
                            <ul class="space-y-1">
                                <li v-for="a in articles" :key="a.id">
                                    <Link
                                        :href="`/help/${a.slug}`"
                                        :class="[
                                            'block rounded-md px-2 py-1.5 text-sm',
                                            a.id === article.id
                                                ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-500/10 dark:text-blue-400'
                                                : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white',
                                        ]"
                                    >
                                        {{ a.title }}
                                    </Link>
                                </li>
                            </ul>
                        </div>
                    </nav>
                </aside>

                <!-- Main content -->
                <div class="min-w-0 flex-1">
                    <Link href="/help" class="mb-4 flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 lg:hidden dark:text-gray-400 dark:hover:text-gray-200">
                        <ArrowLeftIcon class="h-4 w-4" />
                        Back to Help Center
                    </Link>

                    <div class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ article.category }}
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ article.title }}
                    </h1>

                    <!-- Article content -->
                    <div
                        class="prose prose-sm dark:prose-invert mt-6 max-w-none prose-headings:font-semibold prose-a:text-blue-600 dark:prose-a:text-blue-400"
                        v-html="article.content"
                    />

                    <!-- Prev / Next -->
                    <div class="mt-10 flex items-center justify-between border-t border-gray-200 pt-6 dark:border-white/10">
                        <Link
                            v-if="previous"
                            :href="`/help/${previous.slug}`"
                            class="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                        >
                            <ChevronLeftIcon class="h-4 w-4" />
                            {{ previous.title }}
                        </Link>
                        <div v-else />

                        <Link
                            v-if="next"
                            :href="`/help/${next.slug}`"
                            class="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                        >
                            {{ next.title }}
                            <ChevronRightIcon class="h-4 w-4" />
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
