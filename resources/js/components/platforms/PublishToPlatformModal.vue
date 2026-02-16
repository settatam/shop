<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
    DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    PencilIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface AvailableMarketplace {
    id: number;
    name: string;
    platform: string;
    platform_label: string;
}

interface ValidationResult {
    valid: boolean;
    errors: string[];
    warnings: string[];
}

interface ListingPreview {
    title: string;
    description: string | null;
    price: number | null;
    compare_at_price: number | null;
    quantity: number | null;
    attributes: Record<string, string>;
    metafields?: Array<{ namespace: string; key: string; value: string }>;
    item_specifics?: Array<{ Name: string; Value: string }>;
}

interface OverrideForm {
    title: string;
    description: string;
    price: number | null;
    compare_at_price: number | null;
    quantity: number | null;
}

interface Props {
    open: boolean;
    productId: number;
    availableMarketplaces: AvailableMarketplace[];
    initialMarketplace?: AvailableMarketplace | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'published'): void;
}>();

const selectedMarketplace = ref<AvailableMarketplace | null>(null);
const loading = ref(false);
const publishing = ref(false);
const preview = ref<ListingPreview | null>(null);
const validation = ref<ValidationResult | null>(null);
const isEditing = ref(false);
const override = ref<OverrideForm>({
    title: '',
    description: '',
    price: null,
    compare_at_price: null,
    quantity: null,
});

// Platform icon mapping
const platformIcons: Record<string, string> = {
    shopify: '/images/platforms/shopify.svg',
    ebay: '/images/platforms/ebay.svg',
    amazon: '/images/platforms/amazon.svg',
    etsy: '/images/platforms/etsy.svg',
    walmart: '/images/platforms/walmart.svg',
};

// Reset state when modal opens
watch(() => props.open, (isOpen) => {
    if (isOpen) {
        selectedMarketplace.value = props.initialMarketplace || null;
        preview.value = null;
        validation.value = null;
        isEditing.value = false;
        resetOverrideForm();

        if (selectedMarketplace.value) {
            loadPreview();
        }
    }
});

// Load preview when marketplace changes
watch(selectedMarketplace, (marketplace) => {
    if (marketplace) {
        loadPreview();
    } else {
        preview.value = null;
        validation.value = null;
        isEditing.value = false;
        resetOverrideForm();
    }
});

function resetOverrideForm() {
    override.value = {
        title: '',
        description: '',
        price: null,
        compare_at_price: null,
        quantity: null,
    };
}

function startEditing() {
    isEditing.value = true;
}

function cancelEditing() {
    isEditing.value = false;
    resetOverrideForm();
}

// Computed effective values (override or original)
const effectiveTitle = computed(() => override.value.title || preview.value?.title || '');
const effectiveDescription = computed(() => override.value.description || preview.value?.description || '');
const effectivePrice = computed(() => override.value.price ?? preview.value?.price ?? null);
const effectiveQuantity = computed(() => override.value.quantity ?? preview.value?.quantity ?? null);

// Check if any overrides are set
const hasOverrides = computed(() => {
    return override.value.title !== '' ||
        override.value.description !== '' ||
        override.value.price !== null ||
        override.value.compare_at_price !== null ||
        override.value.quantity !== null;
});

async function loadPreview() {
    if (!selectedMarketplace.value) return;

    loading.value = true;
    try {
        const response = await axios.get(`/products/${props.productId}/listings/${selectedMarketplace.value.id}/preview`);
        preview.value = response.data.listing;
        validation.value = response.data.validation;
    } catch (error) {
        console.error('Failed to load preview:', error);
    } finally {
        loading.value = false;
    }
}

const canPublish = computed(() => {
    return selectedMarketplace.value && validation.value?.valid && !publishing.value;
});

