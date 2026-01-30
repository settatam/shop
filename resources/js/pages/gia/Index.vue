<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    DocumentTextIcon,
    ArrowPathIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline';
import axios from 'axios';

interface Category {
    id: number;
    name: string;
    full_path: string;
    is_stud: boolean;
    template_name: string | null;
}

interface Props {
    categories: Category[];
    isConfigured: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Products', href: '/products' },
    { title: 'GIA Entry', href: '/gia' },
];

// Form state
const selectedCategoryId = ref<number | null>(null);
const giaNumber1 = ref('');
const giaNumber2 = ref('');
const isSubmitting = ref(false);
const isLookingUp = ref(false);
const error = ref<string | null>(null);
const success = ref<{ message: string; productUrl: string } | null>(null);
const warnings = ref<string[]>([]);

// GIA data preview
const giaPreview1 = ref<any>(null);
const giaPreview2 = ref<any>(null);

const selectedCategory = computed(() => {
    return props.categories.find(c => c.id === selectedCategoryId.value);
});

const isStud = computed(() => {
    return selectedCategory.value?.is_stud ?? false;
});

const canSubmit = computed(() => {
    if (!selectedCategoryId.value || !giaNumber1.value.trim()) {
        return false;
    }
    // For studs, both GIA numbers are required
    if (isStud.value && !giaNumber2.value.trim()) {
        return false;
    }
    return true;
});

// Reset form when category changes
watch(selectedCategoryId, () => {
    giaNumber1.value = '';
    giaNumber2.value = '';
    giaPreview1.value = null;
    giaPreview2.value = null;
    error.value = null;
    success.value = null;
    warnings.value = [];
});

async function lookupGia(reportNumber: string, index: 1 | 2) {
    if (!reportNumber.trim()) {
        if (index === 1) giaPreview1.value = null;
        else giaPreview2.value = null;
        return;
    }

    isLookingUp.value = true;
    error.value = null;

    try {
        const response = await axios.post('/gia/lookup', {
            reference_number: reportNumber.trim(),
        });

        if (index === 1) {
            giaPreview1.value = response.data.data;
        } else {
            giaPreview2.value = response.data.data;
        }
    } catch (err: any) {
        if (index === 1) giaPreview1.value = null;
        else giaPreview2.value = null;

        const message = err.response?.data?.message || 'Failed to lookup GIA number';
        error.value = `GIA ${reportNumber}: ${message}`;
    } finally {
        isLookingUp.value = false;
    }
}

async function submitForm() {
    if (!canSubmit.value || isSubmitting.value) return;

    isSubmitting.value = true;
    error.value = null;
    success.value = null;
    warnings.value = [];

    try {
        const response = await axios.post('/gia/data', {
            reference_number: giaNumber1.value.trim(),
            gia2: isStud.value ? giaNumber2.value.trim() : null,
            product_type_id: selectedCategoryId.value,
        });

        if (response.data.warnings) {
            warnings.value = response.data.warnings;
        }

        success.value = {
            message: 'Product created successfully!',
            productUrl: response.data.redirect_url,
        };

        // Reset form for next entry
        giaNumber1.value = '';
        giaNumber2.value = '';
        giaPreview1.value = null;
        giaPreview2.value = null;
    } catch (err: any) {
        const messages = err.response?.data?.message;
        if (Array.isArray(messages)) {
            error.value = messages.join('\n');
        } else {
            error.value = messages || 'Failed to create product from GIA data';
        }
    } finally {
        isSubmitting.value = false;
    }
}

function goToProduct() {
    if (success.value?.productUrl) {
        router.visit(success.value.productUrl);
    }
}

function formatGrade(report: any): string {
    const color = report.results?.data?.color_grades?.color_grade_code || report.results?.color_grade || '-';
    const clarity = report.results?.data?.clarity || report.results?.clarity_grade || '-';
    const cut = report.results?.cut_grade || '-';
    return `${color} / ${clarity} / ${cut}`;
}
</script>

