<script setup lang="ts">
interface Props {
    rows?: number;
    columns?: number;
}

withDefaults(defineProps<Props>(), {
    rows: 5,
    columns: 5,
});
</script>

<template>
    <div class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10">
        <!-- Header skeleton -->
        <div class="border-b border-gray-200 px-4 py-5 sm:px-6 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div class="h-6 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                <div class="h-8 w-48 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
            </div>
        </div>

        <!-- Table skeleton -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <!-- Header -->
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th
                            v-for="col in columns"
                            :key="col"
                            scope="col"
                            class="px-3 py-3.5"
                        >
                            <div class="h-4 w-20 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        </th>
                    </tr>
                </thead>

                <!-- Body -->
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                    <tr v-for="row in rows" :key="row">
                        <td
                            v-for="col in columns"
                            :key="col"
                            class="whitespace-nowrap px-3 py-4"
                        >
                            <div
                                :class="[
                                    'h-4 animate-pulse rounded bg-gray-200 dark:bg-gray-700',
                                    col === 1 ? 'w-24' : col === columns ? 'w-16' : 'w-32',
                                ]"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination skeleton -->
        <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 sm:px-6 dark:border-gray-700">
            <div class="h-4 w-48 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
            <div class="flex gap-1">
                <div v-for="i in 5" :key="i" class="h-8 w-8 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
            </div>
        </div>
    </div>
</template>