function formatPrice(price: number | null): string {
    if (price === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
}

async function publish() {
    if (!selectedMarketplace.value) return;

    publishing.value = true;
    try {
        // Build override data to send with publish request
        const overrideData: Record<string, unknown> = {};
        if (override.value.title) overrideData.title = override.value.title;
        if (override.value.description) overrideData.description = override.value.description;
        if (override.value.price !== null) overrideData.price = override.value.price;
        if (override.value.compare_at_price !== null) overrideData.compare_at_price = override.value.compare_at_price;
        if (override.value.quantity !== null) overrideData.quantity = override.value.quantity;

        await axios.post(`/products/${props.productId}/listings/${selectedMarketplace.value.id}/publish`, {
            override: Object.keys(overrideData).length > 0 ? overrideData : null,
        });
        emit('published');
    } catch (error) {
        console.error('Failed to publish:', error);
    } finally {
        publishing.value = false;
    }
}

function closeModal() {
    emit('update:open', false);
}

import { router } from '@inertiajs/vue3';

function selectMarketplace(marketplace: AvailableMarketplace) {
    // Navigate to the full platform page for detailed editing
    router.visit(`/products/${props.productId}/platforms/${marketplace.id}`);
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-w-2xl max-h-[90vh] flex flex-col">
            <DialogHeader>
                <DialogTitle>Publish to Platform</DialogTitle>
                <DialogDescription>
                    Select a platform to publish this product to.
                </DialogDescription>
            </DialogHeader>

            <div class="flex-1 overflow-y-auto py-4">
                <!-- Marketplace Selection -->
                <div v-if="!selectedMarketplace" class="space-y-3">
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Choose a marketplace to publish your product:
                    </p>

                    <button
                        v-for="marketplace in availableMarketplaces"
                        :key="marketplace.id"
                        type="button"
                        class="w-full flex items-center gap-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors text-left"
                        @click="selectMarketplace(marketplace)"
                    >
                        <div class="h-12 w-12 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                            <img
                                v-if="platformIcons[marketplace.platform]"
                                :src="platformIcons[marketplace.platform]"
                                :alt="marketplace.platform_label"
                                class="h-8 w-8"
                            />
                            <span v-else class="text-sm font-medium text-gray-500 uppercase">
                                {{ marketplace.platform.slice(0, 2) }}
                            </span>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ marketplace.name }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ marketplace.platform_label }}
                            </p>
                        </div>
                    </button>

                    <p v-if="availableMarketplaces.length === 0" class="text-center text-sm text-gray-500 dark:text-gray-400 py-8">
                        No available marketplaces to publish to.
                    </p>
                </div>

                <!-- Preview & Validation -->
                <div v-else class="space-y-6">
                    <!-- Selected Marketplace Header -->
                    <div class="flex items-center gap-4 p-4 rounded-lg bg-gray-50 dark:bg-gray-900">
                        <div class="h-10 w-10 rounded bg-white dark:bg-gray-700 flex items-center justify-center">
                            <img
                                v-if="platformIcons[selectedMarketplace.platform]"
                                :src="platformIcons[selectedMarketplace.platform]"
                                :alt="selectedMarketplace.platform_label"
                                class="h-6 w-6"
                            />
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ selectedMarketplace.name }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ selectedMarketplace.platform_label }}
                            </p>
                        </div>
                        <Button variant="ghost" size="sm" @click="selectedMarketplace = null">
                            Change
                        </Button>
                    </div>

                    <!-- Loading State -->
                    <div v-if="loading" class="space-y-4">
                        <Skeleton class="h-6 w-32" />
                        <div class="space-y-2">
                            <Skeleton class="h-4 w-full" />
                            <Skeleton class="h-4 w-3/4" />
                        </div>
                    </div>

                    <!-- Validation Status -->
                    <div v-else-if="validation" class="space-y-4">
                        <!-- Status Badge -->
                        <div class="flex items-center gap-2">
                            <CheckCircleIcon
                                v-if="validation.valid"
                                class="h-5 w-5 text-green-500"
                            />
                            <XCircleIcon
                                v-else
                                class="h-5 w-5 text-red-500"
                            />
                            <span
                                class="text-sm font-medium"
                                :class="validation.valid ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'"
                            >
                                {{ validation.valid ? 'Ready to publish' : 'Cannot publish' }}
                            </span>
                        </div>

                        <!-- Errors -->
                        <div v-if="validation.errors.length > 0" class="rounded-lg bg-red-50 dark:bg-red-900/20 p-4">
                            <h4 class="text-sm font-medium text-red-800 dark:text-red-300 mb-2 flex items-center gap-2">
                                <XCircleIcon class="h-4 w-4" />
                                Errors
                            </h4>
                            <ul class="list-disc list-inside text-sm text-red-700 dark:text-red-400 space-y-1">
                                <li v-for="error in validation.errors" :key="error">{{ error }}</li>
                            </ul>
                        </div>

                        <!-- Warnings -->
                        <div v-if="validation.warnings.length > 0" class="rounded-lg bg-yellow-50 dark:bg-yellow-900/20 p-4">
                            <h4 class="text-sm font-medium text-yellow-800 dark:text-yellow-300 mb-2 flex items-center gap-2">
                                <ExclamationTriangleIcon class="h-4 w-4" />
                                Warnings
                            </h4>
                            <ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-400 space-y-1">
                                <li v-for="warning in validation.warnings" :key="warning">{{ warning }}</li>
                            </ul>
                        </div>

                        <!-- Preview or Edit Mode -->
                        <div v-if="preview" class="space-y-4">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ isEditing ? 'Customize Listing' : 'Listing Preview' }}
                                </h4>
                                <Button
                                    v-if="!isEditing"
                                    variant="outline"
                                    size="sm"
                                    @click="startEditing"
                                >
                                    <PencilIcon class="h-4 w-4 mr-1" />
                                    Customize
                                </Button>
                                <Button
                                    v-else
                                    variant="ghost"
                                    size="sm"
                                    @click="cancelEditing"
                                >
                                    Cancel
                                </Button>
                            </div>

                            <!-- Edit Form -->
                            <div v-if="isEditing" class="space-y-4">
                                <!-- Title -->
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="title">Title</Label>
                                        <span v-if="!override.title" class="text-xs text-gray-500 dark:text-gray-400">
                                            Using product title
                                        </span>
                                    </div>
                                    <Input
                                        id="title"
                                        v-model="override.title"
                                        :placeholder="preview.title || 'Enter title'"
                                    />
                                    <p v-if="override.title" class="text-xs text-gray-500 dark:text-gray-400">
                                        Original: {{ preview.title }}
                                    </p>
                                </div>

                                <!-- Description -->
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="description">Description</Label>
                                        <span v-if="!override.description && preview.description" class="text-xs text-gray-500 dark:text-gray-400">
                                            Using product description
                                        </span>
                                    </div>
                                    <RichTextEditor
                                        v-model="override.description"
                                        :placeholder="preview.description ? 'Enter description to override' : 'Enter description'"
                                    />
                                </div>

                                <!-- Pricing -->
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <Label for="price">Price</Label>
                                            <span v-if="override.price === null && preview.price" class="text-xs text-gray-500 dark:text-gray-400">
                                                Using {{ formatPrice(preview.price) }}
                                            </span>
                                        </div>
                                        <Input
                                            id="price"
                                            v-model.number="override.price"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :placeholder="preview.price?.toString() || '0.00'"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <Label for="compare_at_price">Compare at Price</Label>
                                        <Input
                                            id="compare_at_price"
                                            v-model.number="override.compare_at_price"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :placeholder="preview.compare_at_price?.toString() || '0.00'"
                                        />
                                    </div>
                                </div>

                                <!-- Quantity -->
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="quantity">Quantity</Label>
                                        <span v-if="override.quantity === null && preview.quantity !== null" class="text-xs text-gray-500 dark:text-gray-400">
                                            Using {{ preview.quantity }}
                                        </span>
                                    </div>
                                    <Input
                                        id="quantity"
                                        v-model.number="override.quantity"
                                        type="number"
                                        min="0"
                                        :placeholder="preview.quantity?.toString() || '0'"
                                        class="max-w-xs"
                                    />
                                </div>

                                <!-- Effective Preview -->
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900 p-4">
                                    <h5 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-3">What will be published:</h5>
                                    <dl class="space-y-2 text-sm">
                                        <div class="flex items-start justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Title</dt>
                                            <dd class="text-gray-900 dark:text-white text-right max-w-xs truncate">
                                                {{ effectiveTitle }}
                                            </dd>
                                        </div>
                                        <div class="flex items-start justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Price</dt>
                                            <dd class="text-gray-900 dark:text-white">
                                                {{ formatPrice(effectivePrice) }}
                                            </dd>
                                        </div>
                                        <div class="flex items-start justify-between">
                                            <dt class="text-gray-500 dark:text-gray-400">Quantity</dt>
                                            <dd class="text-gray-900 dark:text-white">
                                                {{ effectiveQuantity ?? 'N/A' }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>

                            <!-- Read-Only Preview -->
                            <template v-else>
                                <dl class="space-y-3 text-sm">
                                    <div class="flex items-start justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Title</dt>
                                        <dd class="text-gray-900 dark:text-white text-right max-w-sm truncate font-medium">
                                            {{ effectiveTitle }}
                                            <Badge v-if="hasOverrides && override.title" variant="secondary" class="ml-2 text-xs">Customized</Badge>
                                        </dd>
                                    </div>
                                    <div class="flex items-start justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Price</dt>
                                        <dd class="text-gray-900 dark:text-white font-medium">
                                            {{ formatPrice(effectivePrice) }}
                                            <Badge v-if="hasOverrides && override.price !== null" variant="secondary" class="ml-2 text-xs">Customized</Badge>
                                        </dd>
                                    </div>
                                    <div class="flex items-start justify-between">
                                        <dt class="text-gray-500 dark:text-gray-400">Quantity</dt>
                                        <dd class="text-gray-900 dark:text-white">
                                            {{ effectiveQuantity ?? 'N/A' }}
                                            <Badge v-if="hasOverrides && override.quantity !== null" variant="secondary" class="ml-2 text-xs">Customized</Badge>
                                        </dd>
                                    </div>
                                </dl>

                                <!-- Attributes/Metafields Preview -->
                                <div v-if="preview.item_specifics && preview.item_specifics.length > 0">
                                    <h5 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Item Specifics</h5>
                                    <div class="flex flex-wrap gap-2">
                                        <Badge
                                            v-for="spec in preview.item_specifics.slice(0, 5)"
                                            :key="spec.Name"
                                            variant="secondary"
                                            class="text-xs"
                                        >
                                            {{ spec.Name }}: {{ spec.Value }}
                                        </Badge>
                                        <Badge
                                            v-if="preview.item_specifics.length > 5"
                                            variant="outline"
                                            class="text-xs"
                                        >
                                            +{{ preview.item_specifics.length - 5 }} more
                                        </Badge>
                                    </div>
                                </div>

                                <div v-if="preview.metafields && preview.metafields.length > 0">
                                    <h5 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Metafields</h5>
                                    <div class="flex flex-wrap gap-2">
                                        <Badge
                                            v-for="field in preview.metafields.slice(0, 5)"
                                            :key="`${field.namespace}.${field.key}`"
                                            variant="secondary"
                                            class="text-xs"
                                        >
                                            {{ field.key }}: {{ field.value }}
                                        </Badge>
                                        <Badge
                                            v-if="preview.metafields.length > 5"
                                            variant="outline"
                                            class="text-xs"
                                        >
                                            +{{ preview.metafields.length - 5 }} more
                                        </Badge>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="closeModal">Cancel</Button>
                <Button
                    v-if="selectedMarketplace"
                    @click="publish"
                    :disabled="!canPublish"
                >
                    {{ publishing ? 'Publishing...' : 'Publish' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
