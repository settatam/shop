<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { ChevronUpDownIcon, CheckIcon, ArchiveBoxIcon } from '@heroicons/vue/20/solid';

interface Bucket {
    id: number;
    name: string;
    total_value: number;
}

interface Props {
    modelValue?: number | null;
    placeholder?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: null,
    placeholder: 'Select a bucket',
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
}>();

const buckets = ref<Bucket[]>([]);
const loading = ref(true);
const open = ref(false);

onMounted(async () => {
    try {
        const response = await fetch('/buckets/search');
        const data = await response.json();
        buckets.value = data.buckets;
    } catch (error) {
        console.error('Failed to fetch buckets:', error);
    } finally {
        loading.value = false;
    }
});

const selectedBucket = computed(() => {
    return buckets.value.find(b => b.id === props.modelValue);
});

function selectBucket(bucket: Bucket | null) {
    emit('update:modelValue', bucket?.id ?? null);
    open.value = false;
}

function formatCurrency(amount: number) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            :disabled="disabled || loading"
            class="relative w-full cursor-pointer rounded-md bg-white py-1.5 pl-3 pr-10 text-left text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 disabled:cursor-not-allowed disabled:opacity-50"
            @click="open = !open"
        >
            <span v-if="loading" class="block truncate text-gray-400">Loading...</span>
            <span v-else-if="selectedBucket" class="flex items-center gap-2">
                <ArchiveBoxIcon class="size-4 text-gray-400" />
                <span class="block truncate">{{ selectedBucket.name }}</span>
                <span class="text-xs text-gray-500">({{ formatCurrency(selectedBucket.total_value) }})</span>
            </span>
            <span v-else class="block truncate text-gray-400">{{ placeholder }}</span>
            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                <ChevronUpDownIcon class="size-5 text-gray-400" />
            </span>
        </button>

        <div
            v-if="open && !loading"
            class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm dark:bg-gray-700 dark:ring-white/10"
        >
            <div
                v-if="modelValue"
                class="relative cursor-pointer select-none py-2 pl-3 pr-9 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-600"
                @click="selectBucket(null)"
            >
                Clear selection
            </div>
            <div
                v-for="bucket in buckets"
                :key="bucket.id"
                class="relative cursor-pointer select-none py-2 pl-3 pr-9 hover:bg-indigo-600 hover:text-white dark:hover:bg-indigo-600"
                :class="{ 'bg-indigo-600 text-white': bucket.id === modelValue, 'text-gray-900 dark:text-white': bucket.id !== modelValue }"
                @click="selectBucket(bucket)"
            >
                <div class="flex items-center gap-2">
                    <ArchiveBoxIcon class="size-4" :class="{ 'text-white': bucket.id === modelValue, 'text-gray-400': bucket.id !== modelValue }" />
                    <span class="block truncate" :class="{ 'font-semibold': bucket.id === modelValue }">
                        {{ bucket.name }}
                    </span>
                    <span class="text-xs" :class="{ 'text-indigo-200': bucket.id === modelValue, 'text-gray-500': bucket.id !== modelValue }">
                        {{ formatCurrency(bucket.total_value) }}
                    </span>
                </div>
                <span
                    v-if="bucket.id === modelValue"
                    class="absolute inset-y-0 right-0 flex items-center pr-4 text-white"
                >
                    <CheckIcon class="size-5" />
                </span>
            </div>
            <div v-if="buckets.length === 0" class="py-2 pl-3 pr-9 text-gray-500 dark:text-gray-400">
                No buckets found. Create one first.
            </div>
        </div>

        <!-- Backdrop to close dropdown -->
        <div
            v-if="open"
            class="fixed inset-0 z-0"
            @click="open = false"
        />
    </div>
</template>