<template>
    <Head title="GIA Product Entry" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto w-full max-w-3xl">
                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">GIA Product Entry</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Create products directly from GIA certificate data
                    </p>
                </div>

                <!-- Not configured warning -->
                <div v-if="!props.isConfigured" class="mb-6 rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20">
                    <div class="flex items-start">
                        <ExclamationCircleIcon class="size-5 text-yellow-400" />
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">GIA Integration Not Configured</h3>
                            <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                                Please configure your GIA API credentials in
                                <a href="/integrations" class="font-medium underline hover:text-yellow-600">Settings &gt; Integrations</a>
                                before using this feature.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Success message -->
                <div v-if="success" class="mb-6 rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                    <div class="flex items-start">
                        <CheckCircleIcon class="size-5 text-green-400" />
                        <div class="ml-3 flex-1">
                            <p class="text-sm font-medium text-green-800 dark:text-green-200">{{ success.message }}</p>
                            <div class="mt-2">
                                <button
                                    type="button"
                                    @click="goToProduct"
                                    class="text-sm font-medium text-green-700 underline hover:text-green-600 dark:text-green-300"
                                >
                                    View Product
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Error message -->
                <div v-if="error" class="mb-6 rounded-lg bg-red-50 p-4 dark:bg-red-900/20">
                    <div class="flex items-start">
                        <ExclamationCircleIcon class="size-5 text-red-400" />
                        <div class="ml-3">
                            <p class="whitespace-pre-line text-sm text-red-700 dark:text-red-300">{{ error }}</p>
                        </div>
                    </div>
                </div>

                <!-- Warnings -->
                <div v-if="warnings.length > 0" class="mb-6 rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20">
                    <div class="flex items-start">
                        <ExclamationCircleIcon class="size-5 text-yellow-400" />
                        <div class="ml-3">
                            <p v-for="(warning, index) in warnings" :key="index" class="text-sm text-yellow-700 dark:text-yellow-300">
                                {{ warning }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <form @submit.prevent="submitForm" class="space-y-6">
                    <!-- Category Selection -->
                    <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Product Type</h2>

                        <div v-if="categories.length === 0" class="rounded-md bg-yellow-50 p-4 dark:bg-yellow-900/20">
                            <div class="flex">
                                <InformationCircleIcon class="size-5 text-yellow-400" />
                                <p class="ml-3 text-sm text-yellow-700 dark:text-yellow-300">
                                    No GIA-eligible categories found. Please create a "Diamond" category under "Loose Stones" or a "Diamond Studs GIA Certified" category.
                                </p>
                            </div>
                        </div>

                        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <button
                                v-for="category in categories"
                                :key="category.id"
                                type="button"
                                @click="selectedCategoryId = category.id"
                                :class="[
                                    'flex flex-col items-start rounded-lg border-2 p-4 text-left transition-all',
                                    selectedCategoryId === category.id
                                        ? 'border-indigo-600 bg-indigo-50 dark:border-indigo-500 dark:bg-indigo-900/20'
                                        : 'border-gray-200 hover:border-gray-300 dark:border-gray-600 dark:hover:border-gray-500',
                                ]"
                            >
                                <div class="flex w-full items-center justify-between">
                                    <DocumentTextIcon class="size-8 text-gray-400" />
                                    <CheckCircleIcon v-if="selectedCategoryId === category.id" class="size-6 text-indigo-600" />
                                </div>
                                <div class="mt-3">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ category.name }}</p>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ category.full_path }}</p>
                                    <p v-if="category.is_stud" class="mt-1 text-xs text-indigo-600 dark:text-indigo-400">
                                        Requires 2 GIA numbers (pair)
                                    </p>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- GIA Number Input -->
                    <div v-if="selectedCategoryId" class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                        <h2 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">GIA Certificate Numbers</h2>

                        <div class="space-y-4">
                            <!-- First GIA Number -->
                            <div>
                                <label for="gia1" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ isStud ? 'First Stone GIA Number' : 'GIA Report Number' }}
                                    <span class="text-red-500">*</span>
                                </label>
                                <div class="mt-1 flex gap-2">
                                    <input
                                        id="gia1"
                                        v-model="giaNumber1"
                                        type="text"
                                        placeholder="e.g., 1234567890"
                                        class="block flex-1 rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                    <button
                                        type="button"
                                        @click="lookupGia(giaNumber1, 1)"
                                        :disabled="!giaNumber1.trim() || isLookingUp"
                                        class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500"
                                    >
                                        <ArrowPathIcon :class="['size-4', isLookingUp ? 'animate-spin' : '']" />
                                        Lookup
                                    </button>
                                </div>

                                <!-- Preview for first stone -->
                                <div v-if="giaPreview1" class="mt-3 rounded-md bg-gray-50 p-3 dark:bg-gray-700/50">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ giaPreview1.results?.carat_weight || '-' }}ct
                                        {{ giaPreview1.results?.shape_and_cutting_style || '-' }}
                                    </p>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        Grades: {{ formatGrade(giaPreview1) }}
                                    </p>
                                </div>
                            </div>

                            <!-- Second GIA Number (for studs) -->
                            <div v-if="isStud">
                                <label for="gia2" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Second Stone GIA Number
                                    <span class="text-red-500">*</span>
                                </label>
                                <div class="mt-1 flex gap-2">
                                    <input
                                        id="gia2"
                                        v-model="giaNumber2"
                                        type="text"
                                        placeholder="e.g., 0987654321"
                                        class="block flex-1 rounded-md border-0 px-3 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                    <button
                                        type="button"
                                        @click="lookupGia(giaNumber2, 2)"
                                        :disabled="!giaNumber2.trim() || isLookingUp"
                                        class="inline-flex items-center gap-2 rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500"
                                    >
                                        <ArrowPathIcon :class="['size-4', isLookingUp ? 'animate-spin' : '']" />
                                        Lookup
                                    </button>
                                </div>

                                <!-- Preview for second stone -->
                                <div v-if="giaPreview2" class="mt-3 rounded-md bg-gray-50 p-3 dark:bg-gray-700/50">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ giaPreview2.results?.carat_weight || '-' }}ct
                                        {{ giaPreview2.results?.shape_and_cutting_style || '-' }}
                                    </p>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        Grades: {{ formatGrade(giaPreview2) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Total weight preview for studs -->
                        <div v-if="isStud && giaPreview1 && giaPreview2" class="mt-4 rounded-md bg-indigo-50 p-3 dark:bg-indigo-900/20">
                            <p class="text-sm font-medium text-indigo-900 dark:text-indigo-200">
                                Total Carat Weight:
                                {{ (parseFloat(giaPreview1.results?.carat_weight || 0) + parseFloat(giaPreview2.results?.carat_weight || 0)).toFixed(2) }}ct
                            </p>
                        </div>
                    </div>

                    <!-- Submit button -->
                    <div v-if="selectedCategoryId" class="flex justify-end gap-4">
                        <button
                            type="submit"
                            :disabled="!canSubmit || isSubmitting"
                            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <ArrowPathIcon v-if="isSubmitting" class="size-4 animate-spin" />
                            {{ isSubmitting ? 'Creating Product...' : 'Create Product' }}
                        </button>
                    </div>
                </form>

                <!-- Info box -->
                <div class="mt-8 rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20">
                    <div class="flex">
                        <InformationCircleIcon class="size-5 text-blue-400" />
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-blue-800 dark:text-blue-200">How it works</h3>
                            <div class="mt-2 text-sm text-blue-700 dark:text-blue-300">
                                <ul class="list-inside list-disc space-y-1">
                                    <li>Enter the GIA certificate number to fetch diamond details from GIA</li>
                                    <li>For Diamond Studs, enter both stones' GIA numbers</li>
                                    <li>Product will be created with all GIA data auto-filled in the template</li>
                                    <li>If a product with this GIA number already exists, it will be updated</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
