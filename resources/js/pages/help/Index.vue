<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface HelpArticle {
    id: number;
    category: string;
    title: string;
    slug: string;
    excerpt: string | null;
}

interface Props {
    articles: HelpArticle[];
    categories: string[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Help Center', href: '/help' },
];

const searchQuery = ref('');

const filteredArticles = computed(() => {
    if (!searchQuery.value.trim()) {
        return props.articles;
    }

    const query = searchQuery.value.toLowerCase();
    return props.articles.filter(
        (article) =>
            article.title.toLowerCase().includes(query) ||
            (article.excerpt && article.excerpt.toLowerCase().includes(query)),
    );
});

const groupedArticles = computed(() => {
    const groups: Record<string, HelpArticle[]> = {};
    for (const article of filteredArticles.value) {
        if (!groups[article.category]) {
            groups[article.category] = [];
        }
        groups[article.category].push(article);
    }
    return groups;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Help Center" />

        <div class="mx-auto max-w-4xl px-4 py-8">
            <div class="mb-8 text-center">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Help Center</h1>
                <p class="mt-2 text-gray-500 dark:text-gray-400">
                    Learn how to use Shopmata to manage your inventory, buys, orders, and more.
                </p>
            </div>

            <!-- Search -->
            <div class="relative mx-auto mb-10 max-w-xl">
                <MagnifyingGlassIcon class="absolute top-1/2 left-3 h-5 w-5 -translate-y-1/2 text-gray-400" />
                <Input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search help articles..."
                    class="pl-10"
                />
            </div>

            <!-- No results -->
            <p
                v-if="filteredArticles.length === 0"
                class="py-12 text-center text-sm text-gray-500 dark:text-gray-400"
            >
                No articles match your search. Try a different query.
            </p>

            <!-- Articles grouped by category -->
            <div v-for="(articles, category) in groupedArticles" :key="category" class="mb-8">
                <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">
                    {{ category }}
                </h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Link
                        v-for="article in articles"
                        :key="article.id"
                        :href="`/help/${article.slug}`"
                        class="rounded-lg border border-gray-200 bg-white p-4 transition hover:border-blue-300 hover:shadow-sm dark:border-white/10 dark:bg-white/5 dark:hover:border-blue-500/50"
                    >
                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ article.title }}
                        </h3>
                        <p v-if="article.excerpt" class="mt-1 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">
                            {{ article.excerpt }}
                        </p>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
