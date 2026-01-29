<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import {
    MapPinIcon,
    TagIcon,
    CheckCircleIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    PlusCircleIcon,
} from '@heroicons/vue/24/outline';
import { complete } from '@/actions/App/Http/Controllers/Web/OnboardingController';

interface ProductCategory {
    id: number;
    name: string;
}

interface Store {
    id: number;
    name: string;
    address?: string;
    city?: string;
    state?: string;
    zip?: string;
}

interface ExistingCategory {
    id: number;
    name: string;
    children_count: number;
}

const props = defineProps<{
    store: Store | null;
    productCategories?: ProductCategory[];
    existingCategories?: ExistingCategory[];
    hasExistingSetup?: boolean;
}>();

// Safely access productCategories with fallback to empty array
const categories = computed(() => props.productCategories ?? []);

// Wizard state
const currentStep = ref(1);
const totalSteps = 3;
const isSubmitting = ref(false);
const loadingChildren = ref<number | null>(null);
const childrenCache = ref<Record<number, { parent: { id: number; name: string }; children: { id: number; name: string }[] }>>({});

// Form data
const formData = ref({
    // Step 1: Business Address (optional)
    address_line1: props.store?.address || '',
    address_line2: '',
    city: props.store?.city || '',
    state: props.store?.state || '',
    postal_code: props.store?.zip || '',
    country: 'US',

    // Step 2: Categories
    ebay_category_ids: [] as number[],
    skip_categories: false, // Allow user to skip AI category generation

    // Options
    create_sample_data: false,
});

// Check if user already has existing categories/templates
const hasExistingSetup = computed(() => props.hasExistingSetup ?? false);
const existingCategoriesCount = computed(() => props.existingCategories?.length ?? 0);

const countries = [
    { code: 'US', name: 'United States' },
    { code: 'CA', name: 'Canada' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'AU', name: 'Australia' },
    { code: 'DE', name: 'Germany' },
    { code: 'FR', name: 'France' },
    { code: 'ES', name: 'Spain' },
    { code: 'IT', name: 'Italy' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'JP', name: 'Japan' },
];

const steps = [
    { number: 1, name: 'Address', icon: MapPinIcon },
    { number: 2, name: 'Categories', icon: TagIcon },
    { number: 3, name: 'Review', icon: CheckCircleIcon },
];

const selectedCategories = computed(() => {
    return categories.value.filter(cat =>
        formData.value.ebay_category_ids.includes(cat.id)
    );
});

const canProceed = computed(() => {
    switch (currentStep.value) {
        case 1:
            return true; // Address is optional
        case 2:
            // Can proceed if categories selected OR user chose to skip OR already has setup
            return formData.value.ebay_category_ids.length > 0 ||
                   formData.value.skip_categories ||
                   hasExistingSetup.value;
        case 3:
            return true; // Always allow completion from review step
        default:
            return false;
    }
});

function toggleCategory(categoryId: number) {
    const index = formData.value.ebay_category_ids.indexOf(categoryId);
    if (index === -1) {
        formData.value.ebay_category_ids.push(categoryId);
        // Fetch children when selecting a category
        fetchCategoryChildren(categoryId);
    } else {
        formData.value.ebay_category_ids.splice(index, 1);
    }
}

function isCategorySelected(categoryId: number): boolean {
    return formData.value.ebay_category_ids.includes(categoryId);
}

async function fetchCategoryChildren(categoryId: number) {
    if (childrenCache.value[categoryId]) return;

    loadingChildren.value = categoryId;

    try {
        const response = await fetch(`/onboarding/ebay-categories/${categoryId}/children`);
        const data = await response.json();
        childrenCache.value[categoryId] = data;
    } catch (error) {
        console.error('Failed to fetch category children:', error);
    } finally {
        loadingChildren.value = null;
    }
}

function nextStep() {
    if (currentStep.value < totalSteps && canProceed.value) {
        currentStep.value++;
    }
}

function prevStep() {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
}

function submitOnboarding() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;

    router.post(complete(), {
        ebay_category_ids: formData.value.skip_categories ? [] : formData.value.ebay_category_ids,
        skip_categories: formData.value.skip_categories,
        address_line1: formData.value.address_line1 || null,
        address_line2: formData.value.address_line2 || null,
        city: formData.value.city || null,
        state: formData.value.state || null,
        postal_code: formData.value.postal_code || null,
        country: formData.value.country || null,
        create_sample_data: formData.value.create_sample_data,
    }, {
        preserveState: false,
        preserveScroll: false,
        onFinish: () => {
            isSubmitting.value = false;
        },
        onError: () => {
            isSubmitting.value = false;
        },
    });
}

