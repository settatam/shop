<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    BuildingStorefrontIcon,
    MapPinIcon,
    TagIcon,
    CheckCircleIcon,
    ChevronRightIcon,
    ChevronLeftIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const emit = defineEmits<{
    close: [];
}>();

// Wizard state
const currentStep = ref(1);
const totalSteps = 4;
const isSubmitting = ref(false);

// Form data
const formData = ref({
    // Step 1: Store Info
    name: '',

    // Step 2: Business Address
    address_line1: '',
    address_line2: '',
    city: '',
    state: '',
    postal_code: '',
    country: 'US',

    // Step 3: Industry/Category
    industry: '' as string,

    // Options
    create_sample_data: true,
});

// Industry presets with categories
const industries = [
    {
        id: 'jewelry',
        name: 'Jewelry & Accessories',
        description: 'Rings, necklaces, bracelets, watches',
        icon: '💍',
        categories: ['Rings', 'Necklaces', 'Bracelets', 'Earrings', 'Watches'],
    },
    {
        id: 'electronics',
        name: 'Electronics',
        description: 'Phones, computers, accessories',
        icon: '📱',
        categories: ['Phones', 'Computers', 'Tablets', 'Accessories', 'Audio'],
    },
    {
        id: 'clothing',
        name: 'Clothing & Apparel',
        description: 'Shirts, pants, dresses, shoes',
        icon: '👕',
        categories: ['Men\'s Clothing', 'Women\'s Clothing', 'Kids\' Clothing', 'Shoes', 'Accessories'],
    },
    {
        id: 'home',
        name: 'Home & Garden',
        description: 'Furniture, decor, garden supplies',
        icon: '🏠',
        categories: ['Furniture', 'Decor', 'Kitchen', 'Bedding', 'Garden'],
    },
    {
        id: 'sports',
        name: 'Sports & Outdoors',
        description: 'Equipment, apparel, camping gear',
        icon: '⚽',
        categories: ['Fitness', 'Team Sports', 'Outdoor Recreation', 'Camping', 'Cycling'],
    },
    {
        id: 'beauty',
        name: 'Beauty & Personal Care',
        description: 'Skincare, makeup, haircare',
        icon: '💄',
        categories: ['Skincare', 'Makeup', 'Haircare', 'Fragrances', 'Tools'],
    },
    {
        id: 'toys',
        name: 'Toys & Games',
        description: 'Kids toys, board games, puzzles',
        icon: '🎮',
        categories: ['Action Figures', 'Board Games', 'Educational', 'Outdoor Toys', 'Puzzles'],
    },
    {
        id: 'other',
        name: 'Other',
        description: 'I\'ll set up categories myself',
        icon: '📦',
        categories: ['General'],
    },
];

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
    { number: 1, name: 'Store Info', icon: BuildingStorefrontIcon },
    { number: 2, name: 'Address', icon: MapPinIcon },
    { number: 3, name: 'What You Sell', icon: TagIcon },
    { number: 4, name: 'Review', icon: CheckCircleIcon },
];

const selectedIndustry = computed(() => {
    return industries.find(i => i.id === formData.value.industry);
});

const canProceed = computed(() => {
    switch (currentStep.value) {
        case 1:
            return formData.value.name.trim().length > 0;
        case 2:
            // Address is optional but if they fill city, require more
            return true;
        case 3:
            return formData.value.industry !== '';
        case 4:
            return true;
        default:
            return false;
    }
});

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

function skipToCreate() {
    // Skip directly to submission with minimal data
    if (!formData.value.name.trim()) return;

    formData.value.industry = 'other';
    formData.value.create_sample_data = false;
    submitStore();
}

