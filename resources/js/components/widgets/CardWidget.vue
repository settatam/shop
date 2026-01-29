<script setup lang="ts">
import { computed } from 'vue';
import type { WidgetData } from '@/composables/useWidget';

interface Props {
    data: WidgetData;
    loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    loading: false,
});

const cardConfig = computed(() => props.data.data || {});
const header = computed(() => cardConfig.value.header || props.data.title);
const body = computed(() => cardConfig.value.body || '');
const links = computed(() => (cardConfig.value.links as Array<{ label: string; href: string }>) || []);
const hasFooter = computed(() => cardConfig.value.hasFooter || links.value.length > 0);
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <!-- Header -->
        <div class="border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                {{ header }}
            </h3>
        </div>

        <!-- Body -->
        <div class="px-4 py-5 sm:p-6">
            <div v-if="body" class="text-sm text-gray-500 dark:text-gray-300" v-html="body" />
            <slot v-else />
        </div>

        <!-- Footer with links -->
        <div v-if="hasFooter" class="border-t border-gray-200 px-4 py-4 sm:px-6 dark:border-gray-700">
            <div class="flex flex-wrap gap-4">
                <a
                    v-for="link in links"
                    :key="link.href"
                    :href="link.href"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                >
                    {{ link.label }}
                </a>
            </div>
        </div>
    </div>
</template>