// Helper to safely get children from cache
function getCategoryChildren(catId: number) {
    return childrenCache.value[catId]?.children ?? [];
}

function getCategoryChildrenCount(catId: number) {
    return getCategoryChildren(catId).length;
}
</script>

<template>
    <Head title="Set Up Your Store" />

    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <!-- Header -->
        <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-white/10">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <AppLogo class="h-8 w-auto" />
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        Setting up: <span class="font-medium text-gray-900 dark:text-white">{{ store?.name }}</span>
                    </span>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
            <!-- Progress steps -->
            <div class="mb-8">
                <nav aria-label="Progress">
                    <ol role="list" class="flex items-center justify-center">
                        <li v-for="(step, stepIdx) in steps" :key="step.name" :class="[stepIdx !== steps.length - 1 ? 'pr-8 sm:pr-20' : '', 'relative']">
                            <template v-if="currentStep > step.number">
                                <!-- Completed step -->
                                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                                    <div class="h-0.5 w-full bg-indigo-600"></div>
                                </div>
                                <div class="relative flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600">
                                    <CheckCircleIcon class="h-6 w-6 text-white" />
                                </div>
                            </template>
                            <template v-else-if="currentStep === step.number">
                                <!-- Current step -->
                                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                                    <div class="h-0.5 w-full bg-gray-200 dark:bg-white/10"></div>
                                </div>
                                <div class="relative flex h-10 w-10 items-center justify-center rounded-full border-2 border-indigo-600 bg-white dark:bg-gray-800">
                                    <component :is="step.icon" class="h-5 w-5 text-indigo-600" />
                                </div>
                            </template>
                            <template v-else>
                                <!-- Upcoming step -->
                                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                                    <div class="h-0.5 w-full bg-gray-200 dark:bg-white/10"></div>
                                </div>
                                <div class="relative flex h-10 w-10 items-center justify-center rounded-full border-2 border-gray-300 dark:border-white/20 bg-white dark:bg-gray-800">
                                    <component :is="step.icon" class="h-5 w-5 text-gray-400" />
                                </div>
                            </template>
                        </li>
                    </ol>
                </nav>
                <div class="mt-4 text-center">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                        Step {{ currentStep }} of {{ totalSteps }}: {{ steps[currentStep - 1].name }}
                    </p>
                </div>
            </div>

            <!-- Card container -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10">
                <!-- Step content -->
                <div class="px-6 py-8 sm:px-10">
                    <!-- Step 1: Address -->
                    <div v-if="currentStep === 1" class="space-y-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                                Welcome! Let's get started
                            </h2>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                Add your business address (optional). This will be used for shipping labels and invoices.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label for="address1" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Address line 1
                                </label>
                                <input
                                    type="text"
                                    id="address1"
                                    v-model="formData.address_line1"
                                    placeholder="123 Main Street"
                                    class="mt-1 block w-full rounded-lg border-0 bg-white dark:bg-gray-900 py-2.5 px-4 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-white/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                />
                            </div>
                            <div>
                                <label for="address2" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Address line 2
                                </label>
                                <input
                                    type="text"
                                    id="address2"
                                    v-model="formData.address_line2"
                                    placeholder="Suite 100"
                                    class="mt-1 block w-full rounded-lg border-0 bg-white dark:bg-gray-900 py-2.5 px-4 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-white/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="city" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        City
                                    </label>
                                    <input
                                        type="text"
                                        id="city"
                                        v-model="formData.city"
                                        placeholder="San Francisco"
                                        class="mt-1 block w-full rounded-lg border-0 bg-white dark:bg-gray-900 py-2.5 px-4 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-white/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                    />
                                </div>
                                <div>
                                    <label for="state" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        State / Province
                                    </label>
                                    <input
                                        type="text"
                                        id="state"
                                        v-model="formData.state"
                                        placeholder="CA"
                                        class="mt-1 block w-full rounded-lg border-0 bg-white dark:bg-gray-900 py-2.5 px-4 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-white/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                    />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="postal" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Postal code
                                    </label>
                                    <input
                                        type="text"
                                        id="postal"
                                        v-model="formData.postal_code"
                                        placeholder="94102"
                                        class="mt-1 block w-full rounded-lg border-0 bg-white dark:bg-gray-900 py-2.5 px-4 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-white/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                    />
                                </div>
                                <div>
                                    <label for="country" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Country
                                    </label>
                                    <select
                                        id="country"
                                        v-model="formData.country"
                                        class="mt-1 block w-full rounded-lg border-0 bg-white dark:bg-gray-900 py-2.5 px-4 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-white/10 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                    >
                                        <option v-for="country in countries" :key="country.code" :value="country.code">
                                            {{ country.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Categories -->
                    <div v-else-if="currentStep === 2" class="space-y-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                                What do you sell?
                            </h2>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                Select categories to auto-generate subcategories and templates, or skip to create your own later.
                            </p>
                        </div>

                        <!-- Existing setup notice -->
                        <div v-if="hasExistingSetup" class="rounded-lg bg-green-50 dark:bg-green-500/10 p-4 border border-green-200 dark:border-green-500/20">
                            <div class="flex items-start gap-3">
                                <CheckCircleIcon class="h-5 w-5 text-green-600 dark:text-green-400 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-sm font-medium text-green-800 dark:text-green-300">
                                        You already have {{ existingCategoriesCount }} categories set up!
                                    </p>
                                    <p class="mt-1 text-sm text-green-700 dark:text-green-400">
                                        You can skip this step or add more categories from the suggestions below.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Skip option -->
                        <div class="rounded-lg border-2 border-dashed border-gray-200 dark:border-white/10 p-4">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="formData.skip_categories"
                                    class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white flex items-center gap-2">
                                        <PlusCircleIcon class="h-4 w-4" />
                                        I'll create my own categories
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Skip the AI-generated categories and create your own custom category structure later.
                                    </p>
                                </div>
                            </label>
                        </div>

                        <!-- Category selection (hidden when skipping) -->
                        <div v-if="!formData.skip_categories && categories.length > 0" class="space-y-4">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Or select from common product categories:
                            </p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                <button
                                    v-for="category in categories"
                                    :key="category.id"
                                    type="button"
                                    @click="toggleCategory(category.id)"
                                    :class="[
                                        isCategorySelected(category.id)
                                            ? 'ring-2 ring-indigo-600 bg-indigo-50 dark:bg-indigo-500/10'
                                            : 'ring-1 ring-gray-200 dark:ring-white/10 hover:bg-gray-50 dark:hover:bg-white/5',
                                        'relative rounded-lg p-4 text-left transition-all',
                                    ]"
                                >
                                    <div class="flex items-center gap-3">
                                        <div
                                            :class="[
                                                isCategorySelected(category.id)
                                                    ? 'bg-indigo-600 border-indigo-600'
                                                    : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-white/20',
                                                'flex h-5 w-5 shrink-0 items-center justify-center rounded border',
                                            ]"
                                        >
                                            <CheckCircleIcon
                                                v-if="isCategorySelected(category.id)"
                                                class="h-4 w-4 text-white"
                                            />
                                        </div>
                                        <p :class="[
                                            isCategorySelected(category.id)
                                                ? 'text-indigo-600 dark:text-indigo-400'
                                                : 'text-gray-900 dark:text-white',
                                            'text-sm font-medium leading-tight',
                                        ]">
                                            {{ category.name }}
                                        </p>
                                    </div>
                                </button>
                            </div>

                            <!-- Selected categories preview -->
                            <div v-if="selectedCategories.length > 0" class="rounded-lg bg-gray-50 dark:bg-white/5 p-4">
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Selected categories ({{ selectedCategories.length }}):
                                </p>
                                <div class="mt-3 space-y-3">
                                    <div v-for="cat in selectedCategories" :key="cat.id" class="border-l-2 border-indigo-500 pl-3">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ cat.name }}</p>
                                        <div v-if="loadingChildren === cat.id" class="mt-1">
                                            <span class="text-xs text-gray-500">Loading subcategories...</span>
                                        </div>
                                        <div v-else-if="getCategoryChildrenCount(cat.id) > 0" class="mt-1 flex flex-wrap gap-1">
                                            <span
                                                v-for="child in getCategoryChildren(cat.id).slice(0, 8)"
                                                :key="child.id"
                                                class="inline-flex items-center rounded bg-white dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-200 dark:ring-white/10"
                                            >
                                                {{ child.name }}
                                            </span>
                                            <span
                                                v-if="getCategoryChildrenCount(cat.id) > 8"
                                                class="inline-flex items-center px-2 py-0.5 text-xs text-gray-500"
                                            >
                                                +{{ getCategoryChildrenCount(cat.id) - 8 }} more
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Guidance message -->
                        <p v-if="!formData.skip_categories && formData.ebay_category_ids.length === 0 && !hasExistingSetup" class="text-sm text-gray-500 dark:text-gray-400">
                            Select categories above or check "I'll create my own" to continue.
                        </p>
                    </div>

                    <!-- Step 3: Review -->
                    <div v-else-if="currentStep === 3" class="space-y-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                                Review & Complete
                            </h2>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                Everything look good? We'll set up your store with the following configuration.
                            </p>
                        </div>

                        <dl class="divide-y divide-gray-200 dark:divide-white/10">
                            <div class="py-4 flex justify-between">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Store name</dt>
                                <dd class="text-sm text-gray-900 dark:text-white">{{ store?.name }}</dd>
                            </div>
                            <div v-if="formData.city || formData.address_line1" class="py-4 flex justify-between">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Location</dt>
                                <dd class="text-sm text-gray-900 dark:text-white text-right">
                                    <div v-if="formData.address_line1">{{ formData.address_line1 }}</div>
                                    <div>
                                        {{ formData.city }}<span v-if="formData.city && formData.state">, </span>{{ formData.state }} {{ formData.postal_code }}
                                    </div>
                                </dd>
                            </div>

                            <!-- Categories section -->
                            <div class="py-4">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">
                                    <template v-if="formData.skip_categories">
                                        Categories
                                    </template>
                                    <template v-else-if="selectedCategories.length > 0">
                                        Categories to create ({{ selectedCategories.length }} parent categories)
                                    </template>
                                    <template v-else-if="hasExistingSetup">
                                        Existing categories
                                    </template>
                                    <template v-else>
                                        Categories
                                    </template>
                                </dt>
                                <dd>
                                    <!-- User chose to skip -->
                                    <div v-if="formData.skip_categories" class="rounded-lg bg-amber-50 dark:bg-amber-500/10 p-3">
                                        <p class="text-sm text-amber-700 dark:text-amber-400">
                                            You've chosen to create your own categories. You can set these up from the Categories page after completing setup.
                                        </p>
                                    </div>

                                    <!-- Selected categories to create -->
                                    <div v-else-if="selectedCategories.length > 0" class="space-y-2">
                                        <div v-for="cat in selectedCategories" :key="cat.id" class="rounded-lg bg-gray-50 dark:bg-white/5 p-3">
                                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ cat.name }}</p>
                                            <div v-if="getCategoryChildrenCount(cat.id) > 0" class="mt-2 flex flex-wrap gap-1">
                                                <span
                                                    v-for="child in getCategoryChildren(cat.id)"
                                                    :key="child.id"
                                                    class="inline-flex items-center rounded bg-white dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-600 dark:text-gray-300"
                                                >
                                                    {{ child.name }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Has existing setup, no new categories selected -->
                                    <div v-else-if="hasExistingSetup" class="rounded-lg bg-green-50 dark:bg-green-500/10 p-3">
                                        <p class="text-sm text-green-700 dark:text-green-400">
                                            You have {{ existingCategoriesCount }} existing categories. No new categories will be created.
                                        </p>
                                    </div>

                                    <!-- No categories at all -->
                                    <div v-else class="rounded-lg bg-gray-50 dark:bg-white/5 p-3">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            No categories selected. You can create them later from the Categories page.
                                        </p>
                                    </div>
                                </dd>
                            </div>
                        </dl>

                        <div class="rounded-lg bg-indigo-50 dark:bg-indigo-500/10 p-4">
                            <p class="text-sm text-indigo-700 dark:text-indigo-400">
                                <strong>Also included:</strong> Default warehouse at your address, and standard user roles (Owner, Admin, Manager, Staff, Viewer).
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Footer with navigation -->
                <div class="border-t border-gray-200 dark:border-white/10 px-6 py-4 sm:px-10 flex justify-between">
                    <button
                        v-if="currentStep > 1"
                        type="button"
                        @click="prevStep"
                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/10"
                    >
                        <ChevronLeftIcon class="h-4 w-4" />
                        Back
                    </button>
                    <div v-else></div>

                    <button
                        v-if="currentStep < totalSteps"
                        type="button"
                        @click="nextStep"
                        :disabled="!canProceed"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Continue
                        <ChevronRightIcon class="h-4 w-4" />
                    </button>
                    <button
                        v-else
                        type="button"
                        @click="submitOnboarding"
                        :disabled="isSubmitting"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ isSubmitting ? 'Setting up...' : 'Complete Setup' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
