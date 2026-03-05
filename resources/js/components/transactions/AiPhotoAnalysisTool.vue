<script setup lang="ts">
import { ref, computed } from 'vue';
import { XMarkIcon, PhotoIcon, ShoppingCartIcon } from '@heroicons/vue/24/outline';

interface AiResearch {
    description?: string;
    market_value_low?: number;
    market_value_high?: number;
    suggested_buy_price?: number;
    suggested_retail_price?: number;
    suggested_wholesale_price?: number;
    notable_features?: string[];
    condition_notes?: string;
    precious_metal?: string;
    [key: string]: unknown;
}

const emit = defineEmits<{
    addItem: [data: {
        title: string;
        description?: string;
        price: number;
        buy_price: number;
        precious_metal?: string;
        images: File[];
    }];
}>();

const title = ref('');
const description = ref('');
const images = ref<File[]>([]);
const imagePreviews = ref<string[]>([]);
const uploadedImageUrls = ref<string[]>([]);
const aiResearch = ref<AiResearch | null>(null);
const uploading = ref(false);
const analyzing = ref(false);
const error = ref('');
const step = ref<'upload' | 'results'>('upload');

const canAnalyze = computed(() => {
    return images.value.length > 0 && title.value.trim().length > 0;
});

const marketAverage = computed(() => {
    if (!aiResearch.value?.market_value_low || !aiResearch.value?.market_value_high) return null;
    return (aiResearch.value.market_value_low + aiResearch.value.market_value_high) / 2;
});

function handleFileSelect(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        addImages(Array.from(target.files));
    }
    target.value = '';
}

function handleDrop(event: DragEvent) {
    event.preventDefault();
    if (event.dataTransfer?.files) {
        addImages(Array.from(event.dataTransfer.files));
    }
}

function handleDragOver(event: DragEvent) {
    event.preventDefault();
}

function addImages(files: File[]) {
    const imageFiles = files.filter(f => f.type.startsWith('image/'));
    const remaining = 4 - images.value.length;
    const toAdd = imageFiles.slice(0, remaining);

    for (const file of toAdd) {
        images.value.push(file);
        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreviews.value.push(e.target?.result as string);
        };
        reader.readAsDataURL(file);
    }
}

function removeImage(index: number) {
    images.value.splice(index, 1);
    imagePreviews.value.splice(index, 1);
}

async function analyze() {
    if (!canAnalyze.value) return;

    error.value = '';
    uploading.value = true;

    try {
        // Step 1: Upload images to get public URLs
        const formData = new FormData();
        images.value.forEach((img) => {
            formData.append('images[]', img);
        });

        const uploadResponse = await fetch('/transactions/quick-evaluation/temp-images', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
            },
            credentials: 'same-origin',
            body: formData,
        });

        if (!uploadResponse.ok) {
            throw new Error('Failed to upload images');
        }

        const uploadData = await uploadResponse.json();
        uploadedImageUrls.value = uploadData.image_urls;

        uploading.value = false;
        analyzing.value = true;

        // Step 2: Call AI research with image URLs
        const researchResponse = await fetch('/transactions/quick-evaluation/ai-research', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                title: title.value,
                description: description.value || undefined,
                image_urls: uploadedImageUrls.value,
            }),
        });

        if (!researchResponse.ok) {
            throw new Error('Failed to analyze item');
        }

        const researchData = await researchResponse.json();
        aiResearch.value = researchData.research;
        step.value = 'results';
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred';
    } finally {
        uploading.value = false;
        analyzing.value = false;
    }
}

function handleAddItem() {
    if (!aiResearch.value) return;

    const avgPrice = marketAverage.value || 0;
    const suggestedBuy = aiResearch.value.suggested_buy_price || avgPrice;

    emit('addItem', {
        title: title.value,
        description: aiResearch.value.description || description.value || undefined,
        price: avgPrice,
        buy_price: suggestedBuy,
        precious_metal: aiResearch.value.precious_metal || undefined,
        images: [...images.value],
    });
}

