<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ShoppingCartIcon } from '@heroicons/vue/24/outline';

interface SelectOption {
    value: string;
    label: string;
}

interface Props {
    preciousMetals: SelectOption[];
    hideAddButton?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    hideAddButton: false,
});

const emit = defineEmits<{
    addItem: [data: {
        precious_metal: string;
        dwt: number;
        price: number;
        buy_price: number;
    }];
}>();

const page = usePage();
const currentStoreId = computed(() => (page.props.currentStore as { id: number } | null)?.id);

const selectedMetal = ref('');
const dwtWeight = ref<number | undefined>(undefined);
const spotPrice = ref<number | null>(null);
const buyPrice = ref<number | null>(null);
const loading = ref(false);
const error = ref('');

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const metalLabel = computed(() => {
    const metal = props.preciousMetals.find(m => m.value === selectedMetal.value);
    return metal?.label || '';
});

const canCalculate = computed(() => {
    return selectedMetal.value && dwtWeight.value && dwtWeight.value > 0;
});

watch([selectedMetal, dwtWeight], () => {
    if (!canCalculate.value) {
        spotPrice.value = null;
        buyPrice.value = null;
        return;
    }

    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(calculatePrice, 300);
});

async function calculatePrice() {
    if (!canCalculate.value) return;

    loading.value = true;
    error.value = '';

    try {
        let url = `/api/v1/metal-prices/calculate?precious_metal=${encodeURIComponent(selectedMetal.value)}&dwt=${dwtWeight.value}`;
        if (currentStoreId.value) {
            url += `&store_id=${currentStoreId.value}`;
        }

        const response = await fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            const data = await response.json();
            spotPrice.value = data.spot_price ?? null;
            buyPrice.value = data.buy_price ?? data.spot_price ?? null;
        } else {
            error.value = 'Failed to calculate price';
            spotPrice.value = null;
            buyPrice.value = null;
        }
    } catch {
        error.value = 'Failed to calculate price';
        spotPrice.value = null;
        buyPrice.value = null;
    } finally {
        loading.value = false;
    }
}

function handleAddItem() {
    if (!selectedMetal.value || !dwtWeight.value || spotPrice.value === null || buyPrice.value === null) return;

    emit('addItem', {
        precious_metal: selectedMetal.value,
        dwt: dwtWeight.value,
        price: spotPrice.value,
        buy_price: buyPrice.value,
    });
}
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <!-- Metal Type -->
            <div>
                <label for="metal-type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Metal Type
                </label>
                <select
                    id="metal-type"
                    v-model="selectedMetal"
                    class="mt-1 block w-full rounded-md border-0 py-2 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                >
                    <option value="">Select metal...</option>
                    <option v-for="metal in preciousMetals" :key="metal.value" :value="metal.value">
                        {{ metal.label }}
                    </option>
                </select>
            </div>

            <!-- DWT Weight -->
            <div>
                <label for="dwt-weight" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Weight (DWT)
                </label>
                <input
                    id="dwt-weight"
                    v-model.number="dwtWeight"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                />
            </div>

            <!-- Results -->
            <div class="flex items-end gap-3">
                <div v-if="loading" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Calculating...
                </div>
                <div v-else-if="spotPrice !== null && buyPrice !== null" class="flex flex-1 items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Spot Price</p>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">${{ spotPrice.toFixed(2) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Buy Price</p>
                        <p class="text-lg font-bold text-green-600 dark:text-green-400">${{ buyPrice.toFixed(2) }}</p>
                    </div>
                    <button
                        v-if="!hideAddButton"
                        type="button"
                        @click="handleAddItem"
                        class="inline-flex shrink-0 items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        <ShoppingCartIcon class="-ml-0.5 size-4" />
                        Buy this item
                    </button>
                </div>
                <div v-else-if="error" class="text-sm text-red-600 dark:text-red-400">
                    {{ error }}
                </div>
                <div v-else class="text-sm text-gray-400 dark:text-gray-500">
                    Select a metal and enter weight
                </div>
            </div>
        </div>
    </div>
</template>
