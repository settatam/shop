<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

interface CurrentStore {
    id: number;
    name: string;
    slug: string;
    logo: string | null;
    logo_url: string | null;
    edition: string;
    edition_name: string;
    edition_logo: string | null;
}

const page = usePage<{ currentStore: CurrentStore | null }>();

const currentStore = computed(() => page.props.currentStore);

// Use edition logo if available, otherwise fall back to store logo
const logoUrl = computed(() => {
    if (currentStore.value?.edition_logo) {
        return currentStore.value.edition_logo;
    }
    return currentStore.value?.logo_url;
});

// Display name: edition name or store name
const displayName = computed(() => {
    return currentStore.value?.edition_name || currentStore.value?.name || 'Shopmata';
});
</script>

<template>
    <div class="flex items-center gap-2">
        <div
            v-if="logoUrl"
            class="flex size-8 items-center justify-center"
        >
            <img
                :src="logoUrl"
                :alt="displayName"
                class="h-8 w-auto object-contain"
            />
        </div>
        <div
            v-else
            class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
        >
            <span class="text-sm font-bold">{{ displayName.charAt(0) }}</span>
        </div>
        <div class="grid flex-1 text-left text-sm">
            <span class="mb-0.5 truncate leading-tight font-semibold">
                {{ displayName }}
            </span>
        </div>
    </div>
</template>