function submitStore() {
    if (isSubmitting.value) return;

    isSubmitting.value = true;

    router.post('/stores', {
        name: formData.value.name.trim(),
        address_line1: formData.value.address_line1 || null,
        address_line2: formData.value.address_line2 || null,
        city: formData.value.city || null,
        state: formData.value.state || null,
        postal_code: formData.value.postal_code || null,
        country: formData.value.country || null,
        industry: formData.value.industry,
        create_sample_data: formData.value.create_sample_data,
    }, {
        preserveState: false,
        preserveScroll: false,
        onFinish: () => {
            isSubmitting.value = false;
            emit('close');
        },
        onError: () => {
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <div class="fixed inset-0 z-50">
        <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" @click="emit('close')"></div>

        <div class="fixed inset-0 z-10 overflow-y-auto">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-2xl transform overflow-hidden rounded-xl bg-white dark:bg-gray-800 shadow-2xl transition-all">
                    <!-- Close button -->
                    <button
                        type="button"
                        @click="emit('close')"
                        class="absolute right-4 top-4 text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                    >
                        <XMarkIcon class="h-6 w-6" />
                    </button>

                    <!-- Progress steps -->
                    <div class="border-b border-gray-200 dark:border-white/10 px-6 pt-6 pb-4">
                        <nav aria-label="Progress">
                            <ol role="list" class="flex items-center">
                                <li v-for="(step, stepIdx) in steps" :key="step.name" :class="[stepIdx !== steps.length - 1 ? 'pr-8 sm:pr-20' : '', 'relative']">
                                    <template v-if="currentStep > step.number">
                                        <!-- Completed step -->
                                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                                            <div class="h-0.5 w-full bg-indigo-600"></div>
                                        </div>
                                        <div class="relative flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600">
                                            <CheckCircleIcon class="h-5 w-5 text-white" />
                                        </div>
                                    </template>
                                    <template v-else-if="currentStep === step.number">
                                        <!-- Current step -->
                                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                                            <div class="h-0.5 w-full bg-gray-200 dark:bg-white/10"></div>
                                        </div>
                                        <div class="relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-indigo-600 bg-white dark:bg-gray-800">
                                            <component :is="step.icon" class="h-4 w-4 text-indigo-600" />
                                        </div>
                                    </template>
                                    <template v-else>
                                        <!-- Upcoming step -->
                                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                                            <div class="h-0.5 w-full bg-gray-200 dark:bg-white/10"></div>
                                        </div>
                                        <div class="relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-gray-300 dark:border-white/20 bg-white dark:bg-gray-800">
                                            <component :is="step.icon" class="h-4 w-4 text-gray-400" />
                                        </div>
                                    </template>
                                </li>
                            </ol>
                        </nav>
                        <div class="mt-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                Step {{ currentStep }} of {{ totalSteps }}: {{ steps[currentStep - 1].name }}
                            </p>
                        </div>
                    </div>

                    <!-- Step content -->
                    <div class="px-6 py-6">
                        <!-- Step 1: Store Info -->
                        <div v-if="currentStep === 1" class="space-y-6">
                            <div>
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                    Let's set up your store
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    What would you like to call your store?
                                </p>
                            </div>

                            <div>
                                <label for="store-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Store name <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="store-name"
                                    v-model="formData.name"
                                    placeholder="My Awesome Store"
                                    class="mt-2 block w-full rounded-lg border-0 bg-white dark:bg-gray-900 py-3 px-4 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-white/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                />
                            </div>

                            <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="skipToCreate"
                                    :disabled="!formData.name.trim()"
                                    class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white disabled:opacity-50"
                                >
                                    Skip wizard & create basic store
                                </button>
                            </div>
                        </div>

                        <!-- Step 2: Address -->
                        <div v-else-if="currentStep === 2" class="space-y-6">
                            <div>
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                    Business address
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    This will be used for shipping labels and invoices. You can skip this for now.
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

                        <!-- Step 3: Industry -->
                        <div v-else-if="currentStep === 3" class="space-y-6">
                            <div>
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                    What will you sell?
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    We'll create starter categories based on your selection.
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    v-for="industry in industries"
                                    :key="industry.id"
                                    type="button"
                                    @click="formData.industry = industry.id"
                                    :class="[
                                        formData.industry === industry.id
                                            ? 'ring-2 ring-indigo-600 bg-indigo-50 dark:bg-indigo-500/10'
                                            : 'ring-1 ring-gray-200 dark:ring-white/10 hover:bg-gray-50 dark:hover:bg-white/5',
                                        'relative rounded-lg p-4 text-left transition-all',
                                    ]"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="text-2xl">{{ industry.icon }}</span>
                                        <div>
                                            <p :class="[
                                                formData.industry === industry.id ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-900 dark:text-white',
                                                'text-sm font-medium',
                                            ]">
                                                {{ industry.name }}
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                {{ industry.description }}
                                            </p>
                                        </div>
                                    </div>
                                </button>
                            </div>

                            <div v-if="selectedIndustry && selectedIndustry.id !== 'other'" class="rounded-lg bg-gray-50 dark:bg-white/5 p-4">
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Categories we'll create for you:
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span
                                        v-for="cat in selectedIndustry.categories"
                                        :key="cat"
                                        class="inline-flex items-center rounded-md bg-indigo-50 dark:bg-indigo-500/10 px-2 py-1 text-xs font-medium text-indigo-700 dark:text-indigo-400"
                                    >
                                        {{ cat }}
                                    </span>
                                </div>
                            </div>

                            <label class="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    v-model="formData.create_sample_data"
                                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-white/20 dark:bg-white/5"
                                />
                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                    Create 2 sample products to help me get started
                                </span>
                            </label>
                        </div>

                        <!-- Step 4: Review -->
                        <div v-else-if="currentStep === 4" class="space-y-6">
                            <div>
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                    Review your store
                                </h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Everything look good? Let's create your store!
                                </p>
                            </div>

                            <dl class="divide-y divide-gray-200 dark:divide-white/10">
                                <div class="py-3 flex justify-between">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Store name</dt>
                                    <dd class="text-sm text-gray-900 dark:text-white">{{ formData.name }}</dd>
                                </div>
                                <div v-if="formData.city" class="py-3 flex justify-between">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Location</dt>
                                    <dd class="text-sm text-gray-900 dark:text-white">
                                        {{ formData.city }}<span v-if="formData.state">, {{ formData.state }}</span>
                                    </dd>
                                </div>
                                <div class="py-3 flex justify-between">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Industry</dt>
                                    <dd class="text-sm text-gray-900 dark:text-white">
                                        {{ selectedIndustry?.name || 'Not selected' }}
                                    </dd>
                                </div>
                                <div v-if="selectedIndustry && selectedIndustry.id !== 'other'" class="py-3">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Categories to create</dt>
                                    <dd class="flex flex-wrap gap-2">
                                        <span
                                            v-for="cat in selectedIndustry.categories"
                                            :key="cat"
                                            class="inline-flex items-center rounded-md bg-gray-100 dark:bg-white/10 px-2 py-1 text-xs font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            {{ cat }}
                                        </span>
                                    </dd>
                                </div>
                                <div class="py-3 flex justify-between">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Sample products</dt>
                                    <dd class="text-sm text-gray-900 dark:text-white">
                                        {{ formData.create_sample_data ? 'Yes, create 2 samples' : 'No' }}
                                    </dd>
                                </div>
                            </dl>

                            <div class="rounded-lg bg-indigo-50 dark:bg-indigo-500/10 p-4">
                                <p class="text-sm text-indigo-700 dark:text-indigo-400">
                                    <strong>Also included:</strong> Default warehouse, user roles (Owner, Admin, Manager, Staff, Viewer)
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Footer with navigation -->
                    <div class="border-t border-gray-200 dark:border-white/10 px-6 py-4 flex justify-between">
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
                            @click="submitStore"
                            :disabled="isSubmitting"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ isSubmitting ? 'Creating...' : 'Create Store' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