function reset() {
    title.value = '';
    description.value = '';
    images.value = [];
    imagePreviews.value = [];
    uploadedImageUrls.value = [];
    aiResearch.value = null;
    error.value = '';
    step.value = 'upload';
}
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <!-- Upload Step -->
        <template v-if="step === 'upload'">
            <div class="space-y-4">
                <!-- Title input -->
                <div>
                    <label for="ai-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Item Title <span class="text-red-500">*</span>
                    </label>
                    <input
                        id="ai-title"
                        v-model="title"
                        type="text"
                        placeholder="e.g., Gold ring, Rolex watch, Diamond necklace"
                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <!-- Description input (optional) -->
                <div>
                    <label for="ai-description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Description <span class="text-xs text-gray-400">(optional)</span>
                    </label>
                    <textarea
                        id="ai-description"
                        v-model="description"
                        rows="2"
                        placeholder="Any additional details about the item..."
                        class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                    />
                </div>

                <!-- Image upload area -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Photos <span class="text-xs text-gray-400">(up to 4)</span>
                    </label>

                    <!-- Image previews -->
                    <div v-if="imagePreviews.length > 0" class="mt-2 flex flex-wrap gap-2">
                        <div v-for="(preview, index) in imagePreviews" :key="index" class="group relative">
                            <img :src="preview" class="size-20 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-600" />
                            <button
                                type="button"
                                @click="removeImage(index)"
                                class="absolute -right-1.5 -top-1.5 rounded-full bg-red-500 p-0.5 text-white opacity-0 shadow-sm transition-opacity group-hover:opacity-100"
                            >
                                <XMarkIcon class="size-3.5" />
                            </button>
                        </div>

                        <!-- Add more button -->
                        <label
                            v-if="images.length < 4"
                            class="flex size-20 cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 text-gray-400 hover:border-indigo-400 hover:text-indigo-500 dark:border-gray-600"
                        >
                            <PhotoIcon class="size-6" />
                            <input type="file" accept="image/*" multiple class="hidden" @change="handleFileSelect" />
                        </label>
                    </div>

                    <!-- Drop zone (when no images) -->
                    <div
                        v-else
                        @drop="handleDrop"
                        @dragover="handleDragOver"
                        class="mt-2 flex justify-center rounded-lg border-2 border-dashed border-gray-300 px-6 py-6 dark:border-gray-600"
                    >
                        <div class="text-center">
                            <PhotoIcon class="mx-auto size-8 text-gray-400" />
                            <div class="mt-2 flex text-sm text-gray-600 dark:text-gray-400">
                                <label class="relative cursor-pointer font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                    <span>Upload photos</span>
                                    <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="handleFileSelect" />
                                </label>
                                <p class="pl-1">or drag and drop</p>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">PNG, JPG up to 10MB each</p>
                        </div>
                    </div>
                </div>

                <!-- Error message -->
                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

                <!-- Analyze button -->
                <div class="flex justify-end">
                    <button
                        type="button"
                        :disabled="!canAnalyze || uploading || analyzing"
                        @click="analyze"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <template v-if="uploading">
                            <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            Uploading...
                        </template>
                        <template v-else-if="analyzing">
                            <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            Analyzing with AI...
                        </template>
                        <template v-else>
                            Analyze Item
                        </template>
                    </button>
                </div>
            </div>
        </template>

        <!-- Results Step -->
        <template v-if="step === 'results' && aiResearch">
            <div class="space-y-4">
                <!-- Header with back button -->
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">AI Analysis Results</h4>
                    <button
                        type="button"
                        @click="reset"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        Start Over
                    </button>
                </div>

                <!-- Image preview row -->
                <div v-if="imagePreviews.length > 0" class="flex gap-2">
                    <img v-for="(preview, index) in imagePreviews" :key="index" :src="preview" class="size-16 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-600" />
                </div>

                <!-- Description -->
                <div v-if="aiResearch.description">
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ aiResearch.description }}</p>
                </div>

                <!-- Pricing Grid -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-if="aiResearch.market_value_low && aiResearch.market_value_high" class="rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Market Value</p>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">
                            ${{ aiResearch.market_value_low.toFixed(0) }} - ${{ aiResearch.market_value_high.toFixed(0) }}
                        </p>
                    </div>
                    <div v-if="aiResearch.suggested_buy_price" class="rounded-lg bg-green-50 p-3 dark:bg-green-900/20">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Suggested Buy</p>
                        <p class="text-sm font-bold text-green-600 dark:text-green-400">${{ aiResearch.suggested_buy_price.toFixed(2) }}</p>
                    </div>
                    <div v-if="aiResearch.suggested_retail_price" class="rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Suggested Retail</p>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">${{ aiResearch.suggested_retail_price.toFixed(2) }}</p>
                    </div>
                    <div v-if="aiResearch.suggested_wholesale_price" class="rounded-lg bg-gray-50 p-3 dark:bg-gray-700/50">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Wholesale</p>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">${{ aiResearch.suggested_wholesale_price.toFixed(2) }}</p>
                    </div>
                </div>

                <!-- Notable Features -->
                <div v-if="aiResearch.notable_features && aiResearch.notable_features.length > 0">
                    <p class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Notable Features</p>
                    <ul class="list-inside list-disc space-y-0.5 text-sm text-gray-700 dark:text-gray-300">
                        <li v-for="(feature, i) in aiResearch.notable_features" :key="i">{{ feature }}</li>
                    </ul>
                </div>

                <!-- Condition Notes -->
                <div v-if="aiResearch.condition_notes">
                    <p class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Condition Notes</p>
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ aiResearch.condition_notes }}</p>
                </div>

                <!-- Buy this item button -->
                <div class="flex justify-end">
                    <button
                        type="button"
                        @click="handleAddItem"
                        class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        <ShoppingCartIcon class="-ml-0.5 size-4" />
                        Buy this item
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
